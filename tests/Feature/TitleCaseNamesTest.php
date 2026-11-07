<?php

use App\Models\Employee;
use App\Models\User;
use App\Models\WellnessLeave;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    signIn(User::factory()->create());

    $this->employee = Employee::factory()->create(['last_name' => 'ABUOMAr', 'first_name' => 'RAZUL', 'middle_name' => 'KARON', 'extension' => null]);
});

it('shows the names in title case on the profile, the print and the list', function () {
    $this->get("/employees/{$this->employee->id}")->assertOk()
        ->assertSee('Abuomar')
        ->assertSee('Karon')
        ->assertDontSee('ABUOMAr');

    $this->get("/employees/{$this->employee->id}/print")->assertOk()
        ->assertSee('Abuomar')
        ->assertDontSee('ABUOMAr');

    $this->get('/employees')->assertOk()->assertSee('Abuomar, Razul K.');
});

it('keeps the names exactly as typed in the database and in the edit form', function () {
    $this->get("/employees/{$this->employee->id}/edit")->assertOk()->assertSee('value="ABUOMAr"', false);

    $this->employee->fresh()->update(['position' => 'Letter Carrier']);

    $row = DB::table('employees')->where('id', $this->employee->id)->first(['last_name', 'first_name', 'middle_name']);

    expect([$row->last_name, $row->first_name, $row->middle_name])->toBe(['ABUOMAr', 'RAZUL', 'KARON']);
});

it('shows leave filings with title-case names', function () {
    WellnessLeave::factory()->for($this->employee)->create(['last_name' => 'ABUOMAr', 'first_name' => 'RAZUL', 'middle_name' => 'KARON']);

    $this->get('/leave')->assertOk()->assertSee('Abuomar')->assertSee('Razul')->assertDontSee('ABUOMAr');
});

it('shows the name heading in capitals on the profile and its print, with the fields below in title case', function () {
    foreach (['', '/print'] as $suffix) {
        $this->get("/employees/{$this->employee->id}{$suffix}")->assertOk()
            ->assertSee('ABUOMAR, RAZUL K.')
            ->assertSee('Abuomar');
    }
});
