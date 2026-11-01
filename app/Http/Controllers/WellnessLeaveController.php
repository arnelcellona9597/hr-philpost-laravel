<?php

namespace App\Http\Controllers;

use App\Enums\EmploymentStatus;
use App\Enums\LeaveStatus;
use App\Exceptions\UserFacingException;
use App\Http\Controllers\Concerns\RespondsToForms;
use App\Models\Employee;
use App\Models\LeaveSetting;
use App\Models\WellnessLeave;
use App\Services\LeaveService;
use App\Support\Choices;
use App\Support\HrDate;
use App\Support\ImportModules;
use App\Support\RecordForm;
use App\Support\RecordRules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

class WellnessLeaveController extends Controller
{
    use RespondsToForms;

    public function index(Request $request, LeaveService $leave): View
    {
        Gate::authorize('viewAny', WellnessLeave::class);

        $thisYear = (int) substr(HrDate::today(), 0, 4);
        $year = min(2100, max(2000, $request->integer('year') ?: $thisYear));
        $text = Str::limit(trim((string) $request->query('q')), 100, '');
        $status = LeaveStatus::tryFrom((string) $request->query('status'));
        $employeeId = $request->integer('employee') ?: null;

        $leaves = WellnessLeave::query()
            ->where('inclusive_date_from', '<=', "{$year}-12-31")
            ->where('inclusive_date_to', '>=', "{$year}-01-01")
            ->when($text !== '', function (Builder $query) use ($text): void {
                $like = '%'.addcslashes($text, '%_\\').'%';

                $query->where(fn (Builder $any) => $any
                    ->where('last_name', 'like', $like)
                    ->orWhere('first_name', 'like', $like)
                    ->orWhere('middle_name', 'like', $like)
                    ->orWhere('remarks', 'like', $like));
            })
            ->when($status, fn (Builder $query) => $query->where('status', $status))
            ->when($employeeId, fn (Builder $query) => $query->where('employee_id', $employeeId))
            ->orderByDesc('inclusive_date_from')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('leave.index', [
            'leaves' => $leaves,
            'remaining' => $leave->remainingAfter($leaves->getCollection()),
            'stats' => $leave->stats($year),
            'allowance' => LeaveSetting::current()->allowanceForYear($year),
            'activeCount' => Employee::query()->where('employment_status', EmploymentStatus::Active)->count(),
            'employee' => $employeeId ? Employee::query()->find($employeeId) : null,
            'year' => $year,
            'thisYear' => $thisYear,
            'text' => $text,
            'status' => $status,
            'employeeId' => $employeeId,
        ]);
    }

    public function create(Request $request): View
    {
        Gate::authorize('create', WellnessLeave::class);

        return view('leave.create', [
            'fields' => $this->fields(),
            'values' => ['employee' => (string) $request->query('employee'), 'date_filing' => HrDate::today(), 'status' => LeaveStatus::Approved->value],
            'employees' => Choices::employees(includeInactive: false),
        ]);
    }

    public function store(Request $request, LeaveService $leave): RedirectResponse
    {
        Gate::authorize('create', WellnessLeave::class);

        ['data' => $data, 'errors' => $errors] = $this->parse($request);

        if ($errors !== []) {
            return $this->invalid($errors);
        }

        try {
            $filing = $leave->save(new WellnessLeave, $data, $request->user());
        } catch (UserFacingException $exception) {
            return $this->refused($exception->getMessage());
        }

        return $this->flash(redirect()->route('leave.index'), "Wellness leave for {$filing->first_name} {$filing->last_name} was saved ({$filing->days} day".($filing->days === 1 ? '' : 's').').');
    }

    public function edit(WellnessLeave $leave): View
    {
        Gate::authorize('update', $leave);

        return view('leave.edit', [
            'leave' => $leave,
            'fields' => $this->fields(),
            'values' => RecordForm::values($leave, $this->fields()),
            'employees' => Choices::employees(),
        ]);
    }

    public function update(Request $request, WellnessLeave $leave, LeaveService $service): RedirectResponse
    {
        Gate::authorize('update', $leave);

        ['data' => $data, 'errors' => $errors] = $this->parse($request);

        if ($errors !== []) {
            return $this->invalid($errors);
        }

        try {
            $service->save($leave, $data, $request->user());
        } catch (UserFacingException $exception) {
            return $this->refused($exception->getMessage());
        }

        return $this->flash(redirect()->route('leave.index'), 'The leave filing was updated.');
    }

    public function destroy(WellnessLeave $leave): RedirectResponse
    {
        Gate::authorize('delete', $leave);

        $leave->delete();

        return $this->flash(redirect()->route('leave.index'), 'The leave filing was deleted.');
    }

    public function preview(Request $request, LeaveService $leave): JsonResponse
    {
        Gate::authorize('viewAny', WellnessLeave::class);

        $employee = $request->integer('employee');

        if ($employee <= 0) {
            return response()->json(['ok' => false, 'error' => 'Select an employee.']);
        }

        $result = $leave->check(
            $employee,
            (string) $request->query('from'),
            (string) $request->query('to'),
            LeaveStatus::tryFrom((string) $request->query('status')),
            $request->integer('exclude') ?: null,
        );

        return response()->json(['ok' => $result['ok'], 'error' => $result['error'] ?? null, 'days' => $result['days'], 'years' => $result['years']]);
    }

    /**
     * @return array{data: array<string, mixed>, errors: array<string, string>}
     */
    private function parse(Request $request): array
    {
        $parsed = RecordForm::parse($request, $this->fields(), 'wellness_leaves');

        return [...$parsed, 'errors' => [...$parsed['errors'], ...RecordRules::errors('wellness-leaves', $parsed['data'])]];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fields(): array
    {
        return array_map(
            fn (array $field): array => $field['name'] === 'employee' ? ['label' => 'Employee'] + $field : $field,
            ImportModules::find('wellness-leaves')['fields'],
        );
    }
}
