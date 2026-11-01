<?php

namespace App\Services;

use App\Enums\CertificateStatus;
use App\Exceptions\UserFacingException;
use App\Models\Certificate;
use App\Models\CertificateTemplate;
use App\Models\DocumentSequence;
use App\Models\Employee;
use App\Models\SiteSetting;
use App\Models\User;
use App\Support\CertificateBlocks;
use App\Support\HrDate;

/**
 * Issues certificates. The merged text is frozen on the record, so reprints stay identical even
 * after the template or the employee changes.
 */
class CertificateService
{
    /**
     * The frozen content of a certificate. Its keys match the old system's stored JSON.
     *
     * @return array{blocks: list<array<string, mixed>>, paperSize: string, font: string, margin: string, companyName: string, logoId: int|null}
     */
    public function compose(CertificateTemplate $template, Employee $employee, ?string $purpose, string $issuedDate, string $controlNumber): array
    {
        $site = SiteSetting::current();
        $values = CertificateBlocks::values($employee->loadMissing('station'), $issuedDate, $purpose, $controlNumber, $site->company_name);

        return [
            'blocks' => array_map(
                fn (array $block): array => $block['type'] === 'letterhead' ? [...$block, ...$site->letterhead()] : $block,
                CertificateBlocks::merge(CertificateBlocks::sanitize($template->blocks), $values),
            ),
            'paperSize' => $template->paper_size,
            'font' => $template->font,
            'margin' => $template->margin,
            'companyName' => $site->company_name,
            'logoId' => $site->logo_id,
        ];
    }

    /**
     * @throws UserFacingException when a choice is missing or the template is inactive
     */
    public function issue(User $user, ?int $templateId, ?int $employeeId, ?string $purpose, ?string $issuedDate): Certificate
    {
        $employee = $employeeId ? Employee::query()->find($employeeId) : null;

        if ($employee === null) {
            throw new UserFacingException('Choose an employee.');
        }

        $template = $templateId ? CertificateTemplate::query()->find($templateId) : null;

        if ($template === null) {
            throw new UserFacingException('Choose a certificate.');
        }

        if (! $template->active) {
            throw new UserFacingException('This certificate template is inactive. Activate it or choose another one.');
        }

        $issuedDate = $issuedDate ?: HrDate::today();

        if (! HrDate::isValid($issuedDate)) {
            throw new UserFacingException('Enter a valid date issued.');
        }

        // Layout errors surface before a number is claimed.
        CertificateBlocks::sanitize($template->blocks);

        $controlNumber = DocumentSequence::nextCertificateNumber($template->prefix, (int) substr($issuedDate, 0, 4));
        $content = $this->compose($template, $employee, $purpose, $issuedDate, $controlNumber);

        $certificate = new Certificate([
            'template_id' => $template->id,
            'employee_id' => $employee->id,
            'purpose' => trim((string) $purpose) ?: null,
            'issued_date' => $issuedDate,
        ]);

        $certificate->forceFill([
            'control_number' => $controlNumber,
            'template_name' => $template->name,
            'employee_name' => CertificateBlocks::values($employee, $issuedDate, null, null, '')['fullName'],
            'content' => $content,
            'issued_by' => $user->id,
            'issued_by_name' => $user->name,
            'status' => CertificateStatus::Valid,
        ])->save();

        return $certificate;
    }

    /**
     * Voiding is the only change an issued certificate accepts, and it cannot be undone.
     *
     * @throws UserFacingException without a reason, or when it is already void
     */
    public function void(Certificate $certificate, ?string $reason): void
    {
        $reason = trim((string) $reason);

        if ($certificate->isVoid()) {
            throw new UserFacingException('This certificate is already void. Issue a new one instead.');
        }

        if ($reason === '') {
            throw new UserFacingException('Give a reason for voiding the certificate.');
        }

        $certificate->forceFill([
            'status' => CertificateStatus::Void,
            'void_reason' => mb_substr($reason, 0, 300),
            'voided_at' => now(),
        ])->save();
    }
}
