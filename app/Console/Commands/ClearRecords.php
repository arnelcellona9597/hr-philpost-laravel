<?php

namespace App\Console\Commands;

use App\Enums\RemittanceKind;
use App\Models\ContractSetting;
use App\Models\Media;
use App\Models\RemittanceSetting;
use App\Models\RequirementDeadlineSetting;
use App\Models\VoucherSetting;
use App\Services\MediaStore;
use App\Support\AuditTrail;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

#[Signature('hr:clear-records {--force : Delete without asking}')]
#[Description('Delete every employee record and its history, keeping user accounts, templates and settings')]
class ClearRecords extends Command
{
    /**
     * Children come before parents, so no restricting foreign key ever fires.
     */
    public const TABLES = [
        'message_recipients', 'message_attachments', 'messages', 'notifications',
        'remittance_lines', 'remittances', 'received_mails', 'import_jobs', 'audit_logs',
        'contracts', 'certificates', 'payslips', 'payroll_periods',
        'pay_changes', 'position_changes', 'ipcr_ratings', 'pds_submissions', 'sworn_declarations', 'itr_submissions',
        'wellness_leaves', 'employment_periods', 'applications',
        'employees', 'branches',
        'document_sequences', 'jobs', 'failed_jobs',
    ];

    /**
     * Setting fields that hold names and numbers of one office, which the demo data fills with sample values.
     */
    private const CONTRACT_FIELDS = ['area_director_name', 'adfin_chief_name', 'hrm_chief_name', 'budget_officer_name', 'wage_order', 'default_place_of_service'];

    private const VOUCHER_FIELDS = ['fund_cluster', 'responsibility_center', 'supervisor_name', 'accountant_name', 'approver_name'];

    private const REMITTANCE_FIELDS = ['employer_id', 'employer_name', 'employer_address', 'employer_contact', 'prepared_by_name', 'noted_by_name', 'voucher_payee', 'voucher_address'];

    /**
     * Execute the console command.
     */
    public function handle(MediaStore $media): int
    {
        if (! $this->option('force') && $this->ask('This deletes every employee, branch and record. User accounts, templates and settings stay. Type DELETE to continue') !== 'DELETE') {
            $this->warn('Nothing was deleted.');

            return self::FAILURE;
        }

        $files = $this->employeeFiles();

        $removed = DB::transaction(fn (): array => AuditTrail::withoutRecording(function (): array {
            $removed = [];

            foreach (self::TABLES as $table) {
                $removed[$table] = DB::table($table)->delete();
            }

            $removed['wage_orders (demo only)'] = DB::table('wage_orders')->where('order_number', 'like', 'DEMO-%')->delete();
            $this->blankSettings();

            return $removed;
        }));

        // Deleting skips any file still used elsewhere, such as the site logo or an avatar.
        foreach (Media::query()->whereKey($files)->get() as $file) {
            $media->delete($file);
        }

        Cache::flush();

        $this->table(['Table', 'Rows removed'], array_map(null, array_keys($removed), array_values($removed)));
        $this->info('Done. User accounts, templates, scopes, holidays and wage rates were kept.');

        return self::SUCCESS;
    }

    /**
     * Profile pictures and message attachments, which go with the records.
     *
     * @return list<int>
     */
    private function employeeFiles(): array
    {
        return DB::table('employees')->whereNotNull('profile_picture_id')->pluck('profile_picture_id')
            ->merge(DB::table('message_attachments')->pluck('media_id'))
            ->unique()->values()->all();
    }

    private function blankSettings(): void
    {
        ContractSetting::current()->update(array_fill_keys(self::CONTRACT_FIELDS, null));
        VoucherSetting::current()->update(array_fill_keys(self::VOUCHER_FIELDS, null));

        foreach (RemittanceKind::cases() as $kind) {
            $defaults = $kind->defaults();

            RemittanceSetting::forKind($kind)->update(array_combine(self::REMITTANCE_FIELDS, array_map(fn (string $field): mixed => $defaults[$field] ?? null, self::REMITTANCE_FIELDS)));
        }

        RequirementDeadlineSetting::current()->update(['overrides' => []]);
    }
}
