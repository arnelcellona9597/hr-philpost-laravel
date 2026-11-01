<?php

namespace App\Support;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Reads a submitted form using a module's field definitions, so forms and Excel imports share one
 * set of rules. Blank inputs become null, and malformed values are reported, never guessed.
 *
 * @phpstan-import-type ModuleField from ImportModules
 */
class RecordForm
{
    /**
     * Parse the fields present in the request. `data` is keyed by column, `errors` by field name.
     *
     * @param  list<ModuleField>  $fields
     * @param  array<string, string>  $unique  Columns that must be unique, with their message
     * @return array{data: array<string, mixed>, errors: array<string, string>}
     */
    public static function parse(Request $request, array $fields, string $table, array $unique = [], ?Model $record = null): array
    {
        $limits = self::columnLimits($table);
        $data = [];
        $errors = [];

        foreach ($fields as $field) {
            if (($field['computed'] ?? false) || ! $request->exists($field['name'])) {
                continue;
            }

            $column = self::column($field);
            $raw = trim((string) $request->input($field['name']));

            if ($raw === '') {
                $data[$column] = null;

                if (($field['required'] ?? false) && ! ($field['fromEmployee'] ?? false)) {
                    $errors[$field['name']] = "{$field['label']} is required";
                }

                continue;
            }

            [$value, $error] = self::convert($field, $raw);

            if ($error === null && is_string($value) && isset($limits[$column]) && mb_strlen($value) > $limits[$column]) {
                $error = "{$field['label']} must be at most {$limits[$column]} characters";
            }

            if ($error === null && isset($unique[$column]) && self::isTaken($table, $column, $value, $record)) {
                $error = $unique[$column];
            }

            if ($error !== null) {
                $errors[$field['name']] = $error;
            } else {
                $data[$column] = $value;
            }
        }

        return ['data' => $data, 'errors' => $errors];
    }

    /**
     * A saved record's values as strings for form inputs, keyed by field name.
     *
     * @param  list<ModuleField>  $fields
     * @return array<string, string>
     */
    public static function values(Model $record, array $fields): array
    {
        $values = [];

        foreach ($fields as $field) {
            if ($field['computed'] ?? false) {
                continue;
            }

            $value = $record->getAttribute(self::column($field));

            $values[$field['name']] = match (true) {
                $value instanceof BackedEnum => (string) $value->value,
                $value instanceof DateTimeInterface => $value->format('Y-m-d'),
                default => (string) $value,
            };
        }

        return $values;
    }

    /**
     * The database column behind a field. Relationship fields are named after the relation.
     *
     * @param  ModuleField  $field
     */
    public static function column(array $field): string
    {
        return match ($field['kind']) {
            'branch' => "{$field['name']}_id",
            'employee' => 'employee_id',
            default => $field['name'],
        };
    }

    /**
     * @param  ModuleField  $field
     * @return array{0: mixed, 1: string|null}
     */
    public static function convert(array $field, string $raw): array
    {
        $label = $field['label'];

        switch ($field['kind']) {
            case 'number':
                if (! is_numeric($raw)) {
                    return [null, "{$label} must be a number"];
                }

                if ((isset($field['min']) && (float) $raw < $field['min']) || (isset($field['max']) && (float) $raw > $field['max'])) {
                    return [null, "{$label} must be between {$field['min']} and {$field['max']}"];
                }

                return [(float) $raw, null];

            case 'year':
                return ctype_digit($raw) && (int) $raw >= 2000 && (int) $raw <= 2100 ? [(int) $raw, null] : [null, 'Enter a 4-digit year'];

            case 'date':
                return HrDate::isValid($raw) ? [$raw, null] : [null, "{$label} must be a valid date"];

            case 'branch':
            case 'employee':
                return ctype_digit($raw) ? [(int) $raw, null] : [null, "Select a valid {$field['kind']}"];

            case 'select':
                return in_array($raw, $field['options'] ?? [], true) ? [$raw, null] : [null, "Choose a valid {$label}"];

            case 'textarea':
                return [$raw, null];

            case 'govId':
                return [GovId::format($field['idKind'], $raw), GovId::validate($field['idKind'], $raw)];

            case 'email':
                return [self::clean($raw), GovId::validateEmail($raw)];

            case 'phone':
                return [self::clean($raw), GovId::validatePhone($raw)];

            default:
                return [self::clean($raw), null];
        }
    }

    /**
     * Collapse runs of whitespace, so "blank" and spacing are stored one way.
     */
    private static function clean(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value));
    }

    private static function isTaken(string $table, string $column, mixed $value, ?Model $record): bool
    {
        return Schema::getConnection()->table($table)
            ->where($column, $value)
            ->when($record?->exists, fn ($query) => $query->where('id', '!=', $record->getKey()))
            ->exists();
    }

    /**
     * Maximum lengths of the table's string columns.
     *
     * @return array<string, int>
     */
    public static function columnLimits(string $table): array
    {
        $limits = [];

        foreach (Schema::getColumns($table) as $column) {
            if (preg_match('/^varchar\((\d+)\)$/', $column['type'], $matches)) {
                $limits[$column['name']] = (int) $matches[1];
            }
        }

        return $limits;
    }
}
