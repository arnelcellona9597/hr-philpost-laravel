<?php

namespace App\Support;

use App\Enums\ApplicationStatus;
use App\Enums\BloodType;
use App\Enums\CivilStatus;
use App\Enums\Classification;
use App\Enums\EducationalAttainment;
use App\Enums\EmploymentStatus;
use App\Enums\Gender;
use App\Enums\HolidayType;
use App\Enums\IpcrPeriod;
use App\Enums\LeaveStatus;
use App\Enums\OnboardingRequirement;
use App\Enums\PayChangeType;
use App\Enums\PositionChangeType;
use App\Enums\RateType;
use App\Enums\Region;
use App\Enums\RequirementStatus;
use App\Models\Application;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmploymentPeriod;
use App\Models\Holiday;
use App\Models\IpcrRating;
use App\Models\ItrSubmission;
use App\Models\PayChange;
use App\Models\PdsSubmission;
use App\Models\PositionChange;
use App\Models\SwornDeclaration;
use App\Models\WageOrder;
use App\Models\WellnessLeave;
use BackedEnum;

/**
 * One definition per module of every form field and Excel column. The Excel header is the field
 * `label`, so exports, import templates and imports always agree.
 *
 * @phpstan-type ModuleField array{name: string, label: string, kind: string, required?: bool, options?: list<string>, idKind?: string, computed?: bool, fromEmployee?: bool, section?: string, help?: string, min?: int|float, max?: int|float, step?: int|float, width?: int}
 * @phpstan-type Module array{slug: string, sheet: string, title: string, key: list<string>, fields: list<ModuleField>}
 */
class ImportModules
{
    /**
     * URL segment for each annual requirement, keyed to its module slug.
     */
    public const COMPLIANCE_ROUTES = [
        'itr' => 'itr-submissions',
        'sworn-declaration' => 'sworn-declarations',
        'pds' => 'pds-submissions',
        'ipcr' => 'ipcr-ratings',
    ];

    /**
     * The model behind each module.
     */
    public const MODELS = [
        'branches' => Branch::class,
        'holidays' => Holiday::class,
        'employees' => Employee::class,
        'employment-history' => EmploymentPeriod::class,
        'wage-orders' => WageOrder::class,
        'pay-history' => PayChange::class,
        'position-history' => PositionChange::class,
        'applications' => Application::class,
        'wellness-leaves' => WellnessLeave::class,
        'itr-submissions' => ItrSubmission::class,
        'sworn-declarations' => SwornDeclaration::class,
        'pds-submissions' => PdsSubmission::class,
        'ipcr-ratings' => IpcrRating::class,
    ];

    /**
     * Every module in import order: branches before employees, employees before what links to them.
     *
     * @return list<Module>
     */
    public static function all(): array
    {
        return [
            self::branches(),
            self::holidays(),
            self::employees(),
            self::employmentHistory(),
            self::wageOrders(),
            self::payHistory(),
            self::positionHistory(),
            self::applications(),
            self::wellnessLeaves(),
            ...self::compliance(),
        ];
    }

    /**
     * @return Module|null
     */
    public static function find(string $slug): ?array
    {
        foreach (self::all() as $module) {
            if ($module['slug'] === $slug) {
                return $module;
            }
        }

        return null;
    }

    /**
     * @return list<Module>
     */
    public static function compliance(): array
    {
        $employeeWork = fn (string $tin = ''): array => array_values(array_filter([
            self::field('station', 'Station / Division / Unit', 'text', ['fromEmployee' => true, 'width' => 26]),
            self::field('job_title', 'Job Title', 'text', ['fromEmployee' => true, 'width' => 24]),
            $tin === '' ? null : self::field('tin', $tin, 'text', ['fromEmployee' => true]),
        ]));

        return [
            self::module('itr-submissions', 'Income Tax Return', 'Annual Income Tax Return', ['employee_id', 'year'], [
                ...self::complianceBase(),
                ...self::nameSnapshot(true),
                ...self::submissionDates(),
                ...self::timelinessFields(),
                self::field('remarks', 'Remark', 'textarea', ['width' => 30]),
            ]),
            self::module('sworn-declarations', 'Sworn Declaration', 'Sworn Declaration', ['employee_id', 'year'], [
                ...self::complianceBase(),
                ...self::nameSnapshot(false),
                ...$employeeWork('TIN Number'),
                ...self::submissionDates(),
                ...self::timelinessFields(),
                self::field('remarks', 'Remarks', 'textarea', ['width' => 30]),
            ]),
            self::module('pds-submissions', 'Personal Data Sheet', 'Personal Data Sheet', ['employee_id', 'year'], [
                ...self::complianceBase(),
                ...self::nameSnapshot(false),
                ...$employeeWork(),
                ...self::submissionDates(),
                ...self::timelinessFields(),
                self::field('remarks', 'Remarks', 'textarea', ['width' => 30]),
            ]),
            self::module('ipcr-ratings', 'IPCR', 'Individual Performance Commitment and Review (IPCR)', ['employee_id', 'year', 'rating_period'], [
                ...self::complianceBase(),
                ...self::nameSnapshot(false),
                self::field('job_title', 'Job Title', 'text', ['fromEmployee' => true, 'width' => 24]),
                self::field('station', 'Station / Division / Unit', 'text', ['fromEmployee' => true, 'width' => 26]),
                self::select('rating_period', 'Rating Period', IpcrPeriod::class, ['required' => true]),
                self::field('period_from', 'Period From', 'date'),
                self::field('period_to', 'Period To', 'date'),
                self::field('rating', 'Rating', 'number', ['min' => 1, 'max' => 5, 'step' => 0.001, 'width' => 8]),
                self::field('adjectival_rating', 'Adjectival Rating', 'text', ['computed' => true, 'width' => 18]),
                ...self::submissionDates(),
                ...self::timelinessFields(),
                self::field('remarks', 'Remarks', 'textarea', ['width' => 30]),
            ]),
        ];
    }

    /**
     * @return Module
     */
    private static function employees(): array
    {
        $personal = ['section' => 'Personal'];
        $education = ['section' => 'Education'];
        $employment = ['section' => 'Employment'];
        $governmentIds = ['section' => 'Government IDs'];
        $bank = ['section' => 'Bank Account'];
        $emergency = ['section' => 'Emergency Contact'];

        return self::module('employees', 'Employees', 'Employees', ['employee_id'], [
            self::field('last_name', 'Last Name', 'text', ['required' => true] + $personal),
            self::field('first_name', 'First Name', 'text', ['required' => true] + $personal),
            self::field('middle_name', 'Middle Name', 'text', $personal),
            self::field('extension', 'Extn.', 'text', ['width' => 8] + $personal),
            self::select('gender', 'Gender', Gender::class, ['required' => true] + $personal),
            self::field('date_of_birth', 'Date of Birth', 'date', $personal),
            self::field('age', 'Age', 'number', ['computed' => true, 'width' => 6] + $personal),
            self::select('civil_status', 'Civil Status', CivilStatus::class, $personal),
            self::field('religion', 'Religion', 'text', $personal),
            self::select('blood_type', 'Blood Type', BloodType::class, ['width' => 8] + $personal),
            self::field('professional_eligibility', 'Professional Eligibility / License', 'text', ['width' => 28] + $personal),
            self::field('contact_number', 'Contact Number', 'phone', $personal),
            self::field('address', 'Address', 'text', ['width' => 36] + $personal),
            self::field('email', 'Email Address', 'email', ['width' => 28] + $personal),
            self::select('educational_attainment', 'Educational Attainment', EducationalAttainment::class, ['width' => 24] + $education),
            self::field('courses', 'Courses', 'text', ['width' => 28] + $education),
            self::field('year_level_earned_units', 'Year Level / Earned Units', 'text', $education),
            self::field('academic_honors', 'Academic Honors Received', 'text', ['width' => 24] + $education),
            self::field('employee_id', 'Employee ID', 'text', ['required' => true] + $employment),
            self::field('station', 'Station / Division / Unit', 'branch', ['width' => 26] + $employment),
            self::select('region', 'Region', Region::class, $employment),
            self::field('position', 'Position / Designation', 'text', ['width' => 26] + $employment),
            self::field('nature_of_work', 'Nature of Work', 'text', $employment),
            self::field('driver_license_number', 'Driver License Number', 'text', $employment),
            self::field('date_hired', 'Date Hired', 'date', $employment),
            self::field('last_day_of_service', 'Last Day of Service', 'date', $employment),
            self::field('length_of_service', 'Length in Service', 'text', ['computed' => true] + $employment),
            self::select('classification', 'Classification', Classification::class, ['required' => true, 'help' => 'COS, Contractual or Regular'] + $employment),
            self::select('employment_status', 'Employment Status', EmploymentStatus::class, ['required' => true] + $employment),
            self::field('sss', 'SSS Number', 'govId', ['idKind' => 'sss'] + $governmentIds),
            self::field('pagibig', 'Pag-IBIG Number', 'govId', ['idKind' => 'pagibig'] + $governmentIds),
            self::field('tin', 'TIN Number', 'govId', ['idKind' => 'tin'] + $governmentIds),
            self::field('philhealth', 'PhilHealth Number', 'govId', ['idKind' => 'philhealth'] + $governmentIds),
            self::field('bank_name', 'Bank Name', 'text', ['width' => 24] + $bank),
            self::field('bank_branch', 'Bank Branch', 'text', ['width' => 24] + $bank),
            self::field('bank_account_name', 'Bank Account Name', 'text', ['width' => 28] + $bank),
            self::field('bank_account_number', 'Bank Account Number', 'text', ['width' => 22, 'help' => 'Digits, spaces and hyphens only.'] + $bank),
            self::field('emergency_contact_name', 'Emergency Contact Person', 'text', ['width' => 24] + $emergency),
            self::field('emergency_relationship', 'Relationship', 'text', $emergency),
            self::field('emergency_contact_number', 'Emergency Contact Number', 'phone', $emergency),
            self::field('emergency_address', 'Emergency Contact Address', 'text', ['width' => 36] + $emergency),
            self::field('remarks', 'Remark', 'textarea', ['width' => 30] + $employment),
        ]);
    }

    /**
     * Every time an employee was hired and separated, oldest first.
     *
     * @return Module
     */
    private static function employmentHistory(): array
    {
        return self::module('employment-history', 'Employment History', 'Employment History', ['employee_id', 'hired_on'], [
            self::field('employee', 'Employee ID', 'employee', ['required' => true]),
            self::field('hired_on', 'Date Hired', 'date', ['required' => true]),
            self::field('separated_on', 'Date Separated', 'date'),
            self::field('separation_type', 'Separation Type', 'select', ['options' => ['Resigned', 'Retired', 'Terminated'], 'help' => 'Resigned, Retired or Terminated']),
            self::field('position', 'Position / Designation', 'text', ['width' => 26]),
            self::field('station', 'Station / Division / Unit', 'branch', ['width' => 26]),
            self::select('classification', 'Classification', Classification::class),
            self::field('remarks', 'Remarks', 'textarea', ['width' => 30]),
        ]);
    }

    /**
     * The general minimum wage of each region, from each wage order.
     *
     * @return Module
     */
    private static function wageOrders(): array
    {
        return self::module('wage-orders', 'Wage Orders', 'Wage Orders', ['region', 'sector', 'effective_on'], [
            self::select('region', 'Region', Region::class, ['required' => true]),
            self::field('order_number', 'Wage Order No.', 'text', ['required' => true, 'width' => 20]),
            self::field('sector', 'Sector', 'text', ['required' => true, 'width' => 22, 'help' => 'For example Non-agriculture, Agriculture, or Retail and service.']),
            self::field('daily_rate_centavos', 'Daily Minimum Wage', 'money', ['required' => true, 'width' => 16]),
            self::field('effective_on', 'Effective Date', 'date', ['required' => true]),
            self::field('remarks', 'Remarks', 'textarea', ['width' => 30]),
        ]);
    }

    /**
     * Every pay rate an employee has had, oldest first.
     *
     * @return Module
     */
    private static function payHistory(): array
    {
        return self::module('pay-history', 'Pay History', 'Pay History', ['employee_id', 'effective_on'], [
            self::field('employee', 'Employee ID', 'employee', ['required' => true]),
            self::field('effective_on', 'Effective Date', 'date', ['required' => true]),
            self::select('change_type', 'Change Type', PayChangeType::class, ['required' => true, 'width' => 16]),
            self::select('rate_type', 'Rate Type', RateType::class, ['required' => true]),
            self::field('amount_centavos', 'Rate', 'money', ['required' => true]),
            self::field('reference', 'Reference', 'text', ['width' => 22, 'help' => 'The wage order or memo behind this change.']),
            self::field('remarks', 'Remarks', 'textarea', ['width' => 30]),
        ]);
    }

    /**
     * Promotions and demotions.
     *
     * @return Module
     */
    private static function positionHistory(): array
    {
        return self::module('position-history', 'Promotions', 'Promotions and Demotions', ['employee_id', 'effective_on'], [
            self::field('employee', 'Employee ID', 'employee', ['required' => true]),
            self::field('effective_on', 'Effective Date', 'date', ['required' => true]),
            self::select('change_type', 'Change Type', PositionChangeType::class, ['required' => true]),
            self::field('from_position', 'From Position', 'text', ['width' => 26]),
            self::field('to_position', 'To Position', 'text', ['required' => true, 'width' => 26]),
            self::field('remarks', 'Remarks', 'textarea', ['width' => 30]),
        ]);
    }

    /**
     * @return Module
     */
    private static function branches(): array
    {
        return self::module('branches', 'Branches', 'Branches', ['name'], [
            self::field('name', 'Branch Name', 'text', ['required' => true, 'width' => 30]),
            self::field('code', 'Code', 'text'),
            self::field('address', 'Address', 'text', ['width' => 40]),
            self::select('region', 'Region', Region::class),
            self::field('contact_number', 'Contact Number', 'phone'),
            self::field('notes', 'Notes', 'textarea', ['width' => 30]),
        ]);
    }

    /**
     * @return Module
     */
    private static function applications(): array
    {
        $requirements = array_map(
            fn (OnboardingRequirement $requirement): array => self::select($requirement->value, $requirement->label(), RequirementStatus::class, ['section' => 'Requirements', 'width' => 12]),
            OnboardingRequirement::cases(),
        );

        return self::module('applications', 'Onboarding', 'Onboarding Tracker', ['application_id'], [
            self::field('application_id', 'Application ID', 'text', ['required' => true]),
            self::field('applicant_name', 'Applicant Name', 'text', ['required' => true, 'width' => 26]),
            self::field('email', 'Email Address', 'email', ['width' => 28, 'help' => 'Used to email the requirements for employment when the applicant is hired.']),
            self::field('position_applied', 'Position Applied', 'text', ['required' => true, 'width' => 24]),
            self::field('station', 'Station', 'branch', ['width' => 24]),
            self::field('replacement', 'Replacement', 'text', ['width' => 22]),
            self::field('date_applied', 'Date Applied', 'date', ['required' => true]),
            self::field('start_ojt', 'Start OJT', 'date'),
            self::field('fds_date', 'FDS Date', 'date', ['help' => 'First day of service.']),
            self::select('application_status', 'Application Status', ApplicationStatus::class, ['required' => true]),
            ...$requirements,
            self::field('date_submitted', 'Date Submitted', 'date'),
            self::field('requirements_status', 'Requirements Status', 'text', ['computed' => true]),
            self::field('remarks', 'Remarks', 'textarea', ['width' => 30]),
        ]);
    }

    /**
     * @return Module
     */
    private static function wellnessLeaves(): array
    {
        return self::module('wellness-leaves', 'Wellness Leave', 'Wellness Leave', ['employee_id', 'inclusive_date_from'], [
            self::field('employee', 'Employee ID', 'employee', ['required' => true]),
            self::field('last_name', 'Last Name', 'text', ['computed' => true]),
            self::field('first_name', 'First Name', 'text', ['computed' => true]),
            self::field('middle_name', 'Middle Name', 'text', ['computed' => true]),
            self::field('date_filing', 'Date Filing', 'date', ['required' => true]),
            self::field('inclusive_date_from', 'Inclusive Date From', 'date', ['required' => true]),
            self::field('inclusive_date_to', 'Inclusive Date To', 'date', ['required' => true]),
            self::field('date_received', 'Date Received', 'date'),
            self::field('days', 'Days', 'number', ['computed' => true, 'width' => 6]),
            self::field('remaining_days', 'Remaining Days', 'number', ['computed' => true, 'width' => 8]),
            self::select('status', 'Status', LeaveStatus::class, ['required' => true]),
            self::field('remarks', 'Remarks', 'textarea', ['width' => 30]),
        ]);
    }

    /**
     * @return Module
     */
    private static function holidays(): array
    {
        return self::module('holidays', 'Holidays', 'Holidays', ['date'], [
            self::field('date', 'Date', 'date', ['required' => true]),
            self::field('name', 'Name', 'text', ['required' => true, 'width' => 30]),
            self::select('type', 'Type', HolidayType::class, ['width' => 24]),
        ]);
    }

    /**
     * @return list<ModuleField>
     */
    private static function complianceBase(): array
    {
        return [
            self::field('employee', 'Employee ID', 'employee', ['required' => true]),
            self::field('year', 'Year', 'year', ['required' => true, 'width' => 8]),
        ];
    }

    /**
     * @return list<ModuleField>
     */
    private static function nameSnapshot(bool $withExtension): array
    {
        return [
            self::field('last_name', 'Last Name', 'text', ['fromEmployee' => true]),
            self::field('first_name', 'First Name', 'text', ['fromEmployee' => true]),
            self::field('middle_name', 'Middle Name', 'text', ['fromEmployee' => true]),
            ...($withExtension ? [self::field('extension', 'Ext.', 'text', ['fromEmployee' => true])] : []),
        ];
    }

    /**
     * @return list<ModuleField>
     */
    private static function submissionDates(): array
    {
        return [
            self::field('date_submitted', 'Date Submitted', 'date'),
            self::field('date_received', 'Date Received', 'date'),
        ];
    }

    /**
     * Computed from the deadline settings: shown and exported, never imported.
     *
     * @return list<ModuleField>
     */
    private static function timelinessFields(): array
    {
        return [
            self::field('deadline', 'Deadline', 'date', ['computed' => true]),
            self::field('timeliness', 'Timeliness', 'text', ['computed' => true, 'width' => 18]),
        ];
    }

    /**
     * @param  list<string>  $key
     * @param  list<ModuleField>  $fields
     * @return Module
     */
    private static function module(string $slug, string $sheet, string $title, array $key, array $fields): array
    {
        return ['slug' => $slug, 'sheet' => $sheet, 'title' => $title, 'key' => $key, 'fields' => $fields];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return ModuleField
     */
    private static function field(string $name, string $label, string $kind, array $extra = []): array
    {
        return ['name' => $name, 'label' => $label, 'kind' => $kind] + $extra;
    }

    /**
     * @param  class-string<BackedEnum>  $enum
     * @param  array<string, mixed>  $extra
     * @return ModuleField
     */
    private static function select(string $name, string $label, string $enum, array $extra = []): array
    {
        return self::field($name, $label, 'select', ['options' => array_column($enum::cases(), 'value')] + $extra);
    }
}
