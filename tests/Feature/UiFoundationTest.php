<?php

use App\Enums\ThemeMode;
use App\Models\Notification;
use App\Models\PayrollPeriod;
use App\Models\Payslip;
use App\Models\PayslipSetting;
use App\Models\SiteSetting;
use App\Models\User;
use App\Support\ImportModules;
use App\Support\Navigation;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;

/**
 * Serves a Blade snippet through the real web middleware.
 */
function page(string $blade): string
{
    Route::middleware('web')->get('/employees/5', fn () => Blade::render($blade));

    return '/employees/5';
}

it('renders the signed-in shell around a page', function () {
    $user = User::factory()->create(['name' => 'Ana Reyes']);

    $this->actingAs($user)
        ->get(page('<x-layouts.app title="Juan" description="Profile">Body text</x-layouts.app>'))
        ->assertSuccessful()
        ->assertSee('Body text')
        ->assertSee('Juan · HR Management System', false)
        ->assertSee('Ana Reyes')
        ->assertSee('HR Staff')
        ->assertSee('aria-current="page"', false)
        ->assertSee('--brand-primary:#00539b', false);
});

it('shows HR Accounts and SMTP only to System Admins', function () {
    $blade = '<x-layouts.app title="X">Y</x-layouts.app>';

    $this->actingAs(User::factory()->create())->get(page($blade))
        ->assertDontSee('HR Accounts')
        ->assertDontSee('Email Server (SMTP)');

    $this->actingAs(User::factory()->systemAdmin()->create())->get('/employees/5')
        ->assertSee('HR Accounts')
        ->assertSee('Email Server (SMTP)');
});

it('never renders Payload Admin', function () {
    $this->actingAs(User::factory()->systemAdmin()->create())
        ->get(page('<x-layouts.app title="X">Y</x-layouts.app>'))
        ->assertDontSee('Payload Admin');
});

it('counts only the current user unread notifications', function () {
    $user = User::factory()->create();
    Notification::factory()->count(2)->for($user)->create();
    Notification::factory()->for($user)->create()->forceFill(['read_at' => now()])->save();
    Notification::factory()->create();

    $this->actingAs($user)->get(page('<x-layouts.app title="X">Y</x-layouts.app>'))
        ->assertSee('Notifications (2 unread)');
});

it('renders display preferences from cookies without encryption', function () {
    $this->actingAs(User::factory()->create())
        ->withUnencryptedCookies(['hr-sidebar' => 'collapsed', 'hr-sidebar-side' => 'right', 'hr-header' => 'compact', 'hr-theme' => 'dark'])
        ->get(page('<x-layouts.app title="X">Y</x-layouts.app>'))
        ->assertSee('data-sidebar="collapsed"', false)
        ->assertSee('data-side="right"', false)
        ->assertSee('data-header="compact"', false)
        ->assertSee('data-theme="dark"', false);
});

it('uses the site default theme until the visitor picks one', function () {
    SiteSetting::current()->update(['default_theme' => ThemeMode::Dark]);

    $this->get(page('<x-layouts.auth title="Sign in">Form</x-layouts.auth>'))
        ->assertSee('data-theme="dark"', false);
});

it('follows the device when the theme is system', function () {
    $this->get(page('<x-layouts.auth title="Sign in">Form</x-layouts.auth>'))
        ->assertDontSee('data-theme="', false)
        ->assertSee('prefers-color-scheme', false);
});

it('shows flash messages', function () {
    $this->actingAs(User::factory()->create())
        ->withSession(['flash' => ['type' => 'success', 'message' => 'Saved <b>']])
        ->get(page('<x-layouts.app title="X">Y</x-layouts.app>'))
        ->assertSee('Saved &lt;b&gt;', false)
        ->assertSee('role="status"', false);
});

it('explains denied redirects', function () {
    $url = page('<x-layouts.app title="X">Y</x-layouts.app>');

    $this->actingAs(User::factory()->create())->get("{$url}?denied=1")
        ->assertSee("You don't have permission", false);
});

it('renders the sign-in card with branding', function () {
    SiteSetting::current()->update(['company_name' => 'PhilPost HR', 'tagline' => 'People first']);

    $this->get(page('<x-layouts.auth title="Sign in" subtitle="Welcome">Form</x-layouts.auth>'))
        ->assertSee('PhilPost HR')
        ->assertSee('People first')
        ->assertSee('Welcome');
});

it('sizes the print layout from the paper', function () {
    $this->blade('<x-layouts.print title="Slip" paper="Half-Letter" back="/payroll">Sheet</x-layouts.print>')
        ->assertSee('size: 5.5in 8.5in', false)
        ->assertSee('Print / Save as PDF');
});

it('renders every icon the navigation asks for', function () {
    foreach (array_merge(...array_column(Navigation::groups(User::factory()->systemAdmin()->make()), 'items')) as $item) {
        expect(Blade::render('<x-icon :name="$name" />', ['name' => $item['icon']]))->toContain('<path d="M');
    }
});

it('labels icons only when asked', function () {
    expect(Blade::render('<x-icon name="check" />'))->toContain('aria-hidden="true"')->toContain('class="size-4"')
        ->and(Blade::render('<x-icon name="check" label="Done" class="size-6" />'))->toContain('aria-label="Done"')->toContain('class="size-6"');
});

it('shows initials or a picture', function () {
    expect(Blade::render('<x-avatar name="Ana Reyes" />'))->toContain('AR')
        ->and(Blade::render('<x-avatar name="Ana" :image-id="7" />'))->toContain('/files/7');
});

it('renders a whole module form with every field kind', function (string $slug) {
    $module = ImportModules::find($slug);

    $html = Blade::render('<x-module-form :fields="$fields" cancel-href="/back" />', ['fields' => $module['fields']]);

    foreach ($module['fields'] as $field) {
        ($field['computed'] ?? false)
            ? expect($html)->not->toContain("id=\"f-{$field['name']}\"")
            : expect($html)->toContain("id=\"f-{$field['name']}\"");
    }

    expect($html)->toContain('_token')->toContain('data-dirty-guard');
})->with(array_column(ImportModules::all(), 'slug'));

it('keeps typed values and shows field errors', function () {
    $html = Blade::render(
        '<x-form-field :field="$field" value="&quot;x" error="Required" />',
        ['field' => ['name' => 'email', 'label' => 'Email', 'kind' => 'email', 'required' => true]],
    );

    expect($html)->toContain('aria-invalid="true"')
        ->toContain('aria-describedby="f-email-err"')
        ->toContain('Required')
        ->toContain('required');
});

it('selects the current option', function () {
    $html = Blade::render('<x-form-field :field="$field" value="B" />', [
        'field' => ['name' => 'pick', 'label' => 'Pick', 'kind' => 'select', 'options' => ['A', 'B']],
    ]);

    expect($html)->toContain('<option value="B" selected');
});

it('marks government ID fields with their format', function () {
    expect(Blade::render('<x-form-field :field="$field" />', [
        'field' => ['name' => 'sss', 'label' => 'SSS', 'kind' => 'govId', 'idKind' => 'sss'],
    ]))->toContain('placeholder="00-0000000-0"')->toContain('inputmode="numeric"');
});

it('paginates with counts', function () {
    $paginator = new LengthAwarePaginator(range(1, 25), 60, 25, 2, ['path' => '/employees']);

    expect(Blade::render('<x-pagination :paginator="$paginator" />', ['paginator' => $paginator]))
        ->toContain('Showing 26–50 of 60')
        ->toContain('Page 2 of 3')
        ->toContain('page=3')
        ->toContain('page=1');
});

it('toggles sort direction and drops the page number', function () {
    $this->get(page('<table><tr><x-sort-header field="name" label="Name" /></tr></table>').'?sort=name&page=3&q=ana')
        ->assertSee('aria-sort="ascending"', false)
        ->assertSee('sort=-name', false)
        ->assertSee('q=ana', false)
        ->assertDontSee('page=3', false);
});

it('explains stat card changes in words', function () {
    $html = Blade::render('<x-stat-card label="Hires" :value="1200" :delta="[\'pct\' => -12.5, \'label\' => \'vs last month\']" :spark="[1, 2, 3]" />');

    expect($html)->toContain('1,200')
        ->toContain('Down 12.5% vs last month')
        ->toContain('bg-danger-soft')
        ->toContain('<svg width="96"');
});

it('treats a fall as good when the card is inverted', function () {
    expect(Blade::render('<x-stat-card label="Overdue" value="3" :delta="[\'pct\' => -10, \'invert\' => true]" />'))->toContain('bg-success-soft');
});

it('falls back when a chart has no data', function () {
    expect(Blade::render('<x-charts.chart title="Hires" :spec="[\'labels\' => [], \'series\' => []]" />'))
        ->toContain('No data yet.')
        ->not->toContain('<canvas');
});

it('renders a chart with a table of its values', function () {
    $spec = ['labels' => ['Jan', 'Feb'], 'series' => [['label' => 'Hires', 'data' => [1200, 3], 'slot' => 1], ['label' => 'Exits', 'data' => [1, 0], 'slot' => 2]]];

    expect(Blade::render('<x-charts.chart title="Hires" :spec="$spec" />', ['spec' => $spec]))
        ->toContain('data-chart=')
        ->toContain('View as table')
        ->toContain('1,201');
});

it('lists doughnut shares', function () {
    $spec = ['kind' => 'doughnut', 'labels' => ['A', 'B'], 'series' => [['label' => 'N', 'data' => [1, 3], 'slot' => 1]]];

    expect(Blade::render('<x-charts.chart title="Mix" :spec="$spec" />', ['spec' => $spec]))->toContain('25%')->toContain('75%');
});

it('renders split bars with real text', function () {
    $rows = [['label' => 'Active', 'parts' => [['label' => 'Male', 'value' => 1, 'slot' => 1], ['label' => 'Female', 'value' => 3, 'slot' => 2]]]];

    expect(Blade::render('<x-charts.split-bars title="Gender" :rows="$rows" />', ['rows' => $rows]))
        ->toContain('4 total')
        ->toContain('Male 1 (25%)')
        ->toContain('Female 3 (75%)');
});

it('renders the email code step', function () {
    expect(Blade::render('<x-auth.code-form masked-email="a***@example.test" resend-available-at="2026-01-01T00:00:00Z" :sends-left="0" />'))
        ->toContain('a***@example.test')
        ->toContain('one-time-code')
        ->toContain('disabled');
});

it('watermarks void certificates', function () {
    $html = Blade::render('<x-certificates.certificate-sheet paper-size="Letter" margin="wide" :void="true">Text</x-certificates.certificate-sheet>');

    expect($html)->toContain('width:8.5in')->toContain('padding:96pt')->toContain('VOID');
});

it('adds the leave filing being edited to the preview', function () {
    expect(Blade::render('<x-leave.leave-preview :exclude-id="9" />'))->toContain('data-exclude="9"');
});

it('renders a payslip and hides zero rows', function () {
    $settings = PayslipSetting::current();
    $slip = Payslip::factory()->create([
        'full_name' => 'Reyes, Ana',
        'employee_code' => 'EMP-1',
        'tin' => '111-222-333',
        'earnings' => [['label' => 'Basic Pay', 'amount_centavos' => 2_500_000], ['label' => 'Overtime', 'amount_centavos' => 0]],
        'deductions' => [['label' => 'PhilHealth', 'amount_centavos' => 125_050]],
    ]);

    $html = Blade::render('<x-payroll.payslip :settings="$settings" company-name="PhilPost" :period="$period" :slip="$slip" />', [
        'settings' => $settings,
        'period' => PayrollPeriod::query()->find($slip->period_id),
        'slip' => $slip,
    ]);

    expect($html)->toContain('Reyes, Ana')
        ->toContain('EMP-1')
        ->toContain('₱25,000.00')
        ->toContain('₱23,749.50')
        ->not->toContain('Overtime')
        ->not->toContain('111-222-333');
});
