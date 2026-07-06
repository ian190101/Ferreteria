<?php

namespace App\Modules\Exports\Services;

use ZipArchive;

class SimpleXlsxExporter
{
    public function save(array $dataset, string $path): void
    {
        if (! class_exists(ZipArchive::class)) {
            abort(500, 'La extension ZIP de PHP es necesaria para generar Excel.');
        }

        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $zip->addFromString('[Content_Types].xml', $this->contentTypes(count($dataset['sections'])));
        $zip->addFromString('_rels/.rels', $this->rootRels());
        $zip->addFromString('docProps/core.xml', $this->coreProperties($dataset));
        $zip->addFromString('docProps/app.xml', $this->appProperties($dataset['sections']));
        $zip->addFromString('xl/workbook.xml', $this->workbook($dataset['sections']));
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels(count($dataset['sections'])));
        $zip->addFromString('xl/styles.xml', $this->styles($dataset));

        foreach ($dataset['sections'] as $index => $section) {
            $zip->addFromString('xl/worksheets/sheet'.($index + 1).'.xml', $this->worksheet($section, $dataset));
        }

        $zip->close();
    }

    private function worksheet(array $section, array $dataset): string
    {
        $rows = [
            ['Modulo', $section['title']],
            ['Descripcion', $section['description']],
            ['Generado', $dataset['generated_at']],
            ['Sucursal', $dataset['filters']['branch']],
            ['Desde', $dataset['filters']['from']],
            ['Hasta', $dataset['filters']['to']],
            [],
            $section['headers'],
            ...$section['rows'],
        ];
        $maxColumn = max(1, collect($rows)->map(fn (array $row) => count($row))->max() ?: 1);
        $dimension = 'A1:'.$this->columnName($maxColumn).count($rows);

        $xmlRows = collect($rows)->map(function (array $row, int $rowIndex) {
            $rowNumber = $rowIndex + 1;
            $cells = collect($row)->values()->map(fn ($value, int $columnIndex) => $this->cell($columnIndex + 1, $rowNumber, $value, $rowNumber === 8))->implode('');

            return '<row r="'.$rowNumber.'">'.$cells.'</row>';
        })->implode('');

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<dimension ref="'.$dimension.'"/>'
            .'<sheetViews><sheetView workbookViewId="0"/></sheetViews>'
            .'<sheetFormatPr defaultRowHeight="15"/>'
            .'<sheetData>'.$xmlRows.'</sheetData>'
            .'<pageMargins left="0.7" right="0.7" top="0.75" bottom="0.75" header="0.3" footer="0.3"/>'
            .'</worksheet>';
    }

    private function cell(int $column, int $row, mixed $value, bool $header = false): string
    {
        $ref = $this->columnName($column).$row;
        $text = $this->xmlText((string) $value);
        $style = $header ? ' s="1"' : '';

        return '<c r="'.$ref.'" t="inlineStr"'.$style.'><is><t>'.$text.'</t></is></c>';
    }

    private function workbook(array $sections): string
    {
        $used = [];
        $sheets = collect($sections)->map(function (array $section, int $index) use (&$used) {
            $name = $this->sheetName($section['title'], $used);
            $sheetId = $index + 1;

            return '<sheet name="'.$name.'" sheetId="'.$sheetId.'" r:id="rId'.$sheetId.'"/>';
        })->implode('');

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<fileVersion appName="xl" lastEdited="7" lowestEdited="7" rupBuild="23426"/>'
            .'<workbookPr defaultThemeVersion="164011"/>'
            .'<bookViews><workbookView xWindow="0" yWindow="0" windowWidth="24000" windowHeight="12000"/></bookViews>'
            .'<sheets>'.$sheets.'</sheets>'
            .'<calcPr calcId="191029"/>'
            .'</workbook>';
    }

    private function workbookRels(int $count): string
    {
        $rels = collect(range(1, $count))->map(
            fn (int $index) => '<Relationship Id="rId'.$index.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$index.'.xml"/>'
        )->push('<Relationship Id="rId'.($count + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>')->implode('');

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'</Relationships>';
    }

    private function contentTypes(int $count): string
    {
        $sheets = collect(range(1, $count))->map(
            fn (int $index) => '<Override PartName="/xl/worksheets/sheet'.$index.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        )->implode('');

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            .'<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .$sheets
            .'</Types>';
    }

    private function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            .'<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            .'</Relationships>';
    }

    private function styles(array $dataset): string
    {
        $primary = $this->argb($dataset['branding']['primary'] ?? '#2563eb');

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts>'
            .'<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="'.$primary.'"/><bgColor indexed="64"/></patternFill></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'<dxfs count="0"/>'
            .'<tableStyles count="0" defaultTableStyle="TableStyleMedium2" defaultPivotStyle="PivotStyleLight16"/>'
            .'</styleSheet>';
    }

    private function coreProperties(array $dataset): string
    {
        $created = now()->utc()->format('Y-m-d\TH:i:s\Z');

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            .'<dc:title>'.$this->xmlText($dataset['title'] ?? 'Exportacion del sistema').'</dc:title>'
            .'<dc:creator>Sistema ERP</dc:creator>'
            .'<cp:lastModifiedBy>Sistema ERP</cp:lastModifiedBy>'
            .'<dcterms:created xsi:type="dcterms:W3CDTF">'.$created.'</dcterms:created>'
            .'<dcterms:modified xsi:type="dcterms:W3CDTF">'.$created.'</dcterms:modified>'
            .'</cp:coreProperties>';
    }

    private function appProperties(array $sections): string
    {
        $sheetNames = collect($sections)
            ->map(fn (array $section) => '<vt:lpstr>'.$this->xmlText($section['title']).'</vt:lpstr>')
            ->implode('');
        $sheetCount = count($sections);

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            .'<Application>Sistema ERP</Application>'
            .'<DocSecurity>0</DocSecurity><ScaleCrop>false</ScaleCrop>'
            .'<HeadingPairs><vt:vector size="2" baseType="variant"><vt:variant><vt:lpstr>Hojas</vt:lpstr></vt:variant><vt:variant><vt:i4>'.$sheetCount.'</vt:i4></vt:variant></vt:vector></HeadingPairs>'
            .'<TitlesOfParts><vt:vector size="'.$sheetCount.'" baseType="lpstr">'.$sheetNames.'</vt:vector></TitlesOfParts>'
            .'</Properties>';
    }

    private function columnName(int $number): string
    {
        $name = '';
        while ($number > 0) {
            $number--;
            $name = chr(65 + ($number % 26)).$name;
            $number = intdiv($number, 26);
        }

        return $name;
    }

    private function sheetName(string $value, array &$used): string
    {
        $name = preg_replace('/[\[\]\:\*\?\/\\\\]/', ' ', $value) ?: 'Hoja';
        $name = trim($name) ?: 'Hoja';
        $name = mb_substr($name, 0, 31);
        $base = $name;
        $index = 2;

        while (isset($used[mb_strtolower($name)])) {
            $suffix = ' '.$index;
            $name = mb_substr($base, 0, 31 - mb_strlen($suffix)).$suffix;
            $index++;
        }

        $used[mb_strtolower($name)] = true;

        return $this->xmlText($name);
    }

    private function xmlText(string $value): string
    {
        $value = iconv('UTF-8', 'UTF-8//IGNORE', $value) ?: '';
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?: '';

        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }

    private function argb(string $hex): string
    {
        $hex = ltrim($hex, '#');

        if (! preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            $hex = '2563eb';
        }

        return 'FF'.strtoupper($hex);
    }
}
