<?php

namespace App\Services;

use App\Enums\PayChangeType;
use App\Enums\PositionChangeType;
use App\Exceptions\UserFacingException;
use App\Models\Employee;
use App\Models\PositionChange;
use App\Models\User;
use App\Support\HrDate;
use Illuminate\Support\Facades\DB;

/**
 * Promotions and demotions. The employee's Position follows the latest change that has taken effect,
 * and a new rate can be recorded with it in the same step.
 */
class PositionHistoryService
{
    public function __construct(private readonly PayHistoryService $pay) {}

    /**
     * @param  array{effective_on: string, change_type: string, to_position: string, remarks?: string|null, amount_centavos?: int, rate_type?: string}  $data
     *
     * @throws UserFacingException
     */
    public function record(Employee $employee, array $data, ?User $user = null): PositionChange
    {
        $type = PositionChangeType::tryFrom($data['change_type']);
        $position = trim($data['to_position']);

        if (! HrDate::isValid($data['effective_on'])) {
            throw new UserFacingException('Enter a valid effective date.');
        }

        if ($type === null) {
            throw new UserFacingException('Choose Promotion or Demotion.');
        }

        if ($position === '') {
            throw new UserFacingException('Enter the new position.');
        }

        if (PositionChange::query()->where('employee_id', $employee->id)->where('effective_on', $data['effective_on'])->exists()) {
            throw new UserFacingException('There is already a promotion or demotion on '.HrDate::format($data['effective_on']).'. Delete it first, or choose another date.');
        }

        return DB::transaction(function () use ($employee, $data, $type, $position, $user): PositionChange {
            $before = PositionChange::query()->where('employee_id', $employee->id)->where('effective_on', '<', $data['effective_on'])->orderByDesc('effective_on')->first();

            $change = new PositionChange([
                'employee_id' => $employee->id,
                'effective_on' => $data['effective_on'],
                'change_type' => $type,
                'from_position' => $before?->to_position ?? $employee->position,
                'to_position' => $position,
                'remarks' => $data['remarks'] ?? null,
            ]);
            $change->created_by = $user?->id;
            $change->save();

            if (($data['amount_centavos'] ?? 0) > 0) {
                $this->pay->record($employee, [
                    'effective_on' => $data['effective_on'],
                    'change_type' => $type === PositionChangeType::Promotion ? PayChangeType::Promotion->value : PayChangeType::Demotion->value,
                    'rate_type' => $data['rate_type'] ?? '',
                    'amount_centavos' => $data['amount_centavos'],
                    'remarks' => "{$type->value} to {$position}",
                ], $user);
            }

            $this->syncEmployee($employee);

            return $change;
        });
    }

    public function delete(PositionChange $change): void
    {
        DB::transaction(function () use ($change): void {
            $employee = $change->employee;
            $wasCurrent = $this->latest($employee)?->is($change);
            $previous = $change->from_position;

            $change->delete();

            if ($wasCurrent) {
                $this->syncEmployee($employee, $previous);
            }
        });
    }

    /**
     * Keep the employee's Position on the latest change that has taken effect.
     */
    private function syncEmployee(Employee $employee, ?string $fallback = null): void
    {
        $position = $this->latest($employee)?->to_position ?? $fallback;

        if ($position !== null && $position !== '' && $position !== $employee->position) {
            $employee->update(['position' => $position]);
        }
    }

    private function latest(Employee $employee): ?PositionChange
    {
        return PositionChange::query()
            ->where('employee_id', $employee->id)
            ->where('effective_on', '<=', HrDate::today())
            ->orderByDesc('effective_on')
            ->first();
    }
}
