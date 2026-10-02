<?php

namespace App\Services\Reports;

use GdImage;
use RuntimeException;

/**
 * The network status report as one PNG (1080 px wide, for phones), for Telegram.
 * Made for troubleshooting: system status, online/offline counts, what to check
 * first with a first diagnosis (from the "Connected to" links), every offline
 * device with its location, and capacity alerts.
 * Drawn with GD and the DejaVu fonts that come with dompdf.
 */
class ReportImage
{
    private const W = 1080;

    private const PAD = 44;

    /** Telegram photos: width + height at most 10,000 px. Longer lists are cut with "and N more". */
    private const MAX_ROOTS = 20;

    private const MAX_ROWS = 60;

    private GdImage $img;

    private int $y = 0;

    private array $c = [];

    private string $font;

    private string $bold;

    /**
     * @param  array  $t  Troubleshooter::analyse()
     * @param  array  $extra  ['at' => Carbon, 'users' => ?int, 'outages' => int, 'recoveries' => int, 'period' => string]
     */
    public function png(array $t, array $extra): string
    {
        $dir = base_path('vendor/dompdf/dompdf/lib/fonts/');
        $this->font = $dir.'DejaVuSans.ttf';
        $this->bold = $dir.'DejaVuSans-Bold.ttf';
        if (! function_exists('imagettftext') || ! is_file($this->font)) {
            throw new RuntimeException('The report picture needs PHP GD with FreeType, and the DejaVu fonts from dompdf.');
        }

        $this->img = imagecreatetruecolor(self::W, 8900);
        foreach ([
            'bg' => '#F3F6F4', 'white' => '#FFFFFF', 'green' => '#0E670D', 'greenSoft' => '#E7F2E8', 'ink' => '#0F1A1F',
            'muted' => '#5C6B66', 'line' => '#D5E2D9', 'red' => '#B3372E', 'redSoft' => '#FBECEA', 'amber' => '#A26A00',
            'amberSoft' => '#FFF5DE', 'gold' => '#F2B84B', 'grey' => '#8A9690', 'track' => '#EEF3EF', 'stripe' => '#F8FAF9',
        ] as $k => $hex) {
            $this->c[$k] = imagecolorallocate($this->img, hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2)));
        }
        imagefill($this->img, 0, 0, $this->c['bg']);

        $this->header($extra);
        $this->status($t, $extra);
        $this->counts($t);
        $this->roots($t);
        $this->offlineTable($t);
        $this->capacity($t);
        $this->guide();
        $this->footer($extra);

        $out = imagecrop($this->img, ['x' => 0, 'y' => 0, 'width' => self::W, 'height' => min($this->y, 8900)]);
        ob_start();
        imagepng($out, null, 6);

        return (string) ob_get_clean();
    }

    /* ---------- Sections ---------- */

    private function header(array $e): void
    {
        imagefilledrectangle($this->img, 0, 0, self::W, 150, $this->c['green']);
        imagefilledrectangle($this->img, 0, 146, self::W, 150, $this->c['gold']);
        $this->text(self::PAD, 64, 'Network status report', 34, 'white', true);
        if (! empty($e['label'])) {
            $w = $this->width($e['label'], 22, true) + 36;
            $this->box(self::W - self::PAD - $w, 32, self::W - self::PAD, 76, 'gold');
            $this->text(self::W - self::PAD - 18, 63, $e['label'], 22, 'ink', true, 'right');
        }
        $this->text(self::PAD, 108, 'Public WiFi Control · '.$e['at']->format('l, F j, Y · H:i').' ('.$e['at']->timezone.')', 18, 'white');
        $this->y = 178;
    }

    private function status(array $t, array $e): void
    {
        [$bg, $fg, $word] = match ($t['status']['level']) {
            'critical' => ['redSoft', 'red', 'CRITICAL'],
            'warning' => ['amberSoft', 'amber', 'WARNING'],
            default => ['greenSoft', 'green', 'NORMAL'],
        };
        $lines = [];
        foreach ($t['status']['reasons'] as $r) {
            foreach ($this->wrap('• '.ucfirst($r), self::W - 2 * self::PAD - 64, 19) as $l) {
                $lines[] = $l;
            }
        }
        $h = 76 + count($lines) * 30 + 42;
        $this->box(self::PAD, $this->y, self::W - self::PAD, $this->y + $h, $bg);
        $this->box(self::PAD, $this->y, self::PAD + 10, $this->y + $h, $fg);
        $this->text(self::PAD + 34, $this->y + 50, 'SYSTEM STATUS:', 20, 'muted', true);
        $this->text(self::PAD + 238, $this->y + 52, $word, 34, $fg, true);
        foreach ($lines as $i => $l) {
            $this->text(self::PAD + 34, $this->y + 90 + $i * 30, $l, 19, 'ink');
        }
        $this->text(self::PAD + 34, $this->y + $h - 22, 'Users online now: '.($e['users'] === null ? 'not known' : number_format($e['users']))
            .'   ·   '.strtolower($e['period']).': '.$e['outages'].' '.($e['outages'] === 1 ? 'outage' : 'outages').', '.$e['recoveries'].' recovered', 16, 'muted');
        $this->y += $h + 26;
    }

    private function counts(array $t): void
    {
        $this->title('Devices');
        $top = $this->y;
        $rowH = 64;
        $this->card(self::PAD, $top, self::W - self::PAD, $top + 56 + 3 * $rowH);
        $cols = [self::PAD + 24 => 'TYPE', 470 => 'ONLINE', 640 => 'OFFLINE', 820 => 'NOT CHECKED'];
        foreach ($cols as $x => $h) {
            $this->text($x, $top + 38, $h, 15, 'muted', true);
        }
        imageline($this->img, self::PAD, $top + 54, self::W - self::PAD, $top + 54, $this->c['line']);
        foreach ([['router', 'Routers'], ['switch', 'Switches'], ['ap', 'Access points']] as $i => [$k, $label]) {
            $c = $t['counts'][$k];
            $y = $top + 56 + $i * $rowH;
            if ($i % 2) {
                $this->box(self::PAD + 1, $y, self::W - self::PAD - 1, $y + $rowH, 'stripe');
            }
            $this->text(self::PAD + 24, $y + 30, $label, 21, 'ink', true);
            $this->text(self::PAD + 24, $y + 54, $c['total'] ? number_format($c['total']).' in total' : 'none added', 14, 'muted');
            $this->text(470, $y + 42, number_format($c['online']), 28, $c['online'] ? 'green' : 'grey', true);
            $this->text(640, $y + 42, number_format($c['offline']), 28, $c['offline'] ? 'red' : 'grey', true);
            $this->text(820, $y + 42, number_format($c['unknown']), 28, 'grey', true);
            if ($c['total']) {
                // Thin bar under the row: green online, red offline, grey unchecked
                $x = self::PAD + 24; $w = self::W - 2 * self::PAD - 48; $at = $x;
                foreach ([['online', 'green'], ['offline', 'red'], ['unknown', 'grey']] as [$part, $color]) {
                    $pw = (int) round($w * $c[$part] / $c['total']);
                    if ($pw) {
                        $this->box($at, $y + $rowH - 6, $at + $pw, $y + $rowH - 3, $color);
                        $at += $pw;
                    }
                }
            }
        }
        $this->y = $top + 56 + 3 * $rowH + 30;
    }

    private function roots(array $t): void
    {
        if (! $t['roots']) {
            $this->title('Check these first');
            $this->card(self::PAD, $this->y, self::W - self::PAD, $this->y + 80);
            $this->text(self::PAD + 24, $this->y + 50, 'Nothing is offline. No action needed.', 21, 'green', true);
            $this->y += 108;

            return;
        }

        $this->title('Check these first ('.count($t['roots']).')  ·  most important first', 'red');
        foreach (array_slice($t['roots'], 0, self::MAX_ROOTS) as $i => $r) {
            $innerW = self::W - 2 * self::PAD - 72;
            $finding = $this->wrap($r['diagnosis']['finding'], $innerW, 18, true);
            $steps = [];
            foreach ($r['diagnosis']['steps'] as $n => $s) {
                foreach ($this->wrap(($n + 1).'. '.$s, $innerW - 20, 17) as $j => $l) {
                    $steps[] = $j ? '    '.$l : $l;
                }
            }
            $affected = $r['diagnosis']['affected'] ? $this->wrap($r['diagnosis']['affected'], $innerW, 17, true) : [];
            $names = [];
            if ($r['behind']) {
                $list = implode(', ', array_map(fn ($b) => $b['name'], array_slice($r['behind'], 0, 12))).(count($r['behind']) > 12 ? ' +'.(count($r['behind']) - 12).' more' : '');
                $names = $this->wrap('Also offline: '.$list, $innerW, 15);
            }
            $h = 112 + count($finding) * 27 + count($steps) * 26 + count($affected) * 26 + count($names) * 23 + 22;

            $top = $this->y;
            $color = $r['type'] === 'ap' ? 'amber' : 'red';
            $this->card(self::PAD, $top, self::W - self::PAD, $top + $h);
            $this->box(self::PAD, $top, self::PAD + 8, $top + $h, $color);
            // Number, type, name, how long
            imagefilledellipse($this->img, self::PAD + 46, $top + 38, 38, 38, $this->c[$color]);
            $this->text(self::PAD + 46, $top + 46, (string) ($i + 1), 19, 'white', true, 'center');
            $x = $this->text(self::PAD + 78, $top + 46, $r['label'].' ', 22, 'muted', true);
            $this->text($x, $top + 46, $this->fit($r['name'], self::W - self::PAD - 260 - $x, 22, true), 22, 'ink', true);
            $this->text(self::W - self::PAD - 24, $top + 46, $r['last_seen'] ? 'down '.$r['down_for'] : 'never answered', 17, $color, true, 'right');
            // Where
            $this->text(self::PAD + 78, $top + 80, $this->fit('📍 '.($r['where'] ?: 'No location set').'  ·  '.$r['ip'], $innerW, 17), 17, 'muted');
            $y = $top + 116;
            foreach ($finding as $l) {
                $this->text(self::PAD + 40, $y, $l, 18, 'ink', true);
                $y += 27;
            }
            $y += 4;
            foreach ($steps as $l) {
                $this->text(self::PAD + 52, $y, $l, 17, 'ink');
                $y += 26;
            }
            foreach ($affected as $l) {
                $this->text(self::PAD + 40, $y, $l, 17, 'red', true);
                $y += 26;
            }
            foreach ($names as $l) {
                $this->text(self::PAD + 40, $y, $l, 15, 'muted');
                $y += 23;
            }
            $this->y = $top + $h + 18;
        }
        if (count($t['roots']) > self::MAX_ROOTS) {
            $this->text(self::PAD, $this->y + 10, '…and '.(count($t['roots']) - self::MAX_ROOTS).' more to check. See the dashboard for the full list.', 18, 'muted', true);
            $this->y += 40;
        }
        $this->y += 12;
    }

    private function offlineTable(array $t): void
    {
        if (! $t['offline']) {
            return;
        }
        $this->title('All offline devices ('.count($t['offline']).')');
        $rows = array_slice($t['offline'], 0, self::MAX_ROWS);
        $rowH = 52;
        $top = $this->y;
        $h = 50 + count($rows) * $rowH + (count($t['offline']) > self::MAX_ROWS ? 40 : 6);
        $this->card(self::PAD, $top, self::W - self::PAD, $top + $h);
        $cols = [self::PAD + 20 => 'DEVICE', 400 => 'LOCATION', 720 => 'DOWN', 820 => 'WHY'];
        foreach ($cols as $x => $head) {
            $this->text($x, $top + 34, $head, 14, 'muted', true);
        }
        imageline($this->img, self::PAD, $top + 48, self::W - self::PAD, $top + 48, $this->c['line']);
        foreach ($rows as $i => $d) {
            $y = $top + 50 + $i * $rowH;
            if ($i % 2) {
                $this->box(self::PAD + 1, $y, self::W - self::PAD - 1, $y + $rowH, 'stripe');
            }
            $short = ['router' => 'Router', 'switch' => 'Switch', 'ap' => 'AP'][$d['type']] ?? '';
            $this->text(self::PAD + 20, $y + 22, $this->fit($d['name'], 330, 17, true), 17, 'ink', true);
            $this->text(self::PAD + 20, $y + 43, $short.' · '.$d['ip'], 13, 'muted');
            $this->text(400, $y + 22, $this->fit($d['barangay'] ?? ($d['type'] === 'router' ? 'Router site' : 'No barangay'), 300, 16), 16, 'ink');
            $this->text(400, $y + 43, $this->fit((string) $d['landmark'], 300, 13), 13, 'muted');
            $this->text(720, $y + 30, $d['last_seen'] ? (string) $d['down_for'] : 'never', 15, 'ink');
            $this->text(820, $y + 22, $d['cause'] ? 'Behind' : 'Itself', 15, $d['cause'] ? 'muted' : 'red', true);
            $this->text(820, $y + 43, $this->fit($d['cause'] ?? ($d['last_seen'] ? 'check on site' : 'check IP / SNMP'), self::W - self::PAD - 840, 13), 13, 'muted');
        }
        if (count($t['offline']) > self::MAX_ROWS) {
            $this->text(self::PAD + 20, $top + $h - 14, '…and '.(count($t['offline']) - self::MAX_ROWS).' more on the dashboard.', 16, 'muted');
        }
        $this->y = $top + $h + 28;
    }

    private function capacity(array $t): void
    {
        if (! $t['capacity']) {
            return;
        }
        $this->title('Capacity', 'amber');
        $top = $this->y;
        $rows = array_slice($t['capacity'], 0, 6);
        $lines = [];
        foreach ($rows as $a) {
            $lines[] = [($a['level'] === 'full' ? 'FULL: ' : 'BUSY: ').$a['title'], $a['level'] === 'full' ? 'red' : 'amber', true];
            foreach (array_slice($this->wrap($a['message'], self::W - 2 * self::PAD - 60, 15), 0, 3) as $l) {
                $lines[] = [$l, 'muted', false];
            }
        }
        $h = 30 + count($lines) * 26;
        $this->card(self::PAD, $top, self::W - self::PAD, $top + $h);
        foreach ($lines as $i => [$l, $color, $b]) {
            $this->text(self::PAD + 24, $top + 38 + $i * 26, $l, $b ? 17 : 15, $color, $b);
        }
        $this->y = $top + $h + 28;
    }

    private function guide(): void
    {
        $this->title('How to troubleshoot');
        $tips = [
            'Fix in this order: routers, then switches, then access points. Devices behind an offline switch come back with it.',
            'Access point offline but its switch online: the problem is at the access point. Check PoE / power, the cable and the port, then restart it.',
            'Switch offline: check its power and the cable from its uplink first; its access points will show offline until it is back.',
            '"Never answered": usually the IP address or SNMP settings in the system, not the hardware.',
        ];
        $lines = [];
        foreach ($tips as $tip) {
            foreach ($this->wrap('• '.$tip, self::W - 2 * self::PAD - 48, 16) as $j => $l) {
                $lines[] = $j ? '   '.$l : $l;
            }
        }
        $top = $this->y;
        $this->card(self::PAD, $top, self::W - self::PAD, $top + 26 + count($lines) * 25);
        foreach ($lines as $i => $l) {
            $this->text(self::PAD + 24, $top + 38 + $i * 25, $l, 16, 'ink');
        }
        $this->y = $top + 26 + count($lines) * 25 + 28;
    }

    private function footer(array $e): void
    {
        imageline($this->img, self::PAD, $this->y, self::W - self::PAD, $this->y, $this->c['green']);
        $this->text(self::PAD, $this->y + 32, 'System-generated report, '.$e['at']->format('F j, Y g:i A').'. Locations and "Connected to" come from the device records.', 14, 'muted');
        $this->text(self::PAD, $this->y + 58, 'System developed by Uplink Integrated Solutions Inc. – System & Network Department', 15, 'green', true);
        $this->y += 86;
    }

    /* ---------- Drawing helpers ---------- */

    private function title(string $text, string $color = 'green'): void
    {
        $this->text(self::PAD, $this->y + 22, strtoupper($text), 17, $color, true);
        $this->y += 38;
    }

    private function card(int $x1, int $y1, int $x2, int $y2): void
    {
        $this->box($x1, $y1, $x2, $y2, 'white');
        imagerectangle($this->img, $x1, $y1, $x2, $y2, $this->c['line']);
    }

    private function box(int $x1, int $y1, int $x2, int $y2, string $color): void
    {
        imagefilledrectangle($this->img, $x1, $y1, $x2, $y2, $this->c[$color]);
    }

    /** Draws text at a baseline (size in px); returns the x where it ends. */
    private function text(int $x, int $y, string $text, int $size, string $color, bool $bold = false, string $align = 'left'): int
    {
        $text = str_replace('📍 ', '', $text); // the fonts have no emoji
        $w = $this->width($text, $size, $bold);
        $x = match ($align) { 'right' => $x - $w, 'center' => (int) ($x - $w / 2), default => $x };
        imagettftext($this->img, $size * 0.75, 0, $x, $y, $this->c[$color], $bold ? $this->bold : $this->font, $text);

        return $x + $w;
    }

    private function width(string $text, int $size, bool $bold = false): int
    {
        $b = imagettfbbox($size * 0.75, 0, $bold ? $this->bold : $this->font, $text);

        return (int) abs($b[2] - $b[0]);
    }

    /** Splits text into lines that fit a width. */
    private function wrap(string $text, int $max, int $size, bool $bold = false): array
    {
        $lines = [];
        $line = '';
        foreach (preg_split('/\s+/', trim($text)) as $word) {
            $try = $line === '' ? $word : $line.' '.$word;
            if ($line !== '' && $this->width($try, $size, $bold) > $max) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $try;
            }
        }

        return $line === '' ? $lines : [...$lines, $line];
    }

    /** Cuts text to fit a width, with an ellipsis. */
    private function fit(string $text, int $max, int $size, bool $bold = false): string
    {
        if ($max <= 0 || $this->width($text, $size, $bold) <= $max) {
            return $text;
        }
        while (mb_strlen($text) > 1 && $this->width($text.'…', $size, $bold) > $max) {
            $text = mb_substr($text, 0, -1);
        }

        return rtrim($text).'…';
    }
}
