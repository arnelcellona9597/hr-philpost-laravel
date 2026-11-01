<?php

namespace App\Http\Controllers;

use App\Enums\ApplicationStatus;
use App\Enums\EmploymentStatus;
use App\Enums\OnboardingRequirement;
use App\Enums\RequirementsCompletion;
use App\Enums\RequirementStatus;
use App\Exceptions\UserFacingException;
use App\Http\Controllers\Concerns\RespondsToForms;
use App\Models\Application;
use App\Models\Branch;
use App\Models\DocumentSequence;
use App\Models\Employee;
use App\Services\ApplicationService;
use App\Support\Choices;
use App\Support\HrDate;
use App\Support\ImportModules;
use App\Support\RecordForm;
use App\Support\RecordRules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ApplicationController extends Controller
{
    use RespondsToForms;

    private const SORTS = [
        'applicationId' => 'application_id',
        'applicantName' => 'applicant_name',
        'positionApplied' => 'position_applied',
        'dateApplied' => 'date_applied',
        'startOjt' => 'start_ojt',
        'applicationStatus' => 'application_status',
        'requirementsStatus' => 'requirements_status',
        'dateSubmitted' => 'date_submitted',
    ];

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Application::class);

        $text = Str::limit(trim((string) $request->query('q')), 100, '');
        $statuses = array_values(array_intersect(Arr::wrap($request->query('status')), array_column(ApplicationStatus::cases(), 'value')));
        $requirements = RequirementsCompletion::tryFrom((string) $request->query('requirements'));
        $station = $request->integer('station') ?: null;
        $sortRaw = (string) $request->query('sort', '-dateApplied');
        $sort = isset(self::SORTS[ltrim($sortRaw, '-')]) ? $sortRaw : '-dateApplied';
        $today = HrDate::today();
        $year = substr($today, 0, 4);

        $applications = Application::query()
            ->with('station')
            ->when($text !== '', function (Builder $query) use ($text): void {
                $like = '%'.addcslashes($text, '%_\\').'%';

                $query->where(fn (Builder $any) => $any
                    ->where('applicant_name', 'like', $like)
                    ->orWhere('application_id', 'like', $like)
                    ->orWhere('position_applied', 'like', $like)
                    ->orWhere('replacement', 'like', $like));
            })
            ->when($statuses !== [], fn (Builder $query) => $query->whereIn('application_status', $statuses))
            ->when($requirements, fn (Builder $query) => $query->where('requirements_status', $requirements))
            ->when($station, fn (Builder $query) => $query->where('station_id', $station))
            ->orderBy(self::SORTS[ltrim($sort, '-')], str_starts_with($sort, '-') ? 'desc' : 'asc')
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString();

        $separated = Employee::query()
            ->whereIn('employment_status', [EmploymentStatus::Resigned, EmploymentStatus::Retired, EmploymentStatus::Terminated])
            ->where('last_day_of_service', '>=', "{$year}-01-01")
            ->toBase()
            ->selectRaw('employment_status, COUNT(*) AS total')
            ->groupBy('employment_status')
            ->pluck('total', 'employment_status');

        $newHires = Employee::query()->where('date_hired', '>=', HrDate::addDays($today, -90));

        return view('onboarding.index', [
            'applications' => $applications,
            'text' => $text,
            'statuses' => $statuses,
            'requirements' => $requirements,
            'station' => $station,
            'year' => $year,
            'branches' => Branch::query()->orderBy('name')->get(['id', 'name']),
            'statusCounts' => Application::query()->toBase()->selectRaw('application_status, COUNT(*) AS total')->groupBy('application_status')->pluck('total', 'application_status'),
            'newHireCount' => (clone $newHires)->count(),
            'newHires' => $newHires->orderByDesc('date_hired')->limit(5)->get(['id', 'full_name', 'date_hired']),
            'separated' => $separated,
            'activeCount' => Employee::query()->where('employment_status', EmploymentStatus::Active)->count(),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', Application::class);

        return view('onboarding.form', [
            'application' => null,
            'fields' => $this->fieldsForNew(),
            'values' => ['application_status' => ApplicationStatus::Pending->value] + array_fill_keys(array_column(OnboardingRequirement::cases(), 'value'), RequirementStatus::Pending->value),
            'branches' => Choices::branches(),
        ]);
    }

    public function store(Request $request, ApplicationService $applications): RedirectResponse
    {
        Gate::authorize('create', Application::class);

        ['data' => $data, 'errors' => $errors] = $this->parse($request, $this->fieldsForNew());

        if ($errors !== []) {
            return $this->invalid($errors);
        }

        // Numbered on save, so viewing the form never uses a number up.
        $data['application_id'] ??= DocumentSequence::nextApplicationId((int) substr(HrDate::today(), 0, 4));

        $application = $applications->save(new Application, $data, $request->user());

        return $this->flash(redirect()->route('onboarding.index'), "Application {$application->application_id} for {$application->applicant_name} was added.");
    }

    public function show(Application $application, ApplicationService $applications): View
    {
        Gate::authorize('view', $application);

        $application->load('employee');

        return view('onboarding.show', [
            'application' => $application,
            'fields' => $this->fields(),
            'values' => RecordForm::values($application, $this->fields()),
            'branches' => Choices::branches(),
            'emailTo' => $applications->emailAddress($application),
            'sentMessage' => $applications->lastMessage($application),
            'stillNeeded' => $application->missingRequirements(),
            'requirementsList' => $applications->requirementsList($application),
        ]);
    }

    public function update(Request $request, Application $application, ApplicationService $applications): RedirectResponse
    {
        Gate::authorize('update', $application);

        ['data' => $data, 'errors' => $errors] = $this->parse($request, $this->fields(), $application);

        if ($errors !== []) {
            return $this->invalid($errors);
        }

        $applications->save($application, $data, $request->user());

        return $this->flash(redirect()->route('onboarding.show', $application), "Application {$application->application_id} was updated.");
    }

    /**
     * Inline update of one requirement from the tracker table.
     */
    public function updateRequirement(Request $request, Application $application): JsonResponse
    {
        Gate::authorize('update', $application);

        $data = $request->validate([
            'key' => ['required', Rule::enum(OnboardingRequirement::class)],
            'value' => ['required', Rule::enum(RequirementStatus::class)],
        ]);

        $application->update([$data['key'] => $data['value']]);

        return response()->json([
            'requirementsStatus' => $application->requirements_status->value,
            'requirementsMissing' => $application->requirements_missing,
        ]);
    }

    public function sendRequirements(Request $request, Application $application, ApplicationService $applications): RedirectResponse
    {
        Gate::authorize('update', $application);

        $redirect = redirect()->route('onboarding.show', $application);

        try {
            $to = $applications->sendRequirements($application, $request->user(), manual: true);
        } catch (UserFacingException $exception) {
            return $this->flash($redirect, $exception->getMessage(), 'error');
        }

        return $this->flash($redirect, "Requirements email queued for {$to}.");
    }

    public function destroy(Application $application): RedirectResponse
    {
        Gate::authorize('delete', $application);

        $application->delete();

        return $this->flash(redirect()->route('onboarding.index'), "Application {$application->application_id} was deleted.");
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @return array{data: array<string, mixed>, errors: array<string, string>}
     */
    private function parse(Request $request, array $fields, ?Application $application = null): array
    {
        $parsed = RecordForm::parse($request, $fields, 'applications', ['application_id' => 'That Application ID is already used.'], $application);

        return [...$parsed, 'errors' => [...$parsed['errors'], ...RecordRules::errors('applications', $parsed['data'])]];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fields(): array
    {
        return ImportModules::find('applications')['fields'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fieldsForNew(): array
    {
        return array_map(
            fn (array $field): array => $field['name'] === 'application_id' ? ['required' => false, 'help' => 'Leave blank to use the next number.'] + $field : $field,
            $this->fields(),
        );
    }
}
