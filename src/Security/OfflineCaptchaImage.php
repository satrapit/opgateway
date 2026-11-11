<?php

declare(strict_types=1);

namespace OPGateway\Security;

use RuntimeException;

/**
 * Self-hosted, randomized raster CAPTCHA. No client-side arithmetic, remote
 * font, GD requirement, or direct plaintext answer in markup/session.
 *
 * A visual CAPTCHA is NOT bot-proof (modern vision models can solve images).
 * Keep SQL rate limiting, one-use answers and normal login throttles enabled.
 */
final class OfflineCaptchaImage
{
    public const WIDTH = 300;
    public const HEIGHT = 106;

    /** Font outlines are intentionally minimal; distortion is per-request. */
    private const GLYPHS = [
        'A' => ['01110','10001','10001','11111','10001','10001','10001'],
        'B' => ['11110','10001','10001','11110','10001','10001','11110'],
        'C' => ['01111','10000','10000','10000','10000','10000','01111'],
        'D' => ['11110','10001','10001','10001','10001','10001','11110'],
        'E' => ['11111','10000','10000','11110','10000','10000','11111'],
        'F' => ['11111','10000','10000','11110','10000','10000','10000'],
        'G' => ['01111','10000','10000','10111','10001','10001','01110'],
        'H' => ['10001','10001','10001','11111','10001','10001','10001'],
        'J' => ['00111','00010','00010','00010','10010','10010','01100'],
        'K' => ['10001','10010','10100','11000','10100','10010','10001'],
        'L' => ['10000','10000','10000','10000','10000','10000','11111'],
        'M' => ['10001','11011','10101','10101','10001','10001','10001'],
        'N' => ['10001','11001','10101','10011','10001','10001','10001'],
        'P' => ['11110','10001','10001','11110','10000','10000','10000'],
        'Q' => ['01110','10001','10001','10001','10101','10010','01101'],
        'R' => ['11110','10001','10001','11110','10100','10010','10001'],
        'S' => ['01111','10000','10000','01110','00001','00001','11110'],
        'T' => ['11111','00100','00100','00100','00100','00100','00100'],
        'U' => ['10001','10001','10001','10001','10001','10001','01110'],
        'V' => ['10001','10001','10001','10001','10001','01010','00100'],
        'W' => ['10001','10001','10001','10101','10101','10101','01010'],
        'X' => ['10001','10001','01010','00100','01010','10001','10001'],
        'Y' => ['10001','10001','01010','00100','00100','00100','00100'],
        'Z' => ['11111','00001','00010','00100','01000','10000','11111'],
        '2' => ['01110','10001','00001','00010','00100','01000','11111'],
        '3' => ['11110','00001','00001','01110','00001','00001','11110'],
        '4' => ['00010','00110','01010','10010','11111','00010','00010'],
        '5' => ['11111','10000','10000','11110','00001','00001','11110'],
        '6' => ['00111','01000','10000','11110','10001','10001','01110'],
        '7' => ['11111','00001','00010','00100','01000','01000','01000'],
        '8' => ['01110','10001','10001','01110','10001','10001','01110'],
        '9' => ['01110','10001','10001','01111','00001','00010','11100'],
    ];

    public static function randomAnswer(): string
    {
        $alphabet = array_keys(self::GLYPHS);
        $answer = '';
        for ($i = 0; $i < 6; $i++) {
            $answer .= $alphabet[random_int(0, count($alphabet) - 1)];
        }
        return $answer;
    }

    /** @return string Binary PNG payload; never embed as a data URL in HTML. */
    public static function render(string $answer): string
    {
        if (!function_exists('gzcompress') || strlen($answer) !== 6) {
            throw new RuntimeException('Image CAPTCHA unavailable.');
        }
        foreach (str_split($answer) as $letter) {
            if (!isset(self::GLYPHS[$letter])) {
                throw new RuntimeException('Unsupported CAPTCHA glyph.');
            }
        }

        $w = self::WIDTH; $h = self::HEIGHT;
        $pixels = array_fill(0, $w * $h, 0);
        $background = random_int(231, 247);
        // Per-pixel decorative noise does not need cryptographic entropy.
        // Reserve random_int() for answers and challenge identifiers, and
        // avoid tens of thousands of CSPRNG calls on every login page GET.
        $noise = random_int(1, 0x7fffffff);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $noise = ($noise * 1103515245 + 12345) & 0x7fffffff;
                $v = max(215, min(255, $background + ($noise % 22) - 9));
                $pixels[$y * $w + $x] = ($v << 16) | (($v - 1) << 8) | $v;
            }
        }
        $plot = static function (int $x, int $y, int $rgb) use (&$pixels, $w, $h): void {
            if ($x >= 0 && $x < $w && $y >= 0 && $y < $h) {
                $pixels[$y * $w + $x] = $rgb;
            }
        };
        for ($i = 0; $i < 11; $i++) {
            $base = random_int(0, $h - 1);
            $phase = random_int(0, 600) / 70;
            $amp = random_int(5, 20);
            $shade = random_int(163, 202);
            $color = ($shade << 16) | ($shade << 8) | ($shade + 2);
            for ($x = 0; $x < $w; $x++) {
                $y = (int) round($base + sin($x / 18 + $phase) * $amp);
                $plot($x, $y, $color);
            }
        }
        $chars = str_split($answer);
        foreach ($chars as $index => $letter) {
            $rows = self::GLYPHS[$letter];
            $angle = random_int(-27, 27) * M_PI / 180;
            $cos = cos($angle); $sin = sin($angle);
            $cx = 31 + $index * 47 + random_int(-4, 4);
            $cy = 51 + random_int(-10, 10);
            $scale = random_int(4, 5);
            $shade = random_int(19, 58);
            $ink = ($shade << 16) | (($shade + 13) << 8) | ($shade + 21);
            for ($gy = 0; $gy < 7; $gy++) {
                for ($gx = 0; $gx < 5; $gx++) {
                    if ($rows[$gy][$gx] !== '1') {
                        continue;
                    }
                    for ($dy = 0; $dy < $scale; $dy++) {
                        for ($dx = 0; $dx < $scale; $dx++) {
                            $a = ($gx - 2.5) * $scale + $dx;
                            $b = ($gy - 3.5) * $scale + $dy;
                            $x = (int) round($cx + $a * $cos - $b * $sin);
                            $y = (int) round($cy + $a * $sin + $b * $cos);
                            $plot($x, $y, $ink);
                        }
                    }
                }
            }
        }
        // Add layered distractors; never encode the answer as SVG/HTML text.
        for ($i = 0; $i < 950; $i++) {
            $x = random_int(0, $w - 1); $y = random_int(0, $h - 1);
            $v = random_int(125, 210);
            $plot($x, $y, ($v << 16) | (($v + 3) << 8) | $v);
        }
        $phase = random_int(1, 1000) / 80;
        $scanlines = '';
        for ($y = 0; $y < $h; $y++) {
            $scanlines .= "\x00"; // PNG filter: None
            $offset = (int) round(3.2 * sin($y / 11 + $phase));
            for ($x = 0; $x < $w; $x++) {
                $srcX = max(0, min($w - 1, $x + $offset));
                $rgb = $pixels[$y * $w + $srcX];
                $scanlines .= chr(($rgb >> 16) & 255)
                    . chr(($rgb >> 8) & 255) . chr($rgb & 255);
            }
        }
        $compressed = gzcompress($scanlines, 6);
        if ($compressed === false) {
            throw new RuntimeException('Image CAPTCHA compression failed.');
        }
        $chunk = static function (string $type, string $data): string {
            return pack('N', strlen($data)) . $type . $data
                . pack('N', crc32($type . $data));
        };
        return "\x89PNG\r\n\x1a\n"
            . $chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0))
            . $chunk('IDAT', $compressed) . $chunk('IEND', '');
    }
}
