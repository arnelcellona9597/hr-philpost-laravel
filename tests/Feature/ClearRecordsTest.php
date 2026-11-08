<?php

use App\Console\Commands\ClearRecords;
use App\Enums\RemittanceKind;
use App\Models\Branch;
use App\Models\CertificateTemplate;
use App\Models\ContractScope;
use App\Models\ContractSetting;
use App\Models\ContractTemplate;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Media;
use App\Models\RemittanceSetting;
use App\Models\RequirementDeadlineSetting;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\VoucherSetting;
use App\Models\WageOrder;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');

    $seeder = app(DemoDataSeeder::class);
    $seeder->employeeCount = 30;
    $seeder->run();

    $this->admin = User::factory()->systemAdmin()->create(['email' => 'admin@example.test']);
    $this->staff = User::factory()->create(['email' => 'staff@example.test']);
});

it('deletes the records and keeps every account, template and real wage rate', function () {
    $users = User::query()->count();
    $realRates = WageOrder::query()->where('order_number', 'not like', 'DEMO-%')->orWhereNull('order_number')->count();
    $logo = Media::factory()->create();
    SiteSetting::current()->update(['logo_id' => $logo->id]);

    $picture = Media::factory()->create();
    Storage::disk('local')->put($picture->path, 'image');
    Employee::query()->firstOrFail()->update(['profile_picture_id' => $picture->id]);

    $this->artisan('hr:clear-records', ['--force' => true])->assertSuccessful();

    foreach (ClearRecords::TABLES as $table) {
        expect(DB::table($table)->count())->toBe(0, "{$table} is not empty");
    }

    expect(User::query()->count())->toBe($users)
        ->and(User::query()->where('email', 'kristine.ramos@example.test')->exists())->toBeTrue()
        ->and($this->admin->fresh())->not->toBeNull()
        ->and($this->staff->fresh())->not->toBeNull()
        ->and(CertificateTemplate::query()->count())->toBeGreaterThan(0)
        ->and(ContractTemplate::query()->count())->toBeGreaterThan(0)
        ->and(ContractScope::query()->count())->toBeGreaterThan(0)
        ->and(Holiday::query()->count())->toBeGreaterThan(0)
        ->and(WageOrder::query()->where('order_number', 'like', 'DEMO-%')->exists())->toBeFalse()
        ->and(WageOrder::query()->count())->toBe($realRates)
        ->and($realRates)->toBeGreaterThan(0)
        ->and($logo->fresh())->not->toBeNull()
        ->and($picture->fresh())->toBeNull();

    Storage::disk('local')->assertMissing($picture->path);
});

it('blanks the sample names the demo wrote into settings', function () {
    $this->artisan('hr:clear-records', ['--force' => true])->assertSuccessful();

    $contract = ContractSetting::current();
    $voucher = VoucherSetting::current();
    $sss = RemittanceSetting::forKind(RemittanceKind::Sss);

    expect($contract->area_director_name)->toBeNull()
        ->and($contract->default_place_of_service)->toBeNull()
        ->and($contract->area_director_title)->not->toBeNull()
        ->and($voucher->approver_name)->toBeNull()
        ->and($voucher->approver_title)->not->toBeNull()
        ->and($sss->employer_name)->toBeNull()
        ->and($sss->prepared_by_name)->toBeNull()
        ->and($sss->prepared_by_title)->toBe(RemittanceKind::Sss->defaults()['prepared_by_title'])
        ->and(RequirementDeadlineSetting::current()->overrides)->toBe([]);
});

it('deletes nothing unless DELETE is typed', function () {
    $employees = Employee::query()->count();

    $this->artisan('hr:clear-records')
        ->expectsQuestion('This deletes every employee, branch and record. User accounts, templates and settings stay. Type DELETE to continue', 'yes')
        ->assertFailed();

    expect(Employee::query()->count())->toBe($employees)->and($employees)->toBeGreaterThan(0);
});

it('can keep the branches and the settings', function () {
    $contract = ContractSetting::current();
    $contract->update(['area_director_name' => 'Typed by HR']);
    $branches = Branch::query()->count();

    $this->artisan('hr:clear-records', ['--force' => true, '--keep-branches' => true, '--keep-settings' => true])->assertSuccessful();

    expect(Employee::query()->count())->toBe(0)
        ->and(Branch::query()->count())->toBe($branches)->and($branches)->toBeGreaterThan(0)
        ->and(ContractSetting::current()->area_director_name)->toBe('Typed by HR');
});
