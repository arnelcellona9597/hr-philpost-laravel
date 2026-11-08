<?php

namespace App\Http\Controllers;

use App\Enums\Classification;
use App\Enums\EmploymentStatus;
use App\Enums\PayChangeType;
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
use App\Services\EmployeeWordExporter;
use App\Services\EmploymentHistoryService;
use App\Services\LeaveService;
use App\Services\MediaStore;
use App\Services\PayHistoryService;
use App\Support\Choices;
use App\Support\EmployeeColumns;
use App\Support\EmployeeSearch;
use App\Support\HrDate;
use App\Support\ImportModules;
use App\Support\RecordForm;
use App\Support\RecordRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

class EmployeeController extends Controller
{
    use RespondsToForms;

    private const IMAGE_MAX_BYTES = 5 * 1024 * 1024;

    /**
     * Profiles in one bulk print. Each loads its own history, so a longer list is printed in parts.
     */
    private const PRINT_MAX = 200;

    /**
     * Employees in one printed or Word table.
     */
    private const TABLE_MAX = 2000;

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Employee::class);

        $search = new EmployeeSearch($request);
        $chosen = EmployeeColumns::choose($request);

        if ($chosen['remember'] === false) {
            Cookie::queue(Cookie::forget(EmployeeColumns::COOKIE));
        } elseif ($chosen['remember'] !== null) {
            Cookie::queue(EmployeeColumns::COOKIE, implode(',', $chosen['remember']), 60 * 24 * 365);
        }

        return view('employees.index', [
            'columns' => $chosen['columns'],
            'columnChoices' => EmployeeColumns::available(),
            'search' => $search,
            'filters' => $search->activeFilterCount(),
            'employees' => $search->apply(Employee::query()->with(['station', 'employmentPeriods']))->paginate($search->limit)->withQueryString(),
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

    public function show(Employee $employee, LeaveService $leave, EmploymentHistoryService $history, PayHistoryService $pay, Request $request): View
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
            'pay' => $pay->summary($employee),
            'positionChanges' => $employee->positionChanges()->get(),
            'payChangeTypes' => PayChangeType::cases(),
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

    public function print(Employee $employee, EmploymentHistoryService $history, PayHistoryService $pay): View
    {
        Gate::authorize('view', $employee);

        $employee->load('station');

        return view('employees.print', [
            'employee' => $employee,
            'fields' => ImportModules::find('employees')['fields'],
            'employment' => $history->summary($employee),
            'pay' => $pay->summary($employee),
            'positionChanges' => $employee->positionChanges()->get(),
            'site' => SiteSetting::current(),
        ]);
    }

    /**
     * Every profile that matches the filters on the employee list, one per page, in the list's order.
     */
    public function printMany(Request $request, EmploymentHistoryService $history, PayHistoryService $pay): View|RedirectResponse
    {
        Gate::authorize('viewAny', Employee::class);

        $query = (new EmployeeSearch($request))->apply(Employee::query()->with('station'));
        $total = (clone $query)->count();

        if ($total === 0) {
            return $this->flash(redirect()->route('employees.index', $request->query()), 'No employees match these filters, so there is nothing to print.', 'error');
        }

        $profiles = $query->limit(self::PRINT_MAX)->get()->map(fn (Employee $employee): array => [
            'employee' => $employee,
            'employment' => $history->summary($employee),
            'pay' => $pay->summary($employee),
            'positionChanges' => $employee->positionChanges()->get(),
        ]);

        return view('employees.print-many', [
            'profiles' => $profiles,
            'total' => $total,
            'max' => self::PRINT_MAX,
            'fields' => ImportModules::find('employees')['fields'],
            'site' => SiteSetting::current(),
            'back' => route('employees.index', $request->except(['page'])),
        ]);
    }

    /**
     * One Word bio per matching employee, zipped.
     */
    public function biosZip(Request $request, EmployeeWordExporter $word): BinaryFileResponse|RedirectResponse
    {
        Gate::authorize('viewAny', Employee::class);

        $query = (new EmployeeSearch($request))->apply(Employee::query()->with('station'));
        $total = (clone $query)->count();

        if ($total === 0) {
            return $this->flash(redirect()->route('employees.index', $request->query()), 'No employees match these filters, so there is nothing to download.', 'error');
        }

        $path = tempnam(sys_get_temp_dir(), 'bios');
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The ZIP file could not be created.');
        }

        $names = [];

        foreach ($query->limit(self::PRINT_MAX)->get() as $employee) {
            $name = $word->filename($employee);
            $names[$name] = ($names[$name] ?? 0) + 1;
            $zip->addFromString($names[$name] > 1 ? Str::replaceLast('.docx', " ({$names[$name]}).docx", $name) : $name, $word->profile($employee));
        }

        $zip->close();

        return response()->download($path, 'employee-bios-'.HrDate::today().'.zip', ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }

    /**
     * Every matching employee in one table, with the columns chosen on the list.
     */
    public function printTable(Request $request): View|RedirectResponse
    {
        Gate::authorize('viewAny', Employee::class);

        [$employees, $total] = $this->tableRows($request);

        if ($total === 0) {
            return $this->flash(redirect()->route('employees.index', $request->query()), 'No employees match these filters, so there is nothing to print.', 'error');
        }

        $columns = EmployeeColumns::choose($request)['columns'];

        return view('employees.print-table', [
            'employees' => $employees,
            'total' => $total,
            'max' => self::TABLE_MAX,
            'columns' => $columns,
            'columnChoices' => EmployeeColumns::available(),
            'site' => SiteSetting::current(),
            'back' => route('employees.index', $request->except(['page'])),
            'wordUrl' => route('employees.table-docx', $request->except(['page'])),
        ]);
    }

    /**
     * The same table as a Word file.
     */
    public function tableDocx(Request $request, EmployeeWordExporter $word): Response|RedirectResponse
    {
        Gate::authorize('viewAny', Employee::class);

        [$employees, $total] = $this->tableRows($request);

        if ($total === 0) {
            return $this->flash(redirect()->route('employees.index', $request->query()), 'No employees match these filters, so there is nothing to download.', 'error');
        }

        return response($word->table($employees, EmployeeColumns::choose($request)['columns']), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Content-Disposition' => 'attachment; filename="employees-'.HrDate::today().'.docx"',
        ]);
    }

    /**
     * @return array{0: Collection<int, Employee>, 1: int}
     */
    private function tableRows(Request $request): array
    {
        $query = (new EmployeeSearch($request))->apply(Employee::query()->with(['station', 'employmentPeriods']));

        $total = (clone $query)->count();

        return [$query->limit(self::TABLE_MAX)->get(), $total];
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
     * @return array{fields: list<array<string, mixed>>, branches: list<array{value: int, label: string}>, units: list<array{value: int, label: string}>, positions: list<string>}
     */
    private function formData(): array
    {
        return ['fields' => ImportModules::find('employees')['fields'], 'branches' => Choices::branches(), 'units' => Choices::units(), 'positions' => Choices::positions()];
    }
}
