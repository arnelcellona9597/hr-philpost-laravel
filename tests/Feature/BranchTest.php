<?php

use App\Enums\Classification;
use App\Enums\EmploymentStatus;
use App\Models\Application;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;

beforeEach(fn () => signIn(User::factory()->create()));

it('needs a signed-in user', function () {
    auth()->logout();

    $this->flushSession()->get('/branches')->assertRedirect('/login?next=%2Fbranches');
});

it('lists branches with counts by classification', function () {
    $branch = Branch::factory()->create(['name' => 'Manila']);
    Employee::factory()->for($branch, 'station')->create(['classification' => Classification::Regular]);
    Employee::factory()->for($branch, 'station')->create(['classification' => Classification::Cos]);
    Employee::factory()->for($branch, 'station')->create(['employment_status' => EmploymentStatus::Resigned]);

    $page = $this->get('/branches')->assertSuccessful()->assertSee('Manila');

    expect($page->viewData('totals')['total'])->toBe(2);
    expect($this->get('/branches?scope=all')->viewData('totals')['total'])->toBe(3);
});

it('shows staff who have no branch', function () {
    Branch::factory()->create();
    Employee::factory()->create(['station_id' => null]);

    $this->get('/branches')->assertSee('Not assigned to a branch');
});

it('adds a branch and normalises the text', function () {
    $this->post('/branches', ['name' => '  Cebu   Main ', 'code' => 'CEB', 'contact_number' => '+63 32 123 4567'])->assertRedirect();

    expect(Branch::query()->firstOrFail()->name)->toBe('Cebu Main');
});

it('explains problems and keeps what was typed', function () {
    Branch::factory()->create(['name' => 'Manila']);

    $this->from('/branches/new')->followingRedirects()->post('/branches', ['name' => 'Manila', 'code' => 'X', 'contact_number' => 'call me'])
        ->assertSee('That branch name is already used.')
        ->assertSee('Enter a valid contact number', false)
        ->assertSee('Please fix the highlighted fields.')
        ->assertSee('value="X"', false);
});

it('requires a name', function () {
    $this->from('/branches/new')->post('/branches', ['name' => '   '])->assertSessionHasErrors('name');
});

it('refuses values too long for the column', function () {
    $this->from('/branches/new')->post('/branches', ['name' => str_repeat('a', 151)])
        ->assertSessionHasErrors(['name' => 'Branch Name must be at most 150 characters']);
});

it('updates a branch and allows its own name', function () {
    $branch = Branch::factory()->create(['name' => 'Manila']);

    $this->put("/branches/{$branch->id}", ['name' => 'Manila', 'address' => 'Liwasang Bonifacio'])->assertRedirect("/branches/{$branch->id}");

    expect($branch->fresh()->address)->toBe('Liwasang Bonifacio');
});

it('clears a field left blank', function () {
    $branch = Branch::factory()->create(['address' => 'Old']);

    $this->put("/branches/{$branch->id}", ['name' => $branch->name, 'address' => '']);

    expect($branch->fresh()->address)->toBeNull();
});

it('shows a branch with its people', function () {
    $branch = Branch::factory()->create();
    $employee = Employee::factory()->for($branch, 'station')->create();

    $this->get("/branches/{$branch->id}")->assertSuccessful()->assertSee($employee->full_name);
    $this->get("/branches/{$branch->id}/edit")->assertSuccessful();
    $this->get('/branches/new')->assertSuccessful();
});

it('refuses to delete a branch in use, with a reason', function () {
    $branch = Branch::factory()->create();
    Employee::factory()->for($branch, 'station')->create();
    Application::factory()->for($branch, 'station')->create();

    $this->delete("/branches/{$branch->id}")
        ->assertRedirect("/branches/{$branch->id}")
        ->assertSessionHas('flash.message', 'This branch still has 1 employee(s) and 1 application(s). Reassign them first.');

    expect($branch->fresh())->not->toBeNull();
});

it('deletes an unused branch', function () {
    $branch = Branch::factory()->create();

    $this->delete("/branches/{$branch->id}")->assertRedirect('/branches');

    expect($branch->fresh())->toBeNull();
});
