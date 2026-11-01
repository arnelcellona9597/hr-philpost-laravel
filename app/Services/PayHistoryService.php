<?php

namespace App\Services;

use App\Enums\Classification;
use App\Enums\PayChangeType;
use App\Enums\RateType;
use App\Enums\Region;
use App\Exceptions\UserFacingException;
use App\Models\ContractSetting;
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
 * @phpstan-type Impact array{pending: list<array{employee_id: int, effective_on: string, premium: float|null}>, applied: int, atOrAbove: int, monthly: int, noRate: int}
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
     * @param  array{effective_on: string, change_type: string, rate_type: string, amount_centavos: int, premium_percent?: float|int|string|null, reference?: string|null, remarks?: string|null}  $data
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

        $premium = $data['premium_percent'] ?? 0;

        if (! is_numeric($premium) || (float) $premium < 0 || (float) $premium > 100) {
            throw new UserFacingException('The premium must be a percentage from 0 to 100.');
        }

        if (PayChange::query()->where('employee_id', $employee->id)->where('effective_on', $data['effective_on'])->exists()) {
            throw new UserFacingException('There is already a compensation record on '.HrDate::format($data['effective_on']).'. Delete it first, or choose another date.');
        }

        $change = new PayChange([...$data, 'employee_id' => $employee->id]);
        $change->created_by = $user?->id;
        $change->save();

        return $change;
    }

    /**
     * Who a wage order would change: everyone in the region employed while it applied, so old rates can
     * be filled in for people who have since left. It runs until the region's next rate, and someone
     * hired in between gets it from their own hire date. Lower daily rates are raised, and COS and
     * contractual workers with no rate yet are given one. Monthly-paid staff are left alone.
     *
     * @return Impact
     */
    public function impact(WageOrder $order): array
    {
        $date = $order->effective_on->toDateString();
        $next = WageOrder::query()->where('region', $order->region->value)->where('sector', $order->sector)->where('effective_on', '>', $date)->min('effective_on');
        $applied = PayChange::query()->where('wage_order_id', $order->id)->pluck('employee_id')->flip();
        $impact = ['pending' => [], 'applied' => $applied->count(), 'atOrAbove' => 0, 'monthly' => 0, 'noRate' => 0];

        $periods = DB::table('employment_periods as p')
            ->join('employees as e', 'e.id', '=', 'p.employee_id')
            ->leftJoin('branches as b', 'b.id', '=', 'e.station_id')
            ->whereRaw('COALESCE(e.region, b.region) = ?', [$order->region->value])
            ->where(fn ($query) => $query->whereNull('p.separated_on')->orWhere('p.separated_on', '>=', $date))
            ->when($next, fn ($query) => $query->where('p.hired_on', '<', $next))
            ->orderBy('p.hired_on')
            ->get(['e.id', 'e.classification', 'p.hired_on'])
            ->unique('id');

        $rates = PayChange::query()->whereIn('employee_id', $periods->pluck('id'))->orderBy('effective_on')->get(['employee_id', 'effective_on', 'rate_type', 'amount_centavos', 'premium_percent'])->groupBy('employee_id');

        foreach ($periods as $period) {
            if ($applied->has($period->id)) {
                continue;
            }

            $start = max($date, substr($period->hired_on, 0, 10));
            $rate = ($rates[$period->id] ?? collect())->last(fn (PayChange $change): bool => $change->effective_on->toDateString() <= $start);

            if ($rate === null && $period->classification === Classification::Regular->value) {
                $impact['noRate']++;
            } elseif ($rate?->rate_type === RateType::Monthly) {
                $impact['monthly']++;
            } elseif ($rate !== null && ($rate->effective_on->toDateString() === $start || $rate->amount_centavos >= $order->daily_rate_centavos)) {
                $impact['atOrAbove']++;
            } else {
                $impact['pending'][] = ['employee_id' => (int) $period->id, 'effective_on' => $start, 'premium' => $rate === null ? null : (float) $rate->premium_percent];
            }
        }

        return $impact;
    }

    /**
     * Add the wage order to everyone it raises. Each person keeps their premium unless one is given.
     * Applying again only picks up people added since.
     *
     * @return int employees raised
     */
    public function apply(WageOrder $order, ?User $user = null, ?float $premium = null): int
    {
        return DB::transaction(function () use ($order, $user, $premium): int {
            $pending = $this->impact($order)['pending'];
            $default = (float) ContractSetting::current()->premium_percent;
            $date = $order->effective_on->toDateString();
            $now = now();

            foreach (array_chunk($pending, 500) as $chunk) {
                PayChange::query()->insert(array_map(fn (array $person): array => [
                    'employee_id' => $person['employee_id'],
                    'effective_on' => $person['effective_on'],
                    'change_type' => ($person['effective_on'] === $date ? PayChangeType::WageOrder : PayChangeType::Starting)->value,
                    'rate_type' => RateType::Daily->value,
                    'amount_centavos' => $order->daily_rate_centavos,
                    'premium_percent' => $premium ?? $person['premium'] ?? $default,
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
