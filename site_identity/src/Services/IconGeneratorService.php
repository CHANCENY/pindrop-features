<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\site_identity\src\Services;

/**
 * IconGeneratorService
 *
 * Renders a full favicon/PWA icon set either from short text (e.g. site
 * initials, drawn in a bundled bold sans font) or from an uploaded image
 * (cropped to a centered square, resized, corners rounded to match).
 *
 * Requires the GD extension (bundled with PHP on most hosts, but not
 * universal — every public method throws \RuntimeException up front if
 * `ext-gd` isn't loaded, with a message the admin UI surfaces directly
 * rather than failing silently or fataling mid-render).
 *
 * Output variants match AssetStoreService::VARIANTS. ICO is a modern
 * (Vista+) PNG-embedded ICO container built by hand in buildIco() — GD
 * itself cannot write .ico, only PNG/JPEG/etc.
 */
class IconGeneratorService
{
    private const RASTER_SIZES = [16, 32, 48, 96, 180, 192, 512];

    public function __construct(private readonly string $fontPath = __DIR__ . '/../../assets/fonts/Outfit-Bold.ttf') {}

    private function assertGdAvailable(): void
    {
        if (!extension_loaded('gd')) {
            throw new \RuntimeException(
                'The GD PHP extension is not installed/enabled on this server. '
                . 'Icon generation needs it — ask your host to enable ext-gd, then try again.'
            );
        }
    }

    /**
     * @param string $text 1-2 characters, e.g. site initials.
     * @param string $bgHex   '#rrggbb'
     * @param string $fgHex   '#rrggbb'
     * @return array<string,array{bytes:string,mime:string}> keyed by AssetStoreService::VARIANTS
     */
    public function generateFromText(string $text, string $bgHex, string $fgHex = '#ffffff'): array
    {
        $this->assertGdAvailable();

        $text = mb_substr(trim($text) ?: '?', 0, 2);
        $bg = $this->hexToRgb($bgHex);
        $fg = $this->hexToRgb($fgHex);

        $rasters = [];
        foreach (self::RASTER_SIZES as $size) {
            $rasters[$size] = $this->renderTextSquare($size, $text, $bg, $fg);
        }

        $svg = $this->buildTextSvg($text, $bgHex, $fgHex);

        return $this->assemble($rasters, $svg);
    }

    /**
     * @param string $sourceBytes raw uploaded image bytes (png/jpg/webp/gif)
     * @return array<string,array{bytes:string,mime:string}>
     */
    public function generateFromImage(string $sourceBytes): array
    {
        $this->assertGdAvailable();

        $source = @imagecreatefromstring($sourceBytes);
        if ($source === false) {
            throw new \RuntimeException('Could not read that image — try a PNG, JPEG, WebP, or GIF file.');
        }

        $squared = $this->cropToSquare($source);
        imagedestroy($source);

        $rasters = [];
        foreach (self::RASTER_SIZES as $size) {
            $rasters[$size] = $this->resizeAndRound($squared, $size);
        }
        imagedestroy($squared);

        // True vector SVG isn't derivable from a raster upload — wrap the
        // 512px PNG as a base64 data URI inside an <svg><image> so
        // `/favicon.svg` still returns valid image/svg+xml either way
        // (browsers render this exactly like a raster favicon).
        $svg = $this->buildImageSvg($rasters[512]);

        return $this->assemble($rasters, $svg);
    }

    /** @param array<int,string> $rasters size(px) => PNG bytes */
    private function assemble(array $rasters, string $svg): array
    {
        return [
            'favicon.svg'          => ['bytes' => $svg, 'mime' => 'image/svg+xml'],
            'favicon-96x96.png'    => ['bytes' => $rasters[96], 'mime' => 'image/png'],
            'apple-touch-icon.png' => ['bytes' => $rasters[180], 'mime' => 'image/png'],
            'icon-192.png'         => ['bytes' => $rasters[192], 'mime' => 'image/png'],
            'icon-512.png'         => ['bytes' => $rasters[512], 'mime' => 'image/png'],
            'favicon.ico'          => [
                'bytes' => $this->buildIco([$rasters[16], $rasters[32], $rasters[48]], [16, 32, 48]),
                'mime'  => 'image/x-icon',
            ],
        ];
    }

    // ── Text rendering ──────────────────────────────────────────────────

    private function renderTextSquare(int $size, string $text, array $bg, array $fg): string
    {
        $im = imagecreatetruecolor($size, $size);
        imagesavealpha($im, true);
        $transparent = imagecolorallocatealpha($im, 0, 0, 0, 127);
        imagefill($im, 0, 0, $transparent);

        $bgColor = imagecolorallocate($im, $bg[0], $bg[1], $bg[2]);
        $radius = (int) round($size * 0.22);
        $this->drawRoundedRect($im, 0, 0, $size - 1, $size - 1, $radius, $bgColor);

        $fgColor = imagecolorallocate($im, $fg[0], $fg[1], $fg[2]);
        $fontSize = $size * (mb_strlen($text) > 1 ? 0.36 : 0.5);

        if (is_file($this->fontPath) && function_exists('imagettftext') && function_exists('imagettfbbox')) {
            $bbox = imagettfbbox($fontSize, 0, $this->fontPath, $text);
            $tw = $bbox[2] - $bbox[0];
            $th = $bbox[1] - $bbox[7];
            $x = ($size - $tw) / 2 - $bbox[0];
            $y = ($size - $th) / 2 - $bbox[7];
            imagettftext($im, $fontSize, 0, (int) $x, (int) $y, $fgColor, $this->fontPath, $text);
        } else {
            // Bundled font missing on this install — fall back to GD's
            // built-in bitmap font rather than failing the whole render.
            $gdFont = 5;
            $tw = imagefontwidth($gdFont) * strlen($text);
            $th = imagefontheight($gdFont);
            imagestring($im, $gdFont, (int) (($size - $tw) / 2), (int) (($size - $th) / 2), $text, $fgColor);
        }

        return $this->pngBytes($im);
    }

    private function buildTextSvg(string $text, string $bgHex, string $fgHex): string
    {
        $safeText = htmlspecialchars($text, ENT_QUOTES | ENT_XML1);
        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">
  <rect width="64" height="64" rx="14" fill="{$bgHex}"/>
  <text x="32" y="42" font-family="Arial, Helvetica, sans-serif" font-size="30" font-weight="700"
        text-anchor="middle" fill="{$fgHex}">{$safeText}</text>
</svg>
SVG;
    }

    // ── Image rendering ─────────────────────────────────────────────────

    /** @return \GdImage */
    private function cropToSquare($source)
    {
        $w = imagesx($source);
        $h = imagesy($source);
        $side = min($w, h);
        $srcX = (int) (($w - $side) / 2);
        $srcY = (int) (($h - $side) / 2);

        $square = imagecreatetruecolor($side, $side);
        imagesavealpha($square, true);
        imagealphablending($square, false);
        $transparent = imagecolorallocatealpha($square, 0, 0, 0, 127);
        imagefill($square, 0, 0, $transparent);
        imagealphablending($square, true);

        imagecopy($square, $source, 0, 0, $srcX, $srcY, $side, $side);
        return $square;
    }

    /** @param \GdImage $squared */
    private function resizeAndRound($squared, int $size): string
    {
        $resized = imagecreatetruecolor($size, $size);
        imagesavealpha($resized, true);
        $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
        imagefill($resized, 0, 0, $transparent);

        imagecopyresampled($resized, $squared, 0, 0, 0, 0, $size, $size, imagesx($squared), imagesy($squared));

        $rounded = $this->applyRoundedMask($resized, (int) round($size * 0.22));
        imagedestroy($resized);

        return $this->pngBytes($rounded);
    }

    /** @param \GdImage $im @return \GdImage */
    private function applyRoundedMask($im, int $radius)
    {
        $size = imagesx($im);
        $masked = imagecreatetruecolor($size, $size);
        imagesavealpha($masked, true);
        $transparent = imagecolorallocatealpha($masked, 0, 0, 0, 127);
        imagefill($masked, 0, 0, $transparent);

        $opaque = imagecolorallocate($masked, 0, 0, 0);
        $this->drawRoundedRect($masked, 0, 0, $size - 1, $size - 1, $radius, $opaque);

        // Use the rounded shape drawn above as an alpha mask over the image.
        imagealphablending($masked, false);
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                $maskAlpha = (imagecolorat($masked, $x, $y) >> 24) & 0x7F;
                if ($maskAlpha === 127) {
                    // Outside the rounded shape — keep fully transparent.
                    continue;
                }
                $srcColor = imagecolorat($im, $x, $y);
                imagesetpixel($masked, $x, $y, $srcColor);
            }
        }

        return $masked;
    }

    // ── Shared primitives ────────────────────────────────────────────────

    /** @param \GdImage $im */
    private function drawRoundedRect($im, int $x0, int $y0, int $x1, int $y1, int $r, int $color): void
    {
        imagefilledrectangle($im, $x0 + $r, $y0, $x1 - $r, $y1, $color);
        imagefilledrectangle($im, $x0, $y0 + $r, $x1, $y1 - $r, $color);
        imagefilledellipse($im, $x0 + $r, $y0 + $r, $r * 2, $r * 2, $color);
        imagefilledellipse($im, $x1 - $r, $y0 + $r, $r * 2, $r * 2, $color);
        imagefilledellipse($im, $x0 + $r, $y1 - $r, $r * 2, $r * 2, $color);
        imagefilledellipse($im, $x1 - $r, $y1 - $r, $r * 2, $r * 2, $color);
    }

    /** @param \GdImage $im */
    private function pngBytes($im): string
    {
        ob_start();
        imagepng($im);
        $bytes = ob_get_clean() ?: '';
        imagedestroy($im);
        return $bytes;
    }

    private function buildImageSvg(string $png512Bytes): string
    {
        $b64 = base64_encode($png512Bytes);
        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">
  <image href="data:image/png;base64,{$b64}" width="512" height="512"/>
</svg>
SVG;
    }

    private function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            $hex = '0a66c2';
        }
        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    /**
     * Minimal ICO encoder (modern PNG-embedded format, supported since
     * Windows Vista and every current browser). Packs 16/32/48px PNGs into
     * a single .ico container — GD can't write ICO natively.
     *
     * @param string[] $pngList  raw PNG bytes, one per size
     * @param int[] $sizes       matching sizes in px (max 255 — ICO format uses a single byte)
     */
    private function buildIco(array $pngList, array $sizes): string
    {
        $count = count($pngList);
        $header = pack('vvv', 0, 1, $count); // reserved, type=1 (icon), image count

        $dirEntries = '';
        $imageData = '';
        $offset = 6 + (16 * $count); // header + one 16-byte directory entry per image

        foreach ($pngList as $i => $png) {
            $size = $sizes[$i];
            $byteSize = strlen($png);

            $dirEntries .= pack(
                'CCCCvvVV',
                $size >= 256 ? 0 : $size, // width (0 = 256px)
                $size >= 256 ? 0 : $size, // height
                0,      // color palette
                0,      // reserved
                1,      // color planes
                32,     // bits per pixel
                $byteSize,
                $offset
            );

            $imageData .= $png;
            $offset += $byteSize;
        }

        return $header . $dirEntries . $imageData;
    }
}
