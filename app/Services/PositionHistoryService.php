<?php

namespace App\Services;

use App\Enums\Classification;
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
     * @param  array{effective_on: string, change_type: string, to_position: string, to_classification?: string|null, remarks?: string|null, amount_centavos?: int, rate_type?: string, premium_percent?: float|int|string|null}  $data
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

        $classification = Classification::tryFrom((string) ($data['to_classification'] ?? ''));

        if (PositionChange::query()->where('employee_id', $employee->id)->where('effective_on', $data['effective_on'])->exists()) {
            throw new UserFacingException('There is already a promotion or demotion on '.HrDate::format($data['effective_on']).'. Delete it first, or choose another date.');
        }

        return DB::transaction(function () use ($employee, $data, $type, $position, $classification, $user): PositionChange {
            $before = PositionChange::query()->where('employee_id', $employee->id)->where('effective_on', '<', $data['effective_on'])->orderByDesc('effective_on')->first();

            $change = new PositionChange([
                'employee_id' => $employee->id,
                'effective_on' => $data['effective_on'],
                'change_type' => $type,
                'from_position' => $before?->to_position ?? $employee->position,
                'to_position' => $position,
                'from_classification' => $classification === null ? null : ($before?->to_classification ?? $employee->classification),
                'to_classification' => $classification,
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
                    'premium_percent' => $data['premium_percent'] ?? $this->pay->rateOn($employee, $data['effective_on'])?->premium_percent ?? 0,
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
            $previousClassification = $change->from_classification;

            $change->delete();

            if ($wasCurrent) {
                $this->syncEmployee($employee, $previous, $previousClassification);
            }
        });
    }

    /**
     * Keep the employee's Position and Classification on the latest change that has taken effect.
     */
    private function syncEmployee(Employee $employee, ?string $fallback = null, ?Classification $fallbackClassification = null): void
    {
        $latest = $this->latest($employee);
        $position = $latest?->to_position ?? $fallback;
        $classification = PositionChange::query()
            ->where('employee_id', $employee->id)
            ->where('effective_on', '<=', HrDate::today())
            ->whereNotNull('to_classification')
            ->orderByDesc('effective_on')
            ->first()?->to_classification ?? $fallbackClassification;
        $changes = [];

        if ($position !== null && $position !== '' && $position !== $employee->position) {
            $changes['position'] = $position;
        }

        if ($classification !== null && $classification !== $employee->classification) {
            $changes['classification'] = $classification;
        }

        if ($changes !== []) {
            $employee->update($changes);
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
