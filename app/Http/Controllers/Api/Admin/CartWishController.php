<?php
namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\CartWishlistData;
use App\Models\Product;
use App\Models\ProductColorVariant;
use App\Models\ProductSizeStock;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CartWishController extends Controller
{
        public function addToCart(Request $request, $userId)
    {
        try {
            $validated = $request->validate([
                'product_id'                => 'required|exists:products,id',
                'product_color_variant_id'  => 'required|exists:product_color_variants,id',
                'family_color_id'           => 'required|exists:family_colors,id',
                'family_color_child_id'     => 'nullable|exists:family_color_children,id',
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
            if (!$product || !$product->is_active) {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => 'Product not found.'], 404);
            }
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
            // The family color / child color sent must match what this variant actually is
            if ((int) $colorVariant->family_color_id !== (int) $validated['family_color_id']) {
                DB::rollBack();
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Selected family color does not match this color variant.'
                ], 422);
            }
            $expectedChildId = $colorVariant->family_color_child_id;
            $providedChildId = $validated['family_color_child_id'] ?? null;
            if ($expectedChildId && (int) $expectedChildId !== (int) $providedChildId) {
                DB::rollBack();
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Selected color child does not match this color variant.'
                ], 422);
            }
            if (!$expectedChildId && $providedChildId) {
                DB::rollBack();
                return response()->json([
                    'status'  => 'error',
                    'message' => 'This color variant has no color child to select.'
                ], 422);
            }
            if (!empty($validated['product_size_stock_id'])) {
                $sizeStock = ProductSizeStock::where('id', $validated['product_size_stock_id'])
                    ->where('product_color_variant_id', $colorVariant->id)
                    ->first();
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
            $existing = CartWishlistData::where('user_id', $userId)
                ->where('product_id', $validated['product_id'])
                ->where('product_color_variant_id', $validated['product_color_variant_id'])
                ->where('product_size_stock_id', $validated['product_size_stock_id'] ?? null)
                ->where('type', 'cart')
                ->first();
            if ($existing) {
                $existing->quantity += $validated['quantity'] ?? 1;
                $existing->save();
                $item = $existing;
            } else {
                $item = CartWishlistData::create([
                    'user_id'                   => $userId,
                    'product_id'                => $validated['product_id'],
                    'product_color_variant_id'  => $validated['product_color_variant_id'],
                    'family_color_id'           => $validated['family_color_id'],
                    'family_color_child_id'     => $providedChildId,
                    'product_size_stock_id'     => $validated['product_size_stock_id'] ?? null,
                    'type'                      => 'cart',
                    'quantity'                  => $validated['quantity'] ?? 1,
                ]);
            }
            DB::commit();
            return response()->json([
                'status'  => 'success',
                'message' => 'Product added to cart.',
                'data'    => $item->load([
                    'product',
                    'colorVariant.familyColor',
                    'colorVariant.familyColorChild',
                    'colorVariant.thumbnailImage',
                    'colorVariant.galleryImages',
                    'familyColor',
                    'familyColorChild',
                    'sizeStock',
                ]),
            ], 201);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Add To Cart Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to add product to cart.'], 500);
        }
    }

    public function listCart($userId)
    {
        try {
            $cartItems = CartWishlistData::with([
                'product:id,name,unit_price,discount,discount_type',
                'product.colorVariants.familyColor',
                'product.colorVariants.familyColorChild',
                'product.colorVariants.thumbnailImage',
                'product.colorVariants.galleryImages',
                'colorVariant.familyColor',
                'colorVariant.familyColorChild',
                'colorVariant.thumbnailImage',
                'colorVariant.galleryImages',
                'familyColor',
                'familyColorChild',
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

    public function updateCart(Request $request, $userId, $id)
    {
        try {
            $item = CartWishlistData::where('id', $id)
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
                'data'    => $item->fresh()->load([
                    'product',
                    'colorVariant.familyColor',
                    'colorVariant.familyColorChild',
                    'colorVariant.thumbnailImage',
                    'colorVariant.galleryImages',
                    'familyColor',
                    'familyColorChild',
                    'sizeStock',
                ]),
            ], 200);
        } catch (Exception $e) {
            Log::error('Update Cart Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to update cart item.'], 500);
        }
    }

    public function removeFromCart($userId, $id)
    {
        try {
            $item = CartWishlistData::where('id', $id)
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

    public function clearCart($userId)
    {
        try {
            CartWishlistData::where('user_id', $userId)->where('type', 'cart')->delete();
            return response()->json(['status' => 'success', 'message' => 'Cart cleared successfully.'], 200);
        } catch (Exception $e) {
            Log::error('Clear Cart Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to clear cart.'], 500);
        }
    }

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

            $existing = CartWishlistData::where('user_id', $userId)
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

            $item = CartWishlistData::create([
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

    public function listWishlist($userId)
    {
        try {
            $wishlistItems = CartWishlistData::with([
                'product.colorVariants.color',
                'product.colorVariants.thumbnailImage',
                'product.colorVariants.galleryImages',
            ])
            ->where('user_id', $userId)
            ->where('type', 'wishlist')
            ->latest()
            ->get();

            $wishlistItems->each(function ($item) {
                if ($item->product) {
                    $item->product->setAttribute('is_wishlisted', true);
                }
            });

            return response()->json(['status' => 'success', 'data' => $wishlistItems], 200);
        } catch (Exception $e) {
            Log::error('List Wishlist Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to retrieve wishlist.'], 500);
        }
    }

    public function removeFromWishlist($userId, $id)
    {
        try {
            $item = CartWishlistData::where('id', $id)
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

    public function removeFromWishlistByProduct($userId, $productId)
    {
        try {
            $item = CartWishlistData::where('user_id', $userId)
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


    public function cartWishSummary($userId)
{
    try {
        $cartCount = CartWishlistData::where('user_id', $userId)
            ->where('type', 'cart')
            ->sum('quantity'); // use ->count() instead if you want line-item count, not total qty

        $hasWishlist = CartWishlistData::where('user_id', $userId)
            ->where('type', 'wishlist')
            ->exists();

        return response()->json([
            'status' => 'success',
            'data'   => [
                'cart_count'    => (int) $cartCount,
                'has_wishlist'  => $hasWishlist,
            ],
        ], 200);
    } catch (Exception $e) {
        Log::error('Cart/Wishlist Summary Error: ' . $e->getMessage());
        return response()->json(['status' => 'error', 'message' => 'Failed to retrieve cart/wishlist summary.'], 500);
    }
}
}
