<?php

use App\Models\Employee;
use App\Models\User;

beforeEach(fn () => signIn(User::factory()->create()));

/**
 * The text inside a .docx file, given its bytes.
 */
function docxText(string $bytes): string
{
    $path = tempnam(sys_get_temp_dir(), 'docx');
    file_put_contents($path, $bytes);
    $zip = new ZipArchive;
    $zip->open($path);
    $xml = (string) $zip->getFromName('word/document.xml');
    $zip->close();
    unlink($path);

    return html_entity_decode(strip_tags(str_replace('</w:p>', "\n", $xml)));
}

it('downloads one Word bio for each matching employee in a ZIP', function () {
    $razul = Employee::factory()->create(['last_name' => 'Abuomar', 'first_name' => 'Razul', 'classification' => 'COS']);
    $ana = Employee::factory()->create(['last_name' => 'Santos', 'first_name' => 'Ana', 'classification' => 'COS']);
    Employee::factory()->create(['last_name' => 'Outsider', 'classification' => 'Regular']);

    $response = $this->get('/employees/print/bios.zip?classification[]=COS')->assertOk();
    $path = $response->baseResponse->getFile()->getPathname();
    $zip = new ZipArchive;
    $zip->open($path);

    expect($zip->numFiles)->toBe(2)
        ->and($response->headers->get('Content-Disposition'))->toContain('employee-bios-');

    $names = collect(range(0, 1))->map(fn (int $index): string => $zip->getNameIndex($index))->all();
    expect($names)->each->toEndWith('.docx')
        ->and(collect($names)->contains(fn (string $name): bool => str_contains($name, $razul->employee_id)))->toBeTrue();

    $texts = collect(range(0, 1))->map(fn (int $index): string => docxText($zip->getFromIndex($index)))->implode("\n");
    $zip->close();

    expect($texts)->toContain('EMPLOYEE PROFILE')->toContain('ABUOMAR, RAZUL')->toContain('SANTOS, ANA')->not->toContain('OUTSIDER')
        ->and($texts)->toContain('Employment history')->toContain('Compensation History');
});

it('gives files that would share a name their own entry in the ZIP', function () {
    Employee::factory()->create(['employee_id' => 'EMP#1', 'last_name' => 'Same', 'first_name' => 'Name']);
    Employee::factory()->create(['employee_id' => 'EMP@1', 'last_name' => 'Same', 'first_name' => 'Name']);

    $response = $this->get('/employees/print/bios.zip')->assertOk();
    $zip = new ZipArchive;
    $zip->open($response->baseResponse->getFile()->getPathname());

    expect($zip->numFiles)->toBe(2);
    $zip->close();
});

it('prints all matching employees in one table with the chosen columns', function () {
    Employee::factory()->create(['last_name' => 'Abuomar', 'first_name' => 'Razul', 'email' => 'razul@example.test']);
    Employee::factory()->create(['last_name' => 'Santos', 'first_name' => 'Ana', 'email' => 'ana@example.test']);

    $page = $this->get('/employees/print/table?cols[]=email')->assertOk();

    expect(substr_count($page->getContent(), '<tr class="break-inside-avoid">'))->toBe(2);
    $page->assertSee('EMPLOYEES')->assertSee('Email Address')->assertSee('razul@example.test')->assertSee('Abuomar, Razul')
        ->assertSee('Download Word (.docx)')->assertDontSee('Classification');
});

it('downloads the employee table as a Word file', function () {
    Employee::factory()->create(['last_name' => 'Abuomar', 'first_name' => 'Razul', 'email' => 'razul@example.test']);
    Employee::factory()->create(['last_name' => 'Outsider', 'first_name' => 'Zed', 'email' => 'zed@example.test']);

    $response = $this->get('/employees/print/table.docx?q=Abuomar&cols[]=email')->assertOk();
    $text = docxText($response->getContent());

    expect($response->headers->get('Content-Type'))->toContain('wordprocessingml')
        ->and($text)->toContain('Abuomar, Razul')->toContain('razul@example.test')->toContain('Email Address')->not->toContain('Outsider');
});

it('says so when no employee matches a download', function (string $url) {
    Employee::factory()->create(['last_name' => 'Santos']);

    $this->get($url.'?q=Nobodyatall')->assertRedirect('/employees?q=Nobodyatall')->assertSessionHas('flash.type', 'error');
})->with(['/employees/print/bios.zip', '/employees/print/table', '/employees/print/table.docx']);

it('offers the print options on the employee list', function () {
    Employee::factory()->count(2)->create();

    $this->get('/employees')->assertOk()
        ->assertSee('Individual bios')
        ->assertSee('All in one table')
        ->assertSee('One profile per page')
        ->assertSee('/employees/print/bios.zip', false)
        ->assertSee('/employees/print/table', false);
});
