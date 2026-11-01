<?php

namespace App\Http\Controllers;

use App\Enums\Classification;
use App\Enums\EmploymentStatus;
use App\Exceptions\UserFacingException;
use App\Http\Controllers\Concerns\RespondsToForms;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\LeaveSetting;
use App\Models\Media;
use App\Models\RequirementDeadlineSetting;
use App\Models\SiteSetting;
use App\Services\ApplicationService;
use App\Services\DuplicateFinder;
use App\Services\EmploymentHistoryService;
use App\Services\LeaveService;
use App\Services\MediaStore;
use App\Support\Choices;
use App\Support\EmployeeSearch;
use App\Support\HrDate;
use App\Support\ImportModules;
use App\Support\RecordForm;
use App\Support\RecordRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

class EmployeeController extends Controller
{
    use RespondsToForms;

    private const IMAGE_MAX_BYTES = 5 * 1024 * 1024;

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Employee::class);

        $search = new EmployeeSearch($request);

        return view('employees.index', [
            'search' => $search,
            'filters' => $search->activeFilterCount(),
            'employees' => $search->apply(Employee::query()->with('station'))->paginate($search->limit)->withQueryString(),
            'branches' => Branch::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(Request $request): View
    {
        Gate::authorize('create', Employee::class);

        $application = Application::query()->find($request->integer('fromApplication'));
        $values = ['employment_status' => EmploymentStatus::Active->value];

        // "Convert to employee" pre-fills what the application already knows.
        if ($application !== null) {
            $parts = preg_split('/\s+/', trim($application->applicant_name));
            $last = count($parts) > 1 ? array_pop($parts) : '';

            $values += array_filter([
                'last_name' => $last,
                'first_name' => implode(' ', $parts),
                'position' => $application->position_applied,
                'station' => (string) $application->station_id,
                'date_hired' => ($application->fds_date ?? $application->start_ojt)?->toDateString(),
                'email' => $application->email,
            ]);
        }

        return view('employees.form', ['employee' => null, 'application' => $application, 'values' => $values, ...$this->formData()]);
    }

    public function store(Request $request, MediaStore $media, ApplicationService $applications): RedirectResponse
    {
        Gate::authorize('create', Employee::class);

        $application = Application::query()->find($request->integer('fromApplication'));

        if ($application?->employee_id !== null) {
            return $this->flash(redirect()->route('onboarding.show', $application), 'This applicant is already linked to an employee.', 'error');
        }

        ['data' => $data, 'errors' => $errors] = $this->parse($request);

        if ($errors !== []) {
            return $this->invalid($errors);
        }

        try {
            $photo = $this->storePhoto($request, $media, $data);
        } catch (UserFacingException $exception) {
            return $this->invalid(['photo' => $exception->getMessage()]);
        }

        $data['profile_picture_id'] = $photo?->id;

        $employee = $application !== null
            ? $applications->convert($application, $data, $request->user())
            : Employee::query()->create($data);

        $similar = count(app(DuplicateFinder::class)->similarTo($employee));

        return $this->flash(redirect()->route('employees.show', $employee), "Employee {$employee->full_name} was added.".($similar > 0 ? ' Someone with the same name and birth date, email or ID number already exists; check Import / Export → duplicates.' : ''));
    }

    public function show(Employee $employee, LeaveService $leave, EmploymentHistoryService $history, Request $request): View
    {
        Gate::authorize('view', $employee);

        $employee->load(['station', 'profilePicture']);

        $year = (int) substr(HrDate::today(), 0, 4);
        $allowance = LeaveSetting::current()->allowanceForYear($year);
        $requirements = [
            'itr' => $employee->itrSubmissions()->orderByDesc('year')->limit(20)->get(),
            'sworn' => $employee->swornDeclarations()->orderByDesc('year')->limit(20)->get(),
            'pds' => $employee->pdsSubmissions()->orderByDesc('year')->limit(20)->get(),
            'ipcr' => $employee->ipcrRatings()->orderByDesc('year')->limit(20)->get(),
        ];

        return view('employees.show', [
            'employee' => $employee,
            'fields' => ImportModules::find('employees')['fields'],
            'year' => $year,
            'allowance' => $allowance,
            'remaining' => $allowance - $leave->usedInYear($employee, $year),
            'employment' => $history->summary($employee),
            'branches' => Choices::branches(),
            'classifications' => array_column(Classification::cases(), 'value'),
            'leaves' => $employee->wellnessLeaves()->orderByDesc('inclusive_date_from')->limit(8)->get(),
            'certificates' => $employee->certificates()->latest()->limit(10)->get(),
            'contracts' => $employee->contracts()->latest('term_start')->limit(10)->get(),
            'requirements' => $requirements,
            'requirementYears' => collect($requirements)->flatten()->pluck('year')->unique()->sortDesc()->values(),
            'deadlineSettings' => RequirementDeadlineSetting::current()->toArray(),
            'history' => AuditLog::query()->visibleTo($request->user())->where('collection_slug', 'employees')->where('doc_id', (string) $employee->id)->latest('id')->limit(30)->get(),
        ]);
    }

    public function print(Employee $employee, EmploymentHistoryService $history): View
    {
        Gate::authorize('view', $employee);

        $employee->load('station');

        return view('employees.print', [
            'employee' => $employee,
            'fields' => ImportModules::find('employees')['fields'],
            'employment' => $history->summary($employee),
            'site' => SiteSetting::current(),
        ]);
    }

    public function edit(Employee $employee): View
    {
        Gate::authorize('update', $employee);

        return view('employees.form', [
            'employee' => $employee,
            'application' => null,
            'values' => RecordForm::values($employee, ImportModules::find('employees')['fields']),
            ...$this->formData(),
        ]);
    }

    public function update(Request $request, Employee $employee, MediaStore $media): RedirectResponse
    {
        Gate::authorize('update', $employee);

        ['data' => $data, 'errors' => $errors] = $this->parse($request, $employee);

        if ($errors !== []) {
            return $this->invalid($errors);
        }

        try {
            $photo = $this->storePhoto($request, $media, $data);
        } catch (UserFacingException $exception) {
            return $this->invalid(['photo' => $exception->getMessage()]);
        }

        $previous = $employee->profilePicture()->first();
        $remove = $request->boolean('removePhoto');

        if ($photo !== null || $remove) {
            $data['profile_picture_id'] = $photo?->id;
        }

        $employee->update($data);

        if ($previous !== null && ($photo !== null || $remove)) {
            $media->delete($previous);
        }

        return $this->flash(redirect()->route('employees.show', $employee), "Changes to {$employee->full_name} were saved.");
    }

    public function destroy(Employee $employee, MediaStore $media): RedirectResponse
    {
        Gate::authorize('delete', $employee);

        $blocker = $employee->deleteBlocker();

        if ($blocker !== null) {
            return $this->flash(redirect()->route('employees.show', $employee), $blocker, 'error');
        }

        $photo = $employee->profilePicture()->first();

        DB::transaction(fn () => $employee->delete());

        if ($photo !== null) {
            $media->delete($photo);
        }

        return $this->flash(redirect()->route('employees.index'), "Employee {$employee->full_name} was deleted.");
    }

    /**
     * @return array{data: array<string, mixed>, errors: array<string, string>}
     */
    private function parse(Request $request, ?Employee $employee = null): array
    {
        $parsed = RecordForm::parse($request, ImportModules::find('employees')['fields'], 'employees', [
            'employee_id' => 'That Employee ID is already used.',
        ], $employee);

        return [...$parsed, 'errors' => [...$parsed['errors'], ...RecordRules::errors('employees', $parsed['data']), ...app(EmploymentHistoryService::class)->employeeProblems($employee, $parsed['data'])]];
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws UserFacingException when the picture isn't acceptable
     */
    private function storePhoto(Request $request, MediaStore $media, array $data): ?Media
    {
        $file = $request->file('photo');

        if ($file === null) {
            return null;
        }

        return $media->store($file, Str::limit('Photo of '.($data['first_name'] ?? '').' '.($data['last_name'] ?? ''), 200, ''), MediaStore::IMAGE_TYPES, self::IMAGE_MAX_BYTES);
    }

    /**
     * @return array{fields: list<array<string, mixed>>, branches: list<array{value: int, label: string}>, positions: list<string>}
     */
    private function formData(): array
    {
        return ['fields' => ImportModules::find('employees')['fields'], 'branches' => Choices::branches(), 'positions' => Choices::positions()];
    }
}
