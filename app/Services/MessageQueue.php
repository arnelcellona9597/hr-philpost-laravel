<?php

namespace App\Services;

use App\Enums\EmploymentStatus;
use App\Enums\MessageCategory;
use App\Enums\MessageStatus;
use App\Enums\RecipientStatus;
use App\Enums\UserStatus;
use App\Exceptions\UserFacingException;
use App\Models\EmailTemplate;
use App\Models\Employee;
use App\Models\LeaveSetting;
use App\Models\Message;
use App\Models\SiteSetting;
use App\Models\User;
use App\Support\GovId;
use App\Support\HrDate;
use App\Support\MergeTemplate;
use App\Support\Names;
use Illuminate\Support\Str;

use function Illuminate\Support\defer;

/**
 * Saves automatic emails to the outbox with their text frozen, one recipient row each. The queue
 * worker sends what is waiting here.
 *
 * @phpstan-type Recipient array{email?: string|null, name?: string|null, employee?: Employee|null, user?: User|null, context?: array<string, string|int|float|null>, payslip_id?: int|null}
 * @phpstan-type PreparedRecipient array{name: string, email: string|null, employee_id: int|null, user_id: int|null, payslip_id: int|null, subject: string, body: string, skip: string|null}
 */
class MessageQueue
{
    public const MAX_RECIPIENTS = 2000;

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
     * Save a message with its text frozen, one row per recipient. Recipients with no valid address
     * are recorded as skipped, and it goes out as soon as the request has finished.
     *
     * @param  array{subject: string, body: string}  $template
     * @param  list<Recipient>  $recipients
     * @param  array{collection: string, id: int}|null  $related
     *
     * @throws UserFacingException when there are too many recipients
     */
    public function queueMany(array $template, MessageCategory $category, array $recipients, string $audience, ?array $related, ?User $sentBy, bool $automatic = true): Message
    {
        $prepared = $this->prepare($template, $recipients);
        $skipped = count(array_filter($prepared, fn (array $row): bool => $row['skip'] !== null));

        $message = new Message([
            'subject' => $template['subject'],
            'body' => $template['body'],
            'category' => $category,
            'audience' => $audience,
            'automatic' => $automatic,
            'related_collection' => $related['collection'] ?? null,
            'related_id' => isset($related['id']) ? (string) $related['id'] : null,
            'sent_by' => $sentBy?->id,
            'sent_by_name' => $sentBy ? ($sentBy->name ?: $sentBy->email) : 'System',
        ]);
        $message->forceFill([
            'status' => $skipped < count($prepared) ? MessageStatus::Queued : MessageStatus::Failed,
            'total' => count($prepared),
            'skipped' => $skipped,
        ])->save();

        foreach ($prepared as $row) {
            $recipient = $message->recipients()->make([
                'name' => $row['name'],
                'email' => $row['email'],
                'employee_id' => $row['employee_id'],
                'user_id' => $row['user_id'],
                'subject' => $row['subject'],
                'body' => $row['body'],
                'payslip_id' => $row['payslip_id'],
            ]);
            $recipient->forceFill(['status' => $row['skip'] === null ? RecipientStatus::Queued : RecipientStatus::Skipped, 'error' => $row['skip']])->save();
        }

        if (config('mail.default') === 'smtp') {
            defer(fn () => app(MailOutbox::class)->processBatch(), 'mail-outbox');
        }

        return $message;
    }

    /**
     * Render the text each recipient would get, one row per address. Payslips are the exception:
     * spouses sharing an address each get their own.
     *
     * @param  array{subject: string, body: string}  $template
     * @param  list<Recipient>  $recipients
     * @return list<PreparedRecipient>
     *
     * @throws UserFacingException when there are too many recipients
     */
    public function prepare(array $template, array $recipients): array
    {
        $company = SiteSetting::current()->company_name;
        $needsLeave = str_contains($template['subject'].$template['body'], 'leaveRemaining');
        $seen = [];
        $prepared = [];

        foreach ($recipients as $recipient) {
            $employee = $recipient['employee'] ?? null;
            $user = $recipient['user'] ?? null;
            $email = Str::lower(trim((string) ($recipient['email'] ?? $employee?->email ?? $user?->email))) ?: null;
            $name = $recipient['name'] ?? ($employee ? Names::title("{$employee->first_name} {$employee->last_name}") : ($user?->name ?? (string) $email));
            $skip = match (true) {
                $email === null => 'No email address on file',
                GovId::validateEmail($email) !== null => 'Invalid email address',
                default => null,
            };

            if ($skip === null && isset($seen[$email]) && ! isset($recipient['payslip_id'])) {
                continue;
            }

            if ($email !== null) {
                $seen[$email] = true;
            }

            $context = array_filter($recipient['context'] ?? [], fn (mixed $value): bool => $value !== null);
            $values = [...$this->mergeValues($company, $employee, $user, $name, $needsLeave), ...$context];

            $prepared[] = [
                'name' => $name,
                'email' => $email,
                'employee_id' => $employee?->id,
                'user_id' => $user?->id,
                'payslip_id' => $recipient['payslip_id'] ?? null,
                'subject' => MergeTemplate::render($template['subject'], $values),
                'body' => MergeTemplate::render($template['body'], $values),
                'skip' => $skip,
            ];
        }

        if (count($prepared) > self::MAX_RECIPIENTS) {
            throw new UserFacingException('Too many recipients ('.count($prepared).'). The limit is '.self::MAX_RECIPIENTS.' per message.');
        }

        return $prepared;
    }

    /**
     * People chosen on the compose page. An employee group must match every filter given.
     *
     * @param  array{employee_ids?: list<int>, all_active?: bool, statuses?: list<string>, classifications?: list<string>, branch_ids?: list<int>, user_ids?: list<int>, all_users?: bool, emails?: list<string>}  $audience
     * @return list<Recipient>
     */
    public function expandAudience(array $audience): array
    {
        $allActive = $audience['all_active'] ?? false;
        $statuses = $audience['statuses'] ?? [];
        $classifications = $audience['classifications'] ?? [];
        $branchIds = $audience['branch_ids'] ?? [];
        $employeeIds = $audience['employee_ids'] ?? [];
        $hasGroup = $allActive || $statuses !== [] || $classifications !== [] || $branchIds !== [];
        $recipients = [];

        if ($employeeIds !== [] || $hasGroup) {
            $employees = Employee::query()
                ->with('station')
                ->where(function ($query) use ($employeeIds, $hasGroup, $allActive, $statuses, $classifications, $branchIds): void {
                    $query->whereIn('id', $employeeIds);

                    if ($hasGroup) {
                        $query->orWhere(fn ($group) => $group
                            ->when($allActive, fn ($filter) => $filter->where('employment_status', EmploymentStatus::Active))
                            ->when($statuses !== [], fn ($filter) => $filter->whereIn('employment_status', $statuses))
                            ->when($classifications !== [], fn ($filter) => $filter->whereIn('classification', $classifications))
                            ->when($branchIds !== [], fn ($filter) => $filter->whereIn('station_id', $branchIds)));
                    }
                })
                ->orderBy('full_name')
                ->get();

            foreach ($employees as $employee) {
                $recipients[] = ['employee' => $employee];
            }
        }

        $userIds = $audience['user_ids'] ?? [];
        $allUsers = $audience['all_users'] ?? false;

        if ($allUsers || $userIds !== []) {
            $users = User::query()->where('status', UserStatus::Approved)->when(! $allUsers, fn ($query) => $query->whereIn('id', $userIds))->orderBy('name')->get();

            foreach ($users as $user) {
                $recipients[] = ['user' => $user];
            }
        }

        foreach ($audience['emails'] ?? [] as $email) {
            $recipients[] = ['email' => $email];
        }

        return $recipients;
    }

    /**
     * @return array<string, string|int>
     */
    private function mergeValues(string $company, ?Employee $employee, ?User $user, string $name, bool $needsLeave): array
    {
        return [
            'companyName' => $company,
            'firstName' => $employee ? Names::title($employee->first_name) : Str::before(trim($user?->name ?? $name), ' '),
            'lastName' => Names::title($employee?->last_name),
            'fullName' => $employee ? Names::title(Names::firstLast($employee->last_name, $employee->first_name, $employee->middle_name, $employee->extension)) : $name,
            'employeeId' => (string) $employee?->employee_id,
            'position' => (string) $employee?->position,
            'station' => (string) $employee?->station?->name,
            'leaveRemaining' => $employee && $needsLeave ? $this->leaveRemaining($employee) : '',
        ];
    }

    private function leaveRemaining(Employee $employee): int
    {
        $year = (int) substr(HrDate::today(), 0, 4);

        return LeaveSetting::current()->allowanceForYear($year) - $employee->wellnessDaysUsedIn($year);
    }
}
