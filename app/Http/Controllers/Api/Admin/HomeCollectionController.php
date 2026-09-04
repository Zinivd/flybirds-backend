<?php
namespace App\Http\Controllers\Api\Admin;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductColorVariant;
use App\Models\CartWishlistData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;
use Exception;

class HomeCollectionController extends Controller
{
    // Fixed limits — kept as named constants so the numbers are
    // explicit and consistent across both endpoints.

    // ── Best Collections ──────────────────────────────────────────
    private const BEST_COLLECTIONS_CATEGORY_LIMIT               = 2; // Top 2 categories when sales data exists
    private const BEST_COLLECTIONS_CATEGORY_LIMIT_FALLBACK      = 2; // Latest 2 categories when no sales data yet
    private const BEST_COLLECTIONS_PRODUCTS_PER_CATEGORY_SALES  = 2; // 2 best-selling products per category
    private const BEST_COLLECTIONS_PRODUCTS_PER_CATEGORY_FALLBACK = 4; // 4 latest products per category (fallback)
    private const BEST_COLLECTIONS_COLORS_PER_PRODUCT           = 2; // Top/first 2 colors shown per product

    // ── Best Sellers ───────────────────────────────────────────────
    private const BEST_SELLERS_CATEGORY_LIMIT = 4; // Best Sellers pulls from top 4 categories
    private const BEST_SELLERS_PRODUCT_LIMIT  = 8; // Best Sellers always shows top/recent 8 products

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
    // (Used by Best Sellers — pools products across all given categories.)
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
    // PRIVATE HELPER: Safely fetch product sales data PER category.
    // Unlike getProductSales() (which pools products across all given
    // categories into one ranked list for Best Sellers), this keeps
    // the ranking scoped to each individual category_id — needed so
    // Best Collections can show "top N products" per category rather
    // than one mixed list.
    // ═══════════════════════════════════════════════════════════════
    private function getProductSalesByCategory(array $categoryIds)
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
                    'products.category_id',
                    'order_items.product_id',
                    DB::raw('SUM(order_items.quantity) as total_sold')
                )
                ->groupBy('products.category_id', 'order_items.product_id')
                ->orderByDesc('total_sold')
                ->get();
            return $rows;
        } catch (Exception $e) {
            Log::error('Product Sales By Category Query Error: ' . $e->getMessage());
            return null;
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // PRIVATE HELPER: Safely fetch color-variant sales data for a set
    // of products — used to find each product's best-selling color(s).
    // ═══════════════════════════════════════════════════════════════
    private function getColorVariantSalesForProducts(array $productIds)
    {
        if (empty($productIds) || !Schema::hasTable('order_items')) {
            return null;
        }
        try {
            $rows = DB::table('order_items')
                ->whereIn('product_id', $productIds)
                ->whereNotNull('product_color_variant_id')
                ->select(
                    'product_id',
                    'product_color_variant_id',
                    DB::raw('SUM(quantity) as total_sold')
                )
                ->groupBy('product_id', 'product_color_variant_id')
                ->orderByDesc('total_sold')
                ->get();
            return $rows;
        } catch (Exception $e) {
            Log::error('Color Variant Sales Query Error: ' . $e->getMessage());
            return null;
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // PRIVATE HELPER: Attach the top-selling color(s) to a product.
    // If $bySales is true and sales rows for this product exist, the
    // colors are ranked by units sold (best-selling color(s) first).
    // Otherwise falls back to the first-added color variant(s).
    // ═══════════════════════════════════════════════════════════════
    private function attachBestColorsToProduct(Product $product, $colorSalesForProduct, int $colorsPerProduct, bool $bySales)
    {
        if ($bySales && $colorSalesForProduct && $colorSalesForProduct->isNotEmpty()) {
            $variantIds = $colorSalesForProduct->take($colorsPerProduct)->pluck('product_color_variant_id')->toArray();
            $soldMap = $colorSalesForProduct->pluck('total_sold', 'product_color_variant_id');
            $variants = ProductColorVariant::with(['familyColor', 'familyColorChild', 'galleryImages', 'thumbnailImage', 'sizeStocks'])
                ->whereIn('id', $variantIds)
                ->get()
                ->keyBy('id');
            $ordered = collect($variantIds)
                ->map(function ($id) use ($variants, $soldMap) {
                    $variant = $variants->get($id);
                    if (!$variant) {
                        return null;
                    }
                    $variant->setAttribute('total_sold', (int) ($soldMap[$id] ?? 0));
                    $variant->setAttribute('is_best_selling_color', true);
                    return $variant;
                })
                ->filter()
                ->values();
            if ($ordered->isNotEmpty()) {
                $product->setAttribute('colors', $ordered);
                return $product;
            }
        }
        // Fallback: first N color variants added to the product
        $firstColors = $product->colorVariants()
            ->with(['familyColor', 'familyColorChild', 'galleryImages', 'thumbnailImage', 'sizeStocks'])
            ->oldest()
            ->limit($colorsPerProduct)
            ->get()
            ->each(function ($variant) {
                $variant->setAttribute('total_sold', 0);
                $variant->setAttribute('is_best_selling_color', false);
            });
        $product->setAttribute('colors', $firstColors);
        return $product;
    }

    // ═══════════════════════════════════════════════════════════════
    // PRIVATE HELPER: Build [category_id => Collection<Product>] for
    // the sales-ranked path — top products per category, each product
    // carrying its top-selling color(s).
    // ═══════════════════════════════════════════════════════════════
    private function buildProductsForCategoriesFromSales(array $categoryIds, int $productsPerCategory, int $colorsPerProduct, $userId = null): array
    {
        $productSales = $this->getProductSalesByCategory($categoryIds);
        if (!$productSales || $productSales->isEmpty()) {
            return [];
        }
        // groupBy() preserves the original (already sales-desc-sorted)
        // order within each group, so take(N) after grouping gives the
        // top N products per category.
        $groupedByCategory = $productSales->groupBy('category_id')->map(function ($rows) use ($productsPerCategory) {
            return $rows->take($productsPerCategory);
        });
        $allProductIds = $groupedByCategory->flatten(1)->pluck('product_id')->unique()->values()->toArray();
        if (empty($allProductIds)) {
            return [];
        }
        $products = Product::with(['category'])->whereIn('id', $allProductIds)->get()->keyBy('id');
        $colorSales = $this->getColorVariantSalesForProducts($allProductIds);
        $colorSalesByProduct = $colorSales ? $colorSales->groupBy('product_id') : collect();
        $result = [];
        foreach ($groupedByCategory as $categoryId => $rows) {
            $categoryProducts = collect();
            foreach ($rows as $row) {
                $baseProduct = $products->get($row->product_id);
                if (!$baseProduct) {
                    continue;
                }
                $product = clone $baseProduct; // avoid shared instance state across categories
                $product->setAttribute('total_sold', (int) $row->total_sold);
                $product->setAttribute('is_fallback', false);
                $productColorSales = $colorSalesByProduct->get($row->product_id);
                $this->attachBestColorsToProduct($product, $productColorSales, $colorsPerProduct, true);
                $categoryProducts->push($product);
            }
            if ($userId) {
                $this->attachWishlistFlagToCollection($categoryProducts, $userId);
            } else {
                $categoryProducts->each(fn($p) => $p->setAttribute('is_wishlisted', false));
            }
            $result[$categoryId] = $categoryProducts->values();
        }
        return $result;
    }

    // ═══════════════════════════════════════════════════════════════
    // PRIVATE HELPER: Fallback path (no sales data) — latest products
    // for a single category, each carrying its first-added color(s).
    // ═══════════════════════════════════════════════════════════════
    private function buildProductsForCategoryFallback(int $categoryId, int $productsPerCategory, int $colorsPerProduct, $userId = null)
    {
        $products = Product::with(['category'])
            ->where('category_id', $categoryId)
            ->where('is_published', true)
            ->latest()
            ->limit($productsPerCategory)
            ->get();
        $products->each(function ($product) use ($colorsPerProduct) {
            $product->setAttribute('total_sold', 0);
            $product->setAttribute('is_fallback', true);
            $this->attachBestColorsToProduct($product, null, $colorsPerProduct, false);
        });
        if ($userId) {
            $this->attachWishlistFlagToCollection($products, $userId);
        } else {
            $products->each(fn($p) => $p->setAttribute('is_wishlisted', false));
        }
        return $products->values();
    }

    // ═══════════════════════════════════════════════════════════════
    // GET /admin/home/best-collections?user_id=5
    //
    // Returns the top 2 categories (ranked by sales if data exists,
    // otherwise the 2 most recently added categories). Each category
    // now also carries its products:
    //   - Sales data exists: top 2 best-selling products per category,
    //     each with its top 2 best-selling colors.
    //   - No sales data yet: 4 latest published products per category,
    //     each with its first 2 color variants.
    // ═══════════════════════════════════════════════════════════════
    public function bestCollections(Request $request)
    {
        try {
            $categoryLimit           = self::BEST_COLLECTIONS_CATEGORY_LIMIT;
            $productsPerCategorySales = self::BEST_COLLECTIONS_PRODUCTS_PER_CATEGORY_SALES;
            $colorsPerProduct        = self::BEST_COLLECTIONS_COLORS_PER_PRODUCT;
            $userId = $request->query('user_id');

            $categorySales = $this->getCategorySales($categoryLimit);

            // Case 1: We have real sales-based ranking
            if ($categorySales && $categorySales->isNotEmpty()) {
                $categoryIds = $categorySales->pluck('category_id')->toArray();
                $categories  = Category::whereIn('id', $categoryIds)->get()->keyBy('id');
                $productsByCategory = $this->buildProductsForCategoriesFromSales(
                    $categoryIds,
                    $productsPerCategorySales,
                    $colorsPerProduct,
                    $userId
                );
                $result = $categorySales->map(function ($row) use ($categories, $productsByCategory) {
                    $category = $categories->get($row->category_id);
                    if (!$category) {
                        return null;
                    }
                    $category = $this->appendCategoryUrls($category);
                    $category->setAttribute('total_sold', (int) $row->total_sold);
                    $category->setAttribute('distinct_products_sold', (int) $row->distinct_products_sold);
                    $category->setAttribute('is_fallback', false);
                    $category->setAttribute('products', $productsByCategory[$row->category_id] ?? collect());
                    return $category;
                })->filter()->values();
                if ($result->isNotEmpty()) {
                    return response()->json(['status' => 'success', 'data' => $result], 200);
                }
            }

            // Case 2: No sales data yet — fall back to latest added categories
            $fbCategoryLimit       = self::BEST_COLLECTIONS_CATEGORY_LIMIT_FALLBACK;
            $fbProductsPerCategory = self::BEST_COLLECTIONS_PRODUCTS_PER_CATEGORY_FALLBACK;
            $fallback = Category::latest()->limit($fbCategoryLimit)->get();
            $fallback = $fallback->map(function ($category) use ($fbProductsPerCategory, $colorsPerProduct, $userId) {
                $category = $this->appendCategoryUrls($category);
                $category->setAttribute('total_sold', 0);
                $category->setAttribute('distinct_products_sold', 0);
                $category->setAttribute('is_fallback', true);
                $category->setAttribute(
                    'products',
                    $this->buildProductsForCategoryFallback($category->id, $fbProductsPerCategory, $colorsPerProduct, $userId)
                );
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
    // Pulls from the top 4 categories by sales, then returns the
    // top 8 products (by sales) within those categories. Falls back
    // to the 8 most recently added published products if no sales
    // data exists yet.
    // ═══════════════════════════════════════════════════════════════
    public function bestSellers(Request $request)
    {
        try {
            $categoryLimit = self::BEST_SELLERS_CATEGORY_LIMIT;
            $productLimit  = self::BEST_SELLERS_PRODUCT_LIMIT;
            $categorySales = $this->getCategorySales($categoryLimit);
            // Case 1: Real sales data exists for categories
            if ($categorySales && $categorySales->isNotEmpty()) {
                $categoryIds  = $categorySales->pluck('category_id')->toArray();
                $productSales = $this->getProductSales($categoryIds, $productLimit);
                if ($productSales && $productSales->isNotEmpty()) {
                    $productIds = $productSales->pluck('product_id')->toArray();
                    $soldMap    = $productSales->pluck('total_sold', 'product_id');
                    $products = Product::with([
                        'category',
                        'colorVariants.color',
                        'colorVariants.galleryImages',
                        'colorVariants.thumbnailImage',
                        'colorVariants.sizeStocks',
                    ])
                    ->whereIn('id', $productIds)
                    ->get();
                    $ordered = $products->sortBy(function ($product) use ($productIds) {
                        return array_search($product->id, $productIds);
                    })->values();
                    $ordered->each(function ($product) use ($soldMap) {
                        $product->setAttribute('total_sold', (int) ($soldMap[$product->id] ?? 0));
                        $product->setAttribute('is_fallback', false);
                    });
                    $userId = $request->query('user_id');
                    $this->attachWishlistFlagToCollection($ordered, $userId);
                    if ($ordered->isNotEmpty()) {
                        return response()->json(['status' => 'success', 'data' => $ordered], 200);
                    }
                }
            }
            // Case 2: No sales data yet — fall back to latest added published products
            $fallbackProducts = Product::with([
                'category',
                'colorVariants.color',
                'colorVariants.galleryImages',
                'colorVariants.thumbnailImage',
                'colorVariants.sizeStocks',
            ])
            ->where('is_published', true)
            ->latest()
            ->limit($productLimit)
            ->get();
            $fallbackProducts->each(function ($product) {
                $product->setAttribute('total_sold', 0);
                $product->setAttribute('is_fallback', true);
            });
            $userId = $request->query('user_id');
            $this->attachWishlistFlagToCollection($fallbackProducts, $userId);
            return response()->json([
                'status'  => 'success',
                'message' => $fallbackProducts->isEmpty() ? 'No products found.' : 'Showing latest added products (no sales data yet).',
                'data'    => $fallbackProducts,
            ], 200);
        } catch (Exception $e) {
            Log::error('Best Sellers Error: ' . $e->getMessage());
            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to retrieve best sellers.',
            ], 500);
        }
    }
}
