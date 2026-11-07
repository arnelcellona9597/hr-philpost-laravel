<?php

use App\Enums\ApplicationStatus;
use App\Enums\EmploymentStatus;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Certificate;
use App\Models\Employee;
use App\Models\IpcrRating;
use App\Models\ItrSubmission;
use App\Models\Media;
use App\Models\Message;
use App\Models\Payslip;
use App\Models\PdsSubmission;
use App\Models\SiteSetting;
use App\Models\SwornDeclaration;
use App\Models\User;
use App\Models\WellnessLeave;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    signIn(User::factory()->create());
});

function employeeForm(array $overrides = []): array
{
    return [
        'last_name' => 'Dela Cruz',
        'first_name' => 'Juan',
        'gender' => 'Male',
        'employee_id' => 'EMP-1',
        'classification' => 'Regular',
        'employment_status' => 'Active',
        ...$overrides,
    ];
}

function searchIds(string $query): array
{
    return test()->get("/employees?{$query}")->assertSuccessful()->viewData('employees')->pluck('employee_id')->all();
}

function photo(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('me.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
}

it('adds an employee with canonical government IDs', function () {
    $branch = Branch::factory()->create();

    $this->post('/employees', employeeForm(['station' => $branch->id, 'sss' => '1234567890', 'tin' => '123 456 789']))->assertRedirect();

    $employee = Employee::query()->firstOrFail();

    expect($employee->full_name)->toBe('Dela Cruz, Juan')
        ->and($employee->sss)->toBe('12-3456789-0')
        ->and($employee->tin)->toBe('123-456-789')
        ->and($employee->station_id)->toBe($branch->id);
});

it('checks every field rule', function (array $overrides, string $field, string $message) {
    $this->from('/employees/new')->post('/employees', employeeForm($overrides))->assertSessionHasErrors([$field => $message]);

    expect(Employee::query()->count())->toBe(0);
})->with([
    [['last_name' => ''], 'last_name', 'Last Name is required'],
    [['gender' => 'Other'], 'gender', 'Choose a valid Gender'],
    [['date_of_birth' => '2023-02-29'], 'date_of_birth', 'Date of Birth must be a valid date'],
    [['date_of_birth' => '2099-01-01'], 'date_of_birth', 'Date of birth cannot be in the future'],
    [['date_hired' => '2024-01-02', 'last_day_of_service' => '2024-01-01'], 'last_day_of_service', 'Last day of service cannot be before the date hired'],
    [['sss' => '123'], 'sss', 'SSS Number must have 10 digits (got 3)'],
    [['email' => 'nope'], 'email', 'Enter a valid email address'],
    [['contact_number' => 'call me'], 'contact_number', 'Enter a valid contact number (digits, spaces, + - ( ) only)'],
]);

it('refuses a duplicate Employee ID but keeps a record its own', function () {
    $existing = Employee::factory()->create(['employee_id' => 'EMP-1']);

    $this->from('/employees/new')->post('/employees', employeeForm())->assertSessionHasErrors(['employee_id' => 'That Employee ID is already used.']);

    $this->put("/employees/{$existing->id}", employeeForm())->assertRedirect("/employees/{$existing->id}");
});

it('updates only what the form sent', function () {
    $employee = Employee::factory()->create(['religion' => 'Keep me']);

    $this->put("/employees/{$employee->id}", employeeForm(['position' => 'Clerk']));

    expect($employee->fresh()->position)->toBe('Clerk')
        ->and($employee->fresh()->religion)->toBe('Keep me');
});

it('stores a photo by content and refuses others', function () {
    $this->post('/employees', employeeForm(['photo' => photo()]));

    $employee = Employee::query()->firstOrFail();

    expect($employee->profilePicture->mime_type)->toBe('image/png')
        ->and($employee->profilePicture->is_public)->toBeFalse();

    $this->from('/employees/new')->post('/employees', employeeForm([
        'employee_id' => 'EMP-2',
        'photo' => UploadedFile::fake()->createWithContent('me.png', '<?php echo 1;'),
    ]))->assertSessionHasErrors('photo');

    expect(Employee::query()->count())->toBe(1);
});

it('deletes the old photo when replacing or removing it', function () {
    $this->post('/employees', employeeForm(['photo' => photo()]));
    $employee = Employee::query()->firstOrFail();
    $first = $employee->profile_picture_id;

    $this->put("/employees/{$employee->id}", employeeForm(['photo' => photo()]));

    expect(Media::query()->find($first))->toBeNull()
        ->and($employee->fresh()->profile_picture_id)->not->toBe($first);

    $this->put("/employees/{$employee->id}", employeeForm(['removePhoto' => 'on']));

    expect($employee->fresh()->profile_picture_id)->toBeNull()
        ->and(Media::query()->count())->toBe(0);
});

it('removes the photo with the employee', function () {
    $this->post('/employees', employeeForm(['photo' => photo()]));

    $this->delete('/employees/'.Employee::query()->firstOrFail()->id)->assertRedirect('/employees');

    expect(Media::query()->count())->toBe(0);
});

it('refuses to delete an employee with any linked record', function (string $factory) {
    $employee = Employee::factory()->create();
    $factory::factory()->for($employee)->create();

    $this->delete("/employees/{$employee->id}")->assertRedirect("/employees/{$employee->id}")->assertSessionHas('flash.type', 'error');

    expect(session('flash.message'))->toContain('Set the employment status to Resigned, Retired or Terminated')
        ->and($employee->fresh())->not->toBeNull();
})->with([
    WellnessLeave::class,
    ItrSubmission::class,
    SwornDeclaration::class,
    PdsSubmission::class,
    IpcrRating::class,
    Payslip::class,
    Certificate::class,
    Application::class,
]);

it('deletes an employee with nothing linked', function () {
    $employee = Employee::factory()->create();

    $this->delete("/employees/{$employee->id}")->assertRedirect('/employees');

    expect($employee->fresh())->toBeNull();
});

it('keeps sent mail when a recipient employee is deleted', function () {
    $employee = Employee::factory()->create();
    $recipient = Message::factory()->create()->recipients()->create(['email' => 'a@example.test', 'employee_id' => $employee->id]);

    $this->delete("/employees/{$employee->id}");

    expect($recipient->fresh()->employee_id)->toBeNull();
});

it('finds people by any word across name, ID and government numbers', function () {
    Employee::factory()->create(['employee_id' => 'A1', 'last_name' => 'Santos', 'first_name' => 'Maria', 'tin' => '111-222-333']);
    Employee::factory()->create(['employee_id' => 'B2', 'last_name' => 'Reyes', 'first_name' => 'Maria']);

    expect(searchIds('q=maria'))->toHaveCount(2)
        ->and(searchIds('q=santos+maria'))->toBe(['A1'])
        ->and(searchIds('q=111-222'))->toBe(['A1'])
        ->and(searchIds('q=zzz'))->toBe([]);
});

it('treats LIKE wildcards in a search as plain text', function () {
    Employee::factory()->create(['employee_id' => 'A1']);

    expect(searchIds('q=%25'))->toBe([])->and(searchIds('q=_'))->toBe([]);
});

it('filters by each list', function (array $attributes, string $query) {
    Employee::factory()->create(['employee_id' => 'HIT', ...$attributes]);
    Employee::factory()->create(['employee_id' => 'MISS', 'gender' => 'Female', 'classification' => 'COS', 'employment_status' => 'Retired', 'civil_status' => 'Widowed', 'blood_type' => 'O+', 'region' => 'CAR', 'educational_attainment' => 'Doctoral Degree', 'position' => 'Driver']);

    expect(searchIds($query))->toBe(['HIT']);
})->with([
    'status' => [['employment_status' => 'Active'], 'status[]=Active'],
    'classification' => [['classification' => 'Regular'], 'classification[]=Regular'],
    'gender' => [['gender' => 'Male'], 'gender[]=Male'],
    'region' => [['region' => 'NCR'], 'region[]=NCR'],
    'civil status' => [['civil_status' => 'Single'], 'civilStatus[]=Single'],
    'blood type' => [['blood_type' => 'A+'], 'bloodType[]=A%2B'],
    'education' => [['educational_attainment' => 'College Graduate'], 'education[]=College+Graduate'],
    'position' => [['position' => 'Letter Carrier'], 'position=carrier'],
]);

it('accepts a single value from a plain link', function () {
    Employee::factory()->create(['employee_id' => 'A1', 'employment_status' => EmploymentStatus::Active]);
    Employee::factory()->create(['employee_id' => 'B2', 'employment_status' => EmploymentStatus::Resigned]);

    expect(searchIds('status=Resigned'))->toBe(['B2']);
});

it('filters by branch', function () {
    $branch = Branch::factory()->create();
    Employee::factory()->for($branch, 'station')->create(['employee_id' => 'A1']);
    Employee::factory()->create(['employee_id' => 'B2']);

    expect(searchIds("branch={$branch->id}"))->toBe(['A1']);
});

it('filters by hire date, age and length of service', function () {
    $this->travelTo('2026-06-15');
    Employee::factory()->create(['employee_id' => 'YOUNG', 'date_of_birth' => '2000-06-15', 'date_hired' => '2024-06-15']);
    Employee::factory()->create(['employee_id' => 'OLD', 'date_of_birth' => '1970-06-16', 'date_hired' => '2000-01-01']);

    expect(searchIds('hiredFrom=2020-01-01'))->toBe(['YOUNG'])
        ->and(searchIds('hiredTo=2020-01-01'))->toBe(['OLD'])
        ->and(searchIds('ageMin=26&ageMax=26'))->toBe(['YOUNG'])
        ->and(searchIds('ageMin=55&ageMax=55'))->toBe(['OLD'])
        ->and(searchIds('losMin=2&losMax=2'))->toBe(['YOUNG'])
        ->and(searchIds('losMin=26'))->toBe(['OLD']);
});

it('counts active filters and ignores junk values', function () {
    $search = $this->get('/employees?status[]=Active&status[]=Nope&ageMin=abc&hiredFrom=bad&position=x')->viewData('search');

    expect($search->status)->toBe(['Active'])
        ->and($search->ageMin)->toBeNull()
        ->and($search->activeFilterCount())->toBe(2);
});

it('sorts only by allowed columns', function () {
    Employee::factory()->create(['employee_id' => 'A', 'last_name' => 'Zed']);
    Employee::factory()->create(['employee_id' => 'B', 'last_name' => 'Abel']);

    expect(searchIds(''))->toBe(['B', 'A'])
        ->and(searchIds('sort=-employeeId'))->toBe(['B', 'A'])
        ->and(searchIds('sort=employeeId'))->toBe(['A', 'B'])
        ->and(searchIds('sort=password'))->toBe(['B', 'A']);
});

it('paginates at the allowed sizes and keeps the filters', function () {
    Employee::factory()->count(26)->create(['employment_status' => 'Active']);

    $page = $this->get('/employees?status[]=Active')->assertSee('status%5B0%5D=Active', false);

    expect($page->viewData('employees')->count())->toBe(25)
        ->and($this->get('/employees?limit=7')->viewData('search')->limit)->toBe(25)
        ->and($this->get('/employees?limit=50')->viewData('employees')->count())->toBe(26);
});

it('shows the profile with computed details and balance', function () {
    $employee = Employee::factory()->create(['sss' => '12-3456789-0']);
    WellnessLeave::factory()->for($employee)->create(['days_by_year' => [(string) now()->year => 2]]);

    $this->get("/employees/{$employee->id}")->assertSuccessful()
        ->assertSee('12-3456789-0')
        ->assertSee('Government IDs')
        ->assertViewHas('remaining', 3);
});

it('shows requirements with their status on the profile', function () {
    $this->travelTo('2026-06-01');
    $employee = Employee::factory()->create();
    ItrSubmission::factory()->for($employee)->create(['year' => 2026, 'date_submitted' => '2026-05-02']);
    IpcrRating::factory()->for($employee)->create(['year' => 2025, 'rating' => 4.5, 'date_submitted' => '2025-07-01']);

    $this->get("/employees/{$employee->id}")->assertSee('Late')->assertSee('4.50')->assertDontSee('4.500');
});

it('shows change history already recorded', function () {
    $employee = Employee::factory()->create();
    AuditLog::factory()->create(['doc_id' => (string) $employee->id, 'user_name' => 'Ana', 'changes' => ['position' => ['from' => 'Clerk', 'to' => 'Officer']]]);

    $this->get("/employees/{$employee->id}")->assertSee('Ana')->assertSee('Position / Designation')->assertSee('Officer');
});

it('escapes employee text', function () {
    $employee = Employee::factory()->create(['position' => '<script>alert(1)</script>']);

    $this->get("/employees/{$employee->id}")->assertDontSee('<script>alert(1)</script>', false);
});

it('answers 404 for a missing employee', function () {
    $this->get('/employees/999')->assertNotFound();
});

it('pre-fills the form from an application', function () {
    $branch = Branch::factory()->create();
    $application = Application::factory()->for($branch, 'station')->create(['applicant_name' => 'Maria Clara Santos', 'position_applied' => 'Clerk', 'start_ojt' => '2026-07-01', 'date_applied' => '2026-06-01']);

    $values = $this->get("/employees/new?fromApplication={$application->id}")->assertSuccessful()->viewData('values');

    expect($values['last_name'])->toBe('Santos')
        ->and($values['first_name'])->toBe('Maria Clara')
        ->and($values['position'])->toBe('Clerk')
        ->and($values['station'])->toBe((string) $branch->id)
        ->and($values['date_hired'])->toBe('2026-07-01');
});

it('converts an applicant in one step', function () {
    $application = Application::factory()->create();

    $this->post("/employees?fromApplication={$application->id}", employeeForm())->assertRedirect();

    $application->refresh();

    expect($application->employee_id)->toBe(Employee::query()->firstOrFail()->id)
        ->and($application->application_status)->toBe(ApplicationStatus::Hired)
        ->and($application->hired_at)->not->toBeNull();
});

it('refuses to convert an applicant twice', function () {
    $application = Application::factory()->for(Employee::factory())->create();

    $this->post("/employees?fromApplication={$application->id}", employeeForm())->assertRedirect("/onboarding/{$application->id}");

    expect(Employee::query()->count())->toBe(1);
});

it('prints the whole employee profile on a page of its own', function () {
    $branch = Branch::factory()->create(['name' => 'Sample Post Office']);
    $employee = Employee::factory()->create(['last_name' => 'Reyes', 'first_name' => 'Ana', 'middle_name' => null, 'position' => 'Clerk', 'station_id' => $branch->id, 'date_hired' => '2020-03-02', 'tin' => '123-456-789-000', 'address' => '12 Sample St.']);

    $this->get("/employees/{$employee->id}")->assertOk()->assertSee("/employees/{$employee->id}/print");

    $this->get("/employees/{$employee->id}/print")->assertOk()
        ->assertSee('EMPLOYEE PROFILE')
        ->assertSee('Reyes, Ana')
        ->assertSee('Sample Post Office')
        ->assertSee('123-456-789-000')
        ->assertSee('12 Sample St.')
        ->assertSee('Employment history')
        ->assertSee('Mar 2, 2020')
        ->assertDontSee('Correct this period')
        ->assertDontSee('Delete');
});

it('keeps the printed profile away from signed-out visitors', function () {
    $employee = Employee::factory()->create();
    auth()->logout();

    $this->get("/employees/{$employee->id}/print")->assertRedirectContains('/login');
});

it('prints the profile under the letterhead from the branding settings', function () {
    SiteSetting::current()->update([
        'letterhead_name' => "SAMPLE POSTAL CORPORATION\nSAMPLE AREA OFFICE",
        'letterhead_details' => "1 Sample Avenue, Sample City\nhr@example.test",
        'letterhead_section' => 'Human Resource Management Section',
    ]);
    $employee = Employee::factory()->create();

    $this->get("/employees/{$employee->id}/print")->assertOk()
        ->assertSeeInOrder(['Republic of the Philippines', 'SAMPLE POSTAL CORPORATION', 'SAMPLE AREA OFFICE', '1 Sample Avenue, Sample City', 'hr@example.test', 'Human Resource Management Section', 'EMPLOYEE PROFILE']);
});

it('falls back to the company name when no letterhead is set', function () {
    SiteSetting::current()->update(['company_name' => 'Sample Company']);
    $employee = Employee::factory()->create();

    $this->get("/employees/{$employee->id}/print")->assertOk()->assertSee('SAMPLE COMPANY');
});

it('stores bank account details and shows them on the profile and printout', function () {
    $bank = ['bank_name' => 'Sample Bank', 'bank_branch' => 'Sample City', 'bank_account_name' => 'Juan Dela Cruz', 'bank_account_number' => '0012-3456 78'];

    $this->post('/employees', employeeForm($bank))->assertRedirect();

    $employee = Employee::query()->where('employee_id', 'EMP-1')->sole();

    expect($employee->only(array_keys($bank)))->toBe($bank);

    $this->get("/employees/{$employee->id}")->assertOk()->assertSee('Bank Account')->assertSee('0012-3456 78');
    $this->get("/employees/{$employee->id}/print")->assertOk()->assertSee('Sample Bank')->assertSee('0012-3456 78');
    $this->get("/employees/{$employee->id}/edit")->assertOk()->assertSee('name="bank_account_number"', false);
    expect(searchIds('0012-3456'))->toBe(['EMP-1']);
});

it('leaves bank details optional and refuses a malformed account number', function (string $number) {
    $this->from('/employees/new')->post('/employees', employeeForm(['bank_account_number' => $number]))
        ->assertSessionHasErrors(['bank_account_number' => 'Enter the bank account number using digits, spaces and hyphens only']);

    expect(Employee::query()->count())->toBe(0);
    $this->post('/employees', employeeForm())->assertSessionHasNoErrors();
})->with(['letters' => 'ABC123', 'symbols' => '12/34']);

it('prints every profile that matches the filters, one per page', function () {
    $branch = Branch::factory()->create();
    $inBranch = Employee::factory()->count(3)->create(['station_id' => $branch->id, 'classification' => 'COS']);
    Employee::factory()->create(['classification' => 'Regular', 'last_name' => 'Outsider']);

    $page = $this->get('/employees/print?classification[]=COS')->assertOk();

    expect(substr_count($page->getContent(), 'EMPLOYEE PROFILE</h1>'))->toBe(3);

    foreach ($inBranch as $employee) {
        $page->assertSee(mb_strtoupper($employee->full_name));
    }

    $page->assertDontSee('OUTSIDER');
});

it('follows the search text when printing profiles', function () {
    Employee::factory()->create(['last_name' => 'Abuomar', 'first_name' => 'Razul']);
    Employee::factory()->create(['last_name' => 'Santos', 'first_name' => 'Ana']);

    $page = $this->get('/employees/print?q=Abuomar')->assertOk();

    expect(substr_count($page->getContent(), 'EMPLOYEE PROFILE</h1>'))->toBe(1);
    $page->assertSee('ABUOMAR, RAZUL')->assertDontSee('SANTOS');
});

it('says so instead of printing nothing when no employee matches', function () {
    Employee::factory()->create(['last_name' => 'Santos']);

    $this->get('/employees/print?q=Nobodyatall')->assertRedirect('/employees?q=Nobodyatall')->assertSessionHas('flash.type', 'error');
});

it('offers a Print profiles button on the employee list with the matching count', function () {
    Employee::factory()->count(2)->create();

    $this->get('/employees')->assertOk()->assertSee('Print profiles (2)', false)->assertSee('/employees/print', false);
});

it('keeps the single profile print working', function () {
    $employee = Employee::factory()->create(['last_name' => 'Abuomar', 'first_name' => 'Razul']);

    $this->get("/employees/{$employee->id}/print")->assertOk()->assertSee('EMPLOYEE PROFILE')->assertSee('ABUOMAR, RAZUL');
});
