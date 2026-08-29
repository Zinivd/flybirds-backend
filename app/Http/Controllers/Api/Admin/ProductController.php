<?php
namespace App\Http\Controllers\Api\Admin;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductColorVariant;
use App\Models\ProductColorImage;
use App\Models\ProductSizeStock;
use App\Models\Media;
use App\Models\FamilyColorChild;
use App\Models\CartWishlistData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Exception;
class ProductController extends Controller
{
    // ═══════════════════════════════════════════════════════════════
    // PRIVATE HELPER: Load full product with all relations (with images)
    // ═══════════════════════════════════════════════════════════════
    private function loadProduct(int $id): Product
    {
        return Product::with([
            'category',
            'colorVariants.familyColor',
            'colorVariants.familyColorChild',
            'colorVariants.galleryImages',
            'colorVariants.thumbnailImage',
            'colorVariants.sizeStocks',
        ])->findOrFail($id);
    }
    // ═══════════════════════════════════════════════════════════════
    // PRIVATE HELPER: Attach is_wishlisted flag to a single product
    // ═══════════════════════════════════════════════════════════════
    private function attachWishlistFlag(Product $product, $userId = null): Product
    {
        $isWishlisted = false;
        if ($userId) {
            $isWishlisted = CartWishlistData::where('user_id', $userId)
                ->where('product_id', $product->id)
                ->where('type', 'wishlist')
                ->exists();
        }
        $product->setAttribute('is_wishlisted', $isWishlisted);
        return $product;
    }
    // ═══════════════════════════════════════════════════════════════
    // PRIVATE HELPER: Attach is_wishlisted flag to a collection efficiently
    // ═══════════════════════════════════════════════════════════════
    private function attachWishlistFlagToCollection($products, $userId = null)
    {
        $wishlistedIds = [];
        if ($userId) {
            if (method_exists($products, 'items')) {
                $productIds = collect($products->items())->pluck('id')->toArray();
            } else {
                $productIds = collect($products)->pluck('id')->toArray();
            }
            $wishlistedIds = CartWishlistData::where('user_id', $userId)
                ->where('type', 'wishlist')
                ->whereIn('product_id', $productIds)
                ->pluck('product_id')
                ->toArray();
        }
        $items = method_exists($products, 'getCollection') ? $products->getCollection() : $products;
        $items->transform(function ($product) use ($wishlistedIds) {
            $product->setAttribute('is_wishlisted', in_array($product->id, $wishlistedIds));
            return $product;
        });
        return $products;
    }
    // ═══════════════════════════════════════════════════════════════
    // PRIVATE HELPER: query param -> clean int array
    // ═══════════════════════════════════════════════════════════════
    private function toIdArray($value): array
    {
        $items = is_array($value) ? $value : explode(',', (string) $value);
        return array_values(array_filter(array_map(function ($v) {
            $v = trim((string) $v);
            return $v !== '' && is_numeric($v) ? (int) $v : null;
        }, $items), fn($v) => $v !== null));
    }
    // ═══════════════════════════════════════════════════════════════
    // PRIVATE HELPER: query param -> clean string array
    // ═══════════════════════════════════════════════════════════════
    private function toStringArray($value): array
    {
        $items = is_array($value) ? $value : explode(',', (string) $value);
        return array_values(array_filter(array_map(fn($v) => trim((string) $v), $items), fn($v) => $v !== ''));
    }
    // ═══════════════════════════════════════════════════════════════
    // PRIVATE HELPER: validate a family_color_child_id actually belongs
    // to the given family_color_id. Throws if mismatched.
    // ═══════════════════════════════════════════════════════════════
    private function assertChildBelongsToFamily(?int $familyColorId, ?int $childId): void
    {
        if (!$childId) {
            return;
        }
        $child = FamilyColorChild::find($childId);
        if (!$child || (int) $child->family_color_id !== (int) $familyColorId) {
            throw new Exception("The selected color child does not belong to the selected family color.");
        }
    }
    // ═══════════════════════════════════════════════════════════════
    // GET /admin/products
    // Query params supported:
    //   ?is_published=1 ?is_today_sale=1 ?is_flash_sale=1 ?search=polo ?user_id=5
    //   ?category_id=2                (or "2,5" for multiple)
    //   ?family_color_id=4            (or "4,7" for multiple)
    //   ?family_color_child_id=9      (or "9,10" for multiple)
    //   ?size=S,L
    //   ?min_price=0 ?max_price=5000
    //   ?status=active|inactive|all   (default: active)
    //   ?sort=price_asc|price_desc|name_asc|name_desc|newest|oldest
    // ═══════════════════════════════════════════════════════════════
    public function index(Request $request)
    {
        try {
            $query = Product::with([
                'category',
                'colorVariants.familyColor',
                'colorVariants.familyColorChild',
                'colorVariants.galleryImages',
                'colorVariants.thumbnailImage',
                'colorVariants.sizeStocks',
            ]);
            // ── Active / inactive status ─────────────────────────
            $status = $request->query('status', 'active');
            if ($status === 'active') {
                $query->where('is_active', true);
            } elseif ($status === 'inactive') {
                $query->where('is_active', false);
            }
            // 'all' → no filter applied
            // ── Boolean flag filters ─────────────────────────────
            if ($request->filled('is_published')) {
                $query->where('is_published', filter_var($request->is_published, FILTER_VALIDATE_BOOLEAN));
            }
            if ($request->filled('is_today_sale')) {
                $query->where('is_today_sale', filter_var($request->is_today_sale, FILTER_VALIDATE_BOOLEAN));
            }
            if ($request->filled('is_flash_sale')) {
                $query->where('is_flash_sale', filter_var($request->is_flash_sale, FILTER_VALIDATE_BOOLEAN));
            }
            // ── Search ────────────────────────────────────────────
            if ($request->filled('search')) {
                $query->where('name', 'like', '%' . $request->search . '%');
            }
            // ── Category filter ───────────────────────────────────
            if ($request->filled('category_id')) {
                $categoryIds = $this->toIdArray($request->query('category_id'));
                if (!empty($categoryIds)) {
                    $query->whereIn('category_id', $categoryIds);
                }
            }
            // ── Family color filter ───────────────────────────────
            if ($request->filled('family_color_id')) {
                $familyColorIds = $this->toIdArray($request->query('family_color_id'));
                if (!empty($familyColorIds)) {
                    $query->whereHas('colorVariants', function ($q) use ($familyColorIds) {
                        $q->whereIn('family_color_id', $familyColorIds);
                    });
                }
            }
            // ── Family color child filter ─────────────────────────
            if ($request->filled('family_color_child_id')) {
                $childIds = $this->toIdArray($request->query('family_color_child_id'));
                if (!empty($childIds)) {
                    $query->whereHas('colorVariants', function ($q) use ($childIds) {
                        $q->whereIn('family_color_child_id', $childIds);
                    });
                }
            }
            // ── Size filter ────────────────────────────────────────
            if ($request->filled('size')) {
                $sizes = $this->toStringArray($request->query('size'));
                if (!empty($sizes)) {
                    $query->whereHas('colorVariants.sizeStocks', function ($q) use ($sizes) {
                        $q->whereIn('size', $sizes);
                    });
                }
            }
            // ── Price range filter ─────────────────────────────────
            if ($request->filled('min_price')) {
                $query->where('unit_price', '>=', (float) $request->query('min_price'));
            }
            if ($request->filled('max_price')) {
                $query->where('unit_price', '<=', (float) $request->query('max_price'));
            }
            // ── Sorting ────────────────────────────────────────────
            switch ($request->query('sort')) {
                case 'price_asc':
                    $query->orderBy('unit_price', 'asc');
                    break;
                case 'price_desc':
                    $query->orderBy('unit_price', 'desc');
                    break;
                case 'name_asc':
                    $query->orderBy('name', 'asc');
                    break;
                case 'name_desc':
                    $query->orderBy('name', 'desc');
                    break;
                case 'oldest':
                    $query->oldest();
                    break;
                case 'newest':
                default:
                    $query->latest();
                    break;
            }
            $products = $query->paginate(15);
            $userId = $request->query('user_id');
            $this->attachWishlistFlagToCollection($products, $userId);
            return response()->json(['status' => 'success', 'data' => $products], 200);
        } catch (Exception $e) {
            Log::error('Product Index Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to retrieve products.'], 500);
        }
    }
    // ═══════════════════════════════════════════════════════════════
    // GET /admin/products/{id}?user_id=5
    // ═══════════════════════════════════════════════════════════════
    public function show(Request $request, $id)
    {
        try {
            $product = $this->loadProduct($id);
            $userId = $request->query('user_id');
            $this->attachWishlistFlag($product, $userId);
            return response()->json(['status' => 'success', 'data' => $product], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'Product not found.'], 404);
        } catch (Exception $e) {
            Log::error('Product Show Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to retrieve product.'], 500);
        }
    }
    // ═══════════════════════════════════════════════════════════════
    // GET /admin/products/category/{categoryId}?user_id=5
    // ═══════════════════════════════════════════════════════════════
    public function getByCategory(Request $request, $categoryId)
    {
        try {
            $products = Product::with([
                'category',
                'colorVariants.familyColor',
                'colorVariants.familyColorChild',
                'colorVariants.galleryImages',
                'colorVariants.thumbnailImage',
                'colorVariants.sizeStocks',
            ])
            ->where('category_id', $categoryId)
            ->where('is_published', true)
            ->where('is_active', true)
            ->latest()
            ->paginate(15);
            $userId = $request->query('user_id');
            $this->attachWishlistFlagToCollection($products, $userId);
            return response()->json(['status' => 'success', 'data' => $products], 200);
        } catch (Exception $e) {
            Log::error('Product By Category Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to retrieve products.'], 500);
        }
    }
    // ═══════════════════════════════════════════════════════════════
    // GET /admin/products/{id}/similar?user_id=5
    // ═══════════════════════════════════════════════════════════════
    public function similar(Request $request, $id)
    {
        try {
            $product = Product::findOrFail($id);
            $similar = Product::with([
                'colorVariants.familyColor',
                'colorVariants.familyColorChild',
                'colorVariants.thumbnailImage',
                'colorVariants.galleryImages',
                'colorVariants.sizeStocks',
            ])
            ->where('category_id', $product->category_id)
            ->where('id', '!=', $id)
            ->where('is_published', true)
            ->where('is_active', true)
            ->latest()
            ->limit(10)
            ->get();
            $userId = $request->query('user_id');
            $this->attachWishlistFlagToCollection($similar, $userId);
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
    // ═══════════════════════════════════════════════════════════════
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'name'                     => 'required|string|max:255',
                'brand'                    => 'nullable|string|max:255',
                'unit'                     => 'required|string|max:50',
                'weight'                   => 'required|numeric|min:0',
                'min_qty'                  => 'required|integer|min:1',
                'tags'                     => 'nullable|string',
                'estimate_shipping_days'   => 'nullable|integer|min:0',
                'description'              => 'nullable|string',
                'category_id'              => 'required|exists:categories,id',
                'unit_price'               => 'required|numeric|min:0',
                'discount'                 => 'nullable|numeric|min:0',
                'discount_type'            => 'nullable|in:flat,percent',
                'discount_start_date'      => 'nullable|date',
                'discount_end_date'        => 'nullable|date|after_or_equal:discount_start_date',
                'reward_points'            => 'nullable|integer|min:0',
                'is_flash_sale'            => 'boolean',
                'flash_sale_title'         => 'nullable|string|max:255',
                'flash_sale_discount'      => 'nullable|numeric|min:0',
                'flash_sale_discount_type' => 'nullable|in:flat,percent',
                'is_today_sale'            => 'boolean',
                'is_published'             => 'boolean',
                'is_active'                => 'boolean',
                // Spotlight image — passed as a media id, same pattern as thumbnail_image_id
                'spotlight_image_id'       => 'nullable|integer|exists:media,id',
                // SEO
                'seo_title'                => 'nullable|string|max:255',
                'seo_description'          => 'nullable|string',
                'seo_keywords'             => 'nullable|array',
                'seo_keywords.*'           => 'string|max:100',
                // Colors — now driven by family color / family color child
                'colors'                        => 'required|array|min:1',
                'colors.*.family_color_id'      => 'required|exists:family_colors,id',
                'colors.*.family_color_child_id'=> 'nullable|exists:family_color_children,id',
                'colors.*.gallery_image_ids'    => 'nullable|array|max:6',
'colors.*.gallery_image_ids.*'  => 'integer|exists:media,id',
                'colors.*.thumbnail_image_id'   => 'nullable|integer|exists:media,id',
                'colors.*.sizes'                => 'required|array|min:1',
                'colors.*.sizes.*.size'         => 'required|string|max:50',
                // SKU is fully user-defined — required, unique, no auto-generation anywhere
                'colors.*.sizes.*.sku'          => 'required|string|max:100|distinct|unique:product_size_stocks,sku',
                'colors.*.sizes.*.price'        => 'required|numeric|min:0',
                'colors.*.sizes.*.stock'        => 'required|integer|min:0',
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
            // Resolve spotlight image URL from media id, if provided
            $spotlightImageUrl = null;
            if (!empty($validated['spotlight_image_id'])) {
                $spotlightMedia = Media::find($validated['spotlight_image_id']);
                $spotlightImageUrl = $spotlightMedia->file_url ?? null;
            }
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
                'is_active'                => $validated['is_active'] ?? true,
                'spotlight_image'          => $spotlightImageUrl,
                'seo_title'                => $validated['seo_title'] ?? null,
                'seo_description'          => $validated['seo_description'] ?? null,
                'seo_keywords'             => $validated['seo_keywords'] ?? [],
            ]);
            foreach ($validated['colors'] as $colorData) {
                $this->assertChildBelongsToFamily(
                    $colorData['family_color_id'],
                    $colorData['family_color_child_id'] ?? null
                );
                $colorVariant = ProductColorVariant::create([
                    'product_id'             => $product->id,
                    'family_color_id'        => $colorData['family_color_id'],
                    'family_color_child_id'  => $colorData['family_color_child_id'] ?? null,
                ]);
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
            return response()->json(['status' => 'error', 'message' => $e->getMessage() ?: 'Failed to create product.'], 500);
        }
    }
    // ═══════════════════════════════════════════════════════════════
    // POST /admin/products/{id}  — Update product
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
                'category_id'              => 'sometimes|exists:categories,id',
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
                'is_active'                => 'sometimes|boolean',
                'spotlight_image_id'       => 'nullable|integer|exists:media,id',
                'seo_title'                => 'nullable|string|max:255',
                'seo_description'          => 'nullable|string',
                'seo_keywords'             => 'nullable|array',
                'seo_keywords.*'           => 'string|max:100',
                'colors'                            => 'sometimes|array|min:1',
                'colors.*.color_variant_id'         => 'nullable|exists:product_color_variants,id',
                'colors.*.family_color_id'          => 'required_with:colors|exists:family_colors,id',
                'colors.*.family_color_child_id'    => 'nullable|exists:family_color_children,id',
                'colors.*.gallery_image_ids'        => 'nullable|array|max:6',
'colors.*.gallery_image_ids.*'      => 'integer|exists:media,id',
                'colors.*.thumbnail_image_id'       => 'nullable|integer|exists:media,id',
                'colors.*.sizes'                    => 'sometimes|array|min:1',
                'colors.*.sizes.*.size_stock_id'    => 'nullable|exists:product_size_stocks,id',
                'colors.*.sizes.*.size'             => 'required_with:colors.*.sizes|string|max:50',
                'colors.*.sizes.*.sku'              => 'required_with:colors.*.sizes|string|max:100',
                'colors.*.sizes.*.price'            => 'required_with:colors.*.sizes|numeric|min:0',
                'colors.*.sizes.*.stock'            => 'required_with:colors.*.sizes|integer|min:0',
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
            $productUpdateData = array_filter(
                $validated,
                fn($k) => !in_array($k, ['colors', 'spotlight_image_id']),
                ARRAY_FILTER_USE_KEY
            );
            if (array_key_exists('spotlight_image_id', $validated)) {
                if (!empty($validated['spotlight_image_id'])) {
                    $media = Media::find($validated['spotlight_image_id']);
                    $productUpdateData['spotlight_image'] = $media->file_url ?? null;
                } else {
                    $productUpdateData['spotlight_image'] = null;
                }
            }
            $product->update($productUpdateData);
            if (!empty($validated['colors'])) {
                foreach ($validated['colors'] as $colorData) {
                    $this->assertChildBelongsToFamily(
                        $colorData['family_color_id'],
                        $colorData['family_color_child_id'] ?? null
                    );
                    if (!empty($colorData['color_variant_id'])) {
                        $colorVariant = ProductColorVariant::where('id', $colorData['color_variant_id'])
                            ->where('product_id', $product->id)
                            ->firstOrFail();
                        $colorVariant->update([
                            'family_color_id'       => $colorData['family_color_id'],
                            'family_color_child_id' => $colorData['family_color_child_id'] ?? null,
                        ]);
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
                        if (!empty($colorData['sizes'])) {
                            foreach ($colorData['sizes'] as $sizeData) {
                                if (!empty($sizeData['size_stock_id'])) {
                                    $sizeStock = ProductSizeStock::where('id', $sizeData['size_stock_id'])
                                        ->where('product_color_variant_id', $colorVariant->id)
                                        ->firstOrFail();
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
                        $colorVariant = ProductColorVariant::create([
                            'product_id'             => $product->id,
                            'family_color_id'        => $colorData['family_color_id'],
                            'family_color_child_id'  => $colorData['family_color_child_id'] ?? null,
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
    // Soft delete only — flips is_active to false, no data is removed.
    // ═══════════════════════════════════════════════════════════════
    public function destroy($id)
    {
        try {
            $product = Product::findOrFail($id);
            $product->is_active = false;
            $product->save();
            return response()->json(['status' => 'success', 'message' => 'Product deactivated successfully.'], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'Product not found.'], 404);
        } catch (Exception $e) {
            Log::error('Product Delete Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to deactivate product.'], 500);
        }
    }
    // ═══════════════════════════════════════════════════════════════
    // PATCH /admin/products/{id}/activate
    // Reverses a soft delete.
    // ═══════════════════════════════════════════════════════════════
    public function activate($id)
    {
        try {
            $product = Product::findOrFail($id);
            $product->is_active = true;
            $product->save();
            return response()->json(['status' => 'success', 'message' => 'Product activated successfully.'], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'Product not found.'], 404);
        } catch (Exception $e) {
            Log::error('Product Activate Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to activate product.'], 500);
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
            $colorVariant->delete();
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
