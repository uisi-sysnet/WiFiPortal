<?php

namespace App\Support;

/**
 * A pie chart as a PNG data URI, for PDFs (dompdf draws SVG arcs unreliably).
 * Drawn at 4x and scaled down with resampling for smooth edges. Needs GD.
 */
class PieChart
{
    /**
     * @param  array<int, array{0:int|float, 1:string}>  $slices  [value, '#rrggbb'], drawn clockwise from 12 o'clock
     */
    public static function dataUri(array $slices, int $size = 160, string $empty = '#EEF3EF'): string
    {
        $scale = 4;
        $big = $size * $scale;
        $img = imagecreatetruecolor($big, $big);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 255, 255, 255, 127));

        $total = array_sum(array_column($slices, 0));
        $c = intdiv($big, 2);
        $d = $big - 2 * $scale;

        if ($total <= 0) {
            imagefilledellipse($img, $c, $c, $d, $d, self::color($img, $empty));
        } else {
            $angle = -90.0; // GD measures from 3 o'clock, clockwise
            foreach ($slices as [$value, $hex]) {
                if ($value <= 0) {
                    continue;
                }
                $sweep = $value / $total * 360;
                if ($sweep >= 359.99) {
                    imagefilledellipse($img, $c, $c, $d, $d, self::color($img, $hex));
                } else {
                    imagefilledarc($img, $c, $c, $d, $d, (int) round($angle), (int) round($angle + $sweep), self::color($img, $hex), IMG_ARC_PIE);
                }
                $angle += $sweep;
            }
        }

        $out = imagecreatetruecolor($size, $size);
        imagesavealpha($out, true);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 255, 255, 255, 127));
        imagecopyresampled($out, $img, 0, 0, 0, 0, $size, $size, $big, $big);

        ob_start();
        imagepng($out);
        $png = (string) ob_get_clean();

        return 'data:image/png;base64,'.base64_encode($png);
    }

    private static function color(\GdImage $img, string $hex): int
    {
        [$r, $g, $b] = sscanf(ltrim($hex, '#'), '%02x%02x%02x');

        return imagecolorallocate($img, $r, $g, $b);
    }
}
