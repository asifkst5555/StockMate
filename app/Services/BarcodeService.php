<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\Storage;
use Picqer\Barcode\BarcodeGeneratorPNG;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Authoritative Barcode Validation, Deterministic PNG Generation & Storage Service (Phase B).
 *
 * Supports:
 * - CODE128 (Product::CODE128 = 1)
 * - CODE39  (Product::CODE39  = 2)
 * - EAN8    (Product::EAN8    = 3)
 * - UPC-A   (Product::UPC     = 4)
 * - EAN13   (Product::EAN13   = 5)
 */
class BarcodeService
{
    public const SUPPORTED_SYMBOLS = [
        Product::CODE128 => 'CODE128',
        Product::CODE39  => 'CODE39',
        Product::EAN8    => 'EAN8',
        Product::UPC     => 'UPC-A',
        Product::EAN13   => 'EAN13',
    ];

    public function storageDisk(): string
    {
        return (string) config('app.media_disc', 'public');
    }

    /**
     * Resolve a barcode symbol integer from its human-readable name (e.g. CSV import).
     */
    public function symbolFromName(string $name): ?int
    {
        $normalized = strtoupper(str_replace(['-', ' ', '_'], '', trim($name)));

        return match ($normalized) {
            'CODE128' => Product::CODE128,
            'CODE39'  => Product::CODE39,
            'EAN8'    => Product::EAN8,
            'UPC', 'UPCA' => Product::UPC,
            'EAN13'   => Product::EAN13,
            default   => null,
        };
    }

    /**
     * Validate barcode value & symbology, including digit lengths and EAN/UPC check digits.
     *
     * @return array{valid: bool, normalized: string, error: ?string}
     */
    public function validate(string $rawValue, int $symbol): array
    {
        $value = trim($rawValue);

        if ($value === '') {
            return ['valid' => false, 'normalized' => '', 'error' => 'Barcode value cannot be empty.'];
        }

        if (! array_key_exists($symbol, self::SUPPORTED_SYMBOLS)) {
            return ['valid' => false, 'normalized' => '', 'error' => 'Unsupported barcode format symbol.'];
        }

        switch ($symbol) {
            case Product::CODE128:
                if (! preg_match('/^[\x20-\x7E]{1,80}$/', $value)) {
                    return ['valid' => false, 'normalized' => '', 'error' => 'CODE128 must be 1 to 80 printable ASCII characters.'];
                }

                return ['valid' => true, 'normalized' => $value, 'error' => null];

            case Product::CODE39:
                $upper = strtoupper($value);
                if (! preg_match('/^[A-Z0-9\-\.\ \$\/\+\%]{1,43}$/', $upper)) {
                    return ['valid' => false, 'normalized' => '', 'error' => 'CODE39 allows only A-Z, 0-9, space, and - . $ / + % (max 43 chars).'];
                }

                return ['valid' => true, 'normalized' => $upper, 'error' => null];

            case Product::EAN8:
                if (! preg_match('/^\d{7,8}$/', $value)) {
                    return ['valid' => false, 'normalized' => '', 'error' => 'EAN8 must be exactly 7 digits (without check digit) or 8 digits (with valid check digit).'];
                }
                $expectedCheck = $this->computeEanUpcCheckDigit(substr($value, 0, 7));
                if (strlen($value) === 8 && (int) $value[7] !== $expectedCheck) {
                    return ['valid' => false, 'normalized' => '', 'error' => "Invalid EAN8 check digit (expected {$expectedCheck})."];
                }

                return ['valid' => true, 'normalized' => substr($value, 0, 7) . $expectedCheck, 'error' => null];

            case Product::UPC:
                if (! preg_match('/^\d{11,12}$/', $value)) {
                    return ['valid' => false, 'normalized' => '', 'error' => 'UPC-A must be exactly 11 digits (without check digit) or 12 digits (with valid check digit).'];
                }
                $expectedCheck = $this->computeEanUpcCheckDigit(substr($value, 0, 11));
                if (strlen($value) === 12 && (int) $value[11] !== $expectedCheck) {
                    return ['valid' => false, 'normalized' => '', 'error' => "Invalid UPC-A check digit (expected {$expectedCheck})."];
                }

                return ['valid' => true, 'normalized' => substr($value, 0, 11) . $expectedCheck, 'error' => null];

            case Product::EAN13:
                if (! preg_match('/^\d{12,13}$/', $value)) {
                    return ['valid' => false, 'normalized' => '', 'error' => 'EAN13 must be exactly 12 digits (without check digit) or 13 digits (with valid check digit).'];
                }
                $expectedCheck = $this->computeEanUpcCheckDigit(substr($value, 0, 12));
                if (strlen($value) === 13 && (int) $value[12] !== $expectedCheck) {
                    return ['valid' => false, 'normalized' => '', 'error' => "Invalid EAN13 check digit (expected {$expectedCheck})."];
                }

                return ['valid' => true, 'normalized' => substr($value, 0, 12) . $expectedCheck, 'error' => null];
        }

        return ['valid' => false, 'normalized' => '', 'error' => 'Unsupported barcode format symbol.'];
    }

    /**
     * Standard GS1 Modulo-10 check digit calculation for EAN-8 (7), UPC-A (11), and EAN-13 (12).
     */
    public function computeEanUpcCheckDigit(string $dataDigits): int
    {
        $sum = 0;
        $len = strlen($dataDigits);
        for ($i = 0; $i < $len; $i++) {
            $digit = (int) $dataDigits[$len - 1 - $i];
            $weight = ($i % 2 === 0) ? 3 : 1;
            $sum += $digit * $weight;
        }

        return (10 - ($sum % 10)) % 10;
    }

    /**
     * Generate deterministic PNG binary bytes for a validated barcode value & symbol.
     */
    public function generatePngBinary(string $rawValue, int $symbol, int $widthFactor = 4, int $height = 70): string
    {
        $check = $this->validate($rawValue, $symbol);
        if (! $check['valid']) {
            throw new UnprocessableEntityHttpException($check['error']);
        }

        $generator = new BarcodeGeneratorPNG();
        $picqerType = match ($symbol) {
            Product::CODE128 => $generator::TYPE_CODE_128,
            Product::CODE39  => $generator::TYPE_CODE_39,
            Product::EAN8    => $generator::TYPE_EAN_8,
            Product::UPC     => $generator::TYPE_UPC_A,
            Product::EAN13   => $generator::TYPE_EAN_13,
            default          => throw new UnprocessableEntityHttpException('Unsupported barcode format symbol.'),
        };

        try {
            return $generator->getBarcode($check['normalized'], $picqerType, $widthFactor, $height);
        } catch (\Throwable $e) {
            throw new UnprocessableEntityHttpException('Barcode generation failed: ' . $e->getMessage());
        }
    }

    public function generateBase64DataUri(string $rawValue, int $symbol = Product::CODE128): string
    {
        $binary = $this->generatePngBinary($rawValue, $symbol);

        return 'data:image/png;base64,' . base64_encode($binary);
    }

    /**
     * Safe deterministic relative storage path for a product's barcode PNG.
     */
    public function relativePathForReference(string $referenceCode): string
    {
        $safeRef = preg_replace('/[^A-Za-z0-9_\-]/', '_', $referenceCode);

        return 'product_barcode/barcode-' . $safeRef . '.png';
    }

    public function relativePathForProduct(Product $product): string
    {
        return $this->relativePathForReference('PR_' . $product->id);
    }

    /**
     * Store a barcode PNG on the authoritative configured media disk.
     */
    public function storeBarcodePng(string $rawValue, int $symbol, string $referenceCode): string
    {
        $path = $this->relativePathForReference($referenceCode);
        $binary = $this->generatePngBinary($rawValue, $symbol);
        Storage::disk($this->storageDisk())->put($path, $binary);

        return $path;
    }

    /**
     * Ensure a product's barcode PNG exists on disk without altering product SKU, price, or stock.
     *
     * @return array{status: string, path: ?string, error: ?string}
     */
    public function ensureProductBarcodePng(Product $product, bool $forceRegenerate = false, bool $dryRun = false): array
    {
        $path = $this->relativePathForProduct($product);
        $disk = Storage::disk($this->storageDisk());

        if (! $forceRegenerate && $disk->exists($path) && $disk->size($path) > 0) {
            return ['status' => 'skipped_existing', 'path' => $path, 'error' => null];
        }

        $barcodeValue = trim((string) ($product->code ?: $product->product_code ?: ''));
        $symbol = (int) ($product->barcode_symbol ?: Product::CODE128);

        $check = $this->validate($barcodeValue, $symbol);
        if (! $check['valid']) {
            return ['status' => 'failed_validation', 'path' => null, 'error' => $check['error']];
        }

        if ($dryRun) {
            return ['status' => 'would_generate', 'path' => $path, 'error' => null];
        }

        $this->storeBarcodePng($check['normalized'], $symbol, 'PR_' . $product->id);

        return ['status' => 'generated', 'path' => $path, 'error' => null];
    }

    /**
     * Resolve the product's barcode URL from the unified media disk with cache-busting timestamp.
     */
    public function resolveProductBarcodeUrl(Product $product): string
    {
        $path = $this->relativePathForProduct($product);
        $disk = Storage::disk($this->storageDisk());

        if (! $disk->exists($path)) {
            return '';
        }

        $url = $disk->url($path);
        $version = $product->updated_at ? $product->updated_at->timestamp : time();

        return $url . (str_contains($url, '?') ? '&' : '?') . 'v=' . $version;
    }

    public function deleteProductBarcodePng(Product $product): void
    {
        $path = $this->relativePathForProduct($product);
        $disk = Storage::disk($this->storageDisk());
        if ($disk->exists($path)) {
            $disk->delete($path);
        }
    }
}
