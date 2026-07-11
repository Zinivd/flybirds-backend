<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\RecentlyViewedProduct;
use App\Models\CartWishlistData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Exception;

class RecentlyViewedController extends Controller
{
    // Max number of recently viewed products kept per user.
    // Oldest entries beyond this are pruned automatically.
    private const MAX_ITEMS_PER_USER = 30;

    // ═══════════════════════════════════════════════════════════════
    // PRIVATE HELPER: Attach is_wishlisted flag to a product collection
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
    // POST /recently-viewed
    // Body: { "user_id": "FYB-USR-xxxx", "product_id": 5 }
    //
    // Records/updates a "view" of a product for a user.
    // - If the user already viewed this product before, just bump
    //   viewed_at to now (moves it to the top of the list).
    // - If not, creates a new row with a snapshot of product/category
    //   name at the time of viewing.
    // - Automatically prunes oldest entries beyond MAX_ITEMS_PER_USER.
    // ═══════════════════════════════════════════════════════════════
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'user_id'    => 'required|string|exists:fly_users,user_id',
                'product_id' => 'required|exists:products,id',
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
            $product = Product::with('category')->find($validated['product_id']);

            if (!$product) {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => 'Product not found.'], 404);
            }

            RecentlyViewedProduct::updateOrCreate(
                [
                    'user_id'    => $validated['user_id'],
                    'product_id' => $product->id,
                ],
                [
                    'category_id'   => $product->category_id,
                    'product_name'  => $product->name,
                    'category_name' => $product->category->name ?? null,
                    'viewed_at'     => now(),
                ]
            );

            // Prune oldest entries beyond the cap for this user
            $totalCount = RecentlyViewedProduct::where('user_id', $validated['user_id'])->count();

            if ($totalCount > self::MAX_ITEMS_PER_USER) {
                $excess = $totalCount - self::MAX_ITEMS_PER_USER;

                $idsToDelete = RecentlyViewedProduct::where('user_id', $validated['user_id'])
                    ->orderBy('viewed_at', 'asc')
                    ->limit($excess)
                    ->pluck('id');

                RecentlyViewedProduct::whereIn('id', $idsToDelete)->delete();
            }

            DB::commit();

            return response()->json([
                'status'  => 'success',
                'message' => 'Product view recorded.',
            ], 200);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Recently Viewed Store Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to record product view.'], 500);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // GET /recently-viewed/{userId}?limit=20
    //
    // Returns the user's recently viewed products, most recent first,
    // with FULL product details (images, variants, sizes, category)
    // — not just the lightweight snapshot stored in the tracking table.
    // ═══════════════════════════════════════════════════════════════
    public function index(Request $request, $userId)
    {
        try {
            $limit = (int) $request->query('limit', self::MAX_ITEMS_PER_USER);
            if ($limit < 1) {
                $limit = self::MAX_ITEMS_PER_USER;
            }

            $recentEntries = RecentlyViewedProduct::where('user_id', $userId)
                ->orderByDesc('viewed_at')
                ->limit($limit)
                ->get();

            if ($recentEntries->isEmpty()) {
                return response()->json([
                    'status'  => 'success',
                    'message' => 'No recently viewed products yet.',
                    'data'    => [],
                ], 200);
            }

            $productIds = $recentEntries->pluck('product_id')->toArray();

            // Fetch full product details (images, variants, sizes, category)
            $products = Product::with([
                'category',
                'colorVariants.color',
                'colorVariants.galleryImages',
                'colorVariants.thumbnailImage',
                'colorVariants.sizeStocks',
            ])
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

            // Preserve the "most recently viewed first" order,
            // and skip any product that was since deleted.
            $ordered = $recentEntries->map(function ($entry) use ($products) {
                $product = $products->get($entry->product_id);

                if (!$product) {
                    // Product was deleted after being viewed — show the
                    // last-known snapshot instead of dropping it silently.
                    return (object) [
                        'id'            => $entry->product_id,
                        'name'          => $entry->product_name,
                        'category_id'   => $entry->category_id,
                        'category_name' => $entry->category_name,
                        'viewed_at'     => $entry->viewed_at,
                        'is_deleted'    => true,
                    ];
                }

                $product->setAttribute('viewed_at', $entry->viewed_at);
                $product->setAttribute('is_deleted', false);
                return $product;
            })->filter()->values();

            // Attach wishlist flag only for products that still exist
            $existingProducts = $ordered->filter(fn($p) => !($p->is_deleted ?? false));
            if ($existingProducts->isNotEmpty()) {
                $this->attachWishlistFlagToCollection($existingProducts, $userId);
            }

            return response()->json([
                'status' => 'success',
                'data'   => $ordered,
            ], 200);
        } catch (Exception $e) {
            Log::error('Recently Viewed Index Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to retrieve recently viewed products.'], 500);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // DELETE /recently-viewed/{userId}/{productId}
    // Remove a single product from the user's recently viewed list.
    // ═══════════════════════════════════════════════════════════════
    public function destroy($userId, $productId)
    {
        try {
            $entry = RecentlyViewedProduct::where('user_id', $userId)
                ->where('product_id', $productId)
                ->first();

            if (!$entry) {
                return response()->json(['status' => 'error', 'message' => 'Entry not found.'], 404);
            }

            $entry->delete();

            return response()->json(['status' => 'success', 'message' => 'Removed from recently viewed.'], 200);
        } catch (Exception $e) {
            Log::error('Recently Viewed Delete Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to remove entry.'], 500);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // DELETE /recently-viewed/{userId}
    // Clear the user's entire recently viewed history.
    // ═══════════════════════════════════════════════════════════════
    public function clear($userId)
    {
        try {
            RecentlyViewedProduct::where('user_id', $userId)->delete();

            return response()->json(['status' => 'success', 'message' => 'Recently viewed history cleared.'], 200);
        } catch (Exception $e) {
            Log::error('Recently Viewed Clear Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to clear history.'], 500);
        }
    }
}