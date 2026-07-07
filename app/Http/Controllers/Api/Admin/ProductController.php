<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductColorVariant;
use App\Models\ProductColorImage;
use App\Models\ProductSizeStock;
use App\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Exception;

class ProductController extends Controller
{
    // ═══════════════════════════════════════════════════════════════
    // PRIVATE HELPER: Load full product with all relations
    // ═══════════════════════════════════════════════════════════════
    private function loadProduct(int $id): Product
    {
        return Product::with([
            'category',
            'colorVariants.color',
            'colorVariants.galleryImages',
            'colorVariants.thumbnailImage',
            'colorVariants.sizeStocks',
        ])->findOrFail($id);
    }

    // ═══════════════════════════════════════════════════════════════
    // GET /admin/products
    // Query params: ?is_published=1 ?is_today_sale=1 ?is_flash_sale=1 ?search=polo
    // ═══════════════════════════════════════════════════════════════
    public function index(Request $request)
    {
        try {
            $query = Product::with([
                'category',
                'colorVariants.color',
                'colorVariants.galleryImages',
                'colorVariants.thumbnailImage',
                'colorVariants.sizeStocks',
            ]);

            if ($request->filled('is_published')) {
                $query->where('is_published', filter_var($request->is_published, FILTER_VALIDATE_BOOLEAN));
            }
            if ($request->filled('is_today_sale')) {
                $query->where('is_today_sale', filter_var($request->is_today_sale, FILTER_VALIDATE_BOOLEAN));
            }
            if ($request->filled('is_flash_sale')) {
                $query->where('is_flash_sale', filter_var($request->is_flash_sale, FILTER_VALIDATE_BOOLEAN));
            }
            if ($request->filled('search')) {
                $query->where('name', 'like', '%' . $request->search . '%');
            }

            $products = $query->latest()->paginate(15);

            return response()->json(['status' => 'success', 'data' => $products], 200);

        } catch (Exception $e) {
            Log::error('Product Index Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to retrieve products.'], 500);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // GET /admin/products/{id}
    // ═══════════════════════════════════════════════════════════════
    public function show($id)
    {
        try {
            return response()->json(['status' => 'success', 'data' => $this->loadProduct($id)], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'Product not found.'], 404);
        } catch (Exception $e) {
            Log::error('Product Show Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to retrieve product.'], 500);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // GET /admin/products/category/{categoryId}
    // ═══════════════════════════════════════════════════════════════
    public function getByCategory($categoryId)
    {
        try {
            $products = Product::with([
                'category',
                'colorVariants.color',
                'colorVariants.galleryImages',
                'colorVariants.thumbnailImage',
                'colorVariants.sizeStocks',
            ])
            ->where('category_id', $categoryId)
            ->where('is_published', true)
            ->latest()
            ->paginate(15);

            return response()->json(['status' => 'success', 'data' => $products], 200);

        } catch (Exception $e) {
            Log::error('Product By Category Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to retrieve products.'], 500);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // GET /admin/products/{id}/similar
    // ═══════════════════════════════════════════════════════════════
    public function similar($id)
    {
        try {
            $product = Product::findOrFail($id);

            $similar = Product::with([
                'colorVariants.color',
                'colorVariants.thumbnailImage',
                'colorVariants.sizeStocks',
            ])
            ->where('category_id', $product->category_id)
            ->where('id', '!=', $id)
            ->where('is_published', true)
            ->latest()
            ->limit(10)
            ->get();

            return response()->json(['status' => 'success', 'data' => $similar], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'Product not found.'], 404);
        } catch (Exception $e) {
            Log::error('Similar Products Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to retrieve similar products.'], 500);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // POST /admin/products  — Create product
    //
    // Expected JSON:
    // {
    //   "name": "Polo Shirt",
    //   "brand": "Flybirds",
    //   "unit": "Pc",
    //   "weight": 0.5,
    //   "min_qty": 1,
    //   "tags": "polo,shirt",
    //   "estimate_shipping_days": 3,
    //   "description": "...",
    //   "category_id": 1,
    //   "unit_price": 999.00,
    //   "discount": 100,
    //   "discount_type": "flat",
    //   "discount_start_date": "2025-01-01",
    //   "discount_end_date": "2025-12-31",
    //   "reward_points": 10,
    //   "is_flash_sale": false,
    //   "flash_sale_title": null,
    //   "flash_sale_discount": 0,
    //   "flash_sale_discount_type": null,
    //   "is_today_sale": false,
    //   "is_published": true,
    //   "colors": [
    //     {
    //       "color_id": 1,
    //       "gallery_image_ids": [1, 2, 3],   ← media IDs (600x600)
    //       "thumbnail_image_id": 4,           ← media ID  (300x300)
    //       "sizes": [
    //         { "size": "S", "sku": "POLO-S-RED", "price": 999.00, "stock": 50 },
    //         { "size": "M", "sku": "POLO-M-RED", "price": 999.00, "stock": 80 },
    //         { "size": "L", "sku": "POLO-L-RED", "price": 1099.00,"stock": 30 }
    //       ]
    //     },
    //     {
    //       "color_id": 2,
    //       "gallery_image_ids": [5, 6, 7],
    //       "thumbnail_image_id": 8,
    //       "sizes": [
    //         { "size": "S", "sku": "POLO-S-BLUE", "price": 999.00, "stock": 20 },
    //         { "size": "M", "sku": "POLO-M-BLUE", "price": 999.00, "stock": 40 }
    //       ]
    //     }
    //   ]
    // }
    // ═══════════════════════════════════════════════════════════════
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                // Product info
                'name'                     => 'required|string|max:255',
                'brand'                    => 'nullable|string|max:255',
                'unit'                     => 'required|string|max:50',
                'weight'                   => 'required|numeric|min:0',
                'min_qty'                  => 'required|integer|min:1',
                'tags'                     => 'nullable|string',
                'estimate_shipping_days'   => 'nullable|integer|min:0',
                'description'              => 'nullable|string',
                'category_id'              => 'required|exists:categories,id',

                // Pricing
                'unit_price'               => 'required|numeric|min:0',
                'discount'                 => 'nullable|numeric|min:0',
                'discount_type'            => 'nullable|in:flat,percent',
                'discount_start_date'      => 'nullable|date',
                'discount_end_date'        => 'nullable|date|after_or_equal:discount_start_date',
                'reward_points'            => 'nullable|integer|min:0',

                // Flash sale
                'is_flash_sale'            => 'boolean',
                'flash_sale_title'         => 'nullable|string|max:255',
                'flash_sale_discount'      => 'nullable|numeric|min:0',
                'flash_sale_discount_type' => 'nullable|in:flat,percent',

                // Status
                'is_today_sale'            => 'boolean',
                'is_published'             => 'boolean',

                // Colors (required, at least 1)
                'colors'                   => 'required|array|min:1',
                'colors.*.color_id'        => 'required|exists:colors,id',

                // Per-color images
                'colors.*.gallery_image_ids' => 'nullable|array|max:5',
                'colors.*.gallery_image_ids.*' => 'integer|exists:media,id',
                'colors.*.thumbnail_image_id'  => 'nullable|integer|exists:media,id',

                // Per-color sizes
                'colors.*.sizes'               => 'required|array|min:1',
                'colors.*.sizes.*.size'        => 'required|string|max:50',
                'colors.*.sizes.*.sku'         => 'required|string|unique:product_size_stocks,sku',
                'colors.*.sizes.*.price'       => 'required|numeric|min:0',
                'colors.*.sizes.*.stock'       => 'required|integer|min:0',
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Validation failed.',
                'errors'  => $e->errors(),
            ], 422);
        }

        DB::beginTransaction();
        try {
            // 1. Create the product
            $product = Product::create([
                'name'                     => $validated['name'],
                'brand'                    => $validated['brand'] ?? null,
                'unit'                     => $validated['unit'],
                'weight'                   => $validated['weight'],
                'min_qty'                  => $validated['min_qty'],
                'tags'                     => $validated['tags'] ?? null,
                'estimate_shipping_days'   => $validated['estimate_shipping_days'] ?? null,
                'description'              => $validated['description'] ?? null,
                'category_id'              => $validated['category_id'],
                'unit_price'               => $validated['unit_price'],
                'discount'                 => $validated['discount'] ?? 0,
                'discount_type'            => $validated['discount_type'] ?? null,
                'discount_start_date'      => $validated['discount_start_date'] ?? null,
                'discount_end_date'        => $validated['discount_end_date'] ?? null,
                'reward_points'            => $validated['reward_points'] ?? 0,
                'is_flash_sale'            => $validated['is_flash_sale'] ?? false,
                'flash_sale_title'         => $validated['flash_sale_title'] ?? null,
                'flash_sale_discount'      => $validated['flash_sale_discount'] ?? 0,
                'flash_sale_discount_type' => $validated['flash_sale_discount_type'] ?? null,
                'is_today_sale'            => $validated['is_today_sale'] ?? false,
                'is_published'             => $validated['is_published'] ?? true,
            ]);

            // 2. Loop each color
            foreach ($validated['colors'] as $colorData) {

                // 2a. Create color variant row
                $colorVariant = ProductColorVariant::create([
                    'product_id' => $product->id,
                    'color_id'   => $colorData['color_id'],
                ]);

                // 2b. Attach gallery images for this color
                if (!empty($colorData['gallery_image_ids'])) {
                    foreach ($colorData['gallery_image_ids'] as $sortOrder => $mediaId) {
                        $media = Media::find($mediaId);
                        if ($media) {
                            ProductColorImage::create([
                                'product_color_variant_id' => $colorVariant->id,
                                'image_url'                => $media->file_url,
                                'type'                     => 'gallery',
                                'sort_order'               => $sortOrder,
                            ]);
                        }
                    }
                }

                // 2c. Attach thumbnail for this color
                if (!empty($colorData['thumbnail_image_id'])) {
                    $media = Media::find($colorData['thumbnail_image_id']);
                    if ($media) {
                        ProductColorImage::create([
                            'product_color_variant_id' => $colorVariant->id,
                            'image_url'                => $media->file_url,
                            'type'                     => 'thumbnail',
                            'sort_order'               => 0,
                        ]);
                    }
                }

                // 2d. Create sizes with stock for this color
                foreach ($colorData['sizes'] as $sizeData) {
                    ProductSizeStock::create([
                        'product_color_variant_id' => $colorVariant->id,
                        'size'                     => $sizeData['size'],
                        'sku'                      => $sizeData['sku'],
                        'price'                    => $sizeData['price'],
                        'stock'                    => $sizeData['stock'],
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'status'  => 'success',
                'message' => 'Product created successfully.',
                'data'    => $this->loadProduct($product->id),
            ], 201);

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Product Store Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to create product.'], 500);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // POST /admin/products/{id}  — Update product
    //
    // Same structure as store but fields are optional (sometimes).
    // For colors: pass color_variant_id to update existing,
    //             omit color_variant_id to add a new color.
    // ═══════════════════════════════════════════════════════════════
    public function update(Request $request, $id)
    {
        try {
            $product = Product::findOrFail($id);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'Product not found.'], 404);
        }

        try {
            $validated = $request->validate([
                'name'                     => 'sometimes|string|max:255',
                'brand'                    => 'nullable|string|max:255',
                'unit'                     => 'sometimes|string|max:50',
                'weight'                   => 'sometimes|numeric|min:0',
                'min_qty'                  => 'sometimes|integer|min:1',
                'tags'                     => 'nullable|string',
                'estimate_shipping_days'   => 'nullable|integer|min:0',
                'description'              => 'nullable|string',
                'category_id'             => 'sometimes|exists:categories,id',
                'unit_price'               => 'sometimes|numeric|min:0',
                'discount'                 => 'nullable|numeric|min:0',
                'discount_type'            => 'nullable|in:flat,percent',
                'discount_start_date'      => 'nullable|date',
                'discount_end_date'        => 'nullable|date|after_or_equal:discount_start_date',
                'reward_points'            => 'nullable|integer|min:0',
                'is_flash_sale'            => 'sometimes|boolean',
                'flash_sale_title'         => 'nullable|string|max:255',
                'flash_sale_discount'      => 'nullable|numeric|min:0',
                'flash_sale_discount_type' => 'nullable|in:flat,percent',
                'is_today_sale'            => 'sometimes|boolean',
                'is_published'             => 'sometimes|boolean',

                // Colors (optional on update)
                'colors'                       => 'sometimes|array|min:1',
                'colors.*.color_variant_id'    => 'nullable|exists:product_color_variants,id', // existing variant ID
                'colors.*.color_id'            => 'required_with:colors|exists:colors,id',

                'colors.*.gallery_image_ids' => 'nullable|array|max:5',
                'colors.*.gallery_image_ids.*' => 'integer|exists:media,id',
                'colors.*.thumbnail_image_id'  => 'nullable|integer|exists:media,id',

                'colors.*.sizes'               => 'sometimes|array|min:1',
                'colors.*.sizes.*.size_stock_id' => 'nullable|exists:product_size_stocks,id',
                'colors.*.sizes.*.size'        => 'required_with:colors.*.sizes|string|max:50',
                'colors.*.sizes.*.sku'         => 'required_with:colors.*.sizes|string',
                'colors.*.sizes.*.price'       => 'required_with:colors.*.sizes|numeric|min:0',
                'colors.*.sizes.*.stock'       => 'required_with:colors.*.sizes|integer|min:0',
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Validation failed.',
                'errors'  => $e->errors(),
            ], 422);
        }

        DB::beginTransaction();
        try {
            // 1. Update product core fields
            $product->update(array_filter($validated, fn($k) => !in_array($k, ['colors']), ARRAY_FILTER_USE_KEY));

            // 2. Update colors if provided
            if (!empty($validated['colors'])) {
                foreach ($validated['colors'] as $colorData) {

                    if (!empty($colorData['color_variant_id'])) {
                        // ── Update existing color variant ──
                        $colorVariant = ProductColorVariant::where('id', $colorData['color_variant_id'])
                            ->where('product_id', $product->id)
                            ->firstOrFail();

                        $colorVariant->update(['color_id' => $colorData['color_id']]);

                        // Replace gallery images if provided
                        if (array_key_exists('gallery_image_ids', $colorData)) {
                            $colorVariant->galleryImages()->delete();
                            foreach (($colorData['gallery_image_ids'] ?? []) as $sortOrder => $mediaId) {
                                $media = Media::find($mediaId);
                                if ($media) {
                                    ProductColorImage::create([
                                        'product_color_variant_id' => $colorVariant->id,
                                        'image_url'  => $media->file_url,
                                        'type'       => 'gallery',
                                        'sort_order' => $sortOrder,
                                    ]);
                                }
                            }
                        }

                        // Replace thumbnail if provided
                        if (array_key_exists('thumbnail_image_id', $colorData)) {
                            $colorVariant->thumbnailImage()->delete();
                            if (!empty($colorData['thumbnail_image_id'])) {
                                $media = Media::find($colorData['thumbnail_image_id']);
                                if ($media) {
                                    ProductColorImage::create([
                                        'product_color_variant_id' => $colorVariant->id,
                                        'image_url'  => $media->file_url,
                                        'type'       => 'thumbnail',
                                        'sort_order' => 0,
                                    ]);
                                }
                            }
                        }

                        // Update sizes
                        if (!empty($colorData['sizes'])) {
                            foreach ($colorData['sizes'] as $sizeData) {
                                if (!empty($sizeData['size_stock_id'])) {
                                    // Update existing size
                                    $sizeStock = ProductSizeStock::where('id', $sizeData['size_stock_id'])
                                        ->where('product_color_variant_id', $colorVariant->id)
                                        ->firstOrFail();

                                    // Check SKU uniqueness (ignore self)
                                    $skuTaken = ProductSizeStock::where('sku', $sizeData['sku'])
                                        ->where('id', '!=', $sizeStock->id)
                                        ->exists();
                                    if ($skuTaken) {
                                        throw new Exception("SKU '{$sizeData['sku']}' already exists.");
                                    }

                                    $sizeStock->update([
                                        'size'  => $sizeData['size'],
                                        'sku'   => $sizeData['sku'],
                                        'price' => $sizeData['price'],
                                        'stock' => $sizeData['stock'],
                                    ]);
                                } else {
                                    // Add new size to this color
                                    $skuTaken = ProductSizeStock::where('sku', $sizeData['sku'])->exists();
                                    if ($skuTaken) {
                                        throw new Exception("SKU '{$sizeData['sku']}' already exists.");
                                    }

                                    ProductSizeStock::create([
                                        'product_color_variant_id' => $colorVariant->id,
                                        'size'  => $sizeData['size'],
                                        'sku'   => $sizeData['sku'],
                                        'price' => $sizeData['price'],
                                        'stock' => $sizeData['stock'],
                                    ]);
                                }
                            }
                        }

                    } else {
                        // ── Add brand new color variant ──
                        $colorVariant = ProductColorVariant::create([
                            'product_id' => $product->id,
                            'color_id'   => $colorData['color_id'],
                        ]);

                        foreach (($colorData['gallery_image_ids'] ?? []) as $sortOrder => $mediaId) {
                            $media = Media::find($mediaId);
                            if ($media) {
                                ProductColorImage::create([
                                    'product_color_variant_id' => $colorVariant->id,
                                    'image_url'  => $media->file_url,
                                    'type'       => 'gallery',
                                    'sort_order' => $sortOrder,
                                ]);
                            }
                        }

                        if (!empty($colorData['thumbnail_image_id'])) {
                            $media = Media::find($colorData['thumbnail_image_id']);
                            if ($media) {
                                ProductColorImage::create([
                                    'product_color_variant_id' => $colorVariant->id,
                                    'image_url'  => $media->file_url,
                                    'type'       => 'thumbnail',
                                    'sort_order' => 0,
                                ]);
                            }
                        }

                        foreach (($colorData['sizes'] ?? []) as $sizeData) {
                            $skuTaken = ProductSizeStock::where('sku', $sizeData['sku'])->exists();
                            if ($skuTaken) {
                                throw new Exception("SKU '{$sizeData['sku']}' already exists.");
                            }
                            ProductSizeStock::create([
                                'product_color_variant_id' => $colorVariant->id,
                                'size'  => $sizeData['size'],
                                'sku'   => $sizeData['sku'],
                                'price' => $sizeData['price'],
                                'stock' => $sizeData['stock'],
                            ]);
                        }
                    }
                }
            }

            DB::commit();

            return response()->json([
                'status'  => 'success',
                'message' => 'Product updated successfully.',
                'data'    => $this->loadProduct($product->id),
            ], 200);

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Product Update Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => $e->getMessage() ?: 'Failed to update product.'], 500);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // PATCH /admin/products/{id}/publish
    // ═══════════════════════════════════════════════════════════════
    public function togglePublish($id)
    {
        try {
            $product = Product::findOrFail($id);
            $product->is_published = !$product->is_published;
            $product->save();

            return response()->json([
                'status'  => 'success',
                'message' => $product->is_published ? 'Product published.' : 'Product unpublished.',
                'data'    => ['id' => $product->id, 'is_published' => $product->is_published],
            ], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'Product not found.'], 404);
        } catch (Exception $e) {
            Log::error('Toggle Publish Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to update publish status.'], 500);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // PATCH /admin/products/{id}/today-sale
    // ═══════════════════════════════════════════════════════════════
    public function toggleTodaySale($id)
    {
        try {
            $product = Product::findOrFail($id);
            $product->is_today_sale = !$product->is_today_sale;
            $product->save();

            return response()->json([
                'status'  => 'success',
                'message' => $product->is_today_sale ? "Added to Today's Sale." : "Removed from Today's Sale.",
                'data'    => ['id' => $product->id, 'is_today_sale' => $product->is_today_sale],
            ], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'Product not found.'], 404);
        } catch (Exception $e) {
            Log::error('Toggle Today Sale Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to update today sale.'], 500);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // PATCH /admin/products/{id}/flash-sale
    // Body: { "is_flash_sale": true, "flash_sale_title": "Summer Sale",
    //         "flash_sale_discount": 25, "flash_sale_discount_type": "percent" }
    // ═══════════════════════════════════════════════════════════════
    public function updateFlashSale(Request $request, $id)
    {
        try {
            $product = Product::findOrFail($id);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'Product not found.'], 404);
        }

        try {
            $validated = $request->validate([
                'is_flash_sale'            => 'required|boolean',
                'flash_sale_title'         => 'nullable|string|max:255',
                'flash_sale_discount'      => 'nullable|numeric|min:0',
                'flash_sale_discount_type' => 'nullable|in:flat,percent',
            ]);
        } catch (ValidationException $e) {
            return response()->json(['status' => 'error', 'message' => 'Validation failed.', 'errors' => $e->errors()], 422);
        }

        try {
            if ($validated['is_flash_sale']) {
                if (empty($validated['flash_sale_discount']) || empty($validated['flash_sale_discount_type'])) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => 'flash_sale_discount and flash_sale_discount_type are required when enabling flash sale.',
                    ], 422);
                }

                $product->update([
                    'is_flash_sale'            => true,
                    'flash_sale_title'         => $validated['flash_sale_title'] ?? null,
                    'flash_sale_discount'      => $validated['flash_sale_discount'],
                    'flash_sale_discount_type' => $validated['flash_sale_discount_type'],
                ]);
            } else {
                $product->update([
                    'is_flash_sale'            => false,
                    'flash_sale_title'         => null,
                    'flash_sale_discount'      => 0,
                    'flash_sale_discount_type' => null,
                ]);
            }

            return response()->json([
                'status'  => 'success',
                'message' => $product->is_flash_sale ? 'Flash sale activated.' : 'Flash sale deactivated.',
                'data'    => [
                    'id'                       => $product->id,
                    'is_flash_sale'            => $product->is_flash_sale,
                    'flash_sale_title'         => $product->flash_sale_title,
                    'flash_sale_discount'      => $product->flash_sale_discount,
                    'flash_sale_discount_type' => $product->flash_sale_discount_type,
                    'effective_price'          => $product->effective_price,
                ],
            ], 200);

        } catch (Exception $e) {
            Log::error('Flash Sale Update Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to update flash sale.'], 500);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // DELETE /admin/products/{id}
    // ═══════════════════════════════════════════════════════════════
    public function destroy($id)
    {
        try {
            $product = Product::findOrFail($id);
            $product->delete(); // Cascades: color_variants → images + size_stocks

            return response()->json(['status' => 'success', 'message' => 'Product deleted successfully.'], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'Product not found.'], 404);
        } catch (Exception $e) {
            Log::error('Product Delete Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to delete product.'], 500);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // DELETE /admin/products/{productId}/colors/{colorVariantId}
    // ═══════════════════════════════════════════════════════════════
    public function destroyColorVariant($productId, $colorVariantId)
    {
        try {
            $colorVariant = ProductColorVariant::where('id', $colorVariantId)
                ->where('product_id', $productId)
                ->firstOrFail();

            $colorVariant->delete(); // Cascades: images + size_stocks

            return response()->json(['status' => 'success', 'message' => 'Color variant deleted successfully.'], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'Color variant not found.'], 404);
        } catch (Exception $e) {
            Log::error('Color Variant Delete Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to delete color variant.'], 500);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // DELETE /admin/products/{productId}/colors/{colorVariantId}/sizes/{sizeStockId}
    // ═══════════════════════════════════════════════════════════════
    public function destroySizeStock($productId, $colorVariantId, $sizeStockId)
    {
        try {
            // Verify the color variant belongs to the product
            $colorVariant = ProductColorVariant::where('id', $colorVariantId)
                ->where('product_id', $productId)
                ->firstOrFail();

            $sizeStock = ProductSizeStock::where('id', $sizeStockId)
                ->where('product_color_variant_id', $colorVariant->id)
                ->firstOrFail();

            $sizeStock->delete();

            return response()->json(['status' => 'success', 'message' => 'Size deleted successfully.'], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'Size not found.'], 404);
        } catch (Exception $e) {
            Log::error('Size Stock Delete Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to delete size.'], 500);
        }
    }
}