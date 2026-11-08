<?php

use App\Models\Branch;
use App\Models\BranchUnit;
use App\Models\Employee;
use App\Models\User;
use App\Support\EmployeeSearch;
use Illuminate\Http\Request;

beforeEach(fn () => signIn(User::factory()->create()));

it('lists units with their branch and the number of employees', function () {
    $branch = Branch::factory()->create(['name' => 'Mintal Post Office']);
    $unit = BranchUnit::factory()->create(['branch_id' => $branch->id, 'name' => 'Accounting Section']);
    Employee::factory()->count(2)->create(['unit_id' => $unit->id]);
    BranchUnit::factory()->unplaced()->create(['name' => 'Waiting Unit']);

    $this->get('/branches/units')->assertOk()
        ->assertSee('Accounting Section')
        ->assertSee('Waiting Unit')
        ->assertSee('1 unit not placed under a branch yet', false);
});

it('adds a unit under a branch, or with no branch yet', function () {
    $branch = Branch::factory()->create();

    $this->post('/branches/units', ['name' => '  Accounting   Section ', 'branch_id' => $branch->id])->assertRedirect('/branches/units');
    $this->post('/branches/units', ['name' => 'Loose Unit', 'branch_id' => ''])->assertRedirect('/branches/units');

    expect(BranchUnit::query()->where('name', 'Accounting Section')->value('branch_id'))->toBe($branch->id)
        ->and(BranchUnit::query()->where('name', 'Loose Unit')->value('branch_id'))->toBeNull();
});

it('refuses a duplicate unit under the same branch but allows the same name under another', function () {
    $a = Branch::factory()->create();
    $b = Branch::factory()->create();
    BranchUnit::factory()->create(['branch_id' => $a->id, 'name' => 'Accounting Section']);

    $this->post('/branches/units', ['name' => 'accounting section', 'branch_id' => $a->id])->assertSessionHasErrors('form');
    $this->post('/branches/units', ['name' => 'Accounting Section', 'branch_id' => $b->id])->assertSessionHasNoErrors();

    expect(BranchUnit::query()->count())->toBe(2);
});

it('moves the people in a unit to its branch when the unit is placed under one', function () {
    $unit = BranchUnit::factory()->unplaced()->create(['name' => 'Accounting Section']);
    $employee = Employee::factory()->create(['unit_id' => $unit->id, 'station_id' => null]);
    $branch = Branch::factory()->create();

    $this->put("/branches/units/{$unit->id}", ['name' => 'Accounting Section', 'branch_id' => $branch->id])->assertRedirect();

    expect($employee->fresh()->station_id)->toBe($branch->id)
        ->and($employee->employmentPeriods()->first()->station_id)->toBe($branch->id);
});

it('lets the unit decide the employee\'s branch', function () {
    $branch = Branch::factory()->create();
    $other = Branch::factory()->create();
    $unit = BranchUnit::factory()->create(['branch_id' => $branch->id]);

    $employee = Employee::factory()->create(['station_id' => $other->id, 'unit_id' => $unit->id]);

    expect($employee->fresh()->station_id)->toBe($branch->id);

    $employee->update(['station_id' => $other->id]);

    expect($employee->fresh()->station_id)->toBe($branch->id);
});

it('keeps the chosen branch when the unit has none yet', function () {
    $branch = Branch::factory()->create();
    $unit = BranchUnit::factory()->unplaced()->create();

    $employee = Employee::factory()->create(['station_id' => $branch->id, 'unit_id' => $unit->id]);

    expect($employee->fresh()->station_id)->toBe($branch->id);
});

it('will not delete a unit with people in it, or a branch with units', function () {
    $branch = Branch::factory()->create();
    $unit = BranchUnit::factory()->create(['branch_id' => $branch->id]);
    Employee::factory()->create(['unit_id' => $unit->id]);

    $this->delete("/branches/units/{$unit->id}")->assertSessionHas('flash.type', 'error');
    $this->delete("/branches/{$branch->id}")->assertSessionHas('flash.type', 'error');

    expect(BranchUnit::query()->whereKey($unit->id)->exists())->toBeTrue()
        ->and(Branch::query()->whereKey($branch->id)->exists())->toBeTrue();

    Employee::query()->delete();
    $this->delete("/branches/units/{$unit->id}")->assertSessionHas('flash.type', 'success');
    expect(BranchUnit::query()->count())->toBe(0);
});

it('lists a branch\'s units on its page and links to manage them', function () {
    $branch = Branch::factory()->create();
    BranchUnit::factory()->create(['branch_id' => $branch->id, 'name' => 'Records Unit']);

    $this->get("/branches/{$branch->id}")->assertOk()->assertSee('Records Unit')->assertSee('Manage units');
    $this->get('/branches')->assertOk()->assertSee('/branches/units', false);
});

it('picks a unit on the employee form and shows it on the profile', function () {
    $branch = Branch::factory()->create(['name' => 'Mintal Post Office']);
    $unit = BranchUnit::factory()->create(['branch_id' => $branch->id, 'name' => 'Accounting Section']);
    $employee = Employee::factory()->create(['unit_id' => $unit->id]);

    $this->get("/employees/{$employee->id}/edit")->assertOk()->assertSee('Mintal Post Office › Accounting Section')->assertSee('Station / Division / Unit');
    $this->get("/employees/{$employee->id}")->assertOk()->assertSee('Accounting Section');
});

it('searches employees by unit and offers the unit as a column', function () {
    $unit = BranchUnit::factory()->create(['name' => 'Parcel Unit']);
    Employee::factory()->create(['unit_id' => $unit->id, 'last_name' => 'Insideunit']);
    Employee::factory()->create(['last_name' => 'Outsideunit']);

    $this->get('/employees?q=Parcel')->assertOk()->assertSee('Insideunit')->assertDontSee('Outsideunit');
    $this->get('/employees?cols[]=unit')->assertOk()->assertSee('Parcel Unit');
});

it('filters the employee list by Division / Unit', function () {
    $branch = Branch::factory()->create(['name' => 'Mintal Post Office']);
    $records = BranchUnit::factory()->create(['branch_id' => $branch->id, 'name' => 'Records Unit']);
    $parcel = BranchUnit::factory()->create(['branch_id' => $branch->id, 'name' => 'Parcel Unit']);
    Employee::factory()->create(['unit_id' => $records->id, 'last_name' => 'Inrecords']);
    Employee::factory()->create(['unit_id' => $parcel->id, 'last_name' => 'Inparcel']);
    Employee::factory()->create(['last_name' => 'Nounit']);

    $this->get("/employees?unit[]={$records->id}")->assertOk()->assertSee('Inrecords')->assertDontSee('Inparcel')->assertDontSee('Nounit');
    $this->get("/employees?unit[]={$records->id}&unit[]={$parcel->id}")->assertOk()->assertSee('Inrecords')->assertSee('Inparcel')->assertDontSee('Nounit');
    $this->get("/employees?branch[]={$branch->id}&unit[]={$parcel->id}")->assertOk()->assertSee('Inparcel')->assertDontSee('Inrecords');
});

it('offers the Division / Unit filter grouped by branch and counts it as a filter', function () {
    $branch = Branch::factory()->create(['name' => 'Mintal Post Office']);
    $unit = BranchUnit::factory()->create(['branch_id' => $branch->id, 'name' => 'Records Unit']);
    BranchUnit::factory()->unplaced()->create(['name' => 'Waiting Unit']);
    Employee::factory()->create(['unit_id' => $unit->id]);

    $this->get('/employees')->assertOk()
        ->assertSee('Division / Unit')
        ->assertSee('<optgroup label="Mintal Post Office" data-branch="'.$branch->id.'">', false)
        ->assertSee('Not placed under a branch yet')
        ->assertSee('Waiting Unit');

    expect((new EmployeeSearch(Request::create('/employees', 'GET', ['unit' => [(string) $unit->id]])))->activeFilterCount())->toBe(1);
});

it('keeps the unit filter in the employee export and print links', function () {
    $unit = BranchUnit::factory()->create(['name' => 'Records Unit']);
    Employee::factory()->create(['unit_id' => $unit->id]);

    $this->get("/employees?unit[]={$unit->id}")->assertOk()->assertSee('unit%5B0%5D='.$unit->id, false);
});
