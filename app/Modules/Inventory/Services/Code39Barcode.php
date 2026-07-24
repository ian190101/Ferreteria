<?php

namespace App\Modules\Inventory\Services;

class Code39Barcode
{
    private const PATTERNS = [
        '0' => 'nnnwwnwnn', '1' => 'wnnwnnnnw', '2' => 'nnwwnnnnw', '3' => 'wnwwnnnnn',
        '4' => 'nnnwwnnnw', '5' => 'wnnwwnnnn', '6' => 'nnwwwnnnn', '7' => 'nnnwnnwnw',
        '8' => 'wnnwnnwnn', '9' => 'nnwwnnwnn', 'A' => 'wnnnnwnnw', 'B' => 'nnwnnwnnw',
        'C' => 'wnwnnwnnn', 'D' => 'nnnnwwnnw', 'E' => 'wnnnwwnnn', 'F' => 'nnwnwwnnn',
        'G' => 'nnnnnwwnw', 'H' => 'wnnnnwwnn', 'I' => 'nnwnnwwnn', 'J' => 'nnnnwwwnn',
        'K' => 'wnnnnnnww', 'L' => 'nnwnnnnww', 'M' => 'wnwnnnnwn', 'N' => 'nnnnwnnww',
        'O' => 'wnnnwnnwn', 'P' => 'nnwnwnnwn', 'Q' => 'nnnnnnwww', 'R' => 'wnnnnnwwn',
        'S' => 'nnwnnnwwn', 'T' => 'nnnnwnwwn', 'U' => 'wwnnnnnnw', 'V' => 'nwwnnnnnw',
        'W' => 'wwwnnnnnn', 'X' => 'nwnnwnnnw', 'Y' => 'wwnnwnnnn', 'Z' => 'nwwnwnnnn',
        '-' => 'nwnnnnwnw', '.' => 'wwnnnnwnn', ' ' => 'nwwnnnwnn', '$' => 'nwnwnwnnn',
        '/' => 'nwnwnnnwn', '+' => 'nwnnnwnwn', '%' => 'nnnwnwnwn', '*' => 'nwnnwnwnn',
    ];

    public function svg(string $value, int $height = 52): string
    {
        $text = '*'.strtoupper(preg_replace('/[^0-9A-Z\-. $\/+%]/', '-', $value)).'*';
        $narrow = 2;
        $wide = 5;
        $gap = 2;
        $x = 0;
        $bars = [];

        foreach (str_split($text) as $character) {
            $pattern = self::PATTERNS[$character] ?? self::PATTERNS['-'];

            foreach (str_split($pattern) as $index => $widthCode) {
                $width = $widthCode === 'w' ? $wide : $narrow;

                if ($index % 2 === 0) {
                    $bars[] = sprintf('<rect x="%d" y="0" width="%d" height="%d" fill="#000"/>', $x, $width, $height);
                }

                $x += $width;
            }

            $x += $gap;
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" role="img" aria-label="Codigo de barras">%s</svg>',
            $x,
            $height,
            implode('', $bars),
        );
    }
}
