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
use PhpOffice\PhpWord\Style\Cell;
use PhpOffice\PhpWord\Style\Tab;

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

    private const TEXT_POINTS = ['xs' => 10, 'sm' => 10.5, 'md' => 12, 'lg' => 13.5];

    /**
     * The .docx file as bytes.
     */
    public function render(Contract $contract): string
    {
        $content = $contract->content;
        $paper = CertificateBlocks::PAPER_POINTS[$content['paperSize']] ?? CertificateBlocks::PAPER_POINTS['A4'];
        $margin = array_map(fn (int $points): int => $points * 20, CertificateBlocks::marginBox($content['margin']));
        $font = CertificateBlocks::font($content['font']);
        $escaping = Settings::isOutputEscapingEnabled();
        Settings::setOutputEscapingEnabled(true);

        try {
            $word = new PhpWord;
            $word->setDefaultParagraphStyle(['lineHeight' => 1.0, 'spaceAfter' => 0, 'spaceBefore' => 0]);
            $word->setDefaultFontName($font['word']);
            $word->setDefaultFontSize($this->baseSize($content['blocks'], $font['points']));
            $word->getDocInfo()->setTitle($contract->control_number)->setSubject('Contract of Service');
            $section = $word->addSection([
                'pageSizeW' => (int) ($paper[0] * 20),
                'pageSizeH' => (int) ($paper[1] * 20),
                'marginTop' => $margin['top'],
                'marginBottom' => $margin['bottom'],
                'marginLeft' => $margin['left'],
                'marginRight' => $margin['right'],
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

    /**
     * The size of the contract's body text, so empty lines and paragraph marks do not make lines taller.
     *
     * @param  list<Block>  $blocks
     */
    private function baseSize(array $blocks, int $fallback): float
    {
        foreach ($blocks as $block) {
            if ($block['type'] === 'paragraph') {
                return (float) (self::TEXT_POINTS[$block['size']] ?? $fallback);
            }
        }

        return (float) $fallback;
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

            case 'sideNames':
                $this->sideNames($section, $block);
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
                $this->pageFooter($section, $block);
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
     * @param  Block  $block
     */
    private function pageFooter(Section $section, array $block): void
    {
        $footer = $section->addFooter();
        $columns = explode(' | ', $block['text']);

        if (count($columns) === 1) {
            $footer->addText($this->plain($block['text']), ['size' => 9, 'color' => '444444'], ['alignment' => self::ALIGN[$block['align']] ?? Jc::CENTER]);

            return;
        }

        $row = $footer->addTable()->addRow();

        foreach ($columns as $column) {
            $cell = $row->addCell((int) (9000 / count($columns)));

            foreach (explode("\n", $column) as $line) {
                $cell->addText($this->plain($line), ['size' => 7.5], ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
            }
        }
    }

    /**
     * Text with **bold** and __underlined__ runs, one Word paragraph per line break. With hanging clause
     * numbers, "1. Heading" and "6.1 item" lines hang their numbers as the office's contracts do.
     *
     * @param  Block  $block
     */
    private function paragraph(Section $section, array $block): void
    {
        $size = self::TEXT_POINTS[$block['size']] ?? 12;
        $after = (int) round($size * 1.22 * 20);
        $align = self::ALIGN[$block['align']] ?? Jc::BOTH;

        if (! ($block['list'] ?? false)) {
            $run = $section->addTextRun(['alignment' => $align, 'spaceAfter' => $after, 'indentation' => $block['indent'] ? ['firstLine' => 720] : []]);

            foreach (explode("\n", $block['text']) as $index => $line) {
                if ($index > 0) {
                    $run->addTextBreak();
                }

                $this->runs($run, $line, $size);
            }

            return;
        }

        foreach (explode("\n", $block['text']) as $line) {
            if (trim($line) === '') {
                $section->addTextRun(['spaceAfter' => 0])->addText('', ['size' => $size]);

                continue;
            }

            [$number, $text, $left, $hanging] = match (true) {
                (bool) preg_match('/^(\d+\.)\s+(.*)$/s', $line, $found) => [$found[1], $found[2], 720, 320],
                (bool) preg_match('/^(\d+\.\d+|[a-z]\.)\s+(.*)$/s', $line, $found) => [$found[1], $found[2], 1600, 580],
                str_starts_with($line, '> ') => [null, substr($line, 2), 720, 0],
                default => [null, $line, 0, 0],
            };

            $run = $section->addTextRun([
                'alignment' => $align,
                'spaceAfter' => 0,
                'indentation' => ['left' => $left, 'hanging' => $hanging],
                'tabs' => $hanging > 0 ? [new Tab('left', $left)] : [],
            ]);

            if ($number !== null) {
                $run->addText($number."\t", ['size' => $size]);
            }

            $this->runs($run, $text, $size);
        }

        $section->addTextRun(['spaceAfter' => 0])->addText('', ['size' => $size]);
    }

    private function runs(TextRun $run, string $line, float $size): void
    {
        foreach (preg_split('/(\*\*.+?\*\*)/s', $line, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            $bold = (bool) preg_match('/^\*\*(.+)\*\*$/s', $part);
            $inner = $bold ? substr($part, 2, -2) : $part;

            foreach (preg_split('/(~~.+?~~)/s', $inner, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $piece) {
                $underline = (bool) preg_match('/^~~(.+)~~$/s', $piece);
                $run->addText($underline ? substr($piece, 2, -2) : $piece, ['bold' => $bold, 'underline' => $underline ? 'single' : 'none', 'size' => $size]);
            }
        }
    }

    /**
     * Names and titles up the left margin on every page: a floating table in the header whose cells run
     * their text from bottom to top, each with a thin line on its left.
     *
     * @param  Block  $block
     */
    private function sideNames(Section $section, array $block): void
    {
        $entries = array_values(array_filter($block['signers'], fn (array $signer): bool => trim($signer['name'].$signer['title']) !== ''));

        if ($entries === []) {
            return;
        }

        $table = $section->addHeader()->addTable([
            'position' => ['horzAnchor' => 'page', 'vertAnchor' => 'page', 'tblpX' => 960, 'tblpY' => 2700],
            'cellMargin' => 0,
        ]);

        foreach ($entries as $index => $signer) {
            if ($index > 0) {
                $table->addRow(900, ['exactHeight' => true])->addCell(520);
            }

            $cell = $table->addRow(2600, ['exactHeight' => true])->addCell(520, ['textDirection' => Cell::TEXT_DIR_BTLR, 'vAlign' => 'center', 'borderLeftSize' => 6, 'borderLeftColor' => '111111']);
            $cell->addText($this->plain($signer['name']), ['bold' => true, 'size' => 9], ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
            $cell->addText($this->plain($signer['title']), ['size' => 9], ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
        }
    }

    /**
     * @param  Block  $block
     */
    private function signatory(Section $section, array $block): void
    {
        $line = $block['line'] ?? true;
        $width = ($block['spread'] ?? 'normal') === 'wide' ? 6760 : 3000;
        $table = $section->addTable(['alignment' => self::ALIGN[$block['align']] ?? Jc::START, 'cellMargin' => $line ? 80 : 0]);
        $row = $table->addRow();
        $alignment = $block['align'] === 'left' ? Jc::START : Jc::CENTER;

        foreach ($block['signers'] as $signer) {
            $cell = $row->addCell($width);
            $label = $this->plain($signer['label']);

            if ($label !== '' || $line) {
                $cell->addText($label, ['size' => 10.5], ['alignment' => Jc::START, 'spaceAfter' => $line ? 480 : 0]);
            }

            if ($line) {
                $cell->addText('', ['size' => 2], ['borderTopSize' => 6, 'borderTopColor' => '111111', 'spaceAfter' => 0]);
            }

            $cell->addText(mb_strtoupper($this->plain($signer['name'])), ['bold' => true, 'size' => 10], ['alignment' => $alignment, 'spaceAfter' => 0]);
            $cell->addText($this->plain($signer['title']), ['size' => 10], ['alignment' => $alignment]);
        }

        if ($line) {
            $section->addText('', ['size' => 2], ['spaceAfter' => 120]);
        }
    }

    /**
     * Text with no markup: leftover placeholders are removed, and **bold** marks are dropped.
     */
    private function plain(string $text): string
    {
        return str_replace(['**', '~~'], '', MergeTemplate::render($text, []));
    }
}
