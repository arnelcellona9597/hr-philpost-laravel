<?php

namespace App\Services;

use App\Models\Certificate;
use App\Models\Media;
use App\Models\Payslip;
use App\Models\PayslipSetting;
use App\Models\Remittance;
use App\Models\SiteSetting;
use App\Support\CertificateBlocks;
use App\Support\CertificateRenderer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Certificates and payslips as PDF files. Logos are embedded, so the PDF engine never fetches a URL.
 */
class PdfRenderer
{
    private const PAYSLIP_PAPER = ['A4' => [595.28, 841.89], 'Letter' => [612, 792], 'Half-Letter' => [396, 612]];

    public function certificate(Certificate $certificate): string
    {
        $content = $certificate->content;
        $logo = $this->logo($content['logoId'] ?? null, 120, 56);
        $paper = CertificateBlocks::PAPER_POINTS[$content['paperSize']] ?? CertificateBlocks::PAPER_POINTS['A4'];

        return $this->render('pdf.certificate', [
            'title' => "{$certificate->control_number} — {$certificate->employee_name}",
            'body' => CertificateRenderer::html($content['blocks'], [
                'companyName' => $content['companyName'],
                'font' => $content['font'],
                'logoUrl' => $logo['src'] ?? null,
                'logoSize' => $logo['size'] ?? null,
                'pdf' => true,
            ]),
            'margin' => CertificateBlocks::MARGIN_POINTS[$content['margin']] ?? 72,
            'void' => $certificate->isVoid(),
            'sans' => $content['font'] === 'sans',
        ], $paper);
    }

    public function certificateFilename(Certificate $certificate): string
    {
        return $this->safeName("{$certificate->control_number}-{$certificate->employee_name}").'.pdf';
    }

    public function payslip(Payslip $payslip): string
    {
        $settings = PayslipSetting::current();
        $site = SiteSetting::current();
        $half = $settings->paper_size === 'Half-Letter';

        return $this->render('pdf.payslip', [
            'settings' => $settings,
            'companyName' => $settings->company_name ?: $site->company_name,
            'logo' => $settings->show_logo ? $this->logo($site->logo_id, 160, $half ? 30 : 42) : null,
            'period' => $payslip->period,
            'slip' => $payslip,
            'half' => $half,
        ], self::PAYSLIP_PAPER[$settings->paper_size] ?? self::PAYSLIP_PAPER['A4']);
    }

    public function payslipFilename(Payslip $payslip): string
    {
        return $this->safeName("payslip-{$payslip->period->code}-".($payslip->employee_code ?: $payslip->id)).'.pdf';
    }

    /**
     * The disbursement voucher for a remittance. `$data` is the same set of values the voucher page uses.
     *
     * @param  array<string, mixed>  $data
     */
    public function voucher(array $data): string
    {
        return $this->render('pdf.voucher', [...$data, 'logo' => $this->logo($data['site']->logo_id, 70, 60)], [595.28, 841.89]);
    }

    public function voucherFilename(Remittance $remittance): string
    {
        return $this->safeName("voucher-{$remittance->kind->value}-{$remittance->year}-".sprintf('%02d', $remittance->month)).'.pdf';
    }

    /**
     * A PNG or JPEG logo as a data URI, sized to fit the box in points.
     *
     * @return array{src: string, size: array{0: float, 1: float}}|null
     */
    private function logo(?int $mediaId, int $maxWidth, int $maxHeight): ?array
    {
        $media = $mediaId ? Media::query()->find($mediaId) : null;

        if ($media === null || ! in_array($media->mime_type, ['image/png', 'image/jpeg'], true) || ! Storage::disk('local')->exists($media->path)) {
            return null;
        }

        $bytes = Storage::disk('local')->get($media->path);
        $dimensions = @getimagesizefromstring($bytes);

        if ($dimensions === false || $dimensions[0] === 0 || $dimensions[1] === 0) {
            return null;
        }

        $scale = min($maxWidth / $dimensions[0], $maxHeight / $dimensions[1]);

        return [
            'src' => "data:{$media->mime_type};base64,".base64_encode($bytes),
            'size' => [round($dimensions[0] * $scale, 2), round($dimensions[1] * $scale, 2)],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{0: int|float, 1: int|float}  $paper  Page size in points
     */
    private function render(string $view, array $data, array $paper): string
    {
        return Pdf::loadView($view, $data)
            ->setOption(['isFontSubsettingEnabled' => true])
            ->setPaper([0, 0, $paper[0], $paper[1]])
            ->output();
    }

    private function safeName(string $name): string
    {
        return trim(preg_replace('/-+/', '-', preg_replace('/[^\w.-]+/', '-', Str::ascii($name))), '-');
    }
}
