<?php

namespace App\Modules\Exports\Services;

class SimplePdfExporter
{
    private array $objects = [];
    private array $branding = [
        'primary' => '#2563eb',
        'secondary' => '#0f172a',
    ];

    public function save(array $dataset, string $path): void
    {
        $this->objects = [
            1 => '',
            2 => '',
            3 => '',
        ];
        $this->branding = array_merge($this->branding, $dataset['branding'] ?? []);
        $pages = $this->pages($dataset);
        $pageIds = [];

        foreach ($pages as $commands) {
            $content = $this->object($this->contentStream($commands));
            $pageIds[] = $this->object('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R >> >> /Contents '.$content.' 0 R >>');
        }

        $kids = collect($pageIds)->map(fn ($id) => $id.' 0 R')->implode(' ');
        $this->objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $this->objects[2] = '<< /Type /Pages /Kids ['.$kids.'] /Count '.count($pageIds).' >>';
        $this->objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

        file_put_contents($path, $this->document());
    }

    private function pages(array $dataset): array
    {
        $pages = [];
        $commands = [];
        $y = 792;

        $this->drawText($commands, 42, $y, $dataset['title'], 16, $this->branding['secondary'] ?? '#0f172a');
        $y -= 22;
        $this->drawText($commands, 42, $y, 'Generado: '.$dataset['generated_at'], 9);
        $y -= 14;
        $this->drawText($commands, 42, $y, 'Sucursal: '.$dataset['filters']['branch'], 9);
        $y -= 14;
        $this->drawText($commands, 42, $y, 'Rango: '.$dataset['filters']['from'].' a '.$dataset['filters']['to'], 9);
        $y -= 24;

        foreach ($dataset['sections'] as $section) {
            $this->ensureSpace($pages, $commands, $y, 82);
            $this->drawRect($commands, 42, $y - 19, 511, 24, $this->branding['primary'] ?? '#2563eb');
            $this->drawText($commands, 50, $y - 11, $section['title'], 12, '#ffffff');
            $y -= 35;
            $this->drawText($commands, 42, $y, $section['description'], 8);
            $y -= 18;

            $headers = $section['headers'];
            $widths = $this->columnWidths(count($headers));
            $this->drawTableRow($commands, $y, $headers, $widths, true);
            $y -= 22;

            foreach (array_slice($section['rows'], 0, 500) as $index => $row) {
                $this->ensureSpace($pages, $commands, $y, 30);
                $this->drawTableRow($commands, $y, array_map(fn ($value) => (string) $value, $row), $widths, false, $index % 2 === 1);
                $y -= 20;
            }

            if (count($section['rows']) > 500) {
                $this->ensureSpace($pages, $commands, $y, 22);
                $this->drawText($commands, 42, $y, 'Nota: se muestran 500 filas en PDF. Use Excel para exportaciones masivas.', 8);
                $y -= 18;
            }

            $y -= 12;
        }

        $pages[] = $commands;

        return $pages;
    }

    private function ensureSpace(array &$pages, array &$commands, int &$y, int $needed): void
    {
        if ($y - $needed >= 48) {
            return;
        }

        $pages[] = $commands;
        $commands = [];
        $y = 792;
    }

    private function drawTableRow(array &$commands, int $y, array $cells, array $widths, bool $header = false, bool $alternate = false): void
    {
        $x = 42;
        $background = $header ? ($this->branding['secondary'] ?? '#0f172a') : ($alternate ? '#f3f6fb' : '#ffffff');
        $color = $header ? '#ffffff' : '#263548';

        foreach ($widths as $index => $width) {
            $this->drawRect($commands, $x, $y - 16, $width, 18, $background, '#d6dde8');
            $this->drawText($commands, $x + 3, $y - 10, $this->truncate((string) ($cells[$index] ?? '-'), max(5, (int) floor($width / 5.4))), 7, $color);
            $x += $width;
        }
    }

    private function columnWidths(int $count): array
    {
        $count = max(1, min($count, 10));
        $base = floor(511 / $count);
        $widths = array_fill(0, $count, $base);
        $widths[$count - 1] += 511 - array_sum($widths);

        return $widths;
    }

    private function drawText(array &$commands, int $x, int $y, string $text, int $size = 9, string $color = '#263548'): void
    {
        [$r, $g, $b] = $this->hexRgb($color);
        $commands[] = "{$r} {$g} {$b} rg BT /F1 {$size} Tf {$x} {$y} Td (".$this->pdfText($text).') Tj ET';
    }

    private function drawRect(array &$commands, int $x, int $y, int $width, int $height, string $fill, ?string $stroke = null): void
    {
        [$fr, $fg, $fb] = $this->hexRgb($fill);
        $command = "{$fr} {$fg} {$fb} rg {$x} {$y} {$width} {$height} re f";

        if ($stroke) {
            [$sr, $sg, $sb] = $this->hexRgb($stroke);
            $command .= "\n{$sr} {$sg} {$sb} RG {$x} {$y} {$width} {$height} re S";
        }

        $commands[] = $command;
    }

    private function contentStream(array $commands): string
    {
        $content = implode("\n", $commands)."\n";

        return '<< /Length '.strlen($content)." >>\nstream\n".$content."endstream";
    }

    private function document(): string
    {
        ksort($this->objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [0];

        foreach ($this->objects as $id => $object) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id." 0 obj\n".$object."\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($this->objects) + 1)."\n0000000000 65535 f \n";

        foreach (array_keys($this->objects) as $id) {
            $pdf .= str_pad((string) $offsets[$id], 10, '0', STR_PAD_LEFT)." 00000 n \n";
        }

        return $pdf."trailer\n<< /Size ".(count($this->objects) + 1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF";
    }

    private function object(string $content): int
    {
        $id = count($this->objects) + 1;
        $this->objects[$id] = $content;

        return $id;
    }

    private function pdfText(string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $ascii);
    }

    private function truncate(string $value, int $length): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?: '');

        if (mb_strlen($value) <= $length) {
            return $value;
        }

        return mb_substr($value, 0, max(1, $length - 3)).'...';
    }

    private function hexRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        if (! preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            $hex = '263548';
        }

        return [
            round(hexdec(substr($hex, 0, 2)) / 255, 3),
            round(hexdec(substr($hex, 2, 2)) / 255, 3),
            round(hexdec(substr($hex, 4, 2)) / 255, 3),
        ];
    }
}
