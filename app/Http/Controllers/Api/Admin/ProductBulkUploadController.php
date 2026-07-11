<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductColorVariant;
use App\Models\ProductColorImage;
use App\Models\ProductSizeStock;
use App\Models\Category;
use App\Models\Color;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Exception;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * BULK PRODUCT UPLOAD (CSV)
 * ═══════════════════════════════════════════════════════════════════════
 * CSV is "flat": one row = one size, belonging to one color, belonging to
 * one product. Rows are linked into a product via `product_group_id` and
 * into a color via `color_name` (repeated for every size row of that
 * color). Images are supplied as direct URLs (pipe-separated for the
 * gallery) — no file upload/Media step required.
 *
 * Suggested routes (routes/api.php):
 *   Route::post('/admin/products/bulk-upload', [ProductBulkUploadController::class, 'import']);
 *   Route::get('/admin/products/bulk-upload/sample', [ProductBulkUploadController::class, 'downloadSample']);
 * ═══════════════════════════════════════════════════════════════════════
 */
class ProductBulkUploadController extends Controller
{
    /**
     * Columns that MUST be present (non-empty) on every data row.
     */
    private const REQUIRED_COLUMNS = [
        'product_group_id', 'name', 'category_id', 'unit_price',
        'color_name', 'size', 'sku', 'price', 'stock',
    ];

    /**
     * Full expected header (order doesn't matter, extra columns are ignored).
     */
    private const EXPECTED_HEADER = [
        'product_group_id', 'name', 'brand', 'unit', 'weight', 'min_qty', 'tags',
        'estimate_shipping_days', 'description', 'category_id', 'unit_price',
        'discount', 'discount_type', 'discount_start_date', 'discount_end_date',
        'reward_points', 'is_flash_sale', 'flash_sale_title', 'flash_sale_discount',
        'flash_sale_discount_type', 'is_today_sale', 'is_published',
        'color_name', 'color_code', 'gallery_image_urls', 'thumbnail_image_url',
        'size', 'sku', 'price', 'stock',
    ];

    // ═══════════════════════════════════════════════════════════════
    // POST /admin/products/bulk-upload
    // Body: multipart/form-data, field name "file" (the .csv)
    // ═══════════════════════════════════════════════════════════════
    public function import(Request $request)
    {
        try {
            $request->validate([
                'file' => 'required|file|mimes:csv,txt|max:10240', // 10 MB
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Validation failed.',
                'errors'  => $e->errors(),
            ], 422);
        }

        $realPath = $request->file('file')->getRealPath();
        $handle = fopen($realPath, 'r');
        if (!$handle) {
            return response()->json(['status' => 'error', 'message' => 'Unable to read the uploaded file.'], 422);
        }

        // ── Read + normalize header row ─────────────────────────────
        $rawHeader = fgetcsv($handle);
        if ($rawHeader === false) {
            fclose($handle);
            return response()->json(['status' => 'error', 'message' => 'CSV file is empty.'], 422);
        }
        $header = array_map(fn($h) => strtolower(trim((string) $h)), $rawHeader);
        // strip BOM if present on first column
        if (isset($header[0])) {
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        }

        $missingColumns = array_diff(self::REQUIRED_COLUMNS, $header);
        if (!empty($missingColumns)) {
            fclose($handle);
            return response()->json([
                'status'  => 'error',
                'message' => 'CSV is missing required column(s): ' . implode(', ', $missingColumns),
            ], 422);
        }

        // ── Read data rows ───────────────────────────────────────────
        $rows = [];
        $lineNo = 1; // header = line 1
        while (($data = fgetcsv($handle)) !== false) {
            $lineNo++;
            $nonEmptyCells = array_filter($data, fn($v) => $v !== null && trim((string) $v) !== '');
            if (empty($nonEmptyCells)) {
                continue; // skip fully blank line
            }
            if (count($data) !== count($header)) {
                fclose($handle);
                return response()->json([
                    'status'  => 'error',
                    'message' => "Column count mismatch at row {$lineNo} (expected " . count($header) . ", got " . count($data) . ").",
                ], 422);
            }
            $rows[] = ['line' => $lineNo, 'data' => array_combine($header, $data)];
        }
        fclose($handle);

        if (empty($rows)) {
            return response()->json(['status' => 'error', 'message' => 'No data rows found in CSV.'], 422);
        }

        // ── Validation pass (no DB writes yet) ─────────────────────
        $errors = $this->validateRows($rows);
        if (!empty($errors)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Validation failed. No products were imported.',
                'errors'  => $errors,
            ], 422);
        }

        // ── Group rows: product_group_id -> [rows] ─────────────────
        $groups = [];
        foreach ($rows as $row) {
            $gid = trim((string) $row['data']['product_group_id']);
            $groups[$gid][] = $row;
        }

        $createdProductIds = [];

        DB::beginTransaction();
        try {
            foreach ($groups as $groupRows) {
                $createdProductIds[] = $this->createProductFromGroup($groupRows);
            }
            DB::commit();

            $products = Product::with([
                'category',
                'colorVariants.color',
                'colorVariants.galleryImages',
                'colorVariants.thumbnailImage',
                'colorVariants.sizeStocks',
            ])->whereIn('id', $createdProductIds)->get();

            return response()->json([
                'status'  => 'success',
                'message' => count($createdProductIds) . ' product(s) imported successfully.',
                'data'    => [
                    'created_product_ids' => $createdProductIds,
                    'products'            => $products,
                ],
            ], 201);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Bulk Product Import Error: ' . $e->getMessage());
            return response()->json([
                'status'  => 'error',
                'message' => 'Import failed, no products were saved: ' . $e->getMessage(),
            ], 500);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // GET /admin/products/bulk-upload/sample
    // Streams a ready-to-fill sample CSV.
    // ═══════════════════════════════════════════════════════════════
    public function downloadSample()
    {
        $columns = self::EXPECTED_HEADER;

        $rows = [
            [1, 'Classic Polo Shirt', 'BrandX', 'pcs', 0.3, 1, 'polo|shirt|cotton', 3,
                'Premium 100% cotton polo shirt with ribbed collar', 1, 999, 10, 'percent',
                '2026-07-01', '2026-07-31', 10, 0, '', '', '', 1, 1,
                'Red', '#FF0000',
                'https://example.com/img/polo-red-1.jpg|https://example.com/img/polo-red-2.jpg',
                'https://example.com/img/polo-red-thumb.jpg', 'S', 'POLO-RED-S', 999, 50],
            [1, 'Classic Polo Shirt', 'BrandX', 'pcs', 0.3, 1, 'polo|shirt|cotton', 3,
                'Premium 100% cotton polo shirt with ribbed collar', 1, 999, 10, 'percent',
                '2026-07-01', '2026-07-31', 10, 0, '', '', '', 1, 1,
                'Red', '#FF0000', '', '', 'M', 'POLO-RED-M', 999, 40],
            [1, 'Classic Polo Shirt', 'BrandX', 'pcs', 0.3, 1, 'polo|shirt|cotton', 3,
                'Premium 100% cotton polo shirt with ribbed collar', 1, 999, 10, 'percent',
                '2026-07-01', '2026-07-31', 10, 0, '', '', '', 1, 1,
                'Blue', '#0000FF',
                'https://example.com/img/polo-blue-1.jpg|https://example.com/img/polo-blue-2.jpg',
                'https://example.com/img/polo-blue-thumb.jpg', 'S', 'POLO-BLUE-S', 999, 50],
            [2, 'Slim Fit Jeans', 'BrandY', 'pcs', 0.6, 1, 'jeans|denim', 5,
                'Stretchable slim fit denim jeans', 2, 1499, 0, '', '', '', 15, 1,
                'Weekend Flash Deal', 20, 'percent', 0, 1,
                'Black', '#000000',
                'https://example.com/img/jeans-black-1.jpg',
                'https://example.com/img/jeans-black-thumb.jpg', '30', 'JEANS-BLK-30', 1499, 20],
        ];

        $headers = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="products_bulk_upload_sample.csv"',
        ];

        return response()->stream(function () use ($columns, $rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $columns);
            foreach ($rows as $r) {
                fputcsv($out, $r);
            }
            fclose($out);
        }, 200, $headers);
    }

    // ═══════════════════════════════════════════════════════════════
    // PRIVATE: Validate every row before touching the database
    // ═══════════════════════════════════════════════════════════════
    private function validateRows(array $rows): array
    {
        $errors = [];
        $seenSkusInFile = [];

        foreach ($rows as $row) {
            $d = $row['data'];
            $line = $row['line'];

            foreach (self::REQUIRED_COLUMNS as $field) {
                if (!isset($d[$field]) || trim((string) $d[$field]) === '') {
                    $errors[] = "Row {$line}: '{$field}' is required.";
                }
            }

            if (!empty($d['category_id'])) {
                if (!is_numeric($d['category_id'])) {
                    $errors[] = "Row {$line}: category_id must be numeric.";
                } elseif (!Category::where('id', $d['category_id'])->exists()) {
                    $errors[] = "Row {$line}: category_id {$d['category_id']} does not exist.";
                }
            }

            foreach (['unit_price' => 'unit_price', 'price' => 'price'] as $field => $label) {
                if (isset($d[$field]) && $d[$field] !== '' && !is_numeric($d[$field])) {
                    $errors[] = "Row {$line}: {$label} must be numeric.";
                }
            }

            if (isset($d['stock']) && $d['stock'] !== '' && !is_numeric($d['stock'])) {
                $errors[] = "Row {$line}: stock must be a whole number.";
            }

            if (!empty($d['discount_type']) && !in_array($d['discount_type'], ['flat', 'percent'], true)) {
                $errors[] = "Row {$line}: discount_type must be 'flat' or 'percent'.";
            }

            if (!empty($d['flash_sale_discount_type']) && !in_array($d['flash_sale_discount_type'], ['flat', 'percent'], true)) {
                $errors[] = "Row {$line}: flash_sale_discount_type must be 'flat' or 'percent'.";
            }

            if (!empty($d['sku'])) {
                $sku = trim($d['sku']);
                if (isset($seenSkusInFile[$sku])) {
                    $errors[] = "Row {$line}: duplicate SKU '{$sku}' also appears at row {$seenSkusInFile[$sku]}.";
                }
                $seenSkusInFile[$sku] = $line;

                if (ProductSizeStock::where('sku', $sku)->exists()) {
                    $errors[] = "Row {$line}: SKU '{$sku}' already exists in the database.";
                }
            }

            foreach (['discount_start_date', 'discount_end_date'] as $dateField) {
                if (!empty($d[$dateField]) && strtotime($d[$dateField]) === false) {
                    $errors[] = "Row {$line}: {$dateField} is not a valid date.";
                }
            }
        }

        return $errors;
    }

    // ═══════════════════════════════════════════════════════════════
    // PRIVATE: Build one Product (+ colors + images + sizes) from its
    // group of CSV rows. Returns the new product's id.
    // ═══════════════════════════════════════════════════════════════
    private function createProductFromGroup(array $groupRows): int
    {
        $first = $groupRows[0]['data'];

        $product = Product::create([
            'name'                     => trim($first['name']),
            'brand'                    => $this->nullableStr($first['brand'] ?? null),
            'unit'                     => $this->nullableStr($first['unit'] ?? null) ?? 'pcs',
            'weight'                   => $this->numOrDefault($first['weight'] ?? null, 0),
            'min_qty'                  => (int) ($first['min_qty'] ?? 1) ?: 1,
            'tags'                     => $this->nullableStr($first['tags'] ?? null),
            'estimate_shipping_days'   => ($first['estimate_shipping_days'] ?? '') !== ''
                                            ? (int) $first['estimate_shipping_days'] : null,
            'description'              => $this->nullableStr($first['description'] ?? null),
            'category_id'              => (int) $first['category_id'],
            'unit_price'               => (float) $first['unit_price'],
            'discount'                 => $this->numOrDefault($first['discount'] ?? null, 0),
            'discount_type'            => $this->nullableStr($first['discount_type'] ?? null),
            'discount_start_date'      => $this->nullableStr($first['discount_start_date'] ?? null),
            'discount_end_date'        => $this->nullableStr($first['discount_end_date'] ?? null),
            'reward_points'            => (int) ($first['reward_points'] ?? 0),
            'is_flash_sale'            => $this->boolVal($first['is_flash_sale'] ?? false),
            'flash_sale_title'         => $this->nullableStr($first['flash_sale_title'] ?? null),
            'flash_sale_discount'      => $this->numOrDefault($first['flash_sale_discount'] ?? null, 0),
            'flash_sale_discount_type' => $this->nullableStr($first['flash_sale_discount_type'] ?? null),
            'is_today_sale'            => $this->boolVal($first['is_today_sale'] ?? false),
            'is_published'             => array_key_exists('is_published', $first) && $first['is_published'] !== ''
                                            ? $this->boolVal($first['is_published']) : true,
        ]);

        // Group this product's rows by color (case-insensitive match on name)
        $colorGroups = [];
        foreach ($groupRows as $row) {
            $colorKey = strtolower(trim($row['data']['color_name']));
            $colorGroups[$colorKey][] = $row;
        }

        foreach ($colorGroups as $colorRows) {
            $firstColorRow = $colorRows[0]['data'];

            $color = Color::firstOrCreate(
                ['name' => trim($firstColorRow['color_name'])],
                ['code' => $this->nullableStr($firstColorRow['color_code'] ?? null) ?? '#000000']
            );

            $colorVariant = ProductColorVariant::create([
                'product_id' => $product->id,
                'color_id'   => $color->id,
            ]);

            // Use the first non-empty gallery/thumbnail found among this color's rows
            $galleryUrls = [];
            $thumbnailUrl = null;
            foreach ($colorRows as $cr) {
                $cd = $cr['data'];
                if (empty($galleryUrls) && !empty($cd['gallery_image_urls'])) {
                    $galleryUrls = array_values(array_filter(array_map('trim', explode('|', $cd['gallery_image_urls']))));
                }
                if (!$thumbnailUrl && !empty($cd['thumbnail_image_url'])) {
                    $thumbnailUrl = trim($cd['thumbnail_image_url']);
                }
            }

            foreach (array_slice($galleryUrls, 0, 5) as $sortOrder => $url) {
                ProductColorImage::create([
                    'product_color_variant_id' => $colorVariant->id,
                    'image_url'                => $url,
                    'type'                     => 'gallery',
                    'sort_order'               => $sortOrder,
                ]);
            }

            if ($thumbnailUrl) {
                ProductColorImage::create([
                    'product_color_variant_id' => $colorVariant->id,
                    'image_url'                => $thumbnailUrl,
                    'type'                     => 'thumbnail',
                    'sort_order'               => 0,
                ]);
            }

            foreach ($colorRows as $sr) {
                $sd = $sr['data'];
                ProductSizeStock::create([
                    'product_color_variant_id' => $colorVariant->id,
                    'size'                     => trim($sd['size']),
                    'sku'                      => trim($sd['sku']),
                    'price'                    => (float) $sd['price'],
                    'stock'                    => (int) $sd['stock'],
                ]);
            }
        }

        return $product->id;
    }

    // ═══════════════════════════════════════════════════════════════
    // Small parsing helpers
    // ═══════════════════════════════════════════════════════════════
    private function boolVal($v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        $v = strtolower(trim((string) $v));
        return in_array($v, ['1', 'true', 'yes', 'y'], true);
    }

    private function numOrDefault($v, $default)
    {
        if ($v === null || $v === '') {
            return $default;
        }
        return is_numeric($v) ? (float) $v : $default;
    }

    private function nullableStr($v): ?string
    {
        $v = trim((string) $v);
        return $v === '' ? null : $v;
    }
}