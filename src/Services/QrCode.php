<?php
declare(strict_types=1);

namespace QRoute\Services;

/**
 * A complete QR Code encoder in pure PHP, implementing ISO/IEC 18004.
 *
 * Written from the specification rather than pulled in as a dependency so
 * the whole application runs on a stock PHP install with no Composer
 * vendor tree — which matters when the deploy target is a $4/month host.
 *
 * Supports versions 1-40, error correction levels L/M/Q/H, and numeric,
 * alphanumeric and byte encoding modes (the mode is chosen automatically
 * to produce the smallest, and therefore most scannable, symbol).
 */
final class QrCode
{
    public const ECC_LOW      = 0; // ~7% recoverable
    public const ECC_MEDIUM   = 1; // ~15%
    public const ECC_QUARTILE = 2; // ~25%
    public const ECC_HIGH     = 3; // ~30%

    private const MODE_NUMERIC = 0;
    private const MODE_ALNUM   = 1;
    private const MODE_BYTE    = 2;

    private const MODE_INDICATOR   = [self::MODE_NUMERIC => 1, self::MODE_ALNUM => 2, self::MODE_BYTE => 4];
    private const CHAR_COUNT_BITS  = [
        self::MODE_NUMERIC => [10, 12, 14],
        self::MODE_ALNUM   => [9, 11, 13],
        self::MODE_BYTE    => [8, 16, 16],
    ];

    private const ALNUM_CHARSET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ $%*+-./:';

    /** Error correction codewords per block, indexed [ecc][version-1]. */
    private const ECC_CODEWORDS_PER_BLOCK = [
        self::ECC_LOW => [7,10,15,20,26,18,20,24,30,18,20,24,26,30,22,24,28,30,28,28,28,28,30,30,26,28,30,30,30,30,30,30,30,30,30,30,30,30,30,30],
        self::ECC_MEDIUM => [10,16,26,18,24,16,18,22,22,26,30,22,22,24,24,28,28,26,26,26,26,28,28,28,28,28,28,28,28,28,28,28,28,28,28,28,28,28,28,28],
        self::ECC_QUARTILE => [13,22,18,26,18,24,18,22,20,24,28,26,24,20,30,24,28,28,26,30,28,30,30,30,30,28,30,30,30,30,30,30,30,30,30,30,30,30,30,30],
        self::ECC_HIGH => [17,28,22,16,22,28,26,26,24,28,24,28,22,24,24,30,28,28,26,28,30,24,30,30,30,30,30,30,30,30,30,30,30,30,30,30,30,30,30,30],
    ];

    /** Number of error correction blocks, indexed [ecc][version-1]. */
    private const NUM_ECC_BLOCKS = [
        self::ECC_LOW => [1,1,1,1,1,2,2,2,2,4,4,4,4,4,6,6,6,6,7,8,8,9,9,10,12,12,12,13,14,15,16,17,18,19,19,20,21,22,24,25],
        self::ECC_MEDIUM => [1,1,1,2,2,4,4,4,5,5,5,8,9,9,10,10,11,13,14,16,17,17,18,20,21,23,25,26,28,29,31,33,35,37,38,40,43,45,47,49],
        self::ECC_QUARTILE => [1,1,2,2,4,4,6,6,8,8,8,10,12,16,12,17,16,18,21,20,23,23,25,27,29,34,34,35,38,40,43,45,48,51,53,56,59,62,65,68],
        self::ECC_HIGH => [1,1,2,4,4,4,5,6,8,8,11,11,16,16,18,16,19,21,25,25,25,34,30,32,35,37,40,42,45,48,51,54,57,60,63,66,70,74,77,81],
    ];

    /** Format-info bit patterns for each EC level (not the same as the index). */
    private const ECC_FORMAT_BITS = [self::ECC_LOW => 1, self::ECC_MEDIUM => 0, self::ECC_QUARTILE => 3, self::ECC_HIGH => 2];

    private const N3_PATTERN = '1011101';

    private const PENALTY_N1 = 3;
    private const PENALTY_N2 = 3;
    private const PENALTY_N3 = 40;
    private const PENALTY_N4 = 10;

    /** @var list<list<bool>> row-major matrix, true = dark */
    private array $modules = [];
    /** @var list<list<bool>> modules that are part of a function pattern */
    private array $isFunction = [];

    private int $mask = 0;

    private function __construct(
        public readonly int $version,
        public readonly int $ecc,
        public readonly int $size
    ) {
    }

    /**
     * Encodes text into a QR symbol.
     *
     * @param int  $ecc       one of the ECC_* constants
     * @param int  $minVersion smallest version to consider (1-40)
     * @param bool $boostEcc  raise the EC level for free if the data still fits,
     *                        which makes a printed code survive more damage
     */
    public static function encode(
        string $text,
        int $ecc = self::ECC_MEDIUM,
        int $minVersion = 1,
        int $maxVersion = 40,
        bool $boostEcc = true
    ): self {
        if ($text === '') {
            throw new \InvalidArgumentException('Cannot encode empty text.');
        }
        if ($minVersion < 1 || $maxVersion > 40 || $minVersion > $maxVersion) {
            throw new \InvalidArgumentException('Invalid version range.');
        }
        if (!isset(self::ECC_CODEWORDS_PER_BLOCK[$ecc])) {
            throw new \InvalidArgumentException('Invalid error correction level.');
        }

        $mode = self::chooseMode($text);

        // Find the smallest version that fits.
        $version = 0;
        $dataUsedBits = 0;
        for ($v = $minVersion; $v <= $maxVersion; $v++) {
            $capacityBits = self::dataCodewords($v, $ecc) * 8;
            $used = self::segmentBitLength($text, $mode, $v);
            if ($used <= $capacityBits) {
                $version = $v;
                $dataUsedBits = $used;
                break;
            }
        }
        if ($version === 0) {
            throw new \InvalidArgumentException(
                'Data is too long to encode (' . strlen($text) . ' bytes).'
            );
        }

        // Spend any spare capacity on stronger error correction.
        if ($boostEcc) {
            foreach ([self::ECC_MEDIUM, self::ECC_QUARTILE, self::ECC_HIGH] as $candidate) {
                if ($candidate > $ecc && $dataUsedBits <= self::dataCodewords($version, $candidate) * 8) {
                    $ecc = $candidate;
                }
            }
        }

        $bits = self::buildBitstream($text, $mode, $version, $ecc);
        $codewords = self::addEccAndInterleave($bits, $version, $ecc);

        return self::buildMatrix($version, $ecc, $codewords);
    }

    // ---------------------------------------------------------------- modes

    private static function chooseMode(string $text): int
    {
        if (preg_match('/^[0-9]+$/', $text) === 1) {
            return self::MODE_NUMERIC;
        }
        if (preg_match('/^[0-9A-Z $%*+\-.\/:]+$/', $text) === 1) {
            return self::MODE_ALNUM;
        }
        return self::MODE_BYTE;
    }

    private static function charCountBits(int $mode, int $version): int
    {
        $i = $version <= 9 ? 0 : ($version <= 26 ? 1 : 2);
        return self::CHAR_COUNT_BITS[$mode][$i];
    }

    private static function segmentBitLength(string $text, int $mode, int $version): int
    {
        $n = strlen($text);
        $dataBits = match ($mode) {
            self::MODE_NUMERIC => intdiv($n, 3) * 10 + match ($n % 3) { 1 => 4, 2 => 7, default => 0 },
            self::MODE_ALNUM   => intdiv($n, 2) * 11 + ($n % 2) * 6,
            default            => $n * 8,
        };
        return 4 + self::charCountBits($mode, $version) + $dataBits;
    }

    // ------------------------------------------------------------ bitstream

    /** @return list<int> data codewords, padded to full capacity */
    private static function buildBitstream(string $text, int $mode, int $version, int $ecc): array
    {
        $bits = [];
        self::appendBits($bits, self::MODE_INDICATOR[$mode], 4);
        self::appendBits($bits, strlen($text), self::charCountBits($mode, $version));

        if ($mode === self::MODE_NUMERIC) {
            $n = strlen($text);
            for ($i = 0; $i < $n; $i += 3) {
                $chunk = substr($text, $i, 3);
                self::appendBits($bits, (int) $chunk, strlen($chunk) * 3 + 1);
            }
        } elseif ($mode === self::MODE_ALNUM) {
            $n = strlen($text);
            for ($i = 0; $i + 1 < $n; $i += 2) {
                $v = self::alnumValue($text[$i]) * 45 + self::alnumValue($text[$i + 1]);
                self::appendBits($bits, $v, 11);
            }
            if ($n % 2 === 1) {
                self::appendBits($bits, self::alnumValue($text[$n - 1]), 6);
            }
        } else {
            for ($i = 0, $n = strlen($text); $i < $n; $i++) {
                self::appendBits($bits, ord($text[$i]), 8);
            }
        }

        $capacityBits = self::dataCodewords($version, $ecc) * 8;
        if (count($bits) > $capacityBits) {
            throw new \LogicException('Bitstream overflow while encoding.');
        }

        // Terminator: up to four zero bits.
        self::appendBits($bits, 0, min(4, $capacityBits - count($bits)));
        // Pad to a byte boundary.
        self::appendBits($bits, 0, (8 - count($bits) % 8) % 8);
        // Alternating pad bytes specified by the standard.
        for ($pad = 0xEC; count($bits) < $capacityBits; $pad ^= 0xEC ^ 0x11) {
            self::appendBits($bits, $pad, 8);
        }

        $codewords = [];
        for ($i = 0, $n = count($bits); $i < $n; $i += 8) {
            $byte = 0;
            for ($b = 0; $b < 8; $b++) {
                $byte = ($byte << 1) | $bits[$i + $b];
            }
            $codewords[] = $byte;
        }
        return $codewords;
    }

    private static function alnumValue(string $ch): int
    {
        $pos = strpos(self::ALNUM_CHARSET, $ch);
        if ($pos === false) {
            throw new \InvalidArgumentException('Character not encodable in alphanumeric mode.');
        }
        return $pos;
    }

    /** @param list<int> $bits */
    private static function appendBits(array &$bits, int $value, int $length): void
    {
        for ($i = $length - 1; $i >= 0; $i--) {
            $bits[] = ($value >> $i) & 1;
        }
    }

    // ------------------------------------------------------------- capacity

    /** Total data modules available for data + ECC codewords. */
    private static function rawDataModules(int $version): int
    {
        $result = (16 * $version + 128) * $version + 64;
        if ($version >= 2) {
            $numAlign = intdiv($version, 7) + 2;
            $result -= (25 * $numAlign - 10) * $numAlign - 55;
            if ($version >= 7) {
                $result -= 36;
            }
        }
        return $result;
    }

    private static function dataCodewords(int $version, int $ecc): int
    {
        return intdiv(self::rawDataModules($version), 8)
            - self::ECC_CODEWORDS_PER_BLOCK[$ecc][$version - 1]
            * self::NUM_ECC_BLOCKS[$ecc][$version - 1];
    }

    // -------------------------------------------------------- Reed-Solomon

    /**
     * Interleaves data blocks with their error correction codewords in the
     * order the standard requires.
     *
     * @param list<int> $data
     * @return list<int>
     */
    private static function addEccAndInterleave(array $data, int $version, int $ecc): array
    {
        $numBlocks   = self::NUM_ECC_BLOCKS[$ecc][$version - 1];
        $blockEccLen = self::ECC_CODEWORDS_PER_BLOCK[$ecc][$version - 1];
        $rawCodewords = intdiv(self::rawDataModules($version), 8);

        $numShortBlocks = $numBlocks - $rawCodewords % $numBlocks;
        $shortBlockLen  = intdiv($rawCodewords, $numBlocks);

        $divisor = self::rsDivisor($blockEccLen);

        $blocks = [];
        $k = 0;
        for ($i = 0; $i < $numBlocks; $i++) {
            $len = $shortBlockLen - $blockEccLen + ($i < $numShortBlocks ? 0 : 1);
            $dat = array_slice($data, $k, $len);
            $k += $len;
            $eccBytes = self::rsRemainder($dat, $divisor);
            // Short blocks carry a placeholder byte so every block array has
            // the same length; the interleaver skips it.
            $dat = array_pad($dat, $shortBlockLen + 1 - $blockEccLen, 0);
            $blocks[] = array_merge($dat, $eccBytes);
        }

        $result = [];
        $blockLen = $shortBlockLen + 1;
        for ($i = 0; $i < $blockLen; $i++) {
            for ($j = 0; $j < $numBlocks; $j++) {
                if ($i !== $shortBlockLen - $blockEccLen || $j >= $numShortBlocks) {
                    $result[] = $blocks[$j][$i];
                }
            }
        }
        return $result;
    }

    /** @return list<int> generator polynomial coefficients, highest degree first */
    private static function rsDivisor(int $degree): array
    {
        $result = array_fill(0, $degree, 0);
        $result[$degree - 1] = 1;

        // Compute (x - r^0)(x - r^1)...(x - r^(degree-1)) over GF(2^8).
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $result[$j] = self::gfMultiply($result[$j], $root);
                if ($j + 1 < $degree) {
                    $result[$j] ^= $result[$j + 1];
                }
            }
            $root = self::gfMultiply($root, 0x02);
        }
        return $result;
    }

    /**
     * @param list<int> $data
     * @param list<int> $divisor
     * @return list<int>
     */
    private static function rsRemainder(array $data, array $divisor): array
    {
        $degree = count($divisor);
        $result = array_fill(0, $degree, 0);
        foreach ($data as $byte) {
            $factor = $byte ^ $result[0];
            array_shift($result);
            $result[] = 0;
            for ($i = 0; $i < $degree; $i++) {
                $result[$i] ^= self::gfMultiply($divisor[$i], $factor);
            }
        }
        return $result;
    }

    /** Multiplication in GF(2^8) modulo x^8 + x^4 + x^3 + x^2 + 1. */
    private static function gfMultiply(int $x, int $y): int
    {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = (($z << 1) ^ ((($z >> 7) & 1) * 0x11D)) & 0x1FF;
            $z ^= (($y >> $i) & 1) * $x;
            $z &= 0xFF;
        }
        return $z;
    }

    // ---------------------------------------------------------- matrix draw

    /** @param list<int> $codewords */
    private static function buildMatrix(int $version, int $ecc, array $codewords): self
    {
        $size = $version * 4 + 17;
        $qr = new self($version, $ecc, $size);

        $qr->modules    = array_fill(0, $size, array_fill(0, $size, false));
        $qr->isFunction = array_fill(0, $size, array_fill(0, $size, false));

        $qr->drawFunctionPatterns();
        $qr->drawCodewords($codewords);

        // Try all eight masks and keep the one the standard scores best.
        $bestMask = 0;
        $minPenalty = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            $qr->applyMask($mask);
            $qr->drawFormatBits($mask);
            $penalty = $qr->penaltyScore();
            if ($penalty < $minPenalty) {
                $minPenalty = $penalty;
                $bestMask = $mask;
            }
            $qr->applyMask($mask); // XOR is its own inverse
        }

        $qr->applyMask($bestMask);
        $qr->drawFormatBits($bestMask);
        $qr->mask = $bestMask;

        return $qr;
    }

    private function drawFunctionPatterns(): void
    {
        // Timing patterns.
        for ($i = 0; $i < $this->size; $i++) {
            $this->setFunction(6, $i, $i % 2 === 0);
            $this->setFunction($i, 6, $i % 2 === 0);
        }

        // Three finder patterns, with their separators.
        $this->drawFinder(3, 3);
        $this->drawFinder($this->size - 4, 3);
        $this->drawFinder(3, $this->size - 4);

        // Alignment patterns at every intersection except the finder corners.
        $positions = $this->alignmentPositions();
        $n = count($positions);
        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                $corner = ($i === 0 && $j === 0)
                    || ($i === 0 && $j === $n - 1)
                    || ($i === $n - 1 && $j === 0);
                if (!$corner) {
                    $this->drawAlignment($positions[$i], $positions[$j]);
                }
            }
        }

        // Reserve the format and version areas.
        $this->drawFormatBits(0);
        $this->drawVersionBits();
    }

    /** @return list<int> */
    private function alignmentPositions(): array
    {
        if ($this->version === 1) {
            return [];
        }
        $numAlign = intdiv($this->version, 7) + 2;
        $step = $this->version === 32
            ? 26
            : intdiv($this->version * 4 + $numAlign * 2 + 1, $numAlign * 2 - 2) * 2;

        $result = [];
        for ($i = 0, $pos = $this->size - 7; $i < $numAlign - 1; $i++, $pos -= $step) {
            array_unshift($result, $pos);
        }
        array_unshift($result, 6);
        return $result;
    }

    private function drawFinder(int $x, int $y): void
    {
        for ($dy = -4; $dy <= 4; $dy++) {
            for ($dx = -4; $dx <= 4; $dx++) {
                $dist = max(abs($dx), abs($dy));
                $xx = $x + $dx;
                $yy = $y + $dy;
                if ($xx >= 0 && $xx < $this->size && $yy >= 0 && $yy < $this->size) {
                    $this->setFunction($xx, $yy, $dist !== 2 && $dist !== 4);
                }
            }
        }
    }

    private function drawAlignment(int $x, int $y): void
    {
        for ($dy = -2; $dy <= 2; $dy++) {
            for ($dx = -2; $dx <= 2; $dx++) {
                $this->setFunction($x + $dx, $y + $dy, max(abs($dx), abs($dy)) !== 1);
            }
        }
    }

    private function drawFormatBits(int $mask): void
    {
        $data = (self::ECC_FORMAT_BITS[$this->ecc] << 3) | $mask;
        $rem = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ ((($rem >> 9) & 1) * 0x537);
        }
        $bits = (($data << 10) | $rem) ^ 0x5412;

        // First copy, around the top-left finder.
        for ($i = 0; $i <= 5; $i++) {
            $this->setFunction(8, $i, (($bits >> $i) & 1) === 1);
        }
        $this->setFunction(8, 7, (($bits >> 6) & 1) === 1);
        $this->setFunction(8, 8, (($bits >> 7) & 1) === 1);
        $this->setFunction(7, 8, (($bits >> 8) & 1) === 1);
        for ($i = 9; $i < 15; $i++) {
            $this->setFunction(14 - $i, 8, (($bits >> $i) & 1) === 1);
        }

        // Second copy, split between the other two finders.
        for ($i = 0; $i < 8; $i++) {
            $this->setFunction($this->size - 1 - $i, 8, (($bits >> $i) & 1) === 1);
        }
        for ($i = 8; $i < 15; $i++) {
            $this->setFunction(8, $this->size - 15 + $i, (($bits >> $i) & 1) === 1);
        }
        $this->setFunction(8, $this->size - 8, true); // always dark
    }

    private function drawVersionBits(): void
    {
        if ($this->version < 7) {
            return;
        }
        $rem = $this->version;
        for ($i = 0; $i < 12; $i++) {
            $rem = ($rem << 1) ^ ((($rem >> 11) & 1) * 0x1F25);
        }
        $bits = ($this->version << 12) | $rem;

        for ($i = 0; $i < 18; $i++) {
            $bit = (($bits >> $i) & 1) === 1;
            $a = $this->size - 11 + $i % 3;
            $b = intdiv($i, 3);
            $this->setFunction($a, $b, $bit);
            $this->setFunction($b, $a, $bit);
        }
    }

    /** @param list<int> $codewords */
    private function drawCodewords(array $codewords): void
    {
        $bitLength = count($codewords) * 8;
        $i = 0;

        // Zigzag upward and downward through two-module-wide columns,
        // skipping the vertical timing pattern at column 6.
        for ($right = $this->size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            for ($vert = 0; $vert < $this->size; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $y = $upward ? $this->size - 1 - $vert : $vert;
                    if (!$this->isFunction[$y][$x] && $i < $bitLength) {
                        $this->modules[$y][$x] = (($codewords[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                        $i++;
                    }
                }
            }
        }
    }

    private function applyMask(int $mask): void
    {
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                if ($this->isFunction[$y][$x]) {
                    continue;
                }
                $invert = match ($mask) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => $x * $y % 2 + $x * $y % 3 === 0,
                    6 => ($x * $y % 2 + $x * $y % 3) % 2 === 0,
                    7 => (($x + $y) % 2 + $x * $y % 3) % 2 === 0,
                    default => throw new \InvalidArgumentException('Mask out of range'),
                };
                if ($invert) {
                    $this->modules[$y][$x] = !$this->modules[$y][$x];
                }
            }
        }
    }

    private function setFunction(int $x, int $y, bool $isDark): void
    {
        if ($x < 0 || $y < 0 || $x >= $this->size || $y >= $this->size) {
            return;
        }
        $this->modules[$y][$x] = $isDark;
        $this->isFunction[$y][$x] = true;
    }

    // --------------------------------------------------------- mask scoring

    /**
     * Scores a masked symbol per ISO/IEC 18004:2015 section 7.8.3, Table 11.
     * The lowest scoring mask is the one printed, because it is the one a
     * real scanner has the easiest time locking onto.
     */
    private function penaltyScore(): int
    {
        $size = $this->size;

        // Build row and column strings once; both N1 and N3 scan them.
        $rows = [];
        $cols = array_fill(0, $size, '');
        $dark = 0;
        for ($y = 0; $y < $size; $y++) {
            $row = '';
            for ($x = 0; $x < $size; $x++) {
                if ($this->modules[$y][$x]) {
                    $row .= '1';
                    $cols[$x] .= '1';
                    $dark++;
                } else {
                    $row .= '0';
                    $cols[$x] .= '0';
                }
            }
            $rows[] = $row;
        }

        $n1 = 0;
        $n3 = 0;
        foreach ($rows as $seq) {
            $n1 += self::runPenalty($seq, $size);
            $n3 += self::finderLikePenalty($seq, $size);
        }
        foreach ($cols as $seq) {
            $n1 += self::runPenalty($seq, $size);
            $n3 += self::finderLikePenalty($seq, $size);
        }

        // N2: every 2x2 block of a single colour.
        $n2 = 0;
        for ($y = 1; $y < $size; $y++) {
            for ($x = 1; $x < $size; $x++) {
                $c = $this->modules[$y][$x];
                if ($c === $this->modules[$y][$x - 1]
                    && $c === $this->modules[$y - 1][$x]
                    && $c === $this->modules[$y - 1][$x - 1]) {
                    $n2 += self::PENALTY_N2;
                }
            }
        }

        // N4: how far the dark/light balance strays from 50%.
        $percent = $dark / ($size * $size);
        $n4 = self::PENALTY_N4 * (int) (abs($percent * 100 - 50) / 5);

        return $n1 + $n2 + $n3 + $n4;
    }

    /** N1: runs of five or more same-coloured modules score 3 + (run - 5). */
    private static function runPenalty(string $seq, int $size): int
    {
        $penalty = 0;
        $run = 1;
        for ($i = 1; $i < $size; $i++) {
            if ($seq[$i] === $seq[$i - 1]) {
                $run++;
            } else {
                if ($run >= 5) {
                    $penalty += self::PENALTY_N1 + ($run - 5);
                }
                $run = 1;
            }
        }
        if ($run >= 5) {
            $penalty += self::PENALTY_N1 + ($run - 5);
        }
        return $penalty;
    }

    /**
     * N3: the 1:1:3:1:1 dark/light pattern that mimics a finder pattern,
     * preceded or followed by four light modules. A symbol edge counts as
     * light because the quiet zone supplies it.
     *
     * On a near-miss the scan restarts four modules in rather than one, so
     * a single run of dark modules cannot be counted repeatedly.
     */
    private static function finderLikePenalty(string $seq, int $size): int
    {
        $count = 0;
        $idx = strpos($seq, self::N3_PATTERN);
        while ($idx !== false) {
            $offset = $idx + 7;

            $beforeStart = max($idx - 4, 0);
            $before = substr($seq, $beforeStart, $idx - $beforeStart);
            $after = substr($seq, $offset, max(0, min($offset + 4, $size) - $offset));

            if ($idx === 0
                || $idx === $size - 7
                || strpos($before, '1') === false
                || strpos($after, '1') === false) {
                $count += self::PENALTY_N3;
            } else {
                // Not enough light either side: the next possible match can
                // only begin at the third dark module of this run.
                $offset = $idx + 4;
            }
            $idx = strpos($seq, self::N3_PATTERN, $offset);
        }
        return $count;
    }

    // ------------------------------------------------------------- accessors

    public function mask(): int
    {
        return $this->mask;
    }

    public function isDark(int $x, int $y): bool
    {
        if ($x < 0 || $y < 0 || $x >= $this->size || $y >= $this->size) {
            return false;
        }
        return $this->modules[$y][$x];
    }

    /** @return list<list<bool>> */
    public function matrix(): array
    {
        return $this->modules;
    }

    public function eccName(): string
    {
        return ['L', 'M', 'Q', 'H'][$this->ecc] ?? 'M';
    }

    public static function eccFromName(string $name): int
    {
        return match (strtoupper($name)) {
            'L' => self::ECC_LOW,
            'Q' => self::ECC_QUARTILE,
            'H' => self::ECC_HIGH,
            default => self::ECC_MEDIUM,
        };
    }
}
