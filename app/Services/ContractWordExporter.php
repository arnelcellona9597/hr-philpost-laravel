<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Media;
use App\Support\CertificateBlocks;
use App\Support\MergeTemplate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\SimpleType\Jc;

/**
 * An issued contract as a Word document (.docx), built from the same frozen blocks as the PDF so the two
 * match. The page footer block becomes a real Word footer, which repeats on every page.
 *
 * @phpstan-import-type Block from CertificateBlocks
 */
class ContractWordExporter
{
    private const ALIGN = ['left' => Jc::START, 'center' => Jc::CENTER, 'right' => Jc::END, 'justify' => Jc::BOTH];

    private const TITLE_POINTS = ['md' => 16, 'lg' => 20, 'xl' => 24];

    private const TEXT_POINTS = ['sm' => 10.5, 'md' => 12, 'lg' => 13.5];

    /**
     * The .docx file as bytes.
     */
    public function render(Contract $contract): string
    {
        $content = $contract->content;
        $paper = CertificateBlocks::PAPER_POINTS[$content['paperSize']] ?? CertificateBlocks::PAPER_POINTS['A4'];
        $margin = (int) ((CertificateBlocks::MARGIN_POINTS[$content['margin']] ?? 72) * 20);
        $font = $content['font'] === 'sans' ? 'Arial' : 'Times New Roman';
        $escaping = Settings::isOutputEscapingEnabled();
        Settings::setOutputEscapingEnabled(true);

        try {
            $word = new PhpWord;
            $word->setDefaultFontName($font);
            $word->setDefaultFontSize($content['font'] === 'sans' ? 11 : 12);
            $word->getDocInfo()->setTitle($contract->control_number)->setSubject('Contract of Service');
            $section = $word->addSection([
                'pageSizeW' => (int) ($paper[0] * 20),
                'pageSizeH' => (int) ($paper[1] * 20),
                'marginTop' => $margin,
                'marginBottom' => $margin,
                'marginLeft' => $margin,
                'marginRight' => $margin,
            ]);

            foreach ($content['blocks'] as $block) {
                $this->block($section, $block, $content);
            }

            if ($contract->isVoid()) {
                $section->addHeader()->addText('VOID', ['bold' => true, 'size' => 28, 'color' => 'C8102E'], ['alignment' => Jc::CENTER]);
            }

            $path = tempnam(sys_get_temp_dir(), 'contract');
            IOFactory::createWriter($word, 'Word2007')->save($path);
            $bytes = (string) file_get_contents($path);
            @unlink($path);

            return $bytes;
        } finally {
            Settings::setOutputEscapingEnabled($escaping);
        }
    }

    public function filename(Contract $contract): string
    {
        return trim(preg_replace('/-+/', '-', preg_replace('/[^\w.-]+/', '-', Str::ascii("{$contract->control_number}-{$contract->employee_name}"))), '-').'.docx';
    }

    /**
     * @param  Block  $block
     * @param  array<string, mixed>  $content
     */
    private function block(Section $section, array $block, array $content): void
    {
        switch ($block['type']) {
            case 'header':
                $this->header($section, $block, $content);
                break;

            case 'title':
                $section->addText($this->plain($block['text']), ['bold' => true, 'size' => self::TITLE_POINTS[$block['size']] ?? 16], ['alignment' => self::ALIGN[$block['align']] ?? Jc::CENTER, 'spaceAfter' => 120]);
                break;

            case 'paragraph':
                $this->paragraph($section, $block);
                break;

            case 'signatory':
                $this->signatory($section, $block);
                break;

            case 'spacer':
                $section->addText('', ['size' => 2], ['spaceBefore' => 0, 'spaceAfter' => (int) (CertificateBlocks::SPACER_POINTS[$block['size']] * 20)]);
                break;

            case 'divider':
                $section->addText('', ['size' => 2], ['borderBottomSize' => 6, 'borderBottomColor' => '333333', 'spaceAfter' => 80]);
                break;

            case 'footer':
                $section->addText($this->plain($block['text']), ['size' => 9, 'color' => '444444'], ['alignment' => self::ALIGN[$block['align']] ?? Jc::START]);
                break;

            case 'pageBreak':
                $section->addPageBreak();
                break;

            case 'pageFooter':
                $section->addFooter()->addText($this->plain($block['text']), ['size' => 9, 'color' => '444444'], ['alignment' => self::ALIGN[$block['align']] ?? Jc::CENTER]);
                break;
        }
    }

    /**
     * @param  Block  $block
     * @param  array<string, mixed>  $content
     */
    private function header(Section $section, array $block, array $content): void
    {
        $align = ['alignment' => $block['align'] === 'left' ? Jc::START : Jc::CENTER, 'spaceAfter' => 40];
        $logo = $block['showLogo'] && ($content['logoId'] ?? null) ? Media::query()->find($content['logoId']) : null;

        if ($logo !== null && in_array($logo->mime_type, ['image/png', 'image/jpeg'], true) && Storage::disk('local')->exists($logo->path)) {
            $section->addImage(Storage::disk('local')->path($logo->path), ['height' => 48, 'alignment' => $align['alignment']]);
        }

        $name = trim($block['companyName']) !== '' ? $block['companyName'] : $content['companyName'];
        $section->addText($this->plain($name), ['bold' => true, 'size' => 14], $align);

        foreach (array_filter(array_map('trim', explode("\n", $block['lines']))) as $line) {
            $section->addText($this->plain($line), ['size' => 10, 'color' => '333333'], $align);
        }
    }

    /**
     * Text with **bold** runs, one Word paragraph per line break.
     *
     * @param  Block  $block
     */
    private function paragraph(Section $section, array $block): void
    {
        $style = [
            'alignment' => self::ALIGN[$block['align']] ?? Jc::BOTH,
            'spaceAfter' => 120,
            'indentation' => $block['indent'] ? ['firstLine' => 720] : [],
        ];
        $size = self::TEXT_POINTS[$block['size']] ?? 12;

        foreach (explode("\n", $block['text']) as $line) {
            $run = $section->addTextRun($style);
            $this->runs($run, $line, $size);
        }
    }

    private function runs(TextRun $run, string $line, float $size): void
    {
        foreach (preg_split('/(\*\*[^*]+\*\*)/', $line, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            $bold = (bool) preg_match('/^\*\*[^*]+\*\*$/', $part);
            $run->addText($bold ? substr($part, 2, -2) : $part, ['bold' => $bold, 'size' => $size]);
        }
    }

    /**
     * @param  Block  $block
     */
    private function signatory(Section $section, array $block): void
    {
        $table = $section->addTable(['alignment' => self::ALIGN[$block['align']] ?? Jc::START, 'cellMargin' => 80]);
        $row = $table->addRow();
        $alignment = $block['align'] === 'left' ? Jc::START : Jc::CENTER;

        foreach ($block['signers'] as $signer) {
            $cell = $row->addCell(3000);
            $cell->addText($this->plain($signer['label']), ['size' => 10.5], ['alignment' => Jc::START, 'spaceAfter' => 480]);
            $cell->addText('', ['size' => 2], ['borderTopSize' => 6, 'borderTopColor' => '111111', 'spaceAfter' => 0]);
            $cell->addText(mb_strtoupper($this->plain($signer['name'])), ['bold' => true], ['alignment' => $alignment, 'spaceAfter' => 0]);
            $cell->addText($this->plain($signer['title']), ['size' => 10.5], ['alignment' => $alignment]);
        }

        $section->addText('', ['size' => 2], ['spaceAfter' => 120]);
    }

    /**
     * Text with no markup: leftover placeholders are removed, and **bold** marks are dropped.
     */
    private function plain(string $text): string
    {
        return str_replace('**', '', MergeTemplate::render($text, []));
    }
}
