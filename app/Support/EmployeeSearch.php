<?php

namespace App\Support;

use App\Enums\BloodType;
use App\Enums\CivilStatus;
use App\Enums\Classification;
use App\Enums\EducationalAttainment;
use App\Enums\EmploymentStatus;
use App\Enums\Gender;
use App\Enums\Region;
use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Advanced employee search: URL parameters to a query. The same class will power "Export these
 * results", so an export always matches what's on screen.
 */
class EmployeeSearch
{
    private const SORTS = [
        'fullName' => 'full_name',
        'employeeId' => 'employee_id',
        'position' => 'position',
        'classification' => 'classification',
        'employmentStatus' => 'employment_status',
        'dateHired' => 'date_hired',
        'dateOfBirth' => 'date_of_birth',
        'updatedAt' => 'updated_at',
    ];

    private const TEXT_COLUMNS = ['full_name', 'employee_id', 'email', 'position', 'contact_number', 'sss', 'tin', 'pagibig', 'philhealth', 'bank_account_number'];

    public string $text;

    /** @var list<string> */
    public array $status;

    /** @var list<string> */
    public array $classification;

    /** @var list<int> */
    public array $branch;

    /** @var list<string> */
    public array $gender;

    /** @var list<string> */
    public array $region;

    /** @var list<string> */
    public array $civilStatus;

    /** @var list<string> */
    public array $bloodType;

    /** @var list<string> */
    public array $education;

    public string $position;

    public ?string $hiredFrom;

    public ?string $hiredTo;

    public ?int $ageMin;

    public ?int $ageMax;

    public ?int $losMin;

    public ?int $losMax;

    public string $sort;

    public int $limit;

    public function __construct(Request $request)
    {
        $this->text = Str::limit(trim((string) $request->query('q')), 100, '');
        $this->status = self::choices($request, 'status', EmploymentStatus::class);
        $this->classification = self::choices($request, 'classification', Classification::class);
        $this->gender = self::choices($request, 'gender', Gender::class);
        $this->region = self::choices($request, 'region', Region::class);
        $this->civilStatus = self::choices($request, 'civilStatus', CivilStatus::class);
        $this->bloodType = self::choices($request, 'bloodType', BloodType::class);
        $this->education = self::choices($request, 'education', EducationalAttainment::class);
        $this->branch = array_values(array_map(intval(...), array_filter(Arr::wrap($request->query('branch')), fn (mixed $id): bool => is_string($id) && ctype_digit($id) && $id !== '0')));
        $this->position = Str::limit(trim((string) $request->query('position')), 100, '');
        $this->hiredFrom = self::date($request, 'hiredFrom');
        $this->hiredTo = self::date($request, 'hiredTo');
        $this->ageMin = self::number($request, 'ageMin');
        $this->ageMax = self::number($request, 'ageMax');
        $this->losMin = self::number($request, 'losMin');
        $this->losMax = self::number($request, 'losMax');

        $sort = (string) $request->query('sort', 'fullName');
        $this->sort = isset(self::SORTS[ltrim($sort, '-')]) ? $sort : 'fullName';
        $this->limit = in_array((int) $request->query('limit'), [25, 50, 100], true) ? (int) $request->query('limit') : 25;
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function apply(Builder $query, ?string $today = null): Builder
    {
        $today ??= HrDate::today();

        // Every word must match somewhere: name, ID, email, position, phone or a government ID number.
        foreach (array_slice(preg_split('/\s+/', $this->text, -1, PREG_SPLIT_NO_EMPTY), 0, 6) as $word) {
            $query->where(function (Builder $words) use ($word): void {
                foreach (self::TEXT_COLUMNS as $column) {
                    $words->orWhere($column, 'like', self::contains($word));
                }
            });
        }

        $filters = [
            'employment_status' => $this->status,
            'classification' => $this->classification,
            'station_id' => $this->branch,
            'gender' => $this->gender,
            'region' => $this->region,
            'civil_status' => $this->civilStatus,
            'blood_type' => $this->bloodType,
            'educational_attainment' => $this->education,
        ];

        foreach ($filters as $column => $values) {
            $query->when($values !== [], fn (Builder $filtered) => $filtered->whereIn($column, $values));
        }

        $query->when($this->position !== '', fn (Builder $filtered) => $filtered->where('position', 'like', self::contains($this->position)));
        $query->when($this->hiredFrom, fn (Builder $filtered) => $filtered->where('date_hired', '>=', $this->hiredFrom));
        $query->when($this->hiredTo, fn (Builder $filtered) => $filtered->where('date_hired', '<=', $this->hiredTo));

        // Age N means born on or before (today − N years) and after (today − (N+1) years).
        $query->when($this->ageMin !== null, fn (Builder $filtered) => $filtered->where('date_of_birth', '<=', HrDate::addYears($today, -$this->ageMin)));
        $query->when($this->ageMax !== null, fn (Builder $filtered) => $filtered->where('date_of_birth', '>', HrDate::addYears($today, -($this->ageMax + 1))));

        // Length of service in whole years, measured from the hire date to today.
        $query->when($this->losMin !== null, fn (Builder $filtered) => $filtered->where('date_hired', '<=', HrDate::addYears($today, -$this->losMin)));
        $query->when($this->losMax !== null, fn (Builder $filtered) => $filtered->where('date_hired', '>', HrDate::addYears($today, -($this->losMax + 1))));

        return $query
            ->orderBy(self::SORTS[ltrim($this->sort, '-')], str_starts_with($this->sort, '-') ? 'desc' : 'asc')
            ->orderBy('id');
    }

    /**
     * Number of active filters other than the free-text box.
     */
    public function activeFilterCount(): int
    {
        $lists = [$this->status, $this->classification, $this->branch, $this->gender, $this->region, $this->civilStatus, $this->bloodType, $this->education];
        $singles = [$this->position ?: null, $this->hiredFrom, $this->hiredTo, $this->ageMin, $this->ageMax, $this->losMin, $this->losMax];

        return array_sum(array_map(count(...), $lists)) + count(array_filter($singles, fn (mixed $value): bool => $value !== null));
    }

    /**
     * Escape LIKE wildcards so a typed % or _ is matched literally.
     */
    private static function contains(string $text): string
    {
        return '%'.addcslashes($text, '%_\\').'%';
    }

    /**
     * @param  class-string<BackedEnum>  $enum
     * @return list<string>
     */
    private static function choices(Request $request, string $key, string $enum): array
    {
        return array_values(array_intersect(Arr::wrap($request->query($key)), array_column($enum::cases(), 'value')));
    }

    private static function date(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && HrDate::isValid($value) ? $value : null;
    }

    private static function number(Request $request, string $key): ?int
    {
        $value = $request->query($key);

        return is_string($value) && ctype_digit($value) ? (int) $value : null;
    }
}
