<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\stockflow\src\Services;

use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Picqer\Barcode\BarcodeGenerator;
use Picqer\Barcode\BarcodeGeneratorSVG;
use Shuchkin\SimpleXLSXGen;
use RuntimeException;

final class StockflowDocumentService
{
    public function barcodeSvg(string $value): string
    {
        if ($value === '' || preg_match('/^[\x20-\x7E]+$/D', $value) !== 1) {
            throw new \InvalidArgumentException('Barcode values must contain printable ASCII characters.');
        }

        return (new BarcodeGeneratorSVG())->getBarcode(
            $value,
            BarcodeGenerator::TYPE_CODE_128,
            2,
            50
        );
    }

    public function qrCodeSvg(string $value): string
    {
        $svg = (new QRCode([
            'outputInterface' => QRMarkupSVG::class,
            'outputBase64' => false,
            'svgAddXmlHeader' => false,
        ]))->render($value);
        if (!is_string($svg) || $svg === '') {
            throw new RuntimeException('The QR code generator returned no image data.');
        }
        return $svg;
    }

    public function variantQrPayload(array $variant): string
    {
        return json_encode([
            'schema' => 'stockflow.variant',
            'version' => 1,
            'product_id' => (int) $variant['product_id'],
            'variant_id' => (int) $variant['id'],
            'sku' => (string) $variant['sku'],
            'barcode' => ($variant['barcode'] ?? '') !== '' ? (string) $variant['barcode'] : null,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public function variantBarcodePayload(array $variant): string
    {
        return 'SF1:' . (int) $variant['id'] . ':' . (string) $variant['sku'];
    }

    public function invoicePdf(string $html): string
    {
        $pdf = new Mpdf(['tempDir' => sys_get_temp_dir()]);
        $pdf->WriteHTML($html);
        $content = $pdf->Output('', Destination::STRING_RETURN);
        if (!is_string($content) || $content === '') {
            throw new RuntimeException('The invoice PDF generator returned no document data.');
        }
        return $content;
    }

    public function spreadsheet(array $rows, string $sheetName): string
    {
        $content = (string) SimpleXLSXGen::fromArray($rows, $sheetName);
        if ($content === '') {
            throw new RuntimeException('The spreadsheet generator returned no workbook data.');
        }
        return $content;
    }
}
