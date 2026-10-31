<?php

namespace App\Services;

use App\Mail\SystemMessage;
use App\Models\NotificationSetting;
use App\Models\SmtpSetting;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends a message right now. `MAIL_MAILER=smtp` uses the server saved on the Email Server page;
 * any other mailer is for local work and tests.
 */
class DirectMailer
{
    public function isConfigured(): bool
    {
        if ($this->usesSavedServer()) {
            $settings = SmtpSetting::current();

            return filled($settings->host) && filled($settings->from_address);
        }

        // A log mailer would write sign-in codes and password links to a file in production.
        return ! app()->isProduction();
    }

    /**
     * Whether the message was handed to the mail server. Never logs the message itself.
     */
    public function send(string $to, string $subject, string $body): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        try {
            if ($this->usesSavedServer()) {
                $settings = SmtpSetting::current();
                $sender = new Address($settings->from_address, $settings->from_name);

                Mail::build(self::transportConfig($settings))->to($to)->send(new SystemMessage($subject, $body, $sender));
            } else {
                Mail::to($to)->send(new SystemMessage($subject, $body));
            }
        } catch (Throwable $exception) {
            report($exception);
            $this->recordFailure($exception->getMessage());

            return false;
        }

        if ($this->usesSavedServer()) {
            NotificationSetting::current()->forceFill(['smtp_last_error' => null, 'smtp_last_error_at' => null, 'smtp_last_ok_at' => now()])->save();
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public static function transportConfig(SmtpSetting $settings): array
    {
        $config = [
            'transport' => 'smtp',
            'host' => $settings->host,
            'port' => $settings->port,
            'username' => $settings->username,
            'password' => $settings->password,
            'timeout' => 15,
        ];

        return $config + match ($settings->security) {
            'ssl' => ['scheme' => 'smtps'],
            'starttls' => ['scheme' => 'smtp', 'require_tls' => true],
            default => ['scheme' => 'smtp', 'auto_tls' => false],
        };
    }

    private function usesSavedServer(): bool
    {
        return config('mail.default') === 'smtp';
    }

    private function recordFailure(string $message): void
    {
        NotificationSetting::current()->forceFill(['smtp_last_error' => $message, 'smtp_last_error_at' => now()])->save();
    }
}
