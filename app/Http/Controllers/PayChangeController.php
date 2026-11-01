<?php

namespace App\Http\Controllers;

use App\Exceptions\UserFacingException;
use App\Http\Controllers\Concerns\RespondsToForms;
use App\Models\Employee;
use App\Models\PayChange;
use App\Services\PayHistoryService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

class PayChangeController extends Controller
{
    use RespondsToForms;

    public function store(Request $request, Employee $employee, PayHistoryService $pay): RedirectResponse
    {
        Gate::authorize('update', $employee);

        try {
            $pay->record($employee, [
                'effective_on' => trim((string) $request->input('effective_on')),
                'change_type' => (string) $request->input('change_type'),
                'rate_type' => (string) $request->input('rate_type'),
                'amount_centavos' => Money::toCentavos($request->input('amount')),
                'premium_percent' => trim((string) $request->input('premium_percent')) ?: 0,
                'reference' => mb_substr(trim((string) $request->input('reference')), 0, 120) ?: null,
                'remarks' => mb_substr(trim((string) $request->input('remarks')), 0, 1000) ?: null,
            ], $request->user());
        } catch (UserFacingException $exception) {
            return $this->back($employee, $exception->getMessage(), 'error');
        } catch (InvalidArgumentException $exception) {
            return $this->back($employee, "The rate {$exception->getMessage()}.", 'error');
        }

        return $this->back($employee, 'The pay record was added.');
    }

    public function destroy(Employee $employee, PayChange $change): RedirectResponse
    {
        Gate::authorize('delete', $change);
        abort_unless($change->employee_id === $employee->id, 404);

        $change->delete();

        return $this->back($employee, 'The pay record was deleted.');
    }

    private function back(Employee $employee, string $message, string $type = 'success'): RedirectResponse
    {
        return $this->flash(redirect()->to(route('employees.show', $employee).'#pay-history'), $message, $type);
    }
}
