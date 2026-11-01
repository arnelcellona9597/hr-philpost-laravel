<?php

namespace App\Services;

use App\Enums\Classification;
use App\Enums\EmploymentStatus;
use App\Enums\PayChangeType;
use App\Enums\RateType;
use App\Enums\Region;
use App\Exceptions\UserFacingException;
use App\Models\Employee;
use App\Models\PayChange;
use App\Models\User;
use App\Models\WageOrder;
use App\Support\AuditTrail;
use App\Support\HrDate;
use Illuminate\Support\Facades\DB;

/**
 * Each employee's pay over time, and regional wage orders applied to it. Amounts are whole centavos.
 * A rate holds from its effective date until the next one, so the history needs no end dates.
 *
 * @phpstan-type Impact array{pending: list<int>, applied: int, atOrAbove: int, monthly: int, noRate: int}
 */
class PayHistoryService
{
    /**
     * The history oldest first, what changed at each step, today's rate and the regional minimum.
     *
     * @return array{rows: list<array{change: PayChange, previous: int|null, difference: int|null, upcoming: bool}>, current: PayChange|null, minimum: WageOrder|null, belowMinimum: bool}
     */
    public function summary(Employee $employee, ?string $today = null): array
    {
        $today ??= HrDate::today();
        $rows = [];
        $last = null;
        $current = null;

        foreach (PayChange::query()->with('wageOrder')->where('employee_id', $employee->id)->orderBy('effective_on')->get() as $change) {
            $comparable = $last !== null && $last->rate_type === $change->rate_type;
            $upcoming = $change->effective_on->toDateString() > $today;

            $rows[] = [
                'change' => $change,
                'previous' => $last?->amount_centavos,
                'difference' => $comparable ? $change->amount_centavos - $last->amount_centavos : null,
                'upcoming' => $upcoming,
            ];

            $last = $change;
            $current = $upcoming ? $current : $change;
        }

        $minimum = $this->minimumFor($this->regionOf($employee), $today);

        return [
            'rows' => $rows,
            'current' => $current,
            'minimum' => $minimum,
            'belowMinimum' => $minimum !== null && $current?->rate_type === RateType::Daily && $current->amount_centavos < $minimum->daily_rate_centavos,
        ];
    }

    /**
     * The rate in force on a date.
     */
    public function rateOn(Employee $employee, ?string $date = null): ?PayChange
    {
        return PayChange::query()
            ->where('employee_id', $employee->id)
            ->where('effective_on', '<=', $date ?? HrDate::today())
            ->orderByDesc('effective_on')
            ->first();
    }

    /**
     * The highest minimum in force for a region on a date, when a region has several sectors.
     */
    public function minimumFor(?Region $region, ?string $date = null): ?WageOrder
    {
        if ($region === null) {
            return null;
        }

        return WageOrder::query()
            ->where('region', $region->value)
            ->where('effective_on', '<=', $date ?? HrDate::today())
            ->orderByDesc('effective_on')
            ->orderByDesc('daily_rate_centavos')
            ->first();
    }

    public function regionOf(Employee $employee): ?Region
    {
        return $employee->region ?? $employee->station?->region;
    }

    /**
     * @param  array{effective_on: string, change_type: string, rate_type: string, amount_centavos: int, reference?: string|null, remarks?: string|null}  $data
     *
     * @throws UserFacingException
     */
    public function record(Employee $employee, array $data, ?User $user = null): PayChange
    {
        if (! HrDate::isValid($data['effective_on'])) {
            throw new UserFacingException('Enter a valid effective date.');
        }

        if (PayChangeType::tryFrom($data['change_type']) === null || RateType::tryFrom($data['rate_type']) === null) {
            throw new UserFacingException('Choose the kind of change and whether the rate is daily or monthly.');
        }

        if ($data['amount_centavos'] <= 0) {
            throw new UserFacingException('Enter the new rate.');
        }

        if (PayChange::query()->where('employee_id', $employee->id)->where('effective_on', $data['effective_on'])->exists()) {
            throw new UserFacingException('There is already a pay record on '.HrDate::format($data['effective_on']).'. Delete it first, or choose another date.');
        }

        $change = new PayChange([...$data, 'employee_id' => $employee->id]);
        $change->created_by = $user?->id;
        $change->save();

        return $change;
    }

    /**
     * Who a wage order would change. Daily-paid active employees in the region below the new rate are
     * raised, as are COS and contractual workers with no rate yet. Monthly-paid staff are left alone.
     *
     * @return Impact
     */
    public function impact(WageOrder $order): array
    {
        $date = $order->effective_on->toDateString();
        $applied = PayChange::query()->where('wage_order_id', $order->id)->pluck('employee_id')->flip();
        $sameDay = PayChange::query()->where('effective_on', $date)->pluck('employee_id')->flip();
        $impact = ['pending' => [], 'applied' => $applied->count(), 'atOrAbove' => 0, 'monthly' => 0, 'noRate' => 0];

        $employees = DB::table('employees as e')
            ->leftJoin('branches as b', 'b.id', '=', 'e.station_id')
            ->leftJoin('pay_changes as p', function ($join) use ($date): void {
                $join->on('p.employee_id', '=', 'e.id')
                    ->whereRaw('p.effective_on = (SELECT MAX(p2.effective_on) FROM pay_changes p2 WHERE p2.employee_id = e.id AND p2.effective_on <= ?)', [$date]);
            })
            ->where('e.employment_status', EmploymentStatus::Active->value)
            ->whereRaw('COALESCE(e.region, b.region) = ?', [$order->region->value])
            ->where(fn ($query) => $query->whereNull('e.date_hired')->orWhere('e.date_hired', '<=', $date))
            ->get(['e.id', 'e.classification', 'p.rate_type', 'p.amount_centavos']);

        foreach ($employees as $employee) {
            if ($applied->has($employee->id)) {
                continue;
            }

            if ($employee->rate_type === null && $employee->classification === Classification::Regular->value) {
                $impact['noRate']++;
            } elseif ($employee->rate_type === RateType::Monthly->value) {
                $impact['monthly']++;
            } elseif ($sameDay->has($employee->id) || ($employee->amount_centavos !== null && (int) $employee->amount_centavos >= $order->daily_rate_centavos)) {
                $impact['atOrAbove']++;
            } else {
                $impact['pending'][] = (int) $employee->id;
            }
        }

        return $impact;
    }

    /**
     * Add the wage order to everyone it raises. Applying again only picks up people added since.
     *
     * @return int employees raised
     */
    public function apply(WageOrder $order, ?User $user = null): int
    {
        return DB::transaction(function () use ($order, $user): int {
            $pending = $this->impact($order)['pending'];
            $now = now();

            foreach (array_chunk($pending, 500) as $chunk) {
                PayChange::query()->insert(array_map(fn (int $employeeId): array => [
                    'employee_id' => $employeeId,
                    'effective_on' => $order->effective_on->toDateString(),
                    'change_type' => PayChangeType::WageOrder->value,
                    'rate_type' => RateType::Daily->value,
                    'amount_centavos' => $order->daily_rate_centavos,
                    'wage_order_id' => $order->id,
                    'reference' => $order->order_number,
                    'created_by' => $user?->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk));
            }

            if ($pending !== [] && ! AuditTrail::isPaused()) {
                AuditTrail::record('update', 'wage-orders', $order->label, ['applied' => ['from' => null, 'to' => count($pending).' employee(s)']], $order->id);
            }

            return count($pending);
        });
    }
}
