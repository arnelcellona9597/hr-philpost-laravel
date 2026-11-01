<?php

namespace App\Services;

use App\Enums\MessageCategory;
use App\Enums\MessageStatus;
use App\Enums\RecipientStatus;
use App\Models\EmailTemplate;
use App\Models\Employee;
use App\Models\Message;
use App\Models\SiteSetting;
use App\Models\User;
use App\Support\GovId;
use App\Support\MergeTemplate;

/**
 * Saves automatic emails to the outbox with their text frozen, one recipient row each. The queue
 * worker sends what is waiting here.
 */
class MessageQueue
{
    /**
     * The saved template with this key, or the fallback when it isn't there.
     *
     * @param  array{subject: string, body: string}  $fallback
     * @return array{subject: string, body: string}
     */
    public function template(string $key, array $fallback): array
    {
        $template = EmailTemplate::query()->where('key', $key)->first();

        return $template ? ['subject' => $template->subject, 'body' => $template->body] : $fallback;
    }

    /**
     * Queue one automatic message. A recipient with no valid address is recorded as skipped.
     *
     * @param  array{subject: string, body: string}  $template
     * @param  array{email?: string|null, name?: string|null, employee?: Employee|null}  $recipient
     * @param  array<string, string|int|float|null>  $context
     * @param  array{collection: string, id: int}  $related
     */
    public function queueOne(array $template, MessageCategory $category, array $recipient, array $context, string $audience, array $related, ?User $sentBy, bool $automatic = true): Message
    {
        return $this->queueMany($template, $category, [[...$recipient, 'context' => $context]], $audience, $related, $sentBy, $automatic);
    }

    /**
     * Queue one message to many recipients, each with their own merged text. Recipients with no
     * valid address are recorded as skipped.
     *
     * @param  array{subject: string, body: string}  $template
     * @param  list<array{email?: string|null, name?: string|null, employee?: Employee|null, context?: array<string, string|int|float|null>, payslip_id?: int|null}>  $recipients
     * @param  array{collection: string, id: int}  $related
     */
    public function queueMany(array $template, MessageCategory $category, array $recipients, string $audience, array $related, ?User $sentBy, bool $automatic = true): Message
    {
        $isDeliverable = function (array $recipient): bool {
            $email = $recipient['email'] ?? ($recipient['employee'] ?? null)?->email;

            return filled($email) && GovId::validateEmail($email) === null;
        };
        $skipped = count(array_filter($recipients, fn (array $recipient): bool => ! $isDeliverable($recipient)));

        $message = new Message([
            'subject' => $template['subject'],
            'body' => $template['body'],
            'category' => $category,
            'audience' => $audience,
            'automatic' => $automatic,
            'related_collection' => $related['collection'],
            'related_id' => (string) $related['id'],
            'sent_by' => $sentBy?->id,
            'sent_by_name' => $sentBy?->name ?? 'System',
        ]);
        $message->forceFill([
            'status' => $skipped < count($recipients) ? MessageStatus::Queued : MessageStatus::Failed,
            'total' => count($recipients),
            'skipped' => $skipped,
        ])->save();

        foreach ($recipients as $recipient) {
            $employee = $recipient['employee'] ?? null;
            $context = array_filter($recipient['context'] ?? [], fn (mixed $value): bool => $value !== null);
            $values = [...$this->mergeValues($employee), ...$context];

            $row = $message->recipients()->make([
                'name' => $recipient['name'] ?? $employee?->full_name,
                'email' => $recipient['email'] ?? $employee?->email,
                'employee_id' => $employee?->id,
                'subject' => MergeTemplate::render($template['subject'], $values),
                'body' => MergeTemplate::render($template['body'], $values),
                'payslip_id' => $recipient['payslip_id'] ?? null,
            ]);
            $row->forceFill(['status' => $isDeliverable($recipient) ? RecipientStatus::Queued : RecipientStatus::Skipped])->save();
        }

        return $message;
    }

    /**
     * @return array<string, string>
     */
    private function mergeValues(?Employee $employee): array
    {
        $values = ['companyName' => SiteSetting::current()->company_name];

        if ($employee === null) {
            return $values;
        }

        return $values + [
            'firstName' => $employee->first_name,
            'lastName' => $employee->last_name,
            'fullName' => $employee->full_name,
            'employeeId' => $employee->employee_id,
            'position' => (string) $employee->position,
            'station' => (string) $employee->station?->name,
        ];
    }
}
