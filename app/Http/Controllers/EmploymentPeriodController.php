<?php

namespace App\Http\Controllers;

use App\Enums\Classification;
use App\Enums\EmploymentStatus;
use App\Exceptions\UserFacingException;
use App\Http\Controllers\Concerns\RespondsToForms;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmploymentPeriod;
use App\Services\EmploymentHistoryService;
use App\Support\HrDate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class EmploymentPeriodController extends Controller
{
    use RespondsToForms;

    /**
     * Hire a separated employee again.
     */
    public function hire(Request $request, Employee $employee, EmploymentHistoryService $history): RedirectResponse
    {
        Gate::authorize('update', $employee);

        try {
            $history->rehire($employee, $this->date($request, 'hired_on', 'the date hired') ?? throw new UserFacingException('Enter the date hired.'), $this->date($request, 'previous_separated_on', 'the last day of the previous period'), $this->details($request));
        } catch (UserFacingException $exception) {
            return $this->back($employee, $exception->getMessage(), 'error');
        }

        return $this->back($employee, "{$employee->full_name} was hired again. The history now has a new period.");
    }

    /**
     * Record that the current period ended.
     */
    public function separate(Request $request, Employee $employee, EmploymentHistoryService $history): RedirectResponse
    {
        Gate::authorize('update', $employee);

        try {
            $history->separate(
                $employee,
                $this->date($request, 'separated_on', 'the date separated') ?? throw new UserFacingException('Enter the date separated.'),
                EmploymentStatus::tryFrom((string) $request->input('separation_type')) ?? throw new UserFacingException('Choose how the employment ended: Resigned, Retired or Terminated.'),
                $this->text($request, 'remarks', 1000),
            );
        } catch (UserFacingException $exception) {
            return $this->back($employee, $exception->getMessage(), 'error');
        }

        return $this->back($employee, 'The separation was recorded.');
    }

    public function update(Request $request, Employee $employee, EmploymentPeriod $period, EmploymentHistoryService $history): RedirectResponse
    {
        Gate::authorize('update', $period);
        abort_unless($period->employee_id === $employee->id, 404);

        try {
            $history->update($period, [
                'hired_on' => $this->date($request, 'hired_on', 'the date hired'),
                'separated_on' => $this->date($request, 'separated_on', 'the date separated'),
                'separation_type' => (string) $request->input('separation_type'),
                ...$this->details($request),
            ]);
        } catch (UserFacingException $exception) {
            return $this->back($employee, $exception->getMessage(), 'error');
        }

        return $this->back($employee, 'The employment period was updated.');
    }

    public function destroy(Employee $employee, EmploymentPeriod $period, EmploymentHistoryService $history): RedirectResponse
    {
        Gate::authorize('delete', $period);
        abort_unless($period->employee_id === $employee->id, 404);

        try {
            $history->delete($period);
        } catch (UserFacingException $exception) {
            return $this->back($employee, $exception->getMessage(), 'error');
        }

        return $this->back($employee, 'The employment period was deleted.');
    }

    /**
     * @return array{position: string|null, station_id: int|null, classification: string|null, remarks: string|null}
     */
    private function details(Request $request): array
    {
        $station = $request->integer('station_id') ?: null;

        return [
            'position' => $this->text($request, 'position', 150),
            'station_id' => $station !== null && Branch::query()->whereKey($station)->exists() ? $station : null,
            'classification' => Classification::tryFrom((string) $request->input('classification'))?->value,
            'remarks' => $this->text($request, 'remarks', 1000),
        ];
    }

    /**
     * @throws UserFacingException when a date was typed but isn't real
     */
    private function date(Request $request, string $name, string $label): ?string
    {
        $value = trim((string) $request->input($name));

        if ($value === '') {
            return null;
        }

        return HrDate::isValid($value) ? $value : throw new UserFacingException("Enter a valid date for {$label}.");
    }

    private function text(Request $request, string $name, int $max): ?string
    {
        return mb_substr(trim((string) $request->input($name)), 0, $max) ?: null;
    }

    private function back(Employee $employee, string $message, string $type = 'success'): RedirectResponse
    {
        return $this->flash(redirect()->to(route('employees.show', $employee).'#employment-history'), $message, $type);
    }
}
