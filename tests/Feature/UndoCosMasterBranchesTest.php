<?php

use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmploymentPeriod;
use App\Models\User;

/**
 * An employee on a real branch from before, then moved the way the first import did: onto a branch made from station text.
 *
 * @return array{old: Branch, made: Branch, employee: Employee}
 */
function movedByImport(string $madeName = 'Made By Import'): array
{
    test()->travelTo(now()->subDays(2));
    $old = Branch::factory()->create(['name' => 'Cotabato City Post Office']);
    $employee = Employee::factory()->create(['station_id' => $old->id, 'date_hired' => '2020-01-01']);

    test()->travelBack();
    $made = Branch::factory()->create(['name' => $madeName]);
    $employee->update(['station_id' => $made->id]);
    EmploymentPeriod::query()->where('employee_id', $employee->id)->update(['station_id' => $made->id]);

    return ['old' => $old, 'made' => $made, 'employee' => $employee];
}

it('shows what it would do and changes nothing without --commit', function () {
    ['made' => $made, 'employee' => $employee] = movedByImport();

    $this->artisan('hr:undo-import-branches', ['--since' => now()->subHour()->toDateTimeString()])->expectsOutputToContain('Nothing was changed')->assertSuccessful();

    expect($employee->fresh()->station_id)->toBe($made->id)->and(Branch::query()->whereKey($made->id)->exists())->toBeTrue();
});

it('puts employees back on their earlier branch and deletes the branches the import made', function () {
    ['old' => $old, 'made' => $made, 'employee' => $employee] = movedByImport();

    $this->artisan('hr:undo-import-branches', ['--since' => now()->subHour()->toDateTimeString(), '--commit' => true])->expectsConfirmation('Save these changes? Take a backup first (php8.5 artisan hr:backup).', 'yes')->assertSuccessful();

    expect($employee->fresh()->station_id)->toBe($old->id)
        ->and($employee->employmentPeriods()->first()->station_id)->toBe($old->id)
        ->and(Branch::query()->whereKey($made->id)->exists())->toBeFalse()
        ->and(Branch::query()->whereKey($old->id)->exists())->toBeTrue();
});

it('leaves an employee alone whose branch was changed by hand after the import', function () {
    ['made' => $made, 'employee' => $employee] = movedByImport();
    signIn(User::factory()->create());
    $manual = Branch::factory()->create(['name' => 'Chosen By HR']);
    $employee->update(['station_id' => $manual->id]);

    $this->artisan('hr:undo-import-branches', ['--since' => now()->subHour()->toDateTimeString(), '--commit' => true])->expectsConfirmation('Save these changes? Take a backup first (php8.5 artisan hr:backup).', 'yes')->assertSuccessful();

    expect($employee->fresh()->station_id)->toBe($manual->id)
        ->and(Branch::query()->whereKey($made->id)->exists())->toBeFalse();
});

it('keeps a created branch that someone else still uses', function () {
    ['made' => $made] = movedByImport();
    signIn(User::factory()->create());
    Employee::factory()->create(['station_id' => $made->id]);

    $this->artisan('hr:undo-import-branches', ['--since' => now()->subHour()->toDateTimeString(), '--commit' => true])->expectsConfirmation('Save these changes? Take a backup first (php8.5 artisan hr:backup).', 'yes')->assertSuccessful();

    expect(Branch::query()->whereKey($made->id)->exists())->toBeTrue();
});

it('empties the branch of a person the import added', function () {
    $made = Branch::factory()->create(['name' => 'Made By Import']);
    $person = Employee::factory()->create(['station_id' => $made->id]);

    $this->artisan('hr:undo-import-branches', ['--since' => now()->subHour()->toDateTimeString(), '--commit' => true])->expectsConfirmation('Save these changes? Take a backup first (php8.5 artisan hr:backup).', 'yes')->assertSuccessful();

    expect($person->fresh()->station_id)->toBeNull()->and(Branch::query()->whereKey($made->id)->exists())->toBeFalse();
});

it('asks when the import ran', function () {
    $this->artisan('hr:undo-import-branches')->assertFailed();
});
