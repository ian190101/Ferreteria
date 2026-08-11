<?php

namespace App\Modules\SystemSuperadmin\Services;

use InvalidArgumentException;

class SimpleQrSvgRenderer
{
    private const DATA_CODEWORDS = [1 => 19, 2 => 34, 3 => 55, 4 => 80, 5 => 108];

    private const ECC_CODEWORDS = [1 => 7, 2 => 10, 3 => 15, 4 => 20, 5 => 26];

    /**
     * Genera un QR SVG en modo byte, ECC L y versiones 1-5.
     */
    public function render(string $text, int $scale = 6, int $quietZone = 4): string
    {
        $bytes = array_values(unpack('C*', $text) ?: []);
        $version = $this->versionFor(count($bytes));
        $size = 21 + ($version - 1) * 4;
        $matrix = array_fill(0, $size, array_fill(0, $size, false));
        $reserved = array_fill(0, $size, array_fill(0, $size, false));

        $this->drawFunctionPatterns($matrix, $reserved, $version);
        $codewords = $this->buildCodewords($bytes, $version);
        $bits = $this->codewordsToBits($codewords);
        $this->drawData($matrix, $reserved, $bits);
        $this->drawFormatBits($matrix, $reserved);

        $dimension = ($size + ($quietZone * 2)) * $scale;
        $rects = [];

        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if ($matrix[$y][$x]) {
                    $rects[] = sprintf(
                        '<rect x="%d" y="%d" width="%d" height="%d"/>',
                        ($x + $quietZone) * $scale,
                        ($y + $quietZone) * $scale,
                        $scale,
                        $scale,
                    );
                }
            }
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%1$d" height="%1$d" viewBox="0 0 %1$d %1$d" role="img" aria-label="Codigo QR"><rect width="100%%" height="100%%" fill="#fff"/><g fill="#000">%2$s</g></svg>',
            $dimension,
            implode('', $rects),
        );
    }

    private function versionFor(int $byteCount): int
    {
        foreach (self::DATA_CODEWORDS as $version => $dataCodewords) {
            $capacityBytes = $dataCodewords - 2;

            if ($byteCount <= $capacityBytes) {
                return $version;
            }
        }

        throw new InvalidArgumentException('La URL del QR es demasiado larga para el generador interno.');
    }

    /**
     * @param array<int, int> $bytes
     * @return array<int, int>
     */
    private function buildCodewords(array $bytes, int $version): array
    {
        $dataCodewords = self::DATA_CODEWORDS[$version];
        $bits = [];

        $this->appendBits($bits, 0b0100, 4);
        $this->appendBits($bits, count($bytes), 8);

        foreach ($bytes as $byte) {
            $this->appendBits($bits, $byte, 8);
        }

        $remaining = ($dataCodewords * 8) - count($bits);
        $this->appendBits($bits, 0, min(4, max(0, $remaining)));

        while (count($bits) % 8 !== 0) {
            $bits[] = 0;
        }

        $data = [];

        foreach (array_chunk($bits, 8) as $chunk) {
            $value = 0;
            foreach ($chunk as $bit) {
                $value = ($value << 1) | $bit;
            }
            $data[] = $value;
        }

        $pad = [0xEC, 0x11];
        $i = 0;

        while (count($data) < $dataCodewords) {
            $data[] = $pad[$i % 2];
            $i++;
        }

        return [...$data, ...$this->reedSolomonRemainder($data, self::ECC_CODEWORDS[$version])];
    }

    /**
     * @param array<int, int> $bits
     */
    private function appendBits(array &$bits, int $value, int $length): void
    {
        for ($i = $length - 1; $i >= 0; $i--) {
            $bits[] = ($value >> $i) & 1;
        }
    }

    /**
     * @param array<int, int> $codewords
     * @return array<int, int>
     */
    private function codewordsToBits(array $codewords): array
    {
        $bits = [];

        foreach ($codewords as $codeword) {
            $this->appendBits($bits, $codeword, 8);
        }

        return $bits;
    }

    /**
     * @param array<int, array<int, bool>> $matrix
     * @param array<int, array<int, bool>> $reserved
     */
    private function drawFunctionPatterns(array &$matrix, array &$reserved, int $version): void
    {
        $size = count($matrix);

        $this->drawFinder($matrix, $reserved, 0, 0);
        $this->drawFinder($matrix, $reserved, $size - 7, 0);
        $this->drawFinder($matrix, $reserved, 0, $size - 7);

        for ($i = 0; $i < $size; $i++) {
            if (! $reserved[6][$i]) {
                $this->setModule($matrix, $reserved, $i, 6, $i % 2 === 0);
            }

            if (! $reserved[$i][6]) {
                $this->setModule($matrix, $reserved, 6, $i, $i % 2 === 0);
            }
        }

        if ($version > 1) {
            $center = 4 * $version + 10;
            $this->drawAlignment($matrix, $reserved, $center, $center);
        }

        $this->setModule($matrix, $reserved, 8, $size - 8, true);
        $this->reserveFormatAreas($reserved);
    }

    /**
     * @param array<int, array<int, bool>> $matrix
     * @param array<int, array<int, bool>> $reserved
     */
    private function drawFinder(array &$matrix, array &$reserved, int $left, int $top): void
    {
        $size = count($matrix);

        for ($dy = -1; $dy <= 7; $dy++) {
            for ($dx = -1; $dx <= 7; $dx++) {
                $x = $left + $dx;
                $y = $top + $dy;

                if ($x < 0 || $y < 0 || $x >= $size || $y >= $size) {
                    continue;
                }

                $dark = $dx >= 0 && $dx <= 6 && $dy >= 0 && $dy <= 6
                    && ($dx === 0 || $dx === 6 || $dy === 0 || $dy === 6 || ($dx >= 2 && $dx <= 4 && $dy >= 2 && $dy <= 4));
                $this->setModule($matrix, $reserved, $x, $y, $dark);
            }
        }
    }

    /**
     * @param array<int, array<int, bool>> $matrix
     * @param array<int, array<int, bool>> $reserved
     */
    private function drawAlignment(array &$matrix, array &$reserved, int $centerX, int $centerY): void
    {
        for ($dy = -2; $dy <= 2; $dy++) {
            for ($dx = -2; $dx <= 2; $dx++) {
                $dark = max(abs($dx), abs($dy)) !== 1;
                $this->setModule($matrix, $reserved, $centerX + $dx, $centerY + $dy, $dark);
            }
        }
    }

    /**
     * @param array<int, array<int, bool>> $matrix
     * @param array<int, array<int, bool>> $reserved
     */
    private function setModule(array &$matrix, array &$reserved, int $x, int $y, bool $dark): void
    {
        $matrix[$y][$x] = $dark;
        $reserved[$y][$x] = true;
    }

    /**
     * @param array<int, array<int, bool>> $reserved
     */
    private function reserveFormatAreas(array &$reserved): void
    {
        $size = count($reserved);

        for ($i = 0; $i <= 8; $i++) {
            if ($i !== 6) {
                $reserved[8][$i] = true;
                $reserved[$i][8] = true;
            }
        }

        for ($i = 0; $i < 8; $i++) {
            $reserved[8][$size - 1 - $i] = true;
            $reserved[$size - 1 - $i][8] = true;
        }
    }

    /**
     * @param array<int, array<int, bool>> $matrix
     * @param array<int, array<int, bool>> $reserved
     * @param array<int, int> $bits
     */
    private function drawData(array &$matrix, array &$reserved, array $bits): void
    {
        $size = count($matrix);
        $bitIndex = 0;
        $upward = true;

        for ($right = $size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right--;
            }

            for ($vert = 0; $vert < $size; $vert++) {
                $y = $upward ? $size - 1 - $vert : $vert;

                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;

                    if ($reserved[$y][$x]) {
                        continue;
                    }

                    $bit = $bits[$bitIndex] ?? 0;
                    $mask = (($x + $y) % 2) === 0;
                    $matrix[$y][$x] = (bool) ($bit ^ (int) $mask);
                    $bitIndex++;
                }
            }

            $upward = ! $upward;
        }
    }

    /**
     * @param array<int, array<int, bool>> $matrix
     * @param array<int, array<int, bool>> $reserved
     */
    private function drawFormatBits(array &$matrix, array &$reserved): void
    {
        $size = count($matrix);
        $format = $this->formatBits(0b01, 0);

        $coordsA = [
            [8, 0], [8, 1], [8, 2], [8, 3], [8, 4], [8, 5],
            [8, 7], [8, 8], [7, 8], [5, 8], [4, 8], [3, 8], [2, 8], [1, 8], [0, 8],
        ];

        $coordsB = [
            [$size - 1, 8], [$size - 2, 8], [$size - 3, 8], [$size - 4, 8],
            [$size - 5, 8], [$size - 6, 8], [$size - 7, 8], [$size - 8, 8],
            [8, $size - 7], [8, $size - 6], [8, $size - 5], [8, $size - 4],
            [8, $size - 3], [8, $size - 2], [8, $size - 1],
        ];

        foreach ([$coordsA, $coordsB] as $coords) {
            foreach ($coords as $i => [$x, $y]) {
                $matrix[$y][$x] = (($format >> $i) & 1) === 1;
                $reserved[$y][$x] = true;
            }
        }
    }

    private function formatBits(int $eccLevel, int $mask): int
    {
        $data = (($eccLevel & 0b11) << 3) | ($mask & 0b111);
        $rem = $data << 10;

        for ($i = 14; $i >= 10; $i--) {
            if ((($rem >> $i) & 1) !== 0) {
                $rem ^= 0x537 << ($i - 10);
            }
        }

        return (($data << 10) | $rem) ^ 0x5412;
    }

    /**
     * @param array<int, int> $data
     * @return array<int, int>
     */
    private function reedSolomonRemainder(array $data, int $degree): array
    {
        $generator = $this->reedSolomonGenerator($degree);
        $result = array_fill(0, $degree, 0);

        foreach ($data as $byte) {
            $factor = $byte ^ $result[0];
            array_shift($result);
            $result[] = 0;

            foreach ($generator as $i => $coefficient) {
                $result[$i] ^= $this->gfMultiply($coefficient, $factor);
            }
        }

        return $result;
    }

    /**
     * @return array<int, int>
     */
    private function reedSolomonGenerator(int $degree): array
    {
        $result = [1];

        for ($i = 0; $i < $degree; $i++) {
            $result[] = 0;

            for ($j = count($result) - 1; $j >= 1; $j--) {
                $result[$j] = $this->gfMultiply($result[$j], $this->gfPow(2, $i)) ^ $result[$j - 1];
            }

            $result[0] = $this->gfMultiply($result[0], $this->gfPow(2, $i));
        }

        return array_slice($result, 1);
    }

    private function gfPow(int $x, int $power): int
    {
        $result = 1;

        for ($i = 0; $i < $power; $i++) {
            $result = $this->gfMultiply($result, $x);
        }

        return $result;
    }

    private function gfMultiply(int $x, int $y): int
    {
        $z = 0;

        for ($i = 7; $i >= 0; $i--) {
            $z = ($z << 1) ^ (($z >> 7) * 0x11D);
            $z ^= (($y >> $i) & 1) * $x;
        }

        return $z & 0xFF;
    }
}
