<?php

namespace App\Http\Controllers;

use App\Exceptions\UserFacingException;
use App\Http\Controllers\Concerns\RespondsToForms;
use App\Models\Employee;
use App\Models\PositionChange;
use App\Services\PositionHistoryService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

class PositionChangeController extends Controller
{
    use RespondsToForms;

    public function store(Request $request, Employee $employee, PositionHistoryService $positions): RedirectResponse
    {
        Gate::authorize('update', $employee);

        try {
            $change = $positions->record($employee, [
                'effective_on' => trim((string) $request->input('effective_on')),
                'change_type' => (string) $request->input('change_type'),
                'to_position' => mb_substr(trim((string) $request->input('to_position')), 0, 150),
                'to_classification' => (string) $request->input('to_classification'),
                'remarks' => mb_substr(trim((string) $request->input('remarks')), 0, 1000) ?: null,
                'amount_centavos' => Money::toCentavos($request->input('amount')),
                'rate_type' => (string) $request->input('rate_type'),
            ], $request->user());
        } catch (UserFacingException $exception) {
            return $this->back($employee, $exception->getMessage(), 'error');
        } catch (InvalidArgumentException $exception) {
            return $this->back($employee, "The new rate {$exception->getMessage()}.", 'error');
        }

        return $this->back($employee, "{$change->change_type->value} to {$change->to_position} was recorded.");
    }

    public function destroy(Employee $employee, PositionChange $change, PositionHistoryService $positions): RedirectResponse
    {
        Gate::authorize('delete', $change);
        abort_unless($change->employee_id === $employee->id, 404);

        $positions->delete($change);

        return $this->back($employee, 'The record was deleted. Any compensation record made with it stays in the compensation history.');
    }

    private function back(Employee $employee, string $message, string $type = 'success'): RedirectResponse
    {
        return $this->flash(redirect()->to(route('employees.show', $employee).'#position-history'), $message, $type);
    }
}
