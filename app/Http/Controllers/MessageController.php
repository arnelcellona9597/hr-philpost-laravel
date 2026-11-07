<?php

namespace App\Http\Controllers;

use App\Enums\Classification;
use App\Enums\EmploymentStatus;
use App\Enums\MessageCategory;
use App\Enums\RecipientStatus;
use App\Enums\UserStatus;
use App\Exceptions\UserFacingException;
use App\Http\Controllers\Concerns\RespondsToForms;
use App\Models\EmailTemplate;
use App\Models\Message;
use App\Models\User;
use App\Services\MailOutbox;
use App\Services\MediaStore;
use App\Services\MessageQueue;
use App\Support\Choices;
use App\Support\EmailLayout;
use App\Support\GovId;
use App\Support\MergeTemplate;
use App\Support\PageSize;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class MessageController extends Controller
{
    use RespondsToForms;

    private const MAX_ATTACHMENT_BYTES = 5 * 1024 * 1024;

    private const ATTACHMENT_TYPES = ['application/pdf' => 'pdf', 'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

    /**
     * Categories a person can pick. System is for messages the application writes itself.
     */
    private const COMPOSE_CATEGORIES = [
        MessageCategory::Announcement,
        MessageCategory::Leave,
        MessageCategory::Payroll,
        MessageCategory::Onboarding,
        MessageCategory::Reminder,
        MessageCategory::General,
    ];

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Message::class);

        $search = mb_substr(trim((string) $request->query('q')), 0, 100);
        $category = MessageCategory::tryFrom((string) $request->query('category'));
        $kind = (string) $request->query('kind');

        return view('messages.index', [
            'messages' => Message::query()
                ->when($category, fn ($query) => $query->where('category', $category))
                ->when($kind === 'manual', fn ($query) => $query->where('automatic', false))
                ->when($kind === 'automatic', fn ($query) => $query->where('automatic', true))
                ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                    ->where('subject', 'like', "%{$search}%")
                    ->orWhere('audience', 'like', "%{$search}%")
                    ->orWhere('sent_by_name', 'like', "%{$search}%")))
                ->latest('id')
                ->paginate(PageSize::resolve($request, 25))
                ->withQueryString(),
            'categories' => MessageCategory::cases(),
            'category' => $category,
            'kind' => $kind,
            'search' => $search,
        ]);
    }

    public function create(Request $request): View
    {
        Gate::authorize('viewAny', Message::class);

        $templates = EmailTemplate::query()->orderBy('name')->get();
        $wanted = (string) $request->query('template');
        $chosen = $templates->first(fn (EmailTemplate $template): bool => (string) $template->id === $wanted || ($template->key !== null && $template->key === $wanted));
        $context = $this->contextFrom($request->query());

        return view('messages.create', [
            'templates' => $templates,
            'chosen' => $chosen,
            'subject' => old('subject', $chosen?->subject ?? ''),
            'body' => old('body', $chosen?->body ?? ''),
            'category' => old('category', $chosen?->category->value ?? $request->query('category', MessageCategory::Announcement->value)),
            'employeeIds' => array_map('intval', (array) old('employees', array_filter(explode(',', (string) $request->query('employees'))))),
            'emails' => old('emails', ''),
            'context' => [...$context, ...$this->contextFrom((array) session()->getOldInput())],
            'categories' => self::COMPOSE_CATEGORIES,
            'employees' => Choices::employees(),
            'branches' => Choices::branches(),
            'users' => User::query()->where('status', UserStatus::Approved)->orderBy('name')->get(['id', 'name', 'email']),
            'classifications' => array_column(Classification::cases(), 'value'),
            'statuses' => array_column(EmploymentStatus::cases(), 'value'),
            'mergeFields' => MergeTemplate::FIELDS,
            'contextFields' => array_intersect_key(MergeTemplate::CONTEXT_FIELDS, $context),
        ]);
    }

    /**
     * What the message would look like: counts, the first recipient's text and who'd be skipped.
     */
    public function preview(Request $request, MessageQueue $queue): JsonResponse
    {
        Gate::authorize('viewAny', Message::class);

        try {
            $message = $this->parse($request);
            $prepared = $queue->prepare($message['template'], $message['recipients']);
        } catch (UserFacingException $exception) {
            return response()->json(['error' => $exception->getMessage()], 400);
        }

        $sample = Arr::first($prepared, fn (array $row): bool => $row['skip'] === null) ?? $prepared[0];
        $skipped = array_values(array_filter($prepared, fn (array $row): bool => $row['skip'] !== null));

        return response()->json([
            'total' => count($prepared),
            'deliverable' => count($prepared) - count($skipped),
            'skipped' => array_map(fn (array $row): array => ['name' => $row['name'], 'reason' => $row['skip']], $skipped),
            'sample' => [
                'to' => $sample['email'],
                'name' => $sample['name'],
                'html' => EmailLayout::html($sample['subject'], $sample['body'], $request->user()->name),
            ],
        ]);
    }

    public function store(Request $request, MessageQueue $queue, MediaStore $media): RedirectResponse
    {
        Gate::authorize('viewAny', Message::class);

        $stored = [];

        try {
            $message = $this->parse($request);
            $files = array_values(array_filter(Arr::wrap($request->file('attachments')), fn (mixed $file): bool => $file instanceof UploadedFile));

            if (array_sum(array_map(fn (UploadedFile $file): int => (int) $file->getSize(), $files)) > self::MAX_ATTACHMENT_BYTES) {
                throw new UserFacingException('Attachments are larger than 5 MB in total.');
            }

            foreach ($files as $file) {
                $stored[] = $media->store($file, "Attachment: {$message['template']['subject']}", self::ATTACHMENT_TYPES, self::MAX_ATTACHMENT_BYTES);
            }

            $created = DB::transaction(function () use ($queue, $message, $stored, $request): Message {
                $created = $queue->queueMany($message['template'], $message['category'], $message['recipients'], $message['audience'], null, $request->user(), false);
                $created->attachments()->sync(array_map(fn ($file): int => $file->id, $stored));

                return $created;
            });
        } catch (UserFacingException $exception) {
            foreach ($stored as $file) {
                $media->delete($file);
            }

            return $this->refused($exception->getMessage());
        }

        $queued = $created->total - $created->skipped;
        $skippedNote = $created->skipped > 0 ? "; {$created->skipped} skipped (no email address)" : '';

        return $this->flash(redirect()->route('messages.show', $created), "Message queued for {$queued} recipient(s){$skippedNote}. It is being sent now.");
    }

    public function show(Request $request, Message $message): View
    {
        Gate::authorize('view', $message);

        $status = RecipientStatus::tryFrom((string) $request->query('status'));

        return view('messages.show', [
            'message' => $message->load('attachments'),
            'recipients' => $message->recipients()->when($status, fn ($query) => $query->where('status', $status))->orderBy('id')->paginate(PageSize::resolve($request, 50))->withQueryString(),
            'status' => $status,
        ]);
    }

    public function process(Message $message, MailOutbox $outbox): RedirectResponse
    {
        Gate::authorize('view', $message);

        $result = $outbox->drain();
        $waiting = $result['remaining'] > 0 ? " {$result['remaining']} still waiting (hourly limit or retry delay)." : '';

        return $this->flash(redirect()->route('messages.show', $message), "Sent {$result['sent']} email(s) now.{$waiting}", 'info');
    }

    public function retry(Message $message, MailOutbox $outbox): RedirectResponse
    {
        Gate::authorize('view', $message);

        $count = $outbox->retryFailed($message);

        return $this->flash(redirect()->route('messages.show', $message), "{$count} failed email(s) put back in the queue.");
    }

    /**
     * Read the compose form.
     *
     * @return array{template: array{subject: string, body: string}, category: MessageCategory, recipients: list<array<string, mixed>>, audience: string}
     *
     * @throws UserFacingException with what to fix
     */
    private function parse(Request $request): array
    {
        $subject = trim((string) $request->input('subject'));
        $body = trim((string) $request->input('body'));

        if ($subject === '') {
            throw new UserFacingException('Enter a subject.');
        }

        if ($body === '') {
            throw new UserFacingException('Write the message.');
        }

        if (mb_strlen($subject) > 200) {
            throw new UserFacingException('The subject is too long (200 characters max).');
        }

        $context = $this->contextFrom($request->all());
        $this->checkPlaceholders("{$subject}\n{$body}", $context);

        $employeeIds = $this->integers($request, 'employees');
        $branchIds = $this->integers($request, 'group_branch');
        $userIds = $this->integers($request, 'users');
        $allActive = $request->boolean('all_active');
        $allUsers = $request->boolean('all_users');
        $classifications = array_values(array_intersect((array) $request->input('group_classification'), array_column(Classification::cases(), 'value')));
        $statuses = array_values(array_intersect((array) $request->input('group_status'), array_column(EmploymentStatus::cases(), 'value')));
        $emails = array_values(array_filter(preg_split('/[\s,;]+/', (string) $request->input('emails')) ?: []));

        foreach ($emails as $email) {
            if (GovId::validateEmail($email) !== null) {
                throw new UserFacingException("\"{$email}\" is not a valid email address.");
            }
        }

        $hasGroup = $allActive || $branchIds !== [] || $classifications !== [] || $statuses !== [];
        $labels = [];

        if ($employeeIds !== []) {
            $labels[] = count($employeeIds).' selected employee(s)';
        }

        if ($hasGroup) {
            $parts = trim(implode(' ', array_filter([$allActive ? 'active' : '', implode('/', $statuses), implode('/', $classifications)])));
            $labels[] = trim(($parts ?: 'all').' employees'.($branchIds !== [] ? ' in '.count($branchIds).' branch(es)' : ''));
        }

        if ($allUsers) {
            $labels[] = 'all HR users';
        } elseif ($userIds !== []) {
            $labels[] = count($userIds).' HR user(s)';
        }

        if ($emails !== []) {
            $labels[] = count($emails).' other address(es)';
        }

        if ($labels === []) {
            throw new UserFacingException('Choose at least one recipient.');
        }

        $recipients = app(MessageQueue::class)->expandAudience([
            'employee_ids' => $employeeIds,
            'all_active' => $allActive,
            'statuses' => $statuses,
            'classifications' => $classifications,
            'branch_ids' => $branchIds,
            'user_ids' => $userIds,
            'all_users' => $allUsers,
            'emails' => $emails,
        ]);

        if ($recipients === []) {
            throw new UserFacingException('Choose at least one recipient.');
        }

        $category = MessageCategory::tryFrom((string) $request->input('category'));

        return [
            'template' => ['subject' => $subject, 'body' => $body],
            'category' => in_array($category, self::COMPOSE_CATEGORIES, true) ? $category : MessageCategory::General,
            'recipients' => array_map(fn (array $recipient): array => [...$recipient, 'context' => $context], $recipients),
            'audience' => implode(', ', $labels),
        ];
    }

    /**
     * @param  array<string, string>  $context
     *
     * @throws UserFacingException when the text uses a placeholder this message can't fill
     */
    private function checkPlaceholders(string $text, array $context): void
    {
        $unknown = MergeTemplate::unknownPlaceholders($text, [...array_keys(MergeTemplate::FIELDS), ...array_keys($context)]);

        if ($unknown === []) {
            return;
        }

        $wrap = fn (string $name): string => "{{{$name}}}";
        $automatic = array_values(array_intersect($unknown, array_keys(MergeTemplate::CONTEXT_FIELDS)));
        $hint = $automatic !== [] ? ' ('.implode(', ', array_map($wrap, $automatic)).' only work in automatic emails)' : '';

        throw new UserFacingException('Unknown placeholder(s): '.implode(', ', array_map($wrap, $unknown)).$hint);
    }

    /**
     * Merge values carried by links such as "Email reminder to all missing", as ctx_<name>.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    private function contextFrom(array $input): array
    {
        $context = [];

        foreach ($input as $name => $value) {
            if (is_string($name) && str_starts_with($name, 'ctx_') && is_scalar($value)) {
                $context[substr($name, 4)] = (string) $value;
            }
        }

        return $context;
    }

    /**
     * @return list<int>
     */
    private function integers(Request $request, string $name): array
    {
        return array_values(array_filter(array_map('intval', (array) $request->input($name)), fn (int $id): bool => $id > 0));
    }
}
