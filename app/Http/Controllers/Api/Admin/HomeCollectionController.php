<?php
namespace App\Http\Controllers\Api\Admin;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\CartWishlistData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Collection;
use Exception;
class HomeCollectionController extends Controller
{
    // Fixed limits — kept as named constants so the numbers are
    // explicit and consistent across both endpoints.
    private const BEST_COLLECTIONS_CATEGORY_LIMIT = 3; // Best Collections always shows exactly 3 categories

    // ── Best Sellers limits ──────────────────────────────────────
    private const BEST_SELLERS_CATEGORY_LIMIT = 2; // Best Sellers always pulls from top 2 categories
    private const BEST_SELLERS_SALES_PRODUCTS_PER_CATEGORY    = 2; // When sales data exists: 2 best-selling products per category
    private const BEST_SELLERS_FALLBACK_PRODUCTS_PER_CATEGORY = 4; // No sales data yet: 4 latest products per category
    private const BEST_SELLERS_COLOR_LIMIT = 2; // Always show at most 2 colors per product (best-selling, or first 2 as fallback)

    // Relations shared by both best-seller branches
    private const PRODUCT_RELATIONS = [
        'category',
        'colorVariants.color',
        'colorVariants.galleryImages',
        'colorVariants.thumbnailImage',
        'colorVariants.sizeStocks',
    ];

    // ═══════════════════════════════════════════════════════════════
    // PRIVATE HELPER: Attach is_wishlisted flag to a collection
    // ═══════════════════════════════════════════════════════════════
    private function attachWishlistFlagToCollection($products, $userId = null)
    {
        $wishlistedIds = [];
        if ($userId) {
            $productIds = collect($products)->pluck('id')->toArray();
            $wishlistedIds = CartWishlistData::where('user_id', $userId)
                ->where('type', 'wishlist')
                ->whereIn('product_id', $productIds)
                ->pluck('product_id')
                ->toArray();
        }
        $products->each(function ($product) use ($wishlistedIds) {
            $product->setAttribute('is_wishlisted', in_array($product->id, $wishlistedIds));
        });
        return $products;
    }
    // ═══════════════════════════════════════════════════════════════
    // PRIVATE HELPER: Append category image URLs (banner/icon/cover)
    // ═══════════════════════════════════════════════════════════════
    private function appendCategoryUrls($category)
    {
        foreach (['banner_path', 'icon_path', 'cover_path'] as $field) {
            $urlField = str_replace('_path', '_url', $field);
            try {
                $category->$urlField = $category->$field
                    ? Storage::disk('s3')->temporaryUrl($category->$field, now()->addMinutes(30))
                    : null;
            } catch (Exception $e) {
                Log::error('S3 URL Generation Error (Category #' . $category->id . '): ' . $e->getMessage());
                $category->$urlField = null;
            }
        }
        return $category;
    }
    // ═══════════════════════════════════════════════════════════════
    // PRIVATE HELPER: Safely fetch category sales data.
    // Returns null (instead of throwing) if the sales table/columns
    // don't exist yet, so callers can fall back to "latest added".
    // ═══════════════════════════════════════════════════════════════
    private function getCategorySales(int $limit)
    {
        if (!Schema::hasTable('order_items')) {
            return null;
        }
        try {
            $rows = DB::table('order_items')
                ->join('products', 'products.id', '=', 'order_items.product_id')
                ->select(
                    'products.category_id',
                    DB::raw('SUM(order_items.quantity) as total_sold'),
                    DB::raw('COUNT(DISTINCT order_items.product_id) as distinct_products_sold')
                )
                ->groupBy('products.category_id')
                ->having('distinct_products_sold', '>', 3)
                ->orderByDesc('total_sold')
                ->limit($limit)
                ->get();
            return $rows;
        } catch (Exception $e) {
            Log::error('Category Sales Query Error: ' . $e->getMessage());
            return null;
        }
    }
    // ═══════════════════════════════════════════════════════════════
    // PRIVATE HELPER: Safely fetch product sales data within categories.
    // ═══════════════════════════════════════════════════════════════
    private function getProductSales(array $categoryIds, int $limit)
    {
        if (!Schema::hasTable('order_items')) {
            return null;
        }
        try {
            $rows = DB::table('order_items')
                ->join('products', 'products.id', '=', 'order_items.product_id')
                ->whereIn('products.category_id', $categoryIds)
                ->where('products.is_published', true)
                ->select(
                    'order_items.product_id',
                    DB::raw('SUM(order_items.quantity) as total_sold')
                )
                ->groupBy('order_items.product_id')
                ->orderByDesc('total_sold')
                ->limit($limit)
                ->get();
            return $rows;
        } catch (Exception $e) {
            Log::error('Product Sales Query Error: ' . $e->getMessage());
            return null;
        }
    }
    // ═══════════════════════════════════════════════════════════════
    // PRIVATE HELPER: Safely fetch color-variant sales for ONE product.
    // Returns the top-selling `product_color_variant_id`s (with their
    // total_sold) from order_items, or null if no sales data exists.
    // ═══════════════════════════════════════════════════════════════
    private function getColorVariantSales(int $productId, int $limit)
    {
        if (!Schema::hasTable('order_items')) {
            return null;
        }
        try {
            $rows = DB::table('order_items')
                ->where('product_id', $productId)
                ->whereNotNull('product_color_variant_id')
                ->select(
                    'product_color_variant_id',
                    DB::raw('SUM(quantity) as total_sold')
                )
                ->groupBy('product_color_variant_id')
                ->orderByDesc('total_sold')
                ->limit($limit)
                ->get();
            return $rows;
        } catch (Exception $e) {
            Log::error('Color Variant Sales Query Error (Product #' . $productId . '): ' . $e->getMessage());
            return null;
        }
    }
    // ═══════════════════════════════════════════════════════════════
    // PRIVATE HELPER: Trim a product's already-loaded `colorVariants`
    // relation down to at most `$limit` entries.
    //
    // When $useSalesRanking is true, it tries to rank the variants by
    // real sales (best-selling colors first) via getColorVariantSales().
    // If there's no sales data for this product yet, or the caller asked
    // for the "latest" fallback branch, it simply keeps the first
    // `$limit` colors in their existing (loaded) order.
    //
    // Each kept variant gets a `total_sold` attribute so the frontend
    // can show "X sold" per color when available (0 when it's a
    // non-sales fallback pick).
    // ═══════════════════════════════════════════════════════════════
    private function limitProductColors(Product $product, int $limit, bool $useSalesRanking): Product
    {
        $colorVariants = $product->colorVariants;
        if (!$colorVariants || $colorVariants->isEmpty()) {
            return $product;
        }
        $selected = null;
        if ($useSalesRanking) {
            $colorSales = $this->getColorVariantSales($product->id, $limit);
            if ($colorSales && $colorSales->isNotEmpty()) {
                $soldMap = $colorSales->pluck('total_sold', 'product_color_variant_id');
                $selected = $colorSales->pluck('product_color_variant_id')
                    ->map(fn($variantId) => $colorVariants->firstWhere('id', $variantId))
                    ->filter()
                    ->values();
                $selected->each(function ($variant) use ($soldMap) {
                    $variant->setAttribute('total_sold', (int) ($soldMap[$variant->id] ?? 0));
                });
            }
        }
        if (!$selected || $selected->isEmpty()) {
            // Fallback: just take the first N colors as currently loaded/ordered
            $selected = $colorVariants->take($limit)->values();
            $selected->each(function ($variant) {
                $variant->setAttribute('total_sold', 0);
            });
        }
        $product->setRelation('colorVariants', $selected);
        return $product;
    }
    // ═══════════════════════════════════════════════════════════════
    // GET /admin/home/best-collections
    //
    // Always returns exactly 3 categories (ranked by sales if data
    // exists, otherwise the 3 most recently added categories).
    // ═══════════════════════════════════════════════════════════════
    public function bestCollections(Request $request)
    {
        try {
            $limit = self::BEST_COLLECTIONS_CATEGORY_LIMIT;
            $categorySales = $this->getCategorySales($limit);
            // Case 1: We have real sales-based ranking
            if ($categorySales && $categorySales->isNotEmpty()) {
                $categoryIds = $categorySales->pluck('category_id')->toArray();
                $categories  = Category::whereIn('id', $categoryIds)->get()->keyBy('id');
                $result = $categorySales->map(function ($row) use ($categories) {
                    $category = $categories->get($row->category_id);
                    if (!$category) {
                        return null;
                    }
                    $category = $this->appendCategoryUrls($category);
                    $category->setAttribute('total_sold', (int) $row->total_sold);
                    $category->setAttribute('distinct_products_sold', (int) $row->distinct_products_sold);
                    $category->setAttribute('is_fallback', false);
                    return $category;
                })->filter()->values();
                if ($result->isNotEmpty()) {
                    return response()->json(['status' => 'success', 'data' => $result], 200);
                }
            }
            // Case 2: No sales data yet — fall back to latest added categories
            $fallback = Category::latest()->limit($limit)->get();
            $fallback = $fallback->map(function ($category) {
                $category = $this->appendCategoryUrls($category);
                $category->setAttribute('total_sold', 0);
                $category->setAttribute('distinct_products_sold', 0);
                $category->setAttribute('is_fallback', true);
                return $category;
            });
            return response()->json([
                'status'  => 'success',
                'message' => $fallback->isEmpty() ? 'No categories found.' : 'Showing latest added categories (no sales data yet).',
                'data'    => $fallback,
            ], 200);
        } catch (Exception $e) {
            Log::error('Best Collections Error: ' . $e->getMessage());
            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to retrieve best collections.',
            ], 500);
        }
    }
    // ═══════════════════════════════════════════════════════════════
    // GET /admin/home/best-sellers?user_id=5
    //
    // Grouped by category. For each of the top 2 categories:
    //
    //   • Sales case: the 2 best-selling products in that category,
    //     each showing only its top 2 best-selling colors.
    //   • Fallback case (no sales data anywhere yet): the 2 most
    //     recently added categories, 4 latest published products
    //     each, each product showing its first 2 colors.
    //
    // Response shape:
    // {
    //   "status": "success",
    //   "data": [
    //     {
    //       "category": { ...category fields incl. banner/icon/cover urls... },
    //       "products": [ { ...product fields incl. colorVariants (max 2)... } ]
    //     },
    //     ...
    //   ]
    // }
    // ═══════════════════════════════════════════════════════════════
    public function bestSellers(Request $request)
    {
        try {
            $userId        = $request->query('user_id');
            $categoryLimit = self::BEST_SELLERS_CATEGORY_LIMIT;
            $colorLimit    = self::BEST_SELLERS_COLOR_LIMIT;
            $categorySales = $this->getCategorySales($categoryLimit);
            // ── Case 1: real sales data exists for categories ────────
            if ($categorySales && $categorySales->isNotEmpty()) {
                $categoryIds = $categorySales->pluck('category_id')->toArray();
                $categories  = Category::whereIn('id', $categoryIds)->get()->keyBy('id');
                $productsPerCategory = self::BEST_SELLERS_SALES_PRODUCTS_PER_CATEGORY;
                $grouped = collect();
                foreach ($categorySales as $row) {
                    $category = $categories->get($row->category_id);
                    if (!$category) {
                        continue;
                    }
                    $categoryProducts = $this->bestSellingProductsForCategory(
                        (int) $row->category_id,
                        $productsPerCategory,
                        $colorLimit
                    );
                    // If this particular category has no product-level
                    // sales rows yet, fall back to its latest products
                    // so the category isn't returned empty.
                    if ($categoryProducts->isEmpty()) {
                        $categoryProducts = $this->latestProductsForCategory(
                            (int) $row->category_id,
                            $productsPerCategory,
                            $colorLimit
                        );
                    }
                    $category = $this->appendCategoryUrls($category);
                    $category->setAttribute('total_sold', (int) $row->total_sold);
                    $category->setAttribute('distinct_products_sold', (int) $row->distinct_products_sold);
                    $category->setAttribute('is_fallback', false);
                    $grouped->push([
                        'category' => $category,
                        'products' => $categoryProducts->values(),
                    ]);
                }
                if ($grouped->isNotEmpty()) {
                    $allProducts = $grouped->flatMap(fn($g) => $g['products']);
                    $this->attachWishlistFlagToCollection($allProducts, $userId);
                    return response()->json(['status' => 'success', 'data' => $grouped->values()], 200);
                }
            }
            // ── Case 2: No sales data yet — latest categories + products ──
            $fallbackProductsPerCategory = self::BEST_SELLERS_FALLBACK_PRODUCTS_PER_CATEGORY;
            $fallbackCategories = Category::latest()->limit($categoryLimit)->get();
            $grouped = $fallbackCategories->map(function ($category) use ($fallbackProductsPerCategory, $colorLimit) {
                $categoryProducts = $this->latestProductsForCategory(
                    $category->id,
                    $fallbackProductsPerCategory,
                    $colorLimit
                );
                $category = $this->appendCategoryUrls($category);
                $category->setAttribute('total_sold', 0);
                $category->setAttribute('distinct_products_sold', 0);
                $category->setAttribute('is_fallback', true);
                return [
                    'category' => $category,
                    'products' => $categoryProducts->values(),
                ];
            });
            $allProducts = $grouped->flatMap(fn($g) => $g['products']);
            $this->attachWishlistFlagToCollection($allProducts, $userId);
            return response()->json([
                'status'  => 'success',
                'message' => $grouped->isEmpty() ? 'No categories found.' : 'Showing latest added categories and products (no sales data yet).',
                'data'    => $grouped->values(),
            ], 200);
        } catch (Exception $e) {
            Log::error('Best Sellers Error: ' . $e->getMessage());
            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to retrieve best sellers.',
            ], 500);
        }
    }
    // ═══════════════════════════════════════════════════════════════
    // PRIVATE HELPER: Best-selling products (with best-selling colors)
    // for a single category. Returns an empty collection if there's no
    // product-level sales data for this category yet.
    // ═══════════════════════════════════════════════════════════════
    private function bestSellingProductsForCategory(int $categoryId, int $productLimit, int $colorLimit): Collection
    {
        $productSales = $this->getProductSales([$categoryId], $productLimit);
        if (!$productSales || $productSales->isEmpty()) {
            return collect();
        }
        $productIds = $productSales->pluck('product_id')->toArray();
        $soldMap    = $productSales->pluck('total_sold', 'product_id');
        $products = Product::with(self::PRODUCT_RELATIONS)
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');
        return collect($productIds)
            ->map(function ($productId) use ($products, $soldMap, $colorLimit) {
                $product = $products->get($productId);
                if (!$product) {
                    return null;
                }
                $product->setAttribute('total_sold', (int) ($soldMap[$productId] ?? 0));
                $product->setAttribute('is_fallback', false);
                return $this->limitProductColors($product, $colorLimit, true);
            })
            ->filter()
            ->values();
    }
    // ═══════════════════════════════════════════════════════════════
    // PRIVATE HELPER: Latest published products for a single category,
    // each trimmed down to its first N colors. Used both as the
    // per-category fallback and as the overall "no sales yet" branch.
    // ═══════════════════════════════════════════════════════════════
    private function latestProductsForCategory(int $categoryId, int $productLimit, int $colorLimit): Collection
    {
        $products = Product::with(self::PRODUCT_RELATIONS)
            ->where('category_id', $categoryId)
            ->where('is_published', true)
            ->latest()
            ->limit($productLimit)
            ->get();
        return $products->map(function ($product) use ($colorLimit) {
            $product->setAttribute('total_sold', 0);
            $product->setAttribute('is_fallback', true);
            return $this->limitProductColors($product, $colorLimit, false);
        })->values();
    }
}
