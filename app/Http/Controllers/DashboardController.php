<?php

namespace App\Http\Controllers;

use App\Enums\Classification;
use App\Enums\EmploymentStatus;
use App\Enums\LeaveStatus;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\ComplianceRecord;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\WellnessLeave;
use App\Services\DashboardStats;
use App\Services\RequirementService;
use App\Support\ActivityFeed;
use App\Support\ImportModules;
use App\Support\ReportingPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    private const REQUIREMENT_NAMES = ['itr' => 'ITR', 'sworn-declaration' => 'Sworn Declaration', 'pds' => 'PDS', 'ipcr' => 'IPCR'];

    public function index(Request $request, DashboardStats $stats, RequirementService $requirements): View
    {
        $user = $request->user();
        $branchId = $request->integer('branch') ?: null;
        $classification = Classification::tryFrom((string) $request->query('classification'))?->value;
        $period = ReportingPeriod::resolve((string) $request->query('period'), null, (string) $request->query('from'), (string) $request->query('to'));
        // Annual requirements and the monthly sparklines follow the range's last day.
        $year = (int) substr($period['to'], 0, 4);
        $sparkMonths = ReportingPeriod::buckets(ReportingPeriod::addMonths(substr($period['to'], 0, 7).'-01', -11), $period['to'], 'month');
        $filter = ['branch' => $branchId, 'classification' => $classification];

        $compliance = array_map(function (string $route) use ($requirements, $year, $filter): array {
            $model = ComplianceRecord::modelFor($route);
            $figures = $requirements->compliance($model, $year, $filter);

            return [
                'route' => $route,
                'title' => ImportModules::find(ImportModules::COMPLIANCE_ROUTES[$route])['title'],
                'name' => self::REQUIREMENT_NAMES[$route],
                'stats' => $figures,
                'late' => $requirements->timeliness($model, $year, $filter)['counts']['late'],
                'percent' => $figures['active'] ? (int) round($figures['complied'] / $figures['active'] * 100) : 0,
            ];
        }, array_keys(ImportModules::COMPLIANCE_ROUTES));

        $filtered = fn (Builder $query): Builder => $query
            ->when($branchId, fn (Builder $scoped) => $scoped->where('station_id', $branchId))
            ->when($classification, fn (Builder $scoped) => $scoped->where('classification', $classification));

        $latestPayroll = PayrollPeriod::query()
            ->withCount('payslips')
            ->withSum('payslips as net_total', 'net_pay_centavos')
            ->orderByDesc('period_end')
            ->orderByDesc('id')
            ->first();

        return view('dashboard', [
            'firstName' => strtok(trim((string) $user->name), ' ') ?: 'there',
            'period' => $period,
            'isCustom' => $period['key'] === ReportingPeriod::CUSTOM,
            'periods' => ReportingPeriod::OPTIONS,
            'branchId' => $branchId,
            'classification' => $classification,
            'year' => $year,
            'sparkMonths' => $sparkMonths,
            'branches' => Branch::query()->orderBy('name')->get(['id', 'name']),
            'classifications' => array_column(Classification::cases(), 'value'),
            'statuses' => array_column(EmploymentStatus::cases(), 'value'),
            'headcount' => $stats->employees($branchId, $classification, $period['to']),
            'movement' => $stats->period($period, $sparkMonths[0]['key'].'-01', $branchId, $classification),
            'compliance' => $compliance,
            'recentHires' => $filtered(Employee::query()->with('station'))->whereBetween('date_hired', [$period['from'], $period['to']])->orderByDesc('date_hired')->orderByDesc('id')->limit(5)->get(),
            'pendingLeaves' => WellnessLeave::query()
                ->where('status', LeaveStatus::Pending)
                ->whereBetween('date_filing', [$period['from'], $period['to']])
                ->whereHas('employee', $filtered)
                ->orderByDesc('date_filing')
                ->orderByDesc('id')
                ->limit(5)
                ->get(),
            'activity' => AuditLog::query()->visibleTo($user)->latest('id')->limit(8)->get()->map(ActivityFeed::describe(...)),
            'latestPayroll' => $latestPayroll,
        ]);
    }
}
