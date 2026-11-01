<?php

namespace App\Services;

use App\Enums\MessageStatus;
use App\Enums\RecipientStatus;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\Message;
use App\Models\MessageRecipient;
use App\Models\Notification;
use App\Models\NotificationSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Sends what is waiting in the outbox: small batches within the hourly limit, with retries. A lock
 * keeps two runs apart, so the hourly budget holds and each email goes out once.
 */
class MailOutbox
{
    public const BATCH_SIZE = 25;

    private const MAX_BATCHES = 8;

    private const MAX_ATTEMPTS = 3;

    private const STUCK_AFTER_MINUTES = 10;

    public function __construct(private DirectMailer $mailer, private PdfRenderer $pdf) {}

    /**
     * Send one batch. `remaining` is -1 when another run holds the lock.
     *
     * @return array{sent: int, failed: int, remaining: int}
     */
    public function processBatch(): array
    {
        $result = Cache::lock('mail-outbox', 600)->get(fn (): array => $this->sendBatch());

        return $result ?: ['sent' => 0, 'failed' => 0, 'remaining' => -1];
    }

    /**
     * Keep sending until the queue is empty, the hourly limit is reached or the batches run out, so
     * one run can't hold the process for too long.
     *
     * @return array{sent: int, failed: int, remaining: int}
     */
    public function drain(): array
    {
        $totals = ['sent' => 0, 'failed' => 0, 'remaining' => 0];

        for ($batch = 0; $batch < self::MAX_BATCHES; $batch++) {
            $result = $this->processBatch();

            if ($result['remaining'] < 0) {
                break;
            }

            $totals = ['sent' => $totals['sent'] + $result['sent'], 'failed' => $totals['failed'] + $result['failed'], 'remaining' => $result['remaining']];

            if ($result['remaining'] === 0 || $result['sent'] + $result['failed'] === 0) {
                break;
            }
        }

        return $totals;
    }

    /**
     * Put a message's failed recipients back in the queue. Returns how many.
     */
    public function retryFailed(Message $message): int
    {
        $count = $message->recipients()->where('status', RecipientStatus::Failed)->update([
            'status' => RecipientStatus::Queued,
            'attempts' => 0,
            'next_attempt_at' => null,
        ]);

        $this->rollUp($message);

        return $count;
    }

    /**
     * Recompute a message's counters and overall status from its recipients.
     */
    public function rollUp(Message $message): void
    {
        $counts = $message->recipients()->toBase()->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');
        $count = fn (RecipientStatus $status): int => (int) ($counts[$status->value] ?? 0);
        $sent = $count(RecipientStatus::Sent);
        $failed = $count(RecipientStatus::Failed);
        $pending = $count(RecipientStatus::Queued) + $count(RecipientStatus::Sending);

        $status = match (true) {
            $pending > 0 => $sent > 0 ? MessageStatus::Sending : MessageStatus::Queued,
            $sent === 0 => MessageStatus::Failed,
            $failed === 0 => MessageStatus::Sent,
            default => MessageStatus::Partial,
        };

        $message->forceFill(['status' => $status, 'sent' => $sent, 'failed' => $failed, 'skipped' => $count(RecipientStatus::Skipped)])->save();
    }

    /**
     * @return array{sent: int, failed: int, remaining: int}
     */
    private function sendBatch(): array
    {
        $this->releaseStuckRows();

        $sent = 0;
        $failed = 0;
        $budget = $this->hourlyBudget();

        if ($budget > 0 && $this->mailer->isConfigured()) {
            $touched = [];
            $gaveUp = [];

            foreach ($this->claim($budget) as $recipient) {
                $touched[$recipient->message_id] = $recipient->message;

                if ($this->sendOne($recipient)) {
                    $sent++;
                } elseif ($recipient->status === RecipientStatus::Failed) {
                    $failed++;
                    $gaveUp[$recipient->message_id] = $recipient->message;
                }
            }

            foreach ($touched as $message) {
                $this->rollUp($message);
            }

            foreach ($gaveUp as $message) {
                $this->alertAdmins($message);
            }
        }

        return [
            'sent' => $sent,
            'failed' => $failed,
            'remaining' => MessageRecipient::query()->whereIn('status', [RecipientStatus::Queued, RecipientStatus::Sending])->count(),
        ];
    }

    /**
     * Rows left in "sending" by a run that stopped halfway go back in the queue.
     */
    private function releaseStuckRows(): void
    {
        MessageRecipient::query()
            ->where('status', RecipientStatus::Sending)
            ->where('updated_at', '<', now()->subMinutes(self::STUCK_AFTER_MINUTES))
            ->update(['status' => RecipientStatus::Queued]);
    }

    private function hourlyBudget(): int
    {
        $sentLastHour = MessageRecipient::query()
            ->where('status', RecipientStatus::Sent)
            ->where('sent_at', '>', now()->subHour())
            ->count();

        return min(self::BATCH_SIZE, NotificationSetting::current()->hourly_limit - $sentLastHour);
    }

    /**
     * @return iterable<MessageRecipient>
     */
    private function claim(int $limit): iterable
    {
        $ids = DB::transaction(function () use ($limit) {
            $ids = MessageRecipient::query()
                ->where('status', RecipientStatus::Queued)
                ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
                ->orderBy('id')
                ->limit($limit)
                ->lock('FOR UPDATE SKIP LOCKED')
                ->pluck('id');

            MessageRecipient::query()->whereKey($ids)->update(['status' => RecipientStatus::Sending]);

            return $ids;
        });

        return MessageRecipient::query()->with(['message.attachments', 'payslip.period'])->whereKey($ids)->orderBy('id')->get();
    }

    private function sendOne(MessageRecipient $recipient): bool
    {
        $message = $recipient->message;

        try {
            $this->mailer->deliver(
                $recipient->email,
                $recipient->subject ?? $message->subject,
                $recipient->body ?? $message->body,
                $message->automatic ? null : $message->sent_by_name,
                $this->attachments($recipient),
            );
        } catch (Throwable $exception) {
            $attempts = $recipient->attempts + 1;
            $giveUp = $attempts >= self::MAX_ATTEMPTS;

            $recipient->forceFill([
                'status' => $giveUp ? RecipientStatus::Failed : RecipientStatus::Queued,
                'attempts' => $attempts,
                'error' => mb_substr($exception->getMessage() ?: 'Send failed', 0, 300),
                'next_attempt_at' => $giveUp ? null : now()->addMinutes(2 ** $attempts),
            ])->save();

            return false;
        }

        $recipient->forceFill(['status' => RecipientStatus::Sent, 'sent_at' => now(), 'error' => null])->save();

        return true;
    }

    /**
     * Files sent with a message. Payslip PDFs are drawn at send time, so a correction is included.
     *
     * @return list<array{name: string, bytes: string, mime: string}>
     */
    private function attachments(MessageRecipient $recipient): array
    {
        $files = [];
        $disk = Storage::disk('local');

        foreach ($recipient->message->attachments as $media) {
            if ($disk->exists($media->path)) {
                $files[] = ['name' => $media->filename, 'bytes' => (string) $disk->get($media->path), 'mime' => $media->mime_type];
            }
        }

        if ($recipient->payslip !== null) {
            $files[] = ['name' => $this->pdf->payslipFilename($recipient->payslip), 'bytes' => $this->pdf->payslip($recipient->payslip), 'mime' => 'application/pdf'];
        }

        return $files;
    }

    /**
     * Put a failed delivery on the bell of every System Admin, once until they have read it.
     */
    private function alertAdmins(Message $message): void
    {
        $link = "/messages/{$message->id}";

        $admins = User::query()
            ->where('status', UserStatus::Approved)
            ->where('role', Role::SystemAdmin)
            ->whereDoesntHave('hrNotifications', fn ($query) => $query->where('link', $link)->whereNull('read_at'))
            ->pluck('id');

        foreach ($admins as $adminId) {
            Notification::query()->create(['user_id' => $adminId, 'title' => 'An email could not be sent', 'body' => $message->subject, 'link' => $link]);
        }
    }
}
