<?php
namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\cart_wishlist;
use App\Models\Product;
use App\Models\ProductColorVariant;
use App\Models\ProductSizeStock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Exception;

class cart_whishlist extends Controller
{
    // ═══════════════════════════════════════════════════════════════
    // POST /admin/users/{userId}/cart
    // Add a product to CART
    // ═══════════════════════════════════════════════════════════════
    public function addToCart(Request $request, $userId)
    {
        try {
            $validated = $request->validate([
                'product_id'                => 'required|exists:products,id',
                'product_color_variant_id'  => 'nullable|exists:product_color_variants,id',
                'product_size_stock_id'     => 'nullable|exists:product_size_stocks,id',
                'quantity'                  => 'nullable|integer|min:1',
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
            $product = Product::find($validated['product_id']);
            if (!$product) {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => 'Product not found.'], 404);
            }

            if (!empty($validated['product_color_variant_id'])) {
                $colorVariant = ProductColorVariant::where('id', $validated['product_color_variant_id'])
                    ->where('product_id', $product->id)
                    ->first();
                if (!$colorVariant) {
                    DB::rollBack();
                    return response()->json([
                        'status'  => 'error',
                        'message' => 'Selected color does not belong to this product.'
                    ], 422);
                }
            }

            if (!empty($validated['product_size_stock_id'])) {
                $sizeStockQuery = ProductSizeStock::where('id', $validated['product_size_stock_id']);
                if (!empty($validated['product_color_variant_id'])) {
                    $sizeStockQuery->where('product_color_variant_id', $validated['product_color_variant_id']);
                }
                $sizeStock = $sizeStockQuery->first();

                if (!$sizeStock) {
                    DB::rollBack();
                    return response()->json([
                        'status'  => 'error',
                        'message' => 'Selected size does not belong to the selected color/product.'
                    ], 422);
                }

                $requestedQty = $validated['quantity'] ?? 1;
                if ($sizeStock->stock < $requestedQty) {
                    DB::rollBack();
                    return response()->json([
                        'status'  => 'error',
                        'message' => 'Insufficient stock available.'
                    ], 422);
                }
            }

            $existing = cart_wishlist::where('user_id', $userId)
                ->where('product_id', $validated['product_id'])
                ->where('product_color_variant_id', $validated['product_color_variant_id'] ?? null)
                ->where('product_size_stock_id', $validated['product_size_stock_id'] ?? null)
                ->where('type', 'cart')
                ->first();

            if ($existing) {
                $existing->quantity += $validated['quantity'] ?? 1;
                $existing->save();
                $item = $existing;
            } else {
                $item = cart_wishlist::create([
                    'user_id'                   => $userId,
                    'product_id'                => $validated['product_id'],
                    'product_color_variant_id'  => $validated['product_color_variant_id'] ?? null,
                    'product_size_stock_id'     => $validated['product_size_stock_id'] ?? null,
                    'type'                      => 'cart',
                    'quantity'                  => $validated['quantity'] ?? 1,
                ]);
            }

            DB::commit();

            return response()->json([
                'status'  => 'success',
                'message' => 'Product added to cart.',
                'data'    => $item->load(['product', 'colorVariant.color', 'sizeStock']),
            ], 201);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Add To Cart Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to add product to cart.'], 500);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // GET /admin/users/{userId}/cart
    // List all CART items for a user
    // ═══════════════════════════════════════════════════════════════
    public function listCart($userId)
    {
        try {
            $cartItems = cart_wishlist::with([
                'product:id,name,unit_price,discount,discount_type',
                'colorVariant.color',
                'colorVariant.thumbnailImage',
                'sizeStock',
            ])
            ->where('user_id', $userId)
            ->where('type', 'cart')
            ->latest()
            ->get();

            $total = $cartItems->sum(function ($item) {
                $price = $item->sizeStock->price ?? $item->product->unit_price ?? 0;
                return $price * $item->quantity;
            });

            return response()->json([
                'status' => 'success',
                'data'   => [
                    'items' => $cartItems,
                    'total' => $total,
                    'count' => $cartItems->count(),
                ],
            ], 200);
        } catch (Exception $e) {
            Log::error('List Cart Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to retrieve cart items.'], 500);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // PATCH /admin/users/{userId}/cart/{id}
    // Update quantity of a CART item
    // ═══════════════════════════════════════════════════════════════
    public function updateCart(Request $request, $userId, $id)
    {
        try {
            $item = cart_wishlist::where('id', $id)
                ->where('user_id', $userId)
                ->where('type', 'cart')
                ->firstOrFail();
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'Cart item not found.'], 404);
        } catch (Exception $e) {
            Log::error('Update Cart - Lookup Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to retrieve cart item.'], 500);
        }

        try {
            $validated = $request->validate([
                'quantity' => 'required|integer|min:1',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Validation failed.',
                'errors'  => $e->errors(),
            ], 422);
        }

        try {
            if ($item->product_size_stock_id) {
                $sizeStock = ProductSizeStock::find($item->product_size_stock_id);
                if ($sizeStock && $sizeStock->stock < $validated['quantity']) {
                    return response()->json(['status' => 'error', 'message' => 'Insufficient stock available.'], 422);
                }
            }

            $item->quantity = $validated['quantity'];
            $item->save();

            return response()->json([
                'status'  => 'success',
                'message' => 'Cart item updated successfully.',
                'data'    => $item->fresh()->load(['product', 'colorVariant.color', 'sizeStock']),
            ], 200);
        } catch (Exception $e) {
            Log::error('Update Cart Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to update cart item.'], 500);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // DELETE /admin/users/{userId}/cart/{id}
    // Remove one product from CART
    // ═══════════════════════════════════════════════════════════════
    public function removeFromCart($userId, $id)
    {
        try {
            $item = cart_wishlist::where('id', $id)
                ->where('user_id', $userId)
                ->where('type', 'cart')
                ->first();

            if (!$item) {
                return response()->json(['status' => 'error', 'message' => 'Cart item not found.'], 404);
            }

            $item->delete();

            return response()->json(['status' => 'success', 'message' => 'Product removed from cart.'], 200);
        } catch (Exception $e) {
            Log::error('Remove From Cart Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to remove product from cart.'], 500);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // DELETE /admin/users/{userId}/cart
    // Clear entire CART for a user
    // ═══════════════════════════════════════════════════════════════
    public function clearCart($userId)
    {
        try {
            cart_wishlist::where('user_id', $userId)->where('type', 'cart')->delete();
            return response()->json(['status' => 'success', 'message' => 'Cart cleared successfully.'], 200);
        } catch (Exception $e) {
            Log::error('Clear Cart Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to clear cart.'], 500);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // POST /admin/users/{userId}/wishlist
    // Add a product to WISHLIST
    // ═══════════════════════════════════════════════════════════════
    public function addToWishlist(Request $request, $userId)
    {
        try {
            $validated = $request->validate([
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
            $product = Product::find($validated['product_id']);
            if (!$product) {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => 'Product not found.'], 404);
            }

            $existing = cart_wishlist::where('user_id', $userId)
                ->where('product_id', $validated['product_id'])
                ->where('type', 'wishlist')
                ->first();

            if ($existing) {
                DB::rollBack();
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Product is already in the wishlist.'
                ], 409);
            }

            $item = cart_wishlist::create([
                'user_id'    => $userId,
                'product_id' => $validated['product_id'],
                'type'       => 'wishlist',
                'quantity'   => 1,
            ]);

            DB::commit();

            return response()->json([
                'status'  => 'success',
                'message' => 'Product added to wishlist.',
                'data'    => $item->load('product'),
            ], 201);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Add To Wishlist Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to add product to wishlist.'], 500);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // GET /admin/users/{userId}/wishlist
    // List all WISHLIST items for a user
    // ═══════════════════════════════════════════════════════════════
    public function listWishlist($userId)
    {
        try {
            $wishlistItems = cart_wishlist::with([
                'product.colorVariants.color',
                'product.colorVariants.thumbnailImage',
            ])
            ->where('user_id', $userId)
            ->where('type', 'wishlist')
            ->latest()
            ->get();

            return response()->json(['status' => 'success', 'data' => $wishlistItems], 200);
        } catch (Exception $e) {
            Log::error('List Wishlist Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to retrieve wishlist.'], 500);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // DELETE /admin/users/{userId}/wishlist/{id}
    // Remove a product from WISHLIST by row id
    // ═══════════════════════════════════════════════════════════════
    public function removeFromWishlist($userId, $id)
    {
        try {
            $item = cart_wishlist::where('id', $id)
                ->where('user_id', $userId)
                ->where('type', 'wishlist')
                ->first();

            if (!$item) {
                return response()->json(['status' => 'error', 'message' => 'Wishlist item not found.'], 404);
            }

            $item->delete();

            return response()->json(['status' => 'success', 'message' => 'Product removed from wishlist.'], 200);
        } catch (Exception $e) {
            Log::error('Remove From Wishlist Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to remove product from wishlist.'], 500);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // DELETE /admin/users/{userId}/wishlist/product/{productId}
    // Remove from WISHLIST by product id (toggle-button friendly)
    // ═══════════════════════════════════════════════════════════════
    public function removeFromWishlistByProduct($userId, $productId)
    {
        try {
            $item = cart_wishlist::where('user_id', $userId)
                ->where('product_id', $productId)
                ->where('type', 'wishlist')
                ->first();

            if (!$item) {
                return response()->json(['status' => 'error', 'message' => 'Product not found in wishlist.'], 404);
            }

            $item->delete();

            return response()->json(['status' => 'success', 'message' => 'Product removed from wishlist.'], 200);
        } catch (Exception $e) {
            Log::error('Remove From Wishlist By Product Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to remove product from wishlist.'], 500);
        }
    }
}