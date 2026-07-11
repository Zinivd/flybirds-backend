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
use Exception;

class HomeCollectionController extends Controller
{
    // Fixed limits — kept as named constants so the numbers are
    // explicit and consistent across both endpoints.
    private const BEST_COLLECTIONS_CATEGORY_LIMIT = 3; // Best Collections always shows exactly 3 categories
    private const BEST_SELLERS_CATEGORY_LIMIT     = 4; // Best Sellers pulls from top 4 categories
    private const BEST_SELLERS_PRODUCT_LIMIT       = 8; // Best Sellers always shows top/recent 8 products

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