<?php
declare(strict_types=1);

/**
 * Letter-mark logos drawn with GD: the business's initials on a shape, in a
 * colour — clean enough for an invoice header, instant, and free. Optional AI
 * symbols come from Pollinations (free image API, no key) in ajax/logo.php.
 */
const LOGO_STYLES = ['circle', 'rounded', 'square', 'outline', 'stacked', 'badge'];
const LOGO_COLORS = ['#3b5bfd', '#10b981', '#0e1726', '#e5484d', '#7c5cff', '#d98a06', '#0891b2', '#be185d'];

function initialsFor(string $name): string
{
    $words = preg_split('/[\s\-&]+/', trim(preg_replace('/\b(pty|ltd|cc|inc|the|and)\b\.?/i', '', $name))) ?: [];
    $words = array_values(array_filter($words, fn($w) => preg_match('/[A-Za-z0-9]/', $w)));
    // One CamelCase word ("SwiftFusion") reads as two: SF.
    if (count($words) === 1 && preg_match_all('/[A-Z][a-z]+/', $words[0], $m) > 1) $words = $m[0];
    $init  = implode('', array_map(fn($w) => mb_strtoupper(mb_substr(preg_replace('/[^A-Za-z0-9]/', '', $w), 0, 1)), array_slice($words, 0, 3)));
    return $init !== '' ? $init : 'IC';
}

function logoFont(bool $serif = false): string
{
    $dir = BASE_DIR . '/vendor/dompdf/dompdf/lib/fonts/';
    return $serif && is_file($dir . 'DejaVuSerif-Bold.ttf') ? $dir . 'DejaVuSerif-Bold.ttf' : $dir . 'DejaVuSans-Bold.ttf';
}

function hexColor(\GdImage $im, string $hex, int $alpha = 0): int
{
    [$r, $g, $b] = hexToRgb($hex);
    return imagecolorallocatealpha($im, $r, $g, $b, $alpha);
}

/** Draws text centred in a box; shrinks the font until it fits. */
function centredText(\GdImage $im, string $text, string $font, int $color, int $cx, int $cy, int $maxW, int $size): void
{
    do {
        $box = imagettfbbox($size, 0, $font, $text);
        $w = $box[2] - $box[0];
        $h = $box[1] - $box[7];
        $size -= 4;
    } while ($w > $maxW && $size > 12);
    imagettftext($im, $size + 4, 0, (int) ($cx - $w / 2 - $box[0]), (int) ($cy + $h / 2 - $box[1]), $color, $font, $text);
}

/** A 600×600 transparent PNG (as a string) for one style + colour. */
function renderLogo(string $letters, string $name, string $style, string $color): string
{
    $S  = 600;
    $im = imagecreatetruecolor($S, $S);
    imagesavealpha($im, true);
    imagealphablending($im, true);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
    imageantialias($im, true);

    $fill  = hexColor($im, $color);
    $white = imagecolorallocate($im, 255, 255, 255);
    $ink   = hexColor($im, '#0e1726');
    $font  = logoFont($style === 'badge');
    $letters = mb_substr($letters, 0, 3);

    switch ($style) {
        case 'circle':
            imagefilledellipse($im, 300, 300, 560, 560, $fill);
            centredText($im, $letters, $font, $white, 300, 300, 400, mb_strlen($letters) > 2 ? 150 : 210);
            break;
        case 'rounded':
            roundedRect($im, 20, 20, 580, 580, 120, $fill);
            centredText($im, $letters, $font, $white, 300, 300, 420, mb_strlen($letters) > 2 ? 160 : 220);
            break;
        case 'square':
            imagefilledrectangle($im, 20, 20, 580, 580, $fill);
            imagefilledrectangle($im, 20, 470, 580, 580, hexColor($im, '#000000', 90));
            centredText($im, $letters, $font, $white, 300, 270, 440, mb_strlen($letters) > 2 ? 160 : 220);
            break;
        case 'outline':
            // A ring: a filled circle with a transparent hole punched out.
            imagefilledellipse($im, 300, 300, 560, 560, $fill);
            imagealphablending($im, false);
            imagefilledellipse($im, 300, 300, 480, 480, imagecolorallocatealpha($im, 0, 0, 0, 127));
            imagealphablending($im, true);
            centredText($im, $letters, $font, $fill, 300, 300, 340, mb_strlen($letters) > 2 ? 130 : 190);
            break;
        case 'stacked':
            roundedRect($im, 110, 40, 490, 420, 90, $fill);
            centredText($im, $letters, $font, $white, 300, 230, 300, mb_strlen($letters) > 2 ? 120 : 170);
            centredText($im, mb_strtoupper(mb_substr($name, 0, 22)), logoFont(), $ink, 300, 510, 560, 54);
            break;
        default: // badge: hexagon
            $pts = [];
            for ($i = 0; $i < 6; $i++) {
                $a = deg2rad(60 * $i - 90);
                $pts[] = (int) (300 + 280 * cos($a));
                $pts[] = (int) (300 + 280 * sin($a));
            }
            imagefilledpolygon($im, $pts, $fill);
            centredText($im, $letters, $font, $white, 300, 300, 360, mb_strlen($letters) > 2 ? 140 : 200);
    }

    ob_start();
    imagepng($im);
    imagedestroy($im);
    return (string) ob_get_clean();
}

function roundedRect(\GdImage $im, int $x1, int $y1, int $x2, int $y2, int $r, int $color): void
{
    imagefilledrectangle($im, $x1 + $r, $y1, $x2 - $r, $y2, $color);
    imagefilledrectangle($im, $x1, $y1 + $r, $x2, $y2 - $r, $color);
    foreach ([[$x1 + $r, $y1 + $r], [$x2 - $r, $y1 + $r], [$x1 + $r, $y2 - $r], [$x2 - $r, $y2 - $r]] as [$cx, $cy]) {
        imagefilledellipse($im, $cx, $cy, $r * 2, $r * 2, $color);
    }
}

/** Saves PNG bytes as the user's logo (replacing the old file) and returns its path. */
function saveUserLogo(int $userId, string $png, string $ext = 'png'): string
{
    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
    $path = UPLOAD_DIR . 'logo_' . $userId . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    file_put_contents($path, $png);
    $pm  = new \InvoiceChat\ProfileManager(getDB());
    $old = $pm->get($userId)['logo_path'] ?? '';
    if ($old && is_file($old)) @unlink($old);
    $pm->update($userId, ['logo_path' => $path]);
    return $path;
}
