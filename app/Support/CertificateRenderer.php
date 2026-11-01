<?php

namespace App\Support;

/**
 * Certificate blocks as HTML for the certificate page, the print view and the PDF. Every piece of
 * text is escaped here. The PDF engine has no flexbox, so letterheads and signatures become tables.
 *
 * @phpstan-import-type Block from CertificateBlocks
 *
 * @phpstan-type RenderOptions array{companyName: string, font: string, logoUrl?: string|null, logoSize?: array{0: float, 1: float}|null, values?: array<string, string>, pdf?: bool}
 */
class CertificateRenderer
{
    /**
     * The full certificate body. Omit `values` when the blocks are already merged.
     *
     * @param  list<Block>  $blocks
     * @param  RenderOptions  $options
     */
    public static function html(array $blocks, array $options): string
    {
        $body = implode('', array_map(fn (array $block): string => self::block($block, $options), $blocks));

        return '<div class="cert cert-'.e($options['font']).'">'.$body.'</div>';
    }

    /**
     * @param  Block  $block
     * @param  RenderOptions  $options
     */
    private static function block(array $block, array $options): string
    {
        return match ($block['type']) {
            'header' => self::header($block, $options),
            // Not a heading: the certificate sits inside pages that already have their own.
            'title' => '<div class="cert-title cert-'.e($block['align']).' cert-t-'.e($block['size']).($block['wide'] ? ' cert-wide' : '').'">'.self::inline($block['text'], $options).'</div>',
            'paragraph' => '<p class="cert-p cert-'.e($block['align']).' cert-s-'.e($block['size']).($block['indent'] ? ' cert-indent' : '').'">'.(self::inline($block['text'], $options) ?: '&nbsp;').'</p>',
            'details' => self::details($block, $options),
            'signatory' => self::signatory($block, $options),
            'spacer' => '<div style="height:'.CertificateBlocks::SPACER_POINTS[$block['size']].'pt"></div>',
            'divider' => '<hr class="cert-hr">',
            'footer' => '<p class="cert-footer cert-'.e($block['align']).'">'.self::inline($block['text'], $options).'</p>',
            default => '',
        };
    }

    /**
     * @param  Block  $block
     * @param  RenderOptions  $options
     */
    private static function header(array $block, array $options): string
    {
        $name = trim($block['companyName']) !== '' ? self::inline($block['companyName'], $options) : e($options['companyName']);
        $lines = trim($block['lines']) !== '' ? '<div class="cert-lines">'.self::inline(trim($block['lines']), $options).'</div>' : '';
        $text = '<div class="cert-company">'.$name.'</div>'.$lines;
        $logo = '';

        if ($block['showLogo'] && ($options['logoUrl'] ?? null)) {
            $size = ($options['logoSize'] ?? null) ? ' style="width:'.$options['logoSize'][0].'pt;height:'.$options['logoSize'][1].'pt"' : '';
            $logo = '<img class="cert-logo" src="'.e($options['logoUrl']).'" alt=""'.$size.'>';
        }

        if (($options['pdf'] ?? false) && $block['align'] === 'left' && $logo !== '') {
            return '<table class="cert-layout cert-header"><tr><td class="cert-logo-cell">'.$logo.'</td><td>'.$text.'</td></tr></table>';
        }

        return '<div class="cert-header cert-'.e($block['align']).'">'.$logo.'<div>'.$text.'</div></div>';
    }

    /**
     * @param  Block  $block
     * @param  RenderOptions  $options
     */
    private static function details(array $block, array $options): string
    {
        $rows = implode('', array_map(function (string $field) use ($options): string {
            [$key, $frozen] = str_contains($field, '=') ? explode('=', $field, 2) : [$field, null];
            $value = $frozen ?? ($options['values'][$key] ?? '');

            return '<tr><th>'.e(CertificateBlocks::FIELDS[$key]['label'] ?? $key).'</th><td>'.e($value).'</td></tr>';
        }, $block['fields']));

        return '<table class="cert-details"><tbody>'.$rows.'</tbody></table>';
    }

    /**
     * @param  Block  $block
     * @param  RenderOptions  $options
     */
    private static function signatory(array $block, array $options): string
    {
        $columns = array_map(function (array $signer) use ($options): string {
            $label = $signer['label'] !== '' ? '<div class="cert-sig-label">'.self::inline($signer['label'], $options).'</div>' : '';

            return '<div class="cert-signer">'.$label.'<div class="cert-sig-line"></div><div class="cert-sig-name">'
                .(self::inline($signer['name'], $options) ?: '&nbsp;').'</div><div class="cert-sig-title">'.self::inline($signer['title'], $options).'</div></div>';
        }, $block['signers']);

        if (! ($options['pdf'] ?? false)) {
            return '<div class="cert-signatory cert-'.e($block['align']).'">'.implode('', $columns).'</div>';
        }

        $cells = implode('<td class="cert-sig-gap"></td>', array_map(fn (string $column): string => '<td class="cert-sig-cell">'.$column.'</td>', $columns));
        $before = in_array($block['align'], ['center', 'right'], true) ? '<td></td>' : '';
        $after = in_array($block['align'], ['center', 'left'], true) ? '<td></td>' : '';

        return '<table class="cert-layout cert-signatory-table cert-'.e($block['align']).'"><tr>'.$before.$cells.$after.'</tr></table>';
    }

    /**
     * @param  RenderOptions  $options
     */
    private static function inline(string $text, array $options): string
    {
        return self::rich(isset($options['values']) ? MergeTemplate::render($text, $options['values']) : $text);
    }

    /**
     * Escaped text with **bold** runs and line breaks.
     */
    private static function rich(string $text): string
    {
        $parts = preg_split('/(\*\*[^*]+\*\*)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        $html = implode('', array_map(
            fn (string $part): string => preg_match('/^\*\*[^*]+\*\*$/', $part) ? '<strong>'.e(substr($part, 2, -2)).'</strong>' : e($part),
            $parts,
        ));

        return str_replace("\n", '<br>', $html);
    }
}
