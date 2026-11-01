<?php

use App\Models\AuditLog;
use App\Models\ImportJob;
use App\Models\ReceivedMail;
use App\Models\User;
use App\Services\PendingImports;
use App\Services\ReceivedMailsWorkbook;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * A workbook shaped like the office's own log: letterhead, a title, then the header row 16 rows down.
 *
 * @param  array<string, list<list<mixed>>>  $sheets  Rows below the header, per sheet title
 */
function mailLog(array $sheets, int $headerRow = 16): UploadedFile
{
    $spreadsheet = new Spreadsheet;
    $spreadsheet->removeSheetByIndex(0);

    foreach ($sheets as $title => $rows) {
        $sheet = $spreadsheet->createSheet()->setTitle($title);
        $sheet->getCell('A1')->setValueExplicit("Republic of the Philippines\nSAMPLE POSTAL CORPORATION", DataType::TYPE_STRING);
        $sheet->getCell('A12')->setValueExplicit('RECEIVED MAILS ', DataType::TYPE_STRING);

        foreach (['No.', 'DATE', "TRACKING NUMBER'", 'POST OFFICE', 'DESCRIPTION', null, null, null, null, null, null, 'REMARKS', 'Received'] as $column => $label) {
            if ($label !== null) {
                $sheet->getCell([$column + 1, $headerRow])->setValueExplicit($label, DataType::TYPE_STRING);
            }
        }

        foreach ($rows as $index => $row) {
            foreach (array_slice($row, 0, 5) as $column => $value) {
                if ($value !== null) {
                    $sheet->getCell([$column + 1, $headerRow + 1 + $index])->setValueExplicit($value, is_int($value) ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
                }
            }
        }
    }

    $path = tempnam(sys_get_temp_dir(), 'mails').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    return new UploadedFile($path, 'received-mails.xlsx', test: true);
}

function stageMailImport(UploadedFile $file): mixed
{
    return test()->post('/received-mails/import', ['file' => $file]);
}

beforeEach(function () {
    Storage::fake('local');
    $this->hr = User::factory()->create(['name' => 'Rita Santos']);
    signIn($this->hr);
    $this->travelTo('2026-09-15 10:00:00');
});

it('logs several pieces of mail for one day and ignores empty rows', function () {
    $this->post('/received-mails', [
        'received_on' => '2026-09-01',
        'received_by' => 'Rita Santos',
        'rows' => [
            ['tracking_number' => 'RL 217 180 939 ZZ', 'post_office' => 'UM', 'description' => 'Statement of absences', 'remarks' => ''],
            ['tracking_number' => '', 'post_office' => '', 'description' => '', 'remarks' => ''],
            ['tracking_number' => '#918', 'post_office' => 'CALINAN', 'description' => 'Leave application of Mr. Santos', 'remarks' => 'Hand carried'],
        ],
    ])->assertRedirect('/received-mails?month=2026-09');

    $mails = ReceivedMail::query()->orderBy('id')->get();

    expect($mails)->toHaveCount(2)
        ->and($mails[0]->received_on->toDateString())->toBe('2026-09-01')
        ->and($mails[0]->remarks)->toBeNull()
        ->and($mails[0]->received_by)->toBe('Rita Santos')
        ->and($mails[0]->created_by)->toBe($this->hr->id)
        ->and($mails[1]->remarks)->toBe('Hand carried')
        ->and(AuditLog::query()->where('collection_slug', 'received-mails')->where('action', 'create')->count())->toBe(2);
});

it('saves nothing when any row is incomplete', function (array $rows, string $message) {
    $this->post('/received-mails', ['received_on' => '2026-09-01', 'rows' => $rows])->assertSessionHasErrors(['form']);

    expect(session('errors')->first('form'))->toContain($message)->and(ReceivedMail::query()->count())->toBe(0);
})->with([
    'no mail' => [[], 'Add at least one piece of mail'],
    'no tracking' => [[['post_office' => 'UM', 'description' => 'x']], 'Row 1: enter the tracking number'],
    'second row no office' => [[['tracking_number' => 'A', 'post_office' => 'UM', 'description' => 'x'], ['tracking_number' => 'B', 'description' => 'y']], 'Row 2: enter the post office'],
    'too long' => [[['tracking_number' => str_repeat('1', 61), 'post_office' => 'UM', 'description' => 'x']], 'tracking number is too long'],
]);

it('refuses a missing or impossible date', function (string $date) {
    $this->post('/received-mails', ['received_on' => $date, 'rows' => [['tracking_number' => 'A', 'post_office' => 'UM', 'description' => 'x']]])->assertSessionHasErrors('form');

    expect(ReceivedMail::query()->count())->toBe(0);
})->with(['', '2026-02-30', 'soon']);

it('edits and deletes a mail', function () {
    $mail = ReceivedMail::factory()->create(['received_on' => '2026-09-02', 'tracking_number' => 'RL 1', 'post_office' => 'UM', 'description' => 'Old']);

    $this->put("/received-mails/{$mail->id}", ['received_on' => '2026-09-03', 'tracking_number' => 'RL 1', 'post_office' => 'TORIL', 'description' => 'New text', 'remarks' => '', 'received_by' => 'Ana'])->assertRedirect('/received-mails?month=2026-09');

    expect($mail->fresh()->post_office)->toBe('TORIL')->and($mail->fresh()->received_on->toDateString())->toBe('2026-09-03');

    $this->get("/received-mails/{$mail->id}/edit")->assertOk()->assertSee('New text');
    $this->put("/received-mails/{$mail->id}", ['received_on' => '2026-09-03', 'tracking_number' => '', 'post_office' => 'X', 'description' => 'Y'])->assertSessionHasErrors('form');

    $this->delete("/received-mails/{$mail->id}")->assertRedirect();

    expect(ReceivedMail::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('collection_slug', 'received-mails')->where('action', 'delete')->count())->toBe(1);
});

it('lists one month at a time, with the date shown once for each day', function () {
    ReceivedMail::factory()->create(['received_on' => '2026-09-01', 'tracking_number' => 'RL FIRST']);
    ReceivedMail::factory()->create(['received_on' => '2026-09-01', 'tracking_number' => 'RL SECOND']);
    ReceivedMail::factory()->create(['received_on' => '2026-09-02', 'tracking_number' => 'RL THIRD']);
    ReceivedMail::factory()->create(['received_on' => '2026-08-31', 'tracking_number' => 'RL AUGUST']);

    $page = $this->get('/received-mails')->assertOk()->assertSee('RL FIRST')->assertSee('RL THIRD')->assertDontSee('RL AUGUST')->assertSee('3 pieces of mail on 2 days');

    expect(substr_count($page->getContent(), 'Sep 1, 2026'))->toBe(1);

    $this->get('/received-mails?month=2026-08')->assertOk()->assertSee('RL AUGUST')->assertDontSee('RL FIRST');
    $this->get('/received-mails?month=all')->assertOk()->assertSee('RL AUGUST')->assertSee('RL FIRST');
    $this->get('/received-mails?month=nonsense')->assertOk()->assertSee('RL FIRST');
});

it('searches by tracking number, post office or description and filters by office', function () {
    ReceivedMail::factory()->create(['received_on' => '2026-09-01', 'tracking_number' => 'RL 111', 'post_office' => 'TAGUM', 'description' => 'Leave application of Ms. Maja']);
    ReceivedMail::factory()->create(['received_on' => '2026-09-01', 'tracking_number' => 'RL 222', 'post_office' => 'DIGOS', 'description' => 'DTR for wages']);

    $this->get('/received-mails?q=RL 111')->assertSee('Maja')->assertDontSee('DTR for wages');
    $this->get('/received-mails?q=wages')->assertSee('RL 222')->assertDontSee('Maja');
    $this->get('/received-mails?q=100%25')->assertOk();
    $this->get('/received-mails?post_office=DIGOS')->assertSee('RL 222')->assertDontSee('RL 111');
});

it('numbers rows across pages and escapes what was typed', function () {
    ReceivedMail::factory()->count(55)->create(['received_on' => '2026-09-01']);
    ReceivedMail::factory()->create(['received_on' => '2026-09-02', 'description' => '<script>alert(1)</script>']);

    $this->get('/received-mails?page=2')->assertOk()->assertSee('Showing 51–56 of 56')->assertDontSee('<script>alert(1)', false)->assertSee('&lt;script&gt;', false);
});

it('exports a month in the office layout', function () {
    ReceivedMail::factory()->create(['received_on' => '2026-09-01', 'tracking_number' => 'RL 1', 'post_office' => 'UM', 'description' => 'First', 'received_by' => 'Rita']);
    ReceivedMail::factory()->create(['received_on' => '2026-09-01', 'tracking_number' => 'RL 2', 'post_office' => 'TORIL', 'description' => '=HYPERLINK("x")']);
    ReceivedMail::factory()->create(['received_on' => '2026-09-02', 'tracking_number' => 'RL 3', 'post_office' => 'TAGUM', 'description' => 'Third']);
    ReceivedMail::factory()->create(['received_on' => '2026-08-31', 'tracking_number' => 'RL OLD']);

    $response = $this->get('/received-mails/export?month=2026-09')->assertOk();
    $path = tempnam(sys_get_temp_dir(), 'export').'.xlsx';
    file_put_contents($path, $response->streamedContent());
    $sheet = IOFactory::load($path)->getActiveSheet();

    expect($response->headers->get('Content-Disposition'))->toContain('received-mails-2026-09.xlsx')
        ->and($sheet->getTitle())->toBe('SEPTEMBER 2026')
        ->and($sheet->getCell('A12')->getValue())->toBe('RECEIVED MAILS')
        ->and($sheet->getCell('A14')->getValue())->toBe('FOR THE MONTH OF SEPTEMBER 2026')
        ->and($sheet->getCell('C16')->getValue())->toBe('TRACKING NUMBER')
        ->and($sheet->getCell('A17')->getValue())->toBe(1)
        ->and($sheet->getCell('B17')->getFormattedValue())->toBe('2026-09-01')
        ->and($sheet->getCell('B18')->getValue())->toBeNull()
        ->and($sheet->getCell('B19')->getFormattedValue())->toBe('2026-09-02')
        ->and($sheet->getCell('E18')->getValue())->toBe('=HYPERLINK("x")')
        ->and($sheet->getCell('E18')->getDataType())->toBe(DataType::TYPE_STRING)
        ->and($sheet->getCell('M17')->getValue())->toBe('Rita')
        ->and($sheet->getMergeCells())->toHaveKey('E17:K17')
        ->and($sheet->getCell('E21')->getValue())->toBe('Total mails received: 3');
});

it('exports every month on file as separate sheets', function () {
    ReceivedMail::factory()->create(['received_on' => '2026-08-31']);
    ReceivedMail::factory()->create(['received_on' => '2026-09-01']);

    $response = $this->get('/received-mails/export?month=all')->assertOk();
    $path = tempnam(sys_get_temp_dir(), 'export').'.xlsx';
    file_put_contents($path, $response->streamedContent());

    expect(IOFactory::load($path)->getSheetNames())->toBe(['AUGUST 2026', 'SEPTEMBER 2026']);
});

it('prints a month', function () {
    ReceivedMail::factory()->create(['received_on' => '2026-09-01', 'tracking_number' => 'RL 1']);

    $this->get('/received-mails/print?month=2026-09')->assertOk()->assertSee('RECEIVED MAILS')->assertSee('SEPTEMBER 2026')->assertSee('Total mails received: 1');
    $this->get('/received-mails/print?month=all')->assertNotFound();
});

it('imports the office layout, carrying each date down to the rows below it', function () {
    $check = stageMailImport(mailLog(['SEPTEMBER 2026' => [
        [null, '2026-09-01', 'RL 217 180 939 ZZ', 'UM', 'Statement of absences ao August 2026'],
        [null, null, 'RL 172 154 874 ZZ', 'TORIL', 'Leave application of Ms. Maja'],
        [null, null, '#918', 'CALINAN', 'Leave application of Mr. Olmoguez'],
        [null, '2026-09-02', 'RL 010 206 450 ZZ', 'COTABATO', 'DTR for wages'],
        [null, null, null, null, null],
    ]]))->assertOk()->assertSee('4 new');

    expect(ReceivedMail::query()->count())->toBe(0);

    $this->post('/received-mails/import/commit', ['token' => $check->viewData('token')])->assertRedirect();

    $mails = ReceivedMail::query()->orderBy('id')->get();

    expect($mails->pluck('received_on')->map->toDateString()->all())->toBe(['2026-09-01', '2026-09-01', '2026-09-01', '2026-09-02'])
        ->and($mails[2]->tracking_number)->toBe('#918')
        ->and($mails[0]->created_by)->toBe($this->hr->id)
        ->and(ImportJob::query()->sole()->module)->toBe('Received Mails')
        ->and(ImportJob::query()->sole()->created)->toBe(4)
        ->and(AuditLog::query()->where('action', 'import')->where('collection_slug', 'received-mails')->count())->toBe(1);

    $this->post('/received-mails/import/commit', ['token' => $check->viewData('token')])->assertSessionHas('flash.type', 'error');
});

it('skips mail already logged and identical rows repeated in the file', function () {
    ReceivedMail::factory()->create(['received_on' => '2026-09-01', 'tracking_number' => 'RL 1', 'post_office' => 'UM', 'description' => 'First']);

    $response = stageMailImport(mailLog(['SEPTEMBER 2026' => [
        [null, '2026-09-01', 'RL 1', 'um', 'first'],
        [null, null, 'RL 2', 'TORIL', 'Second'],
        [null, null, 'RL 2', 'TORIL', 'Second'],
        [null, null, 'RL 2', 'TORIL', 'Different description'],
    ]]))->assertOk();

    $plan = $response->viewData('plan');

    expect($plan['sheets']['SEPTEMBER 2026'])->toMatchArray(['new' => 2, 'existing' => 1])
        ->and(implode(' ', $plan['warnings']))->toContain('1 row(s) were exactly the same');

    $this->post('/received-mails/import/commit', ['token' => $response->viewData('token')]);

    expect(ReceivedMail::query()->count())->toBe(3);
});

it('reads several months from one file and dates early rows from the sheet name', function () {
    $response = stageMailImport(mailLog([
        'SEPTEMBER 2026' => [[null, null, 'RL 1', 'UM', 'No date above']],
        'August 2026' => [[null, '2026-08-05', 'RL 2', 'TORIL', 'Dated']],
    ]))->assertOk();

    $plan = $response->viewData('plan');

    expect($plan['rows'][0]['received_on'])->toBe('2026-09-01')
        ->and($plan['rows'][1]['received_on'])->toBe('2026-08-05')
        ->and(implode(' ', $plan['warnings']))->toContain('dated the 1st of the month');
});

it('accepts Excel date numbers and a header row somewhere else', function () {
    $response = stageMailImport(mailLog(['OCTOBER 2026' => [[null, 46297, 'RL 1', 'UM', 'Serial date']]], headerRow: 9))->assertOk();

    expect($response->viewData('plan')['errors'])->toBe([])
        ->and($response->viewData('plan')['rows'][0]['received_on'])->toBe('2026-10-02');
});

it('lists every problem and saves nothing', function () {
    $response = stageMailImport(mailLog(['SEPTEMBER 2026' => [
        [null, '2026-09-01', 'RL 1', 'UM', 'Fine'],
        [null, 'soon', 'RL 2', 'UM', 'Bad date'],
        [null, null, '', 'UM', 'No tracking'],
        [null, null, 'RL 4', 'UM', str_repeat('x', 2001)],
    ]]))->assertOk();

    expect($response->viewData('plan')['errors'])->toHaveCount(3);

    $response->assertSee('is not a recognised date')->assertSee('is required')->assertSee('must be at most 2000 characters');

    $this->post('/received-mails/import/commit', ['token' => $response->viewData('token')]);
    expect(ReceivedMail::query()->count())->toBe(0);
});

it('refuses a sheet with no header and files that are not workbooks', function () {
    stageMailImport(mailLog(['SEPTEMBER 2026' => [[null, '2026-09-01', 'RL 1', 'UM', 'Fine']]], headerRow: 60))->assertOk()->assertSee('No sheet in this file looks like a mail log');

    $this->post('/received-mails/import')->assertSessionHas('flash.type', 'error');
    $this->post('/received-mails/import', ['file' => UploadedFile::fake()->create('a.xls', 5)])->assertSessionHas('flash.type', 'error');
    $this->post('/received-mails/import', ['file' => UploadedFile::fake()->createWithContent('a.xlsx', str_repeat('text', 20))])->assertOk()->assertSee('not a valid Excel workbook');
});

it('keeps a staged upload to the person who made it', function () {
    $token = stageMailImport(mailLog(['SEPTEMBER 2026' => [[null, '2026-09-01', 'RL 1', 'UM', 'Fine']]]))->viewData('token');

    signIn(User::factory()->create());
    $this->post('/received-mails/import/commit', ['token' => $token])->assertSessionHas('flash.type', 'error');

    expect(ReceivedMail::query()->count())->toBe(0)->and(app(PendingImports::class))->not->toBeNull();
});

it('round trips its own export through the import', function () {
    ReceivedMail::factory()->create(['received_on' => '2026-09-01', 'tracking_number' => 'RL 1', 'post_office' => 'UM', 'description' => 'First']);
    ReceivedMail::factory()->create(['received_on' => '2026-09-01', 'tracking_number' => '#918', 'post_office' => 'TORIL', 'description' => 'Second', 'remarks' => 'Urgent', 'received_by' => 'Rita']);
    ReceivedMail::factory()->create(['received_on' => '2026-09-04', 'tracking_number' => 'RL 3', 'post_office' => 'TAGUM', 'description' => 'Third']);

    $path = tempnam(sys_get_temp_dir(), 'export').'.xlsx';
    (new Xlsx(app(ReceivedMailsWorkbook::class)->export(['2026-09'])))->save($path);

    $plan = app(ReceivedMailsWorkbook::class)->analyze($path);

    expect($plan['errors'])->toBe([])
        ->and($plan['sheets']['SEPTEMBER 2026'])->toMatchArray(['rows' => 3, 'new' => 0, 'existing' => 3]);

    ReceivedMail::query()->delete();

    $again = app(ReceivedMailsWorkbook::class)->analyze($path);
    app(ReceivedMailsWorkbook::class)->commit($again, $this->hr, 'back.xlsx');

    expect(ReceivedMail::query()->orderBy('id')->get()->map(fn (ReceivedMail $mail): array => [$mail->received_on->toDateString(), $mail->tracking_number, $mail->post_office, $mail->description, $mail->remarks, $mail->received_by])->all())
        ->toBe([['2026-09-01', 'RL 1', 'UM', 'First', null, null], ['2026-09-01', '#918', 'TORIL', 'Second', 'Urgent', 'Rita'], ['2026-09-04', 'RL 3', 'TAGUM', 'Third', null, null]]);
});

it('adds the page to the sidebar and shows the log form', function () {
    $this->get('/received-mails/new')->assertOk()->assertSee('Log received mail')->assertSee('Rita Santos');
    $this->get('/received-mails')->assertSee('Received Mails');
});

it('is closed to people who are not signed in', function () {
    auth()->logout();
    $this->flushSession();

    $this->get('/received-mails')->assertRedirectContains('/login');
    $this->post('/received-mails', [])->assertUnauthorized();
});
