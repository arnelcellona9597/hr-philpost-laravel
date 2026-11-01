<?php

namespace App\Http\Controllers;

use App\Enums\Region;
use App\Http\Controllers\Concerns\RespondsToForms;
use App\Models\WageOrder;
use App\Services\PayHistoryService;
use App\Support\HrDate;
use App\Support\ImportModules;
use App\Support\RecordForm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class WageOrderController extends Controller
{
    use RespondsToForms;

    private const PER_PAGE = 25;

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', WageOrder::class);

        $region = Region::tryFrom((string) $request->query('region'));
        $today = HrDate::today();
        $inForce = WageOrder::query()->where('effective_on', '<=', $today)->orderByDesc('effective_on')->orderByDesc('daily_rate_centavos')->get()->unique(fn (WageOrder $order): string => $order->region->value)->keyBy(fn (WageOrder $order): string => $order->region->value);

        return view('wage-orders.index', [
            'regions' => Region::cases(),
            'region' => $region,
            'inForce' => $inForce,
            'orders' => WageOrder::query()->withCount('payChanges')->when($region, fn ($query) => $query->where('region', $region->value))->orderByDesc('effective_on')->orderBy('region')->orderBy('sector')->paginate(self::PER_PAGE)->withQueryString(),
            'today' => $today,
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', WageOrder::class);

        return view('wage-orders.form', ['order' => null, 'fields' => $this->fields(), 'values' => ['sector' => 'Non-agriculture']]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', WageOrder::class);

        ['data' => $data, 'errors' => $errors] = $this->parse($request);

        if ($errors !== []) {
            return $this->invalid($errors);
        }

        $order = WageOrder::query()->create($data);

        return $this->flash(redirect()->route('wage-orders.show', $order), "Wage order {$order->display_name} was added. Check who it changes, then apply it.");
    }

    public function show(WageOrder $order, PayHistoryService $pay): View
    {
        Gate::authorize('view', $order);

        return view('wage-orders.show', ['order' => $order, 'impact' => $pay->impact($order)]);
    }

    public function edit(WageOrder $order): View
    {
        Gate::authorize('update', $order);

        return view('wage-orders.form', ['order' => $order, 'fields' => $this->fields(), 'values' => RecordForm::values($order, $this->fields())]);
    }

    public function update(Request $request, WageOrder $order): RedirectResponse
    {
        Gate::authorize('update', $order);

        ['data' => $data, 'errors' => $errors] = $this->parse($request, $order);

        if ($errors !== []) {
            return $this->invalid($errors);
        }

        $order->update($data);

        return $this->flash(redirect()->route('wage-orders.show', $order), 'The wage order was updated. Rates already given to employees are not changed.');
    }

    public function destroy(WageOrder $order): RedirectResponse
    {
        Gate::authorize('delete', $order);

        $blocker = $order->deleteBlocker();

        if ($blocker !== null) {
            return $this->flash(redirect()->route('wage-orders.show', $order), $blocker, 'error');
        }

        $order->delete();

        return $this->flash(redirect()->route('wage-orders.index'), "Wage order {$order->display_name} was deleted.");
    }

    public function apply(Request $request, WageOrder $order, PayHistoryService $pay): RedirectResponse
    {
        Gate::authorize('update', $order);

        $premium = trim((string) $request->input('premium_percent'));

        if ($premium !== '' && (! is_numeric($premium) || (float) $premium < 0 || (float) $premium > 100)) {
            return $this->flash(redirect()->route('wage-orders.show', $order), 'The premium must be a percentage from 0 to 100.', 'error');
        }

        $raised = $pay->apply($order, $request->user(), $premium === '' ? null : (float) $premium);

        return $this->flash(redirect()->route('wage-orders.show', $order), $raised === 0 ? 'Nobody needed a change.' : "{$raised} employee(s) now have this rate in their compensation history.");
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fields(): array
    {
        return ImportModules::find('wage-orders')['fields'];
    }

    /**
     * @return array{data: array<string, mixed>, errors: array<string, string>}
     */
    private function parse(Request $request, ?WageOrder $order = null): array
    {
        $parsed = RecordForm::parse($request, $this->fields(), 'wage_orders');
        $data = $parsed['data'];

        $taken = ($data['region'] ?? null) && ($data['sector'] ?? null) && ($data['effective_on'] ?? null) && WageOrder::query()
            ->where(['region' => $data['region'], 'sector' => $data['sector'], 'effective_on' => $data['effective_on']])
            ->when($order, fn ($query) => $query->whereKeyNot($order->id))
            ->exists();

        return $taken ? [...$parsed, 'errors' => [...$parsed['errors'], 'effective_on' => 'This region and sector already have a wage order on that date.']] : $parsed;
    }
}
