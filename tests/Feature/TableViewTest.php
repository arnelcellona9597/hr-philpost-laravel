<?php

use App\Models\Employee;
use App\Models\ReceivedMail;
use App\Models\User;
use App\Support\EmployeeColumns;
use App\Support\PageSize;
use Illuminate\Testing\TestResponse;

beforeEach(fn () => signIn(User::factory()->create()));

/**
 * The text of the table's header row, so the Columns menu's own labels don't count.
 */
function tableHeaders(TestResponse $page): string
{
    preg_match('#<thead>(.*?)</thead>#s', $page->getContent(), $head);

    return trim(preg_replace('/\s+/', ' ', strip_tags($head[1] ?? '')));
}

it('shows the number of rows the person picks', function (int $perPage) {
    Employee::factory()->count(8)->create();

    $page = $this->get("/employees?perPage={$perPage}")->assertOk();

    expect(substr_count($page->getContent(), 'data-href='))->toBe(min($perPage, 8));
    $page->assertSee('of 8')->assertSee('Rows per page');
})->with([5, 10, 25]);

it('offers 5 up to 1,000 rows per page on every list', function () {
    ReceivedMail::factory()->count(7)->create();

    $page = $this->get('/received-mails?month=all&perPage=5')->assertOk()->assertSee('Showing 1–5 of 7', false);

    foreach (PageSize::SIZES as $size) {
        $page->assertSee('<option value="'.$size.'"', false);
    }
});

it('ignores a page size that is not on the list', function () {
    Employee::factory()->count(30)->create();

    $this->get('/employees?perPage=7')->assertOk()->assertSee('Showing 1–25 of 30', false);
});

it('uses the page size remembered in the browser', function () {
    Employee::factory()->count(12)->create();

    $this->withUnencryptedCookie(PageSize::COOKIE, '10')->get('/employees')->assertOk()->assertSee('Showing 1–10 of 12', false);
    $this->withUnencryptedCookie(PageSize::COOKIE, '10')->get('/employees?perPage=5')->assertSee('Showing 1–5 of 12', false);
});

it('shows the usual employee columns until the person chooses others', function () {
    Employee::factory()->create(['email' => 'razul@example.test']);

    $page = $this->get('/employees')->assertOk()->assertDontSee('razul@example.test');

    expect(tableHeaders($page))->toBe('Employee ID Name Position Station Classification Status Date hired Service');
});

it('lets the person choose employee columns and remembers them', function () {
    Employee::factory()->create(['email' => 'razul@example.test', 'courses' => 'BS Accountancy']);

    $chosen = $this->get('/employees?cols[]=email&cols[]=courses')->assertOk()
        ->assertSee('razul@example.test')
        ->assertSee('BS Accountancy');

    expect(tableHeaders($chosen))->toBe('Name Email Address Courses');

    $chosen->assertCookie(EmployeeColumns::COOKIE, 'name,email,courses');

    $again = $this->withCookie(EmployeeColumns::COOKIE, 'name,email')->get('/employees')->assertOk();

    expect(tableHeaders($again))->toBe('Name Email Address');
});

it('always keeps the name column and ignores unknown columns', function () {
    Employee::factory()->create();

    expect(tableHeaders($this->get('/employees?cols[]=nonsense&cols[]=email')->assertOk()))->toBe('Name Email Address')
        ->and(tableHeaders($this->get('/employees?cols[]=nonsense')->assertOk()))->toContain('Position');
});

it('goes back to the usual columns on reset', function () {
    Employee::factory()->create();

    $page = $this->withCookie(EmployeeColumns::COOKIE, 'name,email')->get('/employees?cols=default')->assertOk()->assertCookieExpired(EmployeeColumns::COOKIE);

    expect(tableHeaders($page))->toContain('Position')->not->toContain('Email Address');
});

it('lists the columns in the menu by key, with the shown ones ticked', function () {
    Employee::factory()->create();

    $this->get('/employees')->assertOk()
        ->assertSee('name="cols[]" value="email"', false)
        ->assertSee('name="cols[]" value="position" class="size-4" checked', false)
        ->assertDontSee('name="cols[]" value="0"', false);
});
