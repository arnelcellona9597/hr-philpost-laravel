<?php

namespace Database\Seeders;

use App\Enums\CertificateStatus;
use App\Enums\Classification;
use App\Enums\ImportJobStatus;
use App\Enums\IpcrPeriod;
use App\Enums\LeaveStatus;
use App\Enums\MessageStatus;
use App\Enums\OnboardingRequirement;
use App\Enums\PayChangeType;
use App\Enums\PayrollStatus;
use App\Enums\PositionChangeType;
use App\Enums\RateType;
use App\Enums\Region;
use App\Enums\RemittanceKind;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Exceptions\UserFacingException;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\CertificateTemplate;
use App\Models\ContractSetting;
use App\Models\ContractTemplate;
use App\Models\Employee;
use App\Models\EmploymentPeriod;
use App\Models\Holiday;
use App\Models\ImportJob;
use App\Models\IpcrRating;
use App\Models\ItrSubmission;
use App\Models\Message;
use App\Models\Notification;
use App\Models\PayChange;
use App\Models\PayrollPeriod;
use App\Models\Payslip;
use App\Models\PdsSubmission;
use App\Models\PositionChange;
use App\Models\ReceivedMail;
use App\Models\RemittanceSetting;
use App\Models\RequirementDeadlineSetting;
use App\Models\SwornDeclaration;
use App\Models\User;
use App\Models\VoucherSetting;
use App\Models\WageOrder;
use App\Models\WellnessLeave;
use App\Services\CertificateService;
use App\Services\ContractService;
use App\Services\LeaveService;
use App\Services\PayHistoryService;
use App\Services\PayrollService;
use App\Services\RemittanceService;
use App\Support\AuditTrail;
use App\Support\GovId;
use App\Support\HrDate;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Sample data for development and demos: `php artisan db:seed --class=DemoDataSeeder`. It never
 * runs in production or over existing employees, and every run produces the same people. Addresses
 * use example.test and message history is written as already delivered, so no email can go out.
 */
class DemoDataSeeder extends Seeder
{
    /**
     * How many employees to create. The other records scale with it.
     */
    public int $employeeCount = 1000;

    private const LAST_NAMES = ['Santos', 'Reyes', 'Cruz', 'Bautista', 'Ocampo', 'Garcia', 'Mendoza', 'Torres', 'Villanueva', 'Ramos', 'Aquino', 'Castillo', 'Rivera', 'Flores', 'Dela Cruz', 'Gonzales', 'Navarro', 'Salazar', 'Pascual', 'Domingo', 'Mercado', 'Aguilar', 'Soriano', 'Lim', 'Tan'];

    private const MALE_NAMES = ['Juan', 'Jose', 'Mark', 'John Paul', 'Christian', 'Rafael', 'Miguel', 'Carlo', 'Paolo', 'Angelo', 'Ramon', 'Eduardo', 'Noel', 'Ronald', 'Jerome'];

    private const FEMALE_NAMES = ['Maria', 'Ana', 'Kristine', 'Jasmine', 'Angelica', 'Patricia', 'Camille', 'Rose', 'Joy', 'Grace', 'Michelle', 'Liza', 'Carmela', 'Bea', 'Andrea'];

    private const POSITIONS = [
        'Letter Carrier' => 12,
        'Postal Clerk' => 10,
        'Administrative Aide I' => 8,
        'Mail Sorter' => 6,
        'Administrative Assistant II' => 6,
        'Driver I' => 4,
        'Postmaster I' => 3,
        'Security Guard I' => 3,
        'Administrative Officer IV' => 3,
        'Accountant III' => 2,
        'Information Systems Analyst II' => 2,
        'Records Officer I' => 2,
    ];

    private const BRANCHES = [
        ['Central Office', 'CO', 'Liwasang Bonifacio, Manila', 'NCR'],
        ['Quezon City Post Office', 'QC', 'Quezon City', 'NCR'],
        ['Makati Post Office', 'MKT', 'Makati City', 'NCR'],
        ['Baguio Post Office', 'BAG', 'Baguio City', 'CAR'],
        ['San Fernando Post Office', 'SFP', 'San Fernando, Pampanga', 'Region III'],
        ['Calamba Post Office', 'CLB', 'Calamba, Laguna', 'Region IV-A'],
        ['Batangas Post Office', 'BTG', 'Batangas City', 'Region IV-A'],
        ['Legazpi Post Office', 'LGZ', 'Legazpi City', 'Region V'],
        ['Iloilo Post Office', 'ILO', 'Iloilo City', 'Region VI'],
        ['Cebu Post Office', 'CEB', 'Cebu City', 'Region VII'],
        ['Cagayan de Oro Post Office', 'CDO', 'Cagayan de Oro City', 'Region X'],
        ['Davao Post Office', 'DVO', 'Davao City', 'Region XI'],
    ];

    private const HOLIDAYS = [
        '01-01' => "New Year's Day",
        '04-09' => 'Araw ng Kagitingan',
        '05-01' => 'Labor Day',
        '06-12' => 'Independence Day',
        '11-30' => 'Bonifacio Day',
        '12-25' => 'Christmas Day',
        '12-30' => 'Rizal Day',
    ];

    private ?string $today = null;

    private int $year;

    public function __construct(
        private LeaveService $leave,
        private PayrollService $payroll,
        private CertificateService $certificates,
    ) {}

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Refusing to add demo data in production.');
        }

        if (Employee::query()->exists()) {
            $this->command?->warn('Employees already exist, so no demo data was added.');

            return;
        }

        mt_srand(20261004);

        $this->today = HrDate::today();
        $this->year = (int) substr($this->today, 0, 4);

        DB::transaction(fn () => AuditTrail::withoutRecording(function (): void {
            $staff = $this->staff();
            $this->holidays();
            $branches = $this->branches();
            $employees = $this->employees($branches);
            $this->earlierPeriods($employees);
            $active = $employees->where('employment_status.value', 'Active')->values();

            $this->wellnessLeave($active);
            $this->requirements($active);
            $this->applications($branches);
            $released = $this->payrollPeriods($staff[0]);
            $this->certificatesFor($active, $employees, $staff[0]);
            $this->messageHistory($active, $released, $staff[0]);
            $this->notifications($released);
            $this->activity($staff[0], $active, $released);
            $this->importHistory($staff[0]);
            $this->receivedMails($branches, $staff[0]);
            $this->wagesAndCareers($employees->values());
            $this->remittances($staff[0]);
            $this->contracts($staff[0]);
        }));

        $this->command?->info("Added {$this->employeeCount} demo employees and their records.");
    }

    /**
     * People shown as the "who" in activity, certificates and messages. Nobody knows their passwords.
     *
     * @return list<User>
     */
    private function staff(): array
    {
        return array_map(function (array $person): User {
            $user = new User(['name' => $person[0], 'email' => $person[1], 'password' => Str::random(40).'9a']);
            $user->forceFill(['role' => Role::HrStaff, 'status' => UserStatus::Approved, 'email_verified_at' => now()])->save();

            return $user;
        }, [['Kristine Ramos', 'kristine.ramos@example.test'], ['Paolo Mendoza', 'paolo.mendoza@example.test']]);
    }

    private function holidays(): void
    {
        foreach ([$this->year - 1, $this->year] as $year) {
            foreach (self::HOLIDAYS as $monthDay => $name) {
                Holiday::query()->firstOrCreate(['date' => "{$year}-{$monthDay}"], ['name' => $name, 'type' => 'Regular Holiday']);
            }
        }

        // A changed deadline, so the deadlines page shows a year-specific override.
        RequirementDeadlineSetting::current()->update([
            'overrides' => [['requirement' => 'sworn-declarations', 'period' => null, 'year' => $this->year - 1, 'deadline' => "{$this->year}-05-15"]],
        ]);
    }

    /**
     * @return Collection<int, Branch>
     */
    private function branches(): Collection
    {
        return new Collection(array_map(fn (array $branch): Branch => Branch::query()->create([
            'name' => $branch[0],
            'code' => $branch[1],
            'address' => $branch[2],
            'region' => $branch[3],
            'contact_number' => '(02) 8'.$this->digits(3).'-'.$this->digits(4),
        ]), self::BRANCHES));
    }

    /**
     * @param  Collection<int, Branch>  $branches
     * @return Collection<int, Employee>
     */
    private function employees(Collection $branches): Collection
    {
        $employees = new Collection;

        for ($number = 1; $number <= $this->employeeCount; $number++) {
            $female = $this->chance(0.48);
            $firstName = $this->pick($female ? self::FEMALE_NAMES : self::MALE_NAMES);
            $lastName = $this->pick(self::LAST_NAMES);
            $branch = $branches[mt_rand(0, $branches->count() - 1)];
            $hired = min($this->date(mt_rand($this->year - 18, $this->year)), HrDate::addDays($this->today, -mt_rand(1, 30)));
            $roll = $this->roll();
            $status = match (true) {
                $roll < 0.78 => 'Active',
                $roll < 0.88 => 'Resigned',
                $roll < 0.95 => 'Retired',
                default => 'Terminated',
            };
            $classRoll = $this->roll();

            $employees->push(Employee::query()->create([
                'employee_id' => sprintf('EMP-%05d', $number),
                'last_name' => $lastName,
                'first_name' => $firstName,
                'middle_name' => $this->pick(self::LAST_NAMES),
                'extension' => ! $female && $this->chance(0.06) ? 'Jr.' : null,
                'gender' => $female ? 'Female' : 'Male',
                'date_of_birth' => $this->date(mt_rand(1965, 2002)),
                'civil_status' => $this->pick(['Single', 'Married', 'Widowed']),
                'religion' => $this->chance(0.8) ? 'Roman Catholic' : $this->pick(['Iglesia ni Cristo', 'Born Again Christian', 'Islam', 'Seventh-day Adventist']),
                'blood_type' => $this->pick(['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-']),
                'professional_eligibility' => $this->pick(['Career Service Professional', 'Career Service Sub-Professional', null, null]),
                'contact_number' => '09'.$this->digits(9),
                'address' => mt_rand(1, 999).' '.$this->pick(['Rizal St.', 'Mabini St.', 'Bonifacio Ave.', 'Luna St.']).', '.$branch->address,
                'email' => Str::slug("{$firstName} {$lastName}", '.').".{$number}@example.test",
                'educational_attainment' => $this->pick(['Vocational / Technical', 'College Undergraduate', 'College Graduate', 'College Graduate', "Master's Units"]),
                'courses' => $this->pick(['BS Business Administration', 'BS Accountancy', 'BS Information Technology', 'AB Political Science', 'BS Office Administration']),
                'station_id' => $branch->id,
                'region' => $branch->region,
                'position' => $this->weightedPosition(),
                'nature_of_work' => $this->pick(['Office-based', 'Field work', 'Mixed']),
                'date_hired' => $hired,
                'last_day_of_service' => $status === 'Active' ? null : $this->lastDay($hired),
                'classification' => $classRoll < 0.25 ? 'COS' : ($classRoll < 0.45 ? 'Contractual' : 'Regular'),
                'employment_status' => $status,
                'sss' => GovId::format('sss', $this->digits(10)),
                'pagibig' => GovId::format('pagibig', $this->digits(12)),
                'tin' => GovId::format('tin', $this->digits(9).'000'),
                'philhealth' => GovId::format('philhealth', $this->digits(12)),
                'bank_name' => 'Land Bank of the Philippines',
                'bank_branch' => $branch->name,
                'bank_account_name' => "{$firstName} {$lastName}",
                'bank_account_number' => $this->digits(10),
                'emergency_contact_name' => $this->pick(self::FEMALE_NAMES).' '.$this->pick(self::LAST_NAMES),
                'emergency_relationship' => $this->pick(['Spouse', 'Mother', 'Father', 'Sibling']),
                'emergency_contact_number' => '09'.$this->digits(9),
                'emergency_address' => $branch->address,
            ]));
        }

        return $employees;
    }

    /**
     * About one in twelve people worked here before and came back: a closed period before the current one.
     *
     * @param  Collection<int, Employee>  $employees
     */
    private function earlierPeriods(Collection $employees): void
    {
        foreach ($employees as $employee) {
            if (! $this->chance(0.08) || $employee->date_hired === null) {
                continue;
            }

            $hired = HrDate::addDays($employee->date_hired->toDateString(), -mt_rand(900, 2600));
            $separated = HrDate::addDays($hired, mt_rand(200, 700));

            if ($separated >= HrDate::addDays($employee->date_hired->toDateString(), -30)) {
                continue;
            }

            EmploymentPeriod::query()->create([
                'employee_id' => $employee->id,
                'hired_on' => $hired,
                'separated_on' => $separated,
                'separation_type' => $this->pick(['Resigned', 'Terminated']),
                'position' => $employee->position,
                'station_id' => $employee->station_id,
                'classification' => $employee->classification?->value,
            ]);
        }
    }

    /**
     * Filings go through the real leave rules, so nobody exceeds the allowance. A filing the rules
     * refuse, such as one falling on a weekend, is simply left out.
     *
     * @param  Collection<int, Employee>  $active
     */
    private function wellnessLeave(Collection $active): void
    {
        foreach ($active as $employee) {
            foreach ([$this->year - 1, $this->year] as $year) {
                for ($filing = mt_rand(0, 3), $month = 1; $filing > 0; $filing--, $month++) {
                    $month = min(mt_rand($month, $month + 3), 12);
                    $this->file($employee, sprintf('%d-%02d-%02d', $year, $month, mt_rand(1, 20)), mt_rand(0, 2), LeaveStatus::Approved);
                }
            }
        }

        $count = max(6, (int) round($active->count() / 13));

        foreach ($active->shuffle(mt_rand())->take($count)->values() as $index => $employee) {
            match ($index % 3) {
                0 => $this->file($employee, HrDate::addDays($this->today, mt_rand(3, 30)), mt_rand(0, 2), LeaveStatus::Pending, allowFuture: true),
                1 => $this->file($employee, HrDate::addDays($this->today, -mt_rand(10, 120)), 0, LeaveStatus::Disapproved),
                default => $this->file($employee, HrDate::addDays($this->today, -mt_rand(10, 120)), 0, LeaveStatus::Cancelled),
            };
        }
    }

    private function file(Employee $employee, string $from, int $extraDays, LeaveStatus $status, bool $allowFuture = false): void
    {
        if (! $allowFuture && $from > $this->today) {
            return;
        }

        try {
            $this->leave->save(new WellnessLeave, [
                'employee_id' => $employee->id,
                'date_filing' => HrDate::addDays($from, -mt_rand(3, 10)),
                'inclusive_date_from' => $from,
                'inclusive_date_to' => HrDate::addDays($from, $extraDays),
                'date_received' => HrDate::addDays($from, -mt_rand(0, 2)),
                'status' => $status,
            ], null, notify: false);
        } catch (UserFacingException) {
            return;
        }
    }

    /**
     * Last year mostly complete, this year in progress.
     *
     * @param  Collection<int, Employee>  $active
     */
    private function requirements(Collection $active): void
    {
        foreach ([$this->year - 1 => 0.92, $this->year => 0.55] as $year => $rate) {
            foreach ($active as $employee) {
                foreach ([ItrSubmission::class, SwornDeclaration::class, PdsSubmission::class] as $model) {
                    if ($this->chance($rate)) {
                        $submitted = sprintf('%d-%02d-%02d', $year, mt_rand(3, 6), mt_rand(1, 28));

                        $model::query()->create([
                            'employee_id' => $employee->id,
                            'year' => $year,
                            'date_submitted' => $submitted,
                            'date_received' => HrDate::addDays($submitted, mt_rand(0, 5)),
                        ]);
                    }
                }

                if ($this->chance($rate)) {
                    $this->ipcr($employee, $year, IpcrPeriod::JanJun, 1, 6, 7);
                }

                if ($year < $this->year && $this->chance($rate)) {
                    $this->ipcr($employee, $year, IpcrPeriod::JulDec, 7, 12, 1);
                }
            }
        }
    }

    private function ipcr(Employee $employee, int $year, IpcrPeriod $period, int $firstMonth, int $lastMonth, int $submittedMonth): void
    {
        IpcrRating::query()->create([
            'employee_id' => $employee->id,
            'year' => $year,
            'rating_period' => $period,
            'period_from' => sprintf('%d-%02d-01', $year, $firstMonth),
            'period_to' => sprintf('%d-%02d-%02d', $year, $lastMonth, HrDate::lastDayOf($year, $lastMonth)),
            'rating' => round(3 + $this->roll() * 2, 3),
            'date_submitted' => sprintf('%d-%02d-%02d', $submittedMonth === 1 ? $year + 1 : $year, $submittedMonth, mt_rand(5, 25)),
        ]);
    }

    /**
     * @param  Collection<int, Branch>  $branches
     */
    private function applications(Collection $branches): void
    {
        for ($number = 1, $total = max(10, (int) ($this->employeeCount / 5)); $number <= $total; $number++) {
            $status = $this->pick(['Pending', 'For Interview', 'For OJT', 'Hired', 'Not Hired', 'Withdrawn']);
            $applied = HrDate::addDays($this->today, -mt_rand(5, 160));
            $name = $this->pick([...self::MALE_NAMES, ...self::FEMALE_NAMES]).' '.$this->pick(self::LAST_NAMES);
            $requirements = [];

            foreach (OnboardingRequirement::cases() as $requirement) {
                $optional = in_array($requirement, [OnboardingRequirement::OrCr, OnboardingRequirement::DriverLicense], true);
                $requirements[$requirement->value] = $optional && $this->chance(0.7) ? 'N/A' : $this->pick(['Pending', 'Submitted', 'Submitted', 'Verified']);
            }

            Application::query()->create([
                ...$requirements,
                'application_id' => sprintf('APP-%d-%04d', $this->year, $number),
                'applicant_name' => $name,
                'email' => Str::slug($name, '.').".{$number}@example.test",
                'position_applied' => $this->weightedPosition(),
                'station_id' => $branches[mt_rand(0, $branches->count() - 1)]->id,
                'replacement' => $this->chance(0.3) ? $this->pick(self::FEMALE_NAMES).' '.$this->pick(self::LAST_NAMES) : null,
                'date_applied' => $applied,
                'start_ojt' => in_array($status, ['For OJT', 'Hired'], true) ? HrDate::addDays($applied, mt_rand(10, 30)) : null,
                'fds_date' => in_array($status, ['For OJT', 'Hired'], true) ? HrDate::addDays($applied, mt_rand(31, 60)) : (mt_rand(0, 3) === 0 ? HrDate::addDays($applied, mt_rand(20, 70)) : null),
                'application_status' => $status,
                'date_submitted' => $this->chance(0.5) ? HrDate::addDays($applied, mt_rand(3, 20)) : null,
            ]);
        }
    }

    /**
     * Last month released and this month in draft. Returns the released period.
     */
    private function payrollPeriods(User $staff): PayrollPeriod
    {
        $thisMonth = Carbon::parse($this->today)->startOfMonth();
        $released = $this->payrollPeriod($thisMonth->copy()->subMonth(), 1.0);
        $released->forceFill(['status' => PayrollStatus::Released, 'released_at' => $released->pay_date, 'released_by' => $staff->id])->save();

        $this->payrollPeriod($thisMonth, 0.6);

        return $released;
    }

    private function payrollPeriod(Carbon $month, float $filledRate): PayrollPeriod
    {
        $period = PayrollPeriod::query()->create([
            'name' => $month->format('F Y'),
            'code' => $month->format('Y-m'),
            'period_start' => $month->toDateString(),
            'period_end' => $month->copy()->endOfMonth()->toDateString(),
            'pay_date' => $month->copy()->day(min($month->daysInMonth, 28))->toDateString(),
        ]);

        $this->payroll->generate($period);

        foreach ($period->payslips()->get() as $slip) {
            if ($this->chance($filledRate)) {
                $this->fillPayslip($slip);
            }
        }

        return $period;
    }

    private function fillPayslip(Payslip $slip): void
    {
        $basic = match ($slip->classification) {
            'Regular' => mt_rand(22, 45) * 1000,
            'Contractual' => mt_rand(16, 25) * 1000,
            default => mt_rand(12, 18) * 1000,
        };

        $earnings = [
            'Basic Pay' => $basic * 100,
            'PERA' => $slip->classification === 'Regular' ? 200_000 : 0,
            'Overtime' => $this->chance(0.2) ? mt_rand(5, 30) * 10_000 + 50 : 0,
            'Allowances' => $this->chance(0.4) ? 150_000 : 0,
        ];
        $deductions = [
            'Withholding Tax' => $basic * 8,
            'GSIS / SSS' => (int) round($basic * 4.5),
            'PhilHealth' => (int) round($basic * 2.5),
            'Pag-IBIG' => 20_000,
            'Loans' => $this->chance(0.15) ? mt_rand(5, 20) * 10_000 : 0,
        ];
        $items = fn (array $amounts): array => array_map(fn (string $label, int $amount): array => ['label' => $label, 'amount_centavos' => $amount], array_keys($amounts), $amounts);

        $slip->update(['earnings' => $items($earnings), 'deductions' => $items($deductions)]);
    }

    /**
     * Issued through the real service, so control numbers are genuine. The last one is voided.
     *
     * @param  Collection<int, Employee>  $active
     * @param  Collection<int, Employee>  $employees
     */
    private function certificatesFor(Collection $active, Collection $employees, User $staff): void
    {
        $templates = CertificateTemplate::query()->pluck('id', 'key');
        $former = $employees->where('employment_status.value', '!=', 'Active')->values();
        $certificate = null;

        for ($index = 0, $total = max(4, (int) ($this->employeeCount / 20)); $index < $total; $index++) {
            $separated = $index % 4 === 3 && $former->isNotEmpty();
            $pool = $separated ? $former : $active;
            $purpose = $this->pick(['housing loan application with Pag-IBIG', 'bank loan', 'visa application', null]);

            $certificate = $this->certificates->issue(
                $staff,
                $templates[$separated ? 'coe-separated' : 'coe-active'],
                $pool[mt_rand(0, $pool->count() - 1)]->id,
                $purpose,
                HrDate::addDays($this->today, -mt_rand(0, 120)),
            );
        }

        if ($certificate?->status === CertificateStatus::Valid) {
            $this->certificates->void($certificate, 'Wrong purpose; reissued.');
        }
    }

    /**
     * History only. Every row is already delivered or skipped, so nothing waits in the outbox.
     *
     * @param  Collection<int, Employee>  $active
     */
    private function messageHistory(Collection $active, PayrollPeriod $released, User $staff): void
    {
        $branchId = $active->first()->station_id;
        $group = $active->where('station_id', $branchId)->values();
        $branchName = Branch::query()->whereKey($branchId)->value('name');
        $subject = "Office closed on Friday ({$branchName})";
        $body = "Hi {{firstName}},\n\nPlease be informed that {$branchName} will be closed this Friday. Regular office hours resume on Monday.\n\nThank you,\nHR Office";

        $this->history($subject, $body, 'Announcement', false, "Employees at {$branchName}", $staff, 6, $group->map(fn (Employee $employee, int $index): array => [
            'employee' => $employee,
            'subject' => $subject,
            'body' => str_replace('{{firstName}}', $employee->first_name, $body),
            'status' => $index < 2 ? 'failed' : 'sent',
            'error' => $index < 2 ? '550 Mailbox unavailable' : null,
        ])->all());

        $slips = $released->payslips()->get()->keyBy('employee_id');
        $payslipSubject = "Your payslip for {$released->name}";

        $this->history($payslipSubject, 'Your payslip is attached.', 'Payroll', true, "Payroll {$released->name} ({$slips->count()} employees)", $staff, 9, $active->map(fn (Employee $employee): array => [
            'employee' => $employee,
            'subject' => $payslipSubject,
            'body' => "Hi {$employee->first_name},\n\nYour payslip for {$released->name} is attached.",
            'status' => 'sent',
            'error' => null,
            'payslip_id' => $slips->get($employee->id)?->id,
        ])->all(), ['collection' => 'payroll-periods', 'id' => $released->id]);

        $reminderSubject = "Reminder: please submit your Income Tax Return for {$this->year}";

        $this->history($reminderSubject, 'Please submit it to the HR office as soon as possible.', 'Reminder', false, 'Employees with no ITR', $staff, 3, $active->take(25)->map(fn (Employee $employee): array => [
            'employee' => $employee,
            'subject' => $reminderSubject,
            'body' => "Hi {$employee->first_name},\n\nPlease submit your Income Tax Return to the HR office as soon as possible.",
            'status' => 'sent',
            'error' => null,
        ])->all());
    }

    /**
     * @param  list<array{employee: Employee, subject: string, body: string, status: string, error: string|null, payslip_id?: int|null}>  $rows
     * @param  array{collection: string, id: int}|null  $related
     */
    private function history(string $subject, string $body, string $category, bool $automatic, string $audience, User $sender, int $daysAgo, array $rows, ?array $related = null): void
    {
        $at = now()->subDays($daysAgo);
        $failed = count(array_filter($rows, fn (array $row): bool => $row['status'] === 'failed'));

        $message = new Message([
            'subject' => $subject,
            'body' => $body,
            'category' => $category,
            'audience' => $audience,
            'automatic' => $automatic,
            'related_collection' => $related['collection'] ?? null,
            'related_id' => isset($related['id']) ? (string) $related['id'] : null,
            'sent_by' => $sender->id,
            'sent_by_name' => $sender->name,
        ]);
        $message->forceFill([
            'status' => $failed > 0 ? MessageStatus::Partial : MessageStatus::Sent,
            'total' => count($rows),
            'sent' => count($rows) - $failed,
            'failed' => $failed,
            'created_at' => $at,
            'updated_at' => $at,
        ])->save();

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('message_recipients')->insert(array_map(fn (array $row): array => [
                'message_id' => $message->id,
                'name' => "{$row['employee']->first_name} {$row['employee']->last_name}",
                'email' => $row['employee']->email,
                'employee_id' => $row['employee']->id,
                'subject' => $row['subject'],
                'body' => $row['body'],
                'payslip_id' => $row['payslip_id'] ?? null,
                'status' => $row['status'],
                'attempts' => $row['status'] === 'failed' ? 3 : 1,
                'error' => $row['error'],
                'sent_at' => $row['status'] === 'sent' ? $at : null,
                'created_at' => $at,
                'updated_at' => $at,
            ], $chunk));
        }
    }

    private function notifications(PayrollPeriod $released): void
    {
        $pending = WellnessLeave::query()->where('status', LeaveStatus::Pending)->count();
        $items = [
            ["{$pending} wellness leave filing(s) waiting for approval", 'Review and approve or disapprove them.', '/leave?status=Pending', false],
            ["Annual Income Tax Return {$this->year}: submissions are overdue", 'Some active employees have not submitted yet.', "/requirements/itr?year={$this->year}&status=overdue", false],
            ["Payroll released: {$released->name}", 'Payslips were emailed to employees.', "/payroll/{$released->id}", true],
            ['Some emails could not be delivered', 'Two addresses bounced in the latest announcement.', '/messages', true],
        ];

        foreach (User::query()->pluck('id') as $userId) {
            foreach ($items as [$title, $body, $link, $read]) {
                Notification::query()->create(['user_id' => $userId, 'title' => $title, 'body' => $body, 'link' => $link])
                    ->forceFill(['read_at' => $read ? now() : null])
                    ->save();
            }
        }
    }

    /**
     * @param  Collection<int, Employee>  $active
     */
    private function activity(User $staff, Collection $active, PayrollPeriod $released): void
    {
        $employee = $active->first();
        $leave = WellnessLeave::query()->first();

        $entries = [
            ['update', 'employees', $employee->id, $employee->full_name, ['contact_number' => ['from' => '09171234567', 'to' => $employee->contact_number]], 1],
            ['create', 'wellness-leaves', $leave?->id, $leave?->last_name, null, 5],
            ['create', 'employees', $active[1]->id, $active[1]->full_name, null, 72],
            ['import', 'employees', null, 'employees-update.xlsx', ['created' => ['from' => null, 'to' => '3 rows'], 'updated' => ['from' => null, 'to' => '12 rows']], 96],
            ['update', 'payroll-periods', $released->id, $released->name, ['status' => ['from' => 'Draft', 'to' => 'Released']], 216],
        ];

        foreach ($entries as [$action, $slug, $documentId, $label, $changes, $hoursAgo]) {
            (new AuditLog)->forceFill([
                'user_id' => $staff->id,
                'user_name' => $staff->name,
                'action' => $action,
                'collection_slug' => $slug,
                'doc_id' => $documentId === null ? null : (string) $documentId,
                'doc_label' => $label,
                'changes' => $changes,
                'created_at' => now()->subHours($hoursAgo),
                'updated_at' => now()->subHours($hoursAgo),
            ])->save();
        }
    }

    /**
     * Fictional wage orders for each region, a starting rate for everyone, wage orders applied to the
     * daily-paid, raises for some monthly staff, and a few promotions and demotions.
     *
     * @param  Collection<int, Employee>  $employees
     */
    public function wagesAndCareers(Collection $employees, ?string $today = null): void
    {
        $this->today ??= $today ?? HrDate::today();
        $year = (int) substr($this->today, 0, 4);
        $orders = [];

        foreach (Region::cases() as $index => $region) {
            foreach ([3 => 0, 2 => 2_500, 1 => 5_000, 0 => 8_000] as $back => $raise) {
                $orders[] = WageOrder::query()->create([
                    'region' => $region,
                    'order_number' => sprintf('DEMO-%s-%d', str_replace(' ', '', $region->value), $year - $back),
                    'sector' => 'Non-agriculture',
                    'daily_rate_centavos' => 56_000 - $index * 900 + $raise,
                    'effective_on' => ($year - $back).'-07-01',
                ]);
            }
        }

        $now = now();
        $pay = [];
        $positions = [];

        foreach ($employees as $employee) {
            $hired = $employee->date_hired?->toDateString() ?? ($year - 5).'-01-01';
            $monthly = $employee->classification === Classification::Regular;
            $starting = $monthly ? mt_rand(18, 40) * 100_000 : 38_000;
            $row = ['employee_id' => $employee->id, 'rate_type' => ($monthly ? RateType::Monthly : RateType::Daily)->value, 'created_at' => $now, 'updated_at' => $now];
            $pay[] = [...$row, 'effective_on' => $hired, 'change_type' => PayChangeType::Starting->value, 'amount_centavos' => $starting, 'reference' => null];

            if ($monthly && $this->roll() < 0.35 && HrDate::addYears($hired, 2) < $this->today) {
                $pay[] = [...$row, 'effective_on' => HrDate::addYears($hired, 2), 'change_type' => PayChangeType::Increase->value, 'amount_centavos' => $starting + mt_rand(1, 4) * 100_000, 'reference' => 'Demo memo '.mt_rand(100, 999)];
            }

            if ($this->roll() < 0.1 && HrDate::addYears($hired, 3) < $this->today && filled($employee->position)) {
                $demoted = $this->roll() < 0.15;
                $positions[] = [
                    'employee_id' => $employee->id,
                    'effective_on' => HrDate::addYears($hired, 3),
                    'change_type' => ($demoted ? PositionChangeType::Demotion : PositionChangeType::Promotion)->value,
                    'from_position' => $demoted ? 'Senior '.$employee->position : 'Assistant '.$employee->position,
                    'to_position' => $employee->position,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($pay, 500) as $chunk) {
            PayChange::query()->insert($chunk);
        }

        foreach (array_chunk($positions, 500) as $chunk) {
            PositionChange::query()->insert($chunk);
        }

        $service = app(PayHistoryService::class);

        foreach ($orders as $order) {
            if ($order->effective_on->toDateString() <= $this->today) {
                $service->apply($order);
            }
        }
    }

    /**
     * Fictional officials, and this semester's contracts for the first 40 active COS workers.
     */
    private function contracts(User $staff): void
    {
        ContractSetting::current()->update([
            'area_director_name' => 'Elena V. Marquez',
            'adfin_chief_name' => 'Roberto S. Aquino',
            'hrm_chief_name' => 'Teresita L. Navarro',
            'budget_officer_name' => 'Gerardo P. Mendoza',
            'wage_order' => 'the prevailing regional wage order',
            'default_place_of_service' => 'Davao City',
        ]);

        $month = (int) substr($this->today, 5, 2);
        $year = (int) substr($this->today, 0, 4);
        [$start, $end] = $month >= 7 ? ["{$year}-07-01", "{$year}-12-31"] : ["{$year}-01-01", "{$year}-06-30"];
        [$previousStart, $previousEnd] = $month >= 7 ? ["{$year}-01-01", "{$year}-06-30"] : [($year - 1).'-07-01', ($year - 1).'-12-31'];
        $standard = ContractTemplate::query()->firstOrFail();
        $templates = [$standard];

        foreach (['Contract of Service – Driver', 'Contract of Service – Teller'] as $name) {
            $copy = $standard->replicate(['key']);
            $copy->name = $name;
            $copy->sort_order = 20 + count($templates);
            $copy->save();
            $templates[] = $copy;
        }

        $service = app(ContractService::class);
        $settings = ContractSetting::current();
        $issue = function (ContractTemplate $template, string $from, string $to, int $count, string $wage) use ($service, $settings, $staff): array {
            $rows = array_map(fn (Employee $employee): array => [
                'employee' => $employee->id,
                ...$service->defaults($employee, $settings, $from, $to),
                'daily_wage' => $wage,
            ], array_slice($service->candidates($from, $to), 0, $count));

            return $rows === [] ? [] : $service->issueMany($staff, $template->id, $rows, $from);
        };

        // Ending within the month, so the "ending soon" notice has something to show.
        $issue($standard, HrDate::addDays($this->today, -70), HrDate::addDays($this->today, 18), 12, '450');
        $previous = $issue($standard, $previousStart, $previousEnd, 40, '440');
        $issue($templates[1], $start, $end, 25, '470');
        $issue($templates[2], $start, $end, 25, '460');
        $issue($standard, $start, $end, 60, '450');

        foreach (array_slice($previous, 0, 3) as $index => $contract) {
            $service->void($contract, ['Wrong position', 'Reissued with a new wage', 'Worker declined'][$index]);
        }
    }

    /**
     * Three months of SSS, Pag-IBIG and CMPEAI remittances for the COS workers, the latest one still open.
     */
    private function remittances(User $staff): void
    {
        $service = app(RemittanceService::class);
        [$year, $month] = [(int) substr($this->today, 0, 4), (int) substr($this->today, 5, 2)];

        VoucherSetting::current()->update([
            'fund_cluster' => '01',
            'responsibility_center' => '100-01-001',
            'supervisor_name' => 'Teresita L. Navarro',
            'accountant_name' => 'Roberto S. Aquino',
            'approver_name' => 'Elena V. Marquez',
        ]);

        foreach (RemittanceKind::cases() as $kind) {
            RemittanceSetting::forKind($kind)->update([
                'employer_id' => '12-3456789-0',
                'employer_name' => 'Sample Postal Corporation',
                'employer_address' => '1 Sample Avenue, Davao City',
                'employer_contact' => 'HR Office, 0000-000-0000',
                'prepared_by_name' => 'Teresita L. Navarro',
                'noted_by_name' => 'Roberto S. Aquino',
                'voucher_payee' => match ($kind) {
                    RemittanceKind::Sss => 'Social Security System',
                    RemittanceKind::Hdmf => 'Home Development Mutual Fund',
                    RemittanceKind::Cmpeai => 'Sample Employees Association Inc.',
                },
                'voucher_address' => 'Davao City',
            ]);
        }

        foreach (RemittanceKind::cases() as $kind) {
            for ($back = 2; $back >= 0; $back--) {
                $target = $month - $back;
                $remittance = $service->generate($kind, $target < 1 ? $year - 1 : $year, $target < 1 ? $target + 12 : $target, $staff)['remittance'];

                // Some CMPEAI members also repay loans, so their total payment is higher.
                if ($kind === RemittanceKind::Cmpeai) {
                    $remittance->lines()->inRandomOrder()->limit(max(1, intdiv($remittance->lines()->count(), 6)))->increment('amount_centavos', 91_700);
                }

                if ($back > 0) {
                    $service->finalize($remittance);
                    $remittance->update(['voucher_number' => sprintf('%d-%02d-%04d', $year, $target < 1 ? $target + 12 : $target, mt_rand(1, 400)), 'voucher_date' => HrDate::addDays($this->today, -30 * $back + 3)]);
                }
            }
        }
    }

    /**
     * About three months of incoming mail, a dozen or so pieces on each working day.
     *
     * @param  Collection<int, Branch>  $branches
     */
    private function receivedMails(Collection $branches, User $staff): void
    {
        $offices = $branches->map(fn (Branch $branch): string => mb_strtoupper(str_replace(' Post Office', '', $branch->name)))->all();
        $rows = [];

        for ($back = 90; $back >= 0; $back--) {
            $day = HrDate::addDays($this->today, -$back);

            if (HrDate::dayOfWeek($day) === 0 || HrDate::dayOfWeek($day) === 6) {
                continue;
            }

            for ($piece = mt_rand(8, 16); $piece > 0; $piece--) {
                $name = $this->pick(self::LAST_NAMES);
                $rows[] = [
                    'received_on' => $day,
                    'tracking_number' => $this->chance(0.05) ? '#'.mt_rand(100, 999) : sprintf('RL %03d %03d %03d ZZ', mt_rand(0, 999), mt_rand(0, 999), mt_rand(0, 999)),
                    'post_office' => $this->pick($offices),
                    'description' => $this->pick([
                        'DTR for wages for the period of '.date('F', strtotime($day)).' 1-15, '.substr($day, 0, 4),
                        "Leave application of {$this->pick(['Mr.', 'Ms.'])} {$name}",
                        'Statement of absences as of '.date('F Y', strtotime($day)),
                        "Assumption to duty of {$this->pick(['Mr.', 'Ms.'])} {$name}",
                    ]),
                    'remarks' => null,
                    'received_by' => $staff->name,
                    'created_by' => $staff->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            ReceivedMail::query()->insert($chunk);
        }
    }

    private function importHistory(User $staff): void
    {
        $common = ['user_id' => $staff->id, 'user_name' => $staff->name];

        ImportJob::query()->create([...$common, 'file_name' => 'employees-update.xlsx', 'module' => 'Employees', 'status' => ImportJobStatus::Committed, 'created' => 3, 'updated' => 12, 'unchanged' => 125]);
        ImportJob::query()->create([...$common, 'file_name' => 'leave-march.xlsx', 'module' => 'Wellness Leave', 'status' => ImportJobStatus::Failed, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'error' => 'Row 7: Inclusive Date (To) is before Inclusive Date (From). Nothing was saved.']);
    }

    private function lastDay(string $hired): string
    {
        $end = HrDate::addDays($hired, mt_rand(200, 4000));
        $lastDay = $end < $this->today ? $end : HrDate::addDays($this->today, -mt_rand(5, 300));

        return $lastDay < $hired ? HrDate::addDays($hired, 30) : $lastDay;
    }

    private function weightedPosition(): string
    {
        $roll = mt_rand(1, array_sum(self::POSITIONS));

        foreach (self::POSITIONS as $position => $weight) {
            $roll -= $weight;

            if ($roll <= 0) {
                return $position;
            }
        }

        return array_key_first(self::POSITIONS);
    }

    private function date(int $year): string
    {
        return sprintf('%d-%02d-%02d', $year, mt_rand(1, 12), mt_rand(1, 28));
    }

    private function digits(int $length): string
    {
        return implode('', array_map(fn (): int => mt_rand(0, 9), range(1, $length)));
    }

    /**
     * @template TValue
     *
     * @param  array<int, TValue>  $values
     * @return TValue
     */
    private function pick(array $values): mixed
    {
        return $values[mt_rand(0, count($values) - 1)];
    }

    private function roll(): float
    {
        return mt_rand() / mt_getrandmax();
    }

    private function chance(float $rate): bool
    {
        return $this->roll() < $rate;
    }
}
