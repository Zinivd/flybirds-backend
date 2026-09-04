<?php
namespace App\Http\Controllers\Api\Admin;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\CartWishlistData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Collection;
use Exception;

class HomeCollectionController extends Controller
{
    // Fixed limits — kept as named constants so the numbers are
    // explicit and consistent across both endpoints.

    // Best Collections: always exactly 2 best-selling products,
    // and for EACH of those products, its top 2 best-selling colors.
    // Result = 2 products x 2 colors = 4 objects, one per product+color pair.
    private const BEST_COLLECTIONS_PRODUCT_LIMIT        = 2;
    private const BEST_COLLECTIONS_COLORS_PER_PRODUCT    = 2;

    private const BEST_SELLERS_CATEGORY_LIMIT = 3; // Best Sellers pulls from top 3 categories
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
    // PRIVATE HELPER: Safely fetch the overall best-selling products
    // (independent of category), ranked by total units sold.
    // Used by bestCollections(). Returns null if sales data doesn't
    // exist yet, so the caller can fall back to "latest added".
    // ═══════════════════════════════════════════════════════════════
    private function getBestSellingProducts(int $limit)
    {
        if (!Schema::hasTable('order_items')) {
            return null;
        }
        try {
            return DB::table('order_items')
                ->join('products', 'products.id', '=', 'order_items.product_id')
                ->where('products.is_published', true)
                ->select(
                    'order_items.product_id',
                    DB::raw('SUM(order_items.quantity) as total_sold')
                )
                ->groupBy('order_items.product_id')
                ->orderByDesc('total_sold')
                ->limit($limit)
                ->get();
        } catch (Exception $e) {
            Log::error('Best Selling Products Query Error: ' . $e->getMessage());
            return null;
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // PRIVATE HELPER: Safely fetch the best-selling COLOR VARIANTS
    // for a single product, ranked by total units sold. Used by
    // bestCollections() to find each product's top-selling colors.
    // Returns null if sales data doesn't exist yet.
    // ═══════════════════════════════════════════════════════════════
    private function getBestSellingColorsForProduct(int $productId, int $limit)
    {
        if (!Schema::hasTable('order_items')) {
            return null;
        }
        try {
            return DB::table('order_items')
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
        } catch (Exception $e) {
            Log::error('Best Selling Colors Query Error (Product #' . $productId . '): ' . $e->getMessage());
            return null;
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // PRIVATE HELPER: Given a loaded Product (with colorVariants +
    // their familyColor/familyColorChild/galleryImages/thumbnailImage
    // relations already eager-loaded) and an optional set of
    // "top selling color variant" rows, build one flat response
    // object PER color variant (up to $limit), each carrying the
    // full product payload plus that single color's details.
    //
    // If $topColorRows has fewer entries than $limit (e.g. the
    // product only ever sold in 1 color, or we're in the no-sales
    // fallback path), the remaining slots are filled from the
    // product's other color variants (ordered by id) so we still
    // return up to $limit entries per product wherever possible.
    // ═══════════════════════════════════════════════════════════════
    private function buildProductColorEntries(
        Product $product,
        $topColorRows,
        int $limit,
        int $productTotalSold,
        bool $isFallback
    ): Collection {
        $variants = $product->colorVariants; // already eager-loaded

        $selectedVariants = collect();
        $soldMap = [];

        if ($topColorRows && $topColorRows->isNotEmpty()) {
            foreach ($topColorRows as $row) {
                $variant = $variants->firstWhere('id', $row->product_color_variant_id);
                if ($variant) {
                    $soldMap[$variant->id] = (int) $row->total_sold;
                    $selectedVariants->push($variant);
                }
            }
        }

        // Top up to $limit using the product's remaining color variants
        // (covers: fallback mode with no sales data at all, or a
        // product that hasn't sold in enough distinct colors yet).
        if ($selectedVariants->count() < $limit) {
            $selectedIds = $selectedVariants->pluck('id')->toArray();
            $remaining = $variants->whereNotIn('id', $selectedIds)->sortBy('id')->values();
            foreach ($remaining as $variant) {
                if ($selectedVariants->count() >= $limit) {
                    break;
                }
                $selectedVariants->push($variant);
            }
        }

        $selectedVariants = $selectedVariants->take($limit);

        // Base product payload, shared across every color entry for
        // this product — strip the full nested color_variants list
        // since each entry below carries only its own single color.
        $baseProductData = $product->toArray();
        unset($baseProductData['color_variants']);

        return $selectedVariants->map(function ($variant) use ($baseProductData, $productTotalSold, $isFallback, $soldMap) {
            $entry = $baseProductData;
            $entry['total_sold']  = $productTotalSold;
            $entry['is_fallback'] = $isFallback;
            $entry['color_variant'] = [
                'id'                 => $variant->id,
                'family_color'       => $variant->familyColor,
                'family_color_child' => $variant->familyColorChild,
                'gallery_images'     => $variant->galleryImages,
                'thumbnail_image'    => $variant->thumbnailImage,
                'color_total_sold'   => $soldMap[$variant->id] ?? 0,
            ];
            return $entry;
        });
    }

    // ═══════════════════════════════════════════════════════════════
    // GET /admin/home/best-collections
    //
    // Finds the top 2 best-selling products, then for EACH of those
    // 2 products finds its top 2 best-selling colors. Every
    // product+color pairing is returned as its own object in the
    // response array — 2 products x 2 colors = 4 objects.
    //
    // If there's no sales data yet, falls back to the 2 most recently
    // added products, taking the first 2 color variants of each
    // (still 4 objects total, each flagged is_fallback: true).
    // ═══════════════════════════════════════════════════════════════
    public function bestCollections(Request $request)
    {
        try {
            $productLimit = self::BEST_COLLECTIONS_PRODUCT_LIMIT;
            $colorLimit   = self::BEST_COLLECTIONS_COLORS_PER_PRODUCT;

            $withRelations = [
                'category',
                'colorVariants.familyColor',
                'colorVariants.familyColorChild',
                'colorVariants.galleryImages',
                'colorVariants.thumbnailImage',
            ];

            $bestProducts = $this->getBestSellingProducts($productLimit);

            // Case 1: We have real sales-based ranking
            if ($bestProducts && $bestProducts->isNotEmpty()) {
                $result = collect();
                foreach ($bestProducts as $row) {
                    $product = Product::with($withRelations)->find($row->product_id);
                    if (!$product) {
                        continue;
                    }
                    $topColors = $this->getBestSellingColorsForProduct($product->id, $colorLimit);
                    $entries = $this->buildProductColorEntries(
                        $product,
                        $topColors,
                        $colorLimit,
                        (int) $row->total_sold,
                        false
                    );
                    $result = $result->merge($entries);
                }
                if ($result->isNotEmpty()) {
                    return response()->json(['status' => 'success', 'data' => $result->values()], 200);
                }
            }

            // Case 2: No sales data yet — fall back to latest added products
            $fallbackProducts = Product::with($withRelations)->latest()->limit($productLimit)->get();
            $result = collect();
            foreach ($fallbackProducts as $product) {
                $entries = $this->buildProductColorEntries($product, null, $colorLimit, 0, true);
                $result = $result->merge($entries);
            }

            return response()->json([
                'status'  => 'success',
                'message' => $result->isEmpty() ? 'No products found.' : 'Showing latest added products (no sales data yet).',
                'data'    => $result->values(),
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
    // Pulls from the top 3 categories by sales, then returns the
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
