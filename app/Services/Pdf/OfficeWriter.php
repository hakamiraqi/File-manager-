<?php

namespace App\Services\Pdf;

use ZipArchive;

/**
 * Small Excel and PowerPoint writers for «PDF إلى Excel / PowerPoint» (2026-11-12, phase 4).
 * Written by hand (a zip of XML) so they run the same on the VPS and the offline Windows server
 * with no extra library.
 */
class OfficeWriter
{
    /**
     * @param  array<string, list<list<string|int|float>>>  $sheets  sheet name => rows of cells
     */
    public static function xlsx(array $sheets, string $out): void
    {
        $zip = new ZipArchive;
        if ($zip->open($out, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new PdfToolException('تعذّر إنشاء ملف Excel.');
        }
        $sheets = $sheets ?: ['Sheet1' => [['']]];
        $names = [];
        $i = 0;
        $wbSheets = '';
        $wbRels = '';
        $overrides = '';
        foreach ($sheets as $name => $rows) {
            $i++;
            $name = self::sheetName((string) $name, $names);
            $names[] = $name;
            $rtl = false;
            $widths = [];
            $xmlRows = '';
            foreach (array_values($rows) as $r => $cells) {
                $xmlCells = '';
                foreach (array_values($cells) as $c => $v) {
                    $ref = self::col($c).($r + 1);
                    $widths[$c] = max($widths[$c] ?? 8, min(60, mb_strlen((string) $v) + 2));
                    if (is_int($v) || is_float($v)) {
                        $xmlCells .= '<c r="'.$ref.'"><v>'.$v.'</v></c>';
                    } elseif ($v !== '' && $v !== null) {
                        $rtl = $rtl || (bool) preg_match('/\p{Arabic}/u', (string) $v);
                        $xmlCells .= '<c r="'.$ref.'" t="inlineStr"><is><t xml:space="preserve">'.self::x((string) $v).'</t></is></c>';
                    }
                }
                $xmlRows .= '<row r="'.($r + 1).'">'.$xmlCells.'</row>';
            }
            $cols = '';
            foreach ($widths as $c => $w) {
                $cols .= '<col min="'.($c + 1).'" max="'.($c + 1).'" width="'.$w.'" customWidth="1"/>';
            }
            $zip->addFromString("xl/worksheets/sheet{$i}.xml", '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                .'<sheetViews><sheetView workbookViewId="0"'.($rtl ? ' rightToLeft="1"' : '').'/></sheetViews>'
                .($cols ? "<cols>{$cols}</cols>" : '').'<sheetData>'.$xmlRows.'</sheetData></worksheet>');
            $wbSheets .= '<sheet name="'.self::x($name).'" sheetId="'.$i.'" r:id="rId'.$i.'"/>';
            $wbRels .= '<Relationship Id="rId'.$i.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$i.'.xml"/>';
            $overrides .= '<Override PartName="/xl/worksheets/sheet'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        $n = $i + 1;
        $wbRels .= '<Relationship Id="rId'.$n.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .$overrides.'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'.$wbSheets.'</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$wbRels.'</Relationships>');
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="1"><font><sz val="11"/><name val="Arial"/></font></fonts>'
            .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
        $zip->close();
    }

    /**
     * One full-page picture per slide, the slides shaped like the first page, with each page's
     * text as the picture's description (screen readers and search can use it).
     *
     * @param  list<string>  $images  jpg paths in order
     * @param  list<string>  $notes  text per slide (optional)
     */
    public static function pptx(array $images, float $ratio, string $out, array $notes = []): void
    {
        $template = resource_path('office-templates/blank.pptx');
        if (! copy($template, $out)) {
            throw new PdfToolException('تعذّر إنشاء ملف PowerPoint.');
        }
        $zip = new ZipArchive;
        $zip->open($out);
        // Slide size: portrait pages as A4-like slides, landscape as 16:9-wide slides.
        $cx = $ratio > 1 ? 7560000 : 12192000;
        $cy = (int) round($cx * $ratio);
        $pres = (string) $zip->getFromName('ppt/presentation.xml');
        $rels = (string) $zip->getFromName('ppt/_rels/presentation.xml.rels');
        $types = (string) $zip->getFromName('[Content_Types].xml');
        // The template's own slide goes; ours take its place.
        $rels = preg_replace('#<Relationship Id="rId7"[^>]*/>#', '', $rels);
        $types = preg_replace('#<Override PartName="/ppt/slides/slide1\.xml"[^>]*/>#', '', $types);
        if (! str_contains($types, 'Extension="jpg"')) {
            $types = str_replace('<Default Extension="rels"', '<Default Extension="jpg" ContentType="image/jpeg"/><Default Extension="rels"', $types);
        }
        $ids = '';
        $newRels = '';
        $newTypes = '';
        foreach (array_values($images) as $k => $img) {
            $n = $k + 1;
            $rid = 'rId'.(100 + $n);
            $zip->addFile($img, "ppt/media/page{$n}.jpg");
            $zip->addFromString("ppt/slides/slide{$n}.xml", '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<p:sld xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                .'<p:cSld><p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr><p:grpSpPr/>'
                .'<p:pic><p:nvPicPr><p:cNvPr id="2" name="Page '.$n.'" descr="'.self::x(mb_substr((string) ($notes[$k] ?? ''), 0, 4000)).'"/><p:cNvPicPr><a:picLocks noChangeAspect="1"/></p:cNvPicPr><p:nvPr/></p:nvPicPr>'
                .'<p:blipFill><a:blip r:embed="rId2"/><a:stretch><a:fillRect/></a:stretch></p:blipFill>'
                .'<p:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="'.$cx.'" cy="'.$cy.'"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></p:spPr></p:pic>'
                .'</p:spTree></p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sld>');
            $zip->addFromString("ppt/slides/_rels/slide{$n}.xml.rels", '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout7.xml"/>'
                .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/page'.$n.'.jpg"/>'
                .'</Relationships>');
            $ids .= '<p:sldId id="'.(255 + $n).'" r:id="'.$rid.'"/>';
            $newRels .= '<Relationship Id="'.$rid.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide'.$n.'.xml"/>';
            $newTypes .= '<Override PartName="/ppt/slides/slide'.$n.'.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slide+xml"/>';
        }
        $pres = preg_replace('#<p:sldIdLst>.*?</p:sldIdLst>#s', '<p:sldIdLst>'.$ids.'</p:sldIdLst>', $pres);
        $pres = preg_replace('#<p:sldSz [^>]*/>#', '<p:sldSz cx="'.$cx.'" cy="'.$cy.'"/>', $pres);
        $rels = str_replace('</Relationships>', $newRels.'</Relationships>', $rels);
        $types = str_replace('</Types>', $newTypes.'</Types>', $types);
        $zip->addFromString('ppt/presentation.xml', $pres);
        $zip->addFromString('ppt/_rels/presentation.xml.rels', $rels);
        $zip->addFromString('[Content_Types].xml', $types);
        // The template's preview picture would show its own blank slide.
        $zip->deleteName('docProps/thumbnail.jpeg');
        $rootRels = (string) $zip->getFromName('_rels/.rels');
        $zip->addFromString('_rels/.rels', preg_replace('#<Relationship [^>]*thumbnail[^>]*/>#', '', $rootRels));
        $zip->close();
    }

    private static function col(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26).$s;
        }

        return $s;
    }

    private static function x(string $v): string
    {
        // Control characters are not allowed in XML.
        return htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $v), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function sheetName(string $name, array $taken): string
    {
        $name = mb_substr(trim(preg_replace('#[\[\]:*?/\\\\]#', ' ', $name)) ?: 'Sheet', 0, 28);
        $base = $name;
        for ($i = 2; in_array($name, $taken, true); $i++) {
            $name = $base.' '.$i;
        }

        return $name;
    }
}
