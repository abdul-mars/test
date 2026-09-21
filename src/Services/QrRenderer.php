<?php
declare(strict_types=1);

namespace QRoute\Services;

/**
 * Renders a QrCode matrix to SVG or PNG.
 *
 * SVG is the default download because a printed QR code should be vector:
 * a customer scaling a raster code up for a poster is the single most
 * common cause of an unscannable print run.
 */
final class QrRenderer
{
    public const DEFAULT_DARK  = '#0f172a';
    public const DEFAULT_LIGHT = '#ffffff';

    /** Quiet zone in modules. The standard requires 4; never go below it. */
    private const QUIET_ZONE = 4;

    /**
     * @param array{dark?:string,light?:string,scale?:int,quiet?:int,shape?:string,label?:string} $style
     */
    public static function svg(QrCode $qr, array $style = []): string
    {
        $dark  = self::colour($style['dark'] ?? self::DEFAULT_DARK, self::DEFAULT_DARK);
        $light = self::colour($style['light'] ?? self::DEFAULT_LIGHT, self::DEFAULT_LIGHT);
        $quiet = max(self::QUIET_ZONE, (int) ($style['quiet'] ?? self::QUIET_ZONE));
        $shape = ($style['shape'] ?? 'square') === 'dot' ? 'dot' : 'square';

        $size = $qr->size;
        $dim = $size + $quiet * 2;

        $parts = [];
        $parts[] = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$d %1$d" width="%1$d" height="%1$d" shape-rendering="crispEdges" role="img" aria-label="QR code">',
            $dim
        );
        $parts[] = sprintf('<rect width="%1$d" height="%1$d" fill="%2$s"/>', $dim, $light);

        if ($shape === 'dot') {
            // Rounded modules look better in branding but shrink the dark
            // area, so they are only offered with high error correction.
            $circles = [];
            for ($y = 0; $y < $size; $y++) {
                for ($x = 0; $x < $size; $x++) {
                    if ($qr->isDark($x, $y)) {
                        $circles[] = sprintf(
                            '<circle cx="%s" cy="%s" r="0.5"/>',
                            $x + $quiet + 0.5,
                            $y + $quiet + 0.5
                        );
                    }
                }
            }
            $parts[] = '<g fill="' . $dark . '">' . implode('', $circles) . '</g>';
        } else {
            // Merge horizontally adjacent dark modules into one rect. This
            // typically cuts the file size by more than half.
            $path = [];
            for ($y = 0; $y < $size; $y++) {
                $x = 0;
                while ($x < $size) {
                    if (!$qr->isDark($x, $y)) {
                        $x++;
                        continue;
                    }
                    $runStart = $x;
                    while ($x < $size && $qr->isDark($x, $y)) {
                        $x++;
                    }
                    $path[] = sprintf(
                        'M%d %dh%dv1h-%dz',
                        $runStart + $quiet,
                        $y + $quiet,
                        $x - $runStart,
                        $x - $runStart
                    );
                }
            }
            $parts[] = '<path fill="' . $dark . '" d="' . implode('', $path) . '"/>';
        }

        $parts[] = '</svg>';
        return implode('', $parts);
    }

    /**
     * @param array{dark?:string,light?:string,scale?:int,quiet?:int} $style
     */
    public static function png(QrCode $qr, array $style = []): string
    {
        if (!function_exists('imagecreatetruecolor')) {
            throw new \RuntimeException('GD extension is required for PNG output.');
        }

        $scale = (int) ($style['scale'] ?? 8);
        $scale = max(1, min(40, $scale));
        $quiet = max(self::QUIET_ZONE, (int) ($style['quiet'] ?? self::QUIET_ZONE));

        [$dr, $dg, $db] = self::rgb(self::colour($style['dark'] ?? self::DEFAULT_DARK, self::DEFAULT_DARK));
        [$lr, $lg, $lb] = self::rgb(self::colour($style['light'] ?? self::DEFAULT_LIGHT, self::DEFAULT_LIGHT));

        $size = $qr->size;
        $dim = ($size + $quiet * 2) * $scale;

        $img = imagecreatetruecolor($dim, $dim);
        if ($img === false) {
            throw new \RuntimeException('Could not allocate image.');
        }
        $lightColor = imagecolorallocate($img, $lr, $lg, $lb);
        $darkColor  = imagecolorallocate($img, $dr, $dg, $db);
        imagefilledrectangle($img, 0, 0, $dim - 1, $dim - 1, $lightColor);

        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if ($qr->isDark($x, $y)) {
                    $px = ($x + $quiet) * $scale;
                    $py = ($y + $quiet) * $scale;
                    imagefilledrectangle($img, $px, $py, $px + $scale - 1, $py + $scale - 1, $darkColor);
                }
            }
        }

        ob_start();
        imagepng($img, null, 9);
        $data = (string) ob_get_clean();
        imagedestroy($img);
        return $data;
    }

    /** Normalises a hex colour, falling back when input is not trusted. */
    public static function colour(string $value, string $fallback): string
    {
        $value = trim($value);
        if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value) === 1) {
            return strtolower($value);
        }
        return $fallback;
    }

    /** @return array{0:int,1:int,2:int} */
    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    /**
     * Contrast ratio between the two colours, per WCAG. A QR scanner needs
     * roughly 3:1 to read reliably; we warn below 4:1.
     */
    public static function contrastRatio(string $darkHex, string $lightHex): float
    {
        $lum = static function (string $hex): float {
            [$r, $g, $b] = self::rgb($hex);
            $c = static function (int $v): float {
                $s = $v / 255;
                return $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
            };
            return 0.2126 * $c($r) + 0.7152 * $c($g) + 0.0722 * $c($b);
        };
        $l1 = $lum($darkHex);
        $l2 = $lum($lightHex);
        $hi = max($l1, $l2);
        $lo = min($l1, $l2);
        return ($hi + 0.05) / ($lo + 0.05);
    }
}
