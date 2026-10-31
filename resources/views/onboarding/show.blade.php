@php
    use App\Enums\ApplicationStatus;
    use App\Enums\RequirementsCompletion;
    use App\Support\Badge;

    $sentAt = $application->requirements_email_sent_at?->format('M j, Y g:i A');
    $isHired = $application->application_status === ApplicationStatus::Hired;
@endphp

<x-layouts.app
    :title="$application->applicant_name"
    :description="$application->application_id.' · '.$application->position_applied"
    :back="['href' => route('onboarding.index'), 'label' => 'Onboarding tracker']"
>
    <x-slot:actions>
        <span class="{{ Badge::for($application->requirements_status) }}">{{ $application->requirements_status === RequirementsCompletion::Complete ? 'Requirements complete' : "{$application->requirements_missing} requirement(s) missing" }}</span>
        @if ($application->employee_id)
            <a class="btn-secondary" href="{{ route('employees.show', $application->employee_id) }}"><x-icon name="user" /> Open employee record</a>
        @else
            <a class="btn-primary" href="{{ route('employees.create', ['fromApplication' => $application->id]) }}"><x-icon name="user" /> Convert to employee</a>
        @endif
        <form method="post" action="{{ route('onboarding.destroy', $application) }}" data-confirm="Delete application {{ $application->application_id }}?">
            @csrf
            @method('DELETE')
            <button class="btn-danger"><x-icon name="trash" /> Delete</button>
        </form>
    </x-slot:actions>

    <section class="card mb-6 p-5" aria-labelledby="req-email-title" data-requirements-email>
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0 flex-1">
                <h2 id="req-email-title" class="flex items-center gap-2 font-semibold"><x-icon name="mail" class="size-4 text-muted" /> Requirements for employment email</h2>
                <p class="mt-1 text-sm" data-email-status>
                    @if ($sentAt)
                        <span class="badge-success">Queued</span> <span class="text-fg-2">{{ $sentAt }} to <strong class="font-medium text-fg">{{ $application->requirements_email_to }}</strong></span>
                        @if ($sentMessage)
                            <a class="ml-1 text-primary-ink hover:underline" href="{{ url("/messages/{$sentMessage->id}") }}">Delivery: {{ $sentMessage->status->value }}</a>
                        @endif
                    @elseif (! $emailTo)
                        <span class="text-warning">Can't be sent yet: add the applicant's email address below{{ $application->employee_id ? ' (or on the employee record)' : '' }}.</span>
                    @elseif ($isHired)
                        <span class="text-fg-2">Not sent yet. It goes to <strong class="font-medium text-fg">{{ $emailTo }}</strong>.</span>
                    @else
                        <span class="text-fg-2">Sent automatically to <strong class="font-medium text-fg">{{ $emailTo }}</strong> when the status becomes Hired.</span>
                    @endif
                </p>
                <p class="mt-3 text-xs font-semibold uppercase tracking-wide text-muted">Requirements still needed ({{ count($stillNeeded) }})</p>
                <p class="mt-1 whitespace-pre-line text-sm text-fg-2">{{ $requirementsList }}</p>
            </div>
            <form class="shrink-0" method="post" action="{{ route('onboarding.send-requirements', $application) }}" @if ($sentAt) data-confirm="Send the requirements email to {{ $emailTo }} again?" @endif>
                @csrf
                <button class="{{ $sentAt ? 'btn-secondary' : 'btn-primary' }}" @disabled(! $emailTo)>
                    <x-icon name="send" /> {{ $sentAt ? 'Resend requirements email' : 'Send requirements email' }}
                </button>
            </form>
        </div>
    </section>

    <x-module-form
        :fields="$fields"
        :values="$values"
        :choices="['station' => $branches]"
        :cancel-href="route('onboarding.index')"
        submit-label="Save changes"
        spoof="PUT"
        :action="route('onboarding.update', $application)"
    />
</x-layouts.app>
