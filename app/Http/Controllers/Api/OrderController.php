<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductSizeStock;
use App\Models\CartWishlistData;
use App\Models\Transaction;
use App\Services\WhatsAppService;
use App\Services\DelhiveryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Exception;
use App\Mail\InvoiceMail;
use Illuminate\Support\Facades\Mail;

class OrderController extends Controller
{
    public function __construct(
        protected WhatsAppService $whatsAppService,
        protected DelhiveryService $delhivery
    ) {}

    private const DELIVERY_STATUSES = ['Packed', 'Shipped', 'In Transit', 'Out For Delivery', 'Delivered', 'RTO', 'Cancelled', 'Refunded'];
    private const PAYMENT_STATUSES = ['Pending', 'Paid', 'Failed', 'Refunded'];
    private const NON_CANCELLABLE_STATUSES = ['Delivered', 'Cancelled', 'Refunded'];

    // TAX DISABLED: `tax` is always persisted as 0.0 and never added to `amount`.
    private const FALLBACK_SHIPPING_CHARGE = 49.0;

    private const COUPONS = [
        'SAVE10' => 10,
        'SAVE20' => 20,
        'FLAT50' => 50,
    ];

    private const ITEM_DETAIL_RELATIONS = [
        'items.product.category',
        'items.product.colorVariants.color',
        'items.productColorVariant.color',
        'items.productColorVariant.galleryImages',
        'items.productColorVariant.thumbnailImage',
        'items.productSizeStock',
    ];

    private function warehousePincode(): string
    {
        return config('services.delhivery.origin_pin', '641603');
    }

    // Format: FLYODR-MMDD&A00001
    private function generateOrderId(): string
    {
        $current = DB::transaction(function () {
            $row = DB::table('order_sequences')->lockForUpdate()->first();
            if (!$row) {
                DB::table('order_sequences')->insert([
                    'current_number' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                return 1;
            }
            $next = $row->current_number + 1;
            DB::table('order_sequences')->where('id', $row->id)->update([
                'current_number' => $next,
                'updated_at' => now(),
            ]);
            return $next;
        });

        $batch = intdiv($current - 1, 99999);
        $remainder = (($current - 1) % 99999) + 1;
        $letter = chr(65 + ($batch % 26));
        $datePart = now()->format('md');
        $seqPart = $letter . str_pad((string) $remainder, 5, '0', STR_PAD_LEFT);

        return 'FLYODR-' . $datePart . '&' . $seqPart;
    }

    // Format: INV-00001
    private function generateInvoiceNumber(): string
    {
        $next = DB::transaction(function () {
            $row = DB::table('invoice_sequences')->lockForUpdate()->first();
            if (!$row) {
                DB::table('invoice_sequences')->insert([
                    'current_number' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                return 1;
            }
            $next = $row->current_number + 1;
            DB::table('invoice_sequences')->where('id', $row->id)->update([
                'current_number' => $next,
                'updated_at' => now(),
            ]);
            return $next;
        });

        return 'INV-' . str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }

    private function clampStock($stock): int
    {
        return max(0, (int) $stock);
    }

    private function decrementStockSafely(int $sizeStockId, int $quantity): bool
    {
        $affected = DB::table('product_size_stocks')
            ->where('id', $sizeStockId)
            ->where('stock', '>=', $quantity)
            ->decrement('stock', $quantity);

        return $affected > 0;
    }

    // Server-side effective (discounted) unit price. Never trust client prices.
    private function calculateEffectiveUnitPrice(Product $product, ?ProductSizeStock $sizeStock): float
    {
        $basePrice = (float) ($sizeStock->price ?? $product->unit_price ?? 0);
        $discount = (float) ($product->discount ?? 0);

        if ($discount <= 0) {
            return round($basePrice, 2);
        }

        $now = now();
        if ($product->discount_start_date && $now->lt($product->discount_start_date)) {
            return round($basePrice, 2);
        }
        if ($product->discount_end_date && $now->gt($product->discount_end_date)) {
            return round($basePrice, 2);
        }

        $discounted = $product->discount_type === 'percent'
            ? $basePrice - ($basePrice * $discount / 100)
            : $basePrice - $discount;

        return round(max(0, $discounted), 2);
    }

    private function resolveCouponDiscount(?string $couponCode, float $subtotal): array
    {
        $code = strtoupper(trim((string) $couponCode));
        if ($code === '' || !isset(self::COUPONS[$code])) {
            return [null, 0.0];
        }

        $percent = self::COUPONS[$code];
        $discount = round(($subtotal * $percent) / 100, 2);

        return [$code, $discount];
    }

    private function attachFullItemDetails($orderOrOrders)
    {
        $isPaginator = method_exists($orderOrOrders, 'getCollection');
        $orders = $isPaginator
            ? $orderOrOrders->getCollection()
            : ($orderOrOrders instanceof \Illuminate\Support\Collection ? $orderOrOrders : collect([$orderOrOrders]));

        foreach ($orders as $order) {
            if (!$order->relationLoaded('items')) {
                continue;
            }

            $order->items->each(function ($item) {
                $product = $item->relationLoaded('product') ? $item->product : null;
                $colorVariant = $item->relationLoaded('productColorVariant') ? $item->productColorVariant : null;
                $sizeStock = $item->relationLoaded('productSizeStock') ? $item->productSizeStock : null;

                $thumbnail = $colorVariant?->thumbnailImage?->image_url ?? null;
                $gallery = ($colorVariant && $colorVariant->galleryImages)
                    ? $colorVariant->galleryImages->pluck('image_url')->values()
                    : collect([]);

                $item->setAttribute('product_details', [
                    'product_id' => $product->id ?? $item->product_id,
                    'name' => $product->name ?? $item->product_name,
                    'brand' => $product->brand ?? null,
                    'description' => $product->description ?? null,
                    'unit' => $product->unit ?? null,
                    'weight' => $product->weight ?? null,
                    'unit_price' => $product->unit_price ?? null,
                    'is_published' => $product->is_published ?? null,
                    'category' => ($product && $product->category) ? [
                        'id' => $product->category->id,
                        'name' => $product->category->name,
                    ] : null,
                    'color' => [
                        'name' => $colorVariant?->color?->name ?? $item->color,
                        'thumbnail_image' => $thumbnail,
                        'gallery_images' => $gallery,
                    ],
                    'size_stock' => $sizeStock ? [
                        'id' => $sizeStock->id,
                        'size' => $sizeStock->size,
                        'sku' => $sizeStock->sku,
                        'price' => $sizeStock->price,
                        'stock' => $this->clampStock($sizeStock->stock),
                    ] : [
                        'id' => $item->product_size_stock_id,
                        'size' => $item->size,
                        'sku' => null,
                        'price' => $item->price,
                        'stock' => null,
                    ],
                ]);
            });
        }

        return $orderOrOrders;
    }

    // ═══════════════════════════════════════════════════════════════
    // GET /orders/check-stock
    // ═══════════════════════════════════════════════════════════════
    public function checkStock(Request $request)
    {
        try {
            $validated = $request->validate([
                'product_id' => 'required|exists:products,id',
                'product_size_stock_id' => 'nullable|exists:product_size_stocks,id',
                'quantity' => 'nullable|integer|min:1',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        }

        try {
            $product = Product::find($validated['product_id']);
            $requestedQty = $validated['quantity'] ?? 1;

            if (!empty($validated['product_size_stock_id'])) {
                $sizeStock = ProductSizeStock::find($validated['product_size_stock_id']);
                if (!$sizeStock) {
                    return response()->json(['status' => 'error', 'message' => 'Size/stock variant not found.'], 404);
                }

                $available = $this->clampStock($sizeStock->stock);

                return response()->json([
                    'status' => 'success',
                    'data' => [
                        'product_id' => $product->id,
                        'product_size_stock_id' => $sizeStock->id,
                        'size' => $sizeStock->size,
                        'available_stock' => $available,
                        'requested_quantity' => $requestedQty,
                        'in_stock' => $available > 0,
                        'can_fulfill_quantity' => $available >= $requestedQty,
                    ],
                ], 200);
            }

            return response()->json([
                'status' => 'success',
                'data' => [
                    'product_id' => $product->id,
                    'message' => 'This product requires a size/color selection to check stock.',
                ],
            ], 200);
        } catch (Exception $e) {
            Log::error('Check Stock Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to check stock.'], 500);
        }
    }

    private function resolveCheckoutItems(array $rawItems): array
    {
        $resolved = [];

        foreach ($rawItems as $line) {
            $product = Product::find($line['product_id']);
            if (!$product) {
                throw new Exception("Product #{$line['product_id']} no longer exists.");
            }

            $quantity = $line['quantity'] ?? 1;
            $sizeStock = null;

            if (!empty($line['product_size_stock_id'])) {
                $sizeStock = ProductSizeStock::where('id', $line['product_size_stock_id'])->lockForUpdate()->first();
                if (!$sizeStock) {
                    throw new Exception("Selected size/stock for '{$product->name}' no longer exists.");
                }

                $availableStock = $this->clampStock($sizeStock->stock);
                if ($availableStock <= 0) {
                    throw new Exception("'{$product->name}' ({$sizeStock->size}) is out of stock.");
                }
                if ($availableStock < $quantity) {
                    throw new Exception("Insufficient stock for '{$product->name}' ({$sizeStock->size}). Only {$availableStock} left.");
                }
            }

            $mrp = round((float) ($sizeStock->price ?? $product->unit_price ?? 0), 2);
            $unitPrice = $this->calculateEffectiveUnitPrice($product, $sizeStock);

            $colorName = null;
            $sizeName = $sizeStock->size ?? null;

            if (!empty($line['product_color_variant_id'])) {
                $colorVariant = $product->colorVariants()->with('color')->find($line['product_color_variant_id']);
                $colorName = $colorVariant?->color?->name ?? null;
            }

            $resolved[] = [
                'product_id' => $product->id,
                'product_color_variant_id' => $line['product_color_variant_id'] ?? null,
                'product_size_stock_id' => $sizeStock->id ?? null,
                'product_name' => $product->name,
                'color' => $colorName,
                'size' => $sizeName,
                'weight_kg' => (float) ($product->weight ?? 0.3),
                'mrp' => $mrp,
                'mrp_total' => round($mrp * $quantity, 2),
                'price' => $unitPrice,
                'quantity' => $quantity,
                'total' => round($unitPrice * $quantity, 2),
            ];
        }

        return $resolved;
    }

    private function calculateWeightFromLines(array $lines): int
    {
        $totalGrams = 0;
        foreach ($lines as $line) {
            $totalGrams += ($line['weight_kg'] ?? 0.3) * 1000 * $line['quantity'];
        }

        return max(1, (int) round($totalGrams));
    }

    private function calculateOrderWeight(Order $order): int
    {
        $items = $order->items()->with('product')->get();
        $totalGrams = 0;

        foreach ($items as $item) {
            $weightKg = (float) ($item->product->weight ?? 0.3);
            $totalGrams += $weightKg * 1000 * $item->quantity;
        }

        return $totalGrams > 0
            ? (int) round($totalGrams)
            : (int) config('services.delhivery.default_weight_grams', 500);
    }

    private function extractPincodeFromAddress(?string $address): ?string
    {
        if ($address && preg_match('/(\d{6})/', $address, $m)) {
            return $m[1];
        }

        return null;
    }

    private function resolveShippingCharge(
        Order $order,
        float $taxableAmount,
        string $paymentMethod
    ): float {
        $pincode = $order->shipping_pincode ?: $this->extractPincodeFromAddress($order->shipping_address);
        if (!$pincode) {
            return self::FALLBACK_SHIPPING_CHARGE;
        }

        $weightGrams = $this->calculateOrderWeight($order);
        $paymentMode = strtolower($paymentMethod) === 'cod' ? 'COD' : 'Prepaid';
        $codAmount = $paymentMode === 'COD' ? $taxableAmount : 0;

        try {
            $result = $this->delhivery->calculateShippingCost(
                $this->warehousePincode(),
                $pincode,
                $weightGrams,
                $paymentMode,
                $codAmount
            );

            if ($result['success'] ?? false) {
                $charge = (float) ($result['data']['total_amount'] ?? $result['total_amount'] ?? self::FALLBACK_SHIPPING_CHARGE);
                return round($charge, 2);
            }
        } catch (Exception $e) {
            Log::error('Shipping quote failed for order ' . $order->id . ': ' . $e->getMessage());
        }

        return self::FALLBACK_SHIPPING_CHARGE;
    }

    // ═══════════════════════════════════════════════════════════════
    // POST /orders/checkout
    //
    // Creates the order in an UNPLACED state (placed_at = null). It only
    // becomes a "placed" order once the customer completes payment
    // (Razorpay -> payment_status 'Paid') or confirms Cash on Delivery
    // (confirmCod sets placed_at). Until then it appears in the
    // upcoming-orders API, not the main orders list.
    // ═══════════════════════════════════════════════════════════════
    public function checkout(Request $request)
    {
        try {
            $validated = $request->validate([
                'user_id' => 'required|string|exists:fly_users,user_id',
                'customer_name' => 'required|string|max:255',
                'customer_email' => 'required|email|max:255',
                'customer_phone' => 'nullable|string|max:20',
                'seller_name' => 'nullable|string|max:255',
                'payment_method' => 'required|string|max:50',
                'shipping_address' => 'required|string',
                'shipping_pincode' => 'nullable|digits:6',
                'billing_address' => 'nullable|string',
                'coupon_code' => 'nullable|string|max:30',
                'transaction_id' => 'nullable|exists:transactions,id',
                'items' => 'sometimes|array|min:1',
                'items.*.product_id' => 'required_with:items|exists:products,id',
                'items.*.product_color_variant_id' => 'nullable|exists:product_color_variants,id',
                'items.*.product_size_stock_id' => 'nullable|exists:product_size_stocks,id',
                'items.*.quantity' => 'nullable|integer|min:1',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        }

        DB::beginTransaction();

        try {
            $userId = $validated['user_id'];
            $usedCart = false;

            if (!empty($validated['items'])) {
                $rawItems = $validated['items'];
            } else {
                $cartItems = CartWishlistData::where('user_id', $userId)
                    ->where('type', 'cart')
                    ->get();

                if ($cartItems->isEmpty()) {
                    DB::rollBack();
                    return response()->json(['status' => 'error', 'message' => 'Cart is empty.'], 422);
                }

                $rawItems = $cartItems->map(function ($item) {
                    return [
                        'product_id' => $item->product_id,
                        'product_color_variant_id' => $item->product_color_variant_id,
                        'product_size_stock_id' => $item->product_size_stock_id,
                        'quantity' => $item->quantity,
                    ];
                })->toArray();
                $usedCart = true;
            }

            $lines = $this->resolveCheckoutItems($rawItems);

            $subtotal = round(array_sum(array_column($lines, 'mrp_total')), 2);
            $productDiscount = round($subtotal - array_sum(array_column($lines, 'total')), 2);

            [$couponCode, $couponDiscount] = $this->resolveCouponDiscount(
                $validated['coupon_code'] ?? null,
                round($subtotal - $productDiscount, 2),
            );

            $discount = round($productDiscount + $couponDiscount, 2);
            $taxableAmount = round($subtotal - $discount, 2);

            $tax = 0.0;
            $shippingCharge = self::FALLBACK_SHIPPING_CHARGE;
            $amount = round($taxableAmount + $shippingCharge, 2);

            if (round($taxableAmount, 2) < 0) {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => 'Discount cannot exceed order subtotal.'], 422);
            }

            $isCod = strtolower($validated['payment_method']) === 'cod';

            $order = Order::create([
                'order_id' => $this->generateOrderId(),
                'customer_id' => $userId,
                'customer_name' => $validated['customer_name'],
                'customer_email' => $validated['customer_email'],
                'customer_phone' => $validated['customer_phone'] ?? null,
                'seller_name' => $validated['seller_name'] ?? null,
                'amount' => $amount,
                'subtotal' => $subtotal,
                'discount' => $discount,
                'shipping' => $shippingCharge,
                'tax' => $tax,
                'delivery_status' => 'Pending',
                'payment_method' => $validated['payment_method'],
                'payment_status' => 'Pending',
                'shipping_address' => $validated['shipping_address'],
                'shipping_pincode' => $validated['shipping_pincode'] ?? $this->extractPincodeFromAddress($validated['shipping_address']),
                'billing_address' => $validated['billing_address'] ?? null,
                // COD sent directly at checkout is already "placed";
                // anything else stays upcoming until payment completes.
                'placed_at' => $isCod ? now() : null,
            ]);

            if (!empty($validated['transaction_id'])) {
                Transaction::where('id', $validated['transaction_id'])
                    ->whereNull('order_table_id')
                    ->update(['order_table_id' => $order->id]);
            }

            foreach ($lines as $line) {
                OrderItem::create([
                    'order_table_id' => $order->id,
                    'product_id' => $line['product_id'],
                    'product_color_variant_id' => $line['product_color_variant_id'],
                    'product_size_stock_id' => $line['product_size_stock_id'],
                    'product_name' => $line['product_name'],
                    'color' => $line['color'],
                    'size' => $line['size'],
                    'price' => $line['price'],
                    'quantity' => $line['quantity'],
                    'total' => $line['total'],
                ]);

                if ($line['product_size_stock_id']) {
                    $success = $this->decrementStockSafely($line['product_size_stock_id'], $line['quantity']);
                    if (!$success) {
                        throw new Exception("'{$line['product_name']}' ({$line['size']}) just went out of stock. Please try again.");
                    }
                }
            }

            $order->refresh();
            $liveShippingCharge = $this->resolveShippingCharge($order, $taxableAmount, $validated['payment_method']);
            if ($liveShippingCharge !== $shippingCharge) {
                $amount = round($taxableAmount + $liveShippingCharge, 2);
                $order->shipping = $liveShippingCharge;
                $order->amount = $amount;
                $order->save();
            }

            if ($usedCart) {
                CartWishlistData::where('user_id', $userId)->where('type', 'cart')->delete();
            }

            DB::commit();

            if ($isCod) {
                $this->maybeSendInvoice($order->fresh()->load('items.productSizeStock'));
            }

            return response()->json([
                'status' => 'success',
                'message' => $isCod ? 'Order placed successfully.' : 'Order created. Complete payment to place it.',
                'data' => $order->load('items'),
            ], 201);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Order Checkout Error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage() ?: 'Failed to place order.',
            ], 500);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // GET /orders/{id}/shipping-quote?payment_method=cod|razorpay
    // ═══════════════════════════════════════════════════════════════
    public function shippingQuote(Request $request, $id)
    {
        $validated = $request->validate([
            'payment_method' => 'required|in:cod,razorpay',
        ]);

        try {
            $order = Order::with('items')->findOrFail($id);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'Order not found.'], 404);
        }

        try {
            $taxableAmount = round((float) $order->subtotal - (float) $order->discount, 2);
            $shippingCharge = $this->resolveShippingCharge($order, $taxableAmount, $validated['payment_method']);
            $tax = 0.0;
            $amount = round($taxableAmount + $shippingCharge, 2);

            $order->shipping = $shippingCharge;
            $order->tax = $tax;
            $order->amount = $amount;
            $order->payment_method = $validated['payment_method'];
            $order->save();

            return response()->json([
                'status' => 'success',
                'data' => [
                    'shipping' => $shippingCharge,
                    'tax' => $tax,
                    'subtotal' => (float) $order->subtotal,
                    'discount' => (float) $order->discount,
                    'amount' => $amount,
                ],
            ], 200);
        } catch (Exception $e) {
            Log::error('Shipping Quote Error (Order #' . $order->id . '): ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to calculate shipping.'], 500);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // POST /orders/{id}/cod-confirm
    // Confirming COD is what turns an upcoming order into a placed one.
    // ═══════════════════════════════════════════════════════════════
    public function confirmCod(Request $request, $id)
    {
        try {
            $order = Order::with('items')->findOrFail($id);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'Order not found.'], 404);
        }

        if ($order->payment_status === 'Paid') {
            return response()->json([
                'status' => 'error',
                'message' => 'This order has already been paid for.',
            ], 422);
        }

        if (in_array($order->delivery_status, self::NON_CANCELLABLE_STATUSES)) {
            return response()->json([
                'status' => 'error',
                'message' => "Order cannot be confirmed because it is already '{$order->delivery_status}'.",
            ], 422);
        }

        try {
            $taxableAmount = round((float) $order->subtotal - (float) $order->discount, 2);
            $shippingCharge = $this->resolveShippingCharge($order, $taxableAmount, 'cod');
            $tax = 0.0;
            $amount = round($taxableAmount + $shippingCharge, 2);

            $order->payment_method = 'cod';
            $order->payment_status = 'Pending';
            $order->shipping = $shippingCharge;
            $order->tax = $tax;
            $order->amount = $amount;
            if (empty($order->placed_at)) {
                $order->placed_at = now(); // order is now officially placed
            }
            $order->save();

            $this->maybeSendInvoice($order->fresh()->load('items.productSizeStock'));

            return response()->json([
                'status' => 'success',
                'message' => 'Order confirmed for Cash on Delivery.',
                'data' => $order->load('items'),
            ], 200);
        } catch (Exception $e) {
            Log::error('Order COD Confirm Error (Order #' . $order->id . '): ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to confirm Cash on Delivery order.'], 500);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // PRIVATE HELPER: Shared search/status/date filters for list endpoints.
    // ═══════════════════════════════════════════════════════════════
    private function applyListFilters($query, Request $request)
    {
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('order_id', 'like', "%{$search}%")
                    ->orWhere('invoice_number', 'like', "%{$search}%")
                    ->orWhere('awb_number', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhere('customer_email', 'like', "%{$search}%")
                    ->orWhere('seller_name', 'like', "%{$search}%")
                    ->orWhere('delivery_status', 'like', "%{$search}%")
                    ->orWhere('payment_method', 'like', "%{$search}%")
                    ->orWhere('payment_status', 'like', "%{$search}%");
            });
        }
        if ($request->filled('delivery_status')) {
            $query->where('delivery_status', $request->delivery_status);
        }
        if ($request->filled('payment_status')) {
            $query->where('payment_status', $request->payment_status);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        return $query->orderBy('created_at', 'desc');
    }

    private function listResponse($query, Request $request)
    {
        $perPage = $request->query('per_page');
        if ($perPage && strtolower((string) $perPage) !== 'all' && (int) $perPage > 0) {
            return response()->json(['status' => 'success', 'data' => $query->paginate((int) $perPage)], 200);
        }

        $orders = $query->get();

        return response()->json([
            'status' => 'success',
            'data' => ['data' => $orders, 'total' => $orders->count()],
        ], 200);
    }

    // ═══════════════════════════════════════════════════════════════
    // GET /orders — Admin list. ONLY PLACED orders (paid, or COD-confirmed).
    // ═══════════════════════════════════════════════════════════════
    public function index(Request $request)
    {
        try {
            $query = Order::placed()->withCount('items');
            $this->applyListFilters($query, $request);

            return $this->listResponse($query, $request);
        } catch (Exception $e) {
            Log::error('Order Index Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to retrieve orders.'], 500);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // GET /orders/upcoming — Orders that were started (checkout reached)
    // but NOT yet placed: no completed payment and no COD confirmation.
    // Optional: ?user_id=... to scope to one customer.
    // ═══════════════════════════════════════════════════════════════
    public function upcomingOrders(Request $request)
    {
        try {
            $query = Order::upcoming()->withCount('items');

            if ($request->filled('user_id')) {
                $query->where('customer_id', $request->user_id);
            }

            $this->applyListFilters($query, $request);

            return $this->listResponse($query, $request);
        } catch (Exception $e) {
            Log::error('Upcoming Orders Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to retrieve upcoming orders.'], 500);
        }
    }

    // GET /orders/{id}
    public function show($id)
    {
        try {
            $order = Order::with(array_merge(['items', 'customer'], self::ITEM_DETAIL_RELATIONS))->findOrFail($id);
            $this->attachFullItemDetails($order);

            return response()->json(['status' => 'success', 'data' => $order], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'Order not found.'], 404);
        } catch (Exception $e) {
            Log::error('Order Show Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to retrieve order details.'], 500);
        }
    }

    // GET /users/{userId}/orders — customer's placed orders only
    public function myOrders(Request $request, $userId)
    {
        try {
            $query = Order::placed()->with(array_merge(['items'], self::ITEM_DETAIL_RELATIONS))->where('customer_id', $userId);

            if ($request->filled('delivery_status')) {
                $query->where('delivery_status', $request->delivery_status);
            }

            $orders = $query->orderBy('created_at', 'desc')->get();
            $this->attachFullItemDetails($orders);

            return response()->json(['status' => 'success', 'data' => $orders], 200);
        } catch (Exception $e) {
            Log::error('My Orders Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to retrieve your orders.'], 500);
        }
    }

    private function buildInvoiceData(Order $order): array
    {
        if (empty($order->invoice_number)) {
            $order->invoice_number = $this->generateInvoiceNumber();
            $order->invoice_date = now();
            $order->save();
        }

        $company = config('company', []);

        $items = $order->items->map(function ($item) {
            $description = $item->product_name;
            if ($item->color) {
                $description .= ' - ' . $item->color;
            }
            if ($item->size) {
                $description .= ' (' . $item->size . ')';
            }

            return [
                'description' => $description,
                'sku' => $item->productSizeStock?->sku ?? null,
                'qty' => (int) $item->quantity,
                'rate' => (float) $item->price,
                'amount' => (float) $item->total,
            ];
        })->values()->all();

        return [
            'company' => [
                'name' => $company['name'] ?? 'Flybirds',
                'tagline' => $company['tagline'] ?? null,
                'address_line1' => $company['address_line1'] ?? '',
                'address_line2' => $company['address_line2'] ?? '',
                'city_state_zip' => $company['city_state_zip'] ?? '',
                'email' => $company['email'] ?? '',
                'phone' => $company['phone'] ?? '',
                'website' => $company['website'] ?? '',
                'logo_url' => $company['logo_url'] ?? '',
                'gstin' => $company['gstin'] ?? '',
            ],
            'invoice_no' => $order->invoice_number,
            'invoice_date' => optional($order->invoice_date)->format('Y-m-d H:i:s'),
            'order_id' => $order->order_id,
            'sale_date' => optional($order->created_at)->format('Y-m-d H:i:s'),
            'awb_number' => $order->awb_number,
            'payment_method' => $order->payment_method,
            'payment_status' => $order->payment_status,
            'shipping' => [
                'name' => $order->customer_name,
                'address' => $order->shipping_address,
                'email' => $order->customer_email,
            ],
            'billing' => [
                'name' => $order->customer_name,
                'address' => $order->billing_address ?: $order->shipping_address,
                'email' => $order->customer_email,
            ],
            'items' => $items,
            'subtotal' => (float) $order->subtotal,
            'discount' => (float) $order->discount,
            'shipping_charge' => (float) $order->shipping,
            'tax' => (float) $order->tax,
            'total' => (float) $order->amount,
        ];
    }

    private function resolveInvoicePdfUrl(Order $order, array $invoiceData): ?string
    {
        try {
            $pdf = \PDF::loadView('invoices.pdf', ['data' => $invoiceData]);
            $relativePath = 'invoices/' . $order->order_id . '.pdf';

            Storage::disk('s3')->put($relativePath, $pdf->output());

            return Storage::disk('s3')->temporaryUrl(
                $relativePath,
                now()->addMinutes(30)
            );
        } catch (Exception $e) {
            Log::error('Invoice PDF generation for WhatsApp failed (Order #' . $order->id . '): ' . $e->getMessage());
            return null;
        }
    }

    private function sendInvoiceWhatsApp(Order $order, array $invoiceData): void
    {
        try {
            if (empty($order->customer_phone)) {
                Log::info('Invoice WhatsApp skipped — no phone on file for Order #' . $order->id);
                return;
            }

            $template = config('whatsapp_templates.invoice');
            $waPhone = '91' . ltrim($order->customer_phone, '0');
            $pdfUrl = $this->resolveInvoicePdfUrl($order, $invoiceData);

            if (!$pdfUrl) {
                Log::error('WhatsApp invoice skipped — no PDF URL for Order #' . $order->id);
                return;
            }

            $bodyParams = [
                $order->customer_name,
                number_format((float) $order->amount, 2),
                $order->order_id,
                optional($order->invoice_date ?? $order->created_at)->format('d M Y')
                    ?? now()->format('d M Y'),
            ];

            $filename = 'Invoice-' . $order->order_id . '.pdf';

            Log::info('WhatsApp invoice send attempt', [
                'to' => $waPhone,
                'template' => $template['name'],
                'language' => $template['language'],
                'params' => $bodyParams,
                'pdf_url' => $pdfUrl,
                'filename' => $filename,
            ]);

            $sent = $this->whatsAppService->sendTemplateMessage(
                to: $waPhone,
                templateName: $template['name'],
                language: $template['language'],
                bodyParams: $bodyParams,
                documentUrl: $pdfUrl,
                documentFilename: $filename,
            );

            if (!$sent) {
                Log::error('WhatsApp invoice send failed for Order #' . $order->id);
            }
        } catch (Exception $e) {
            Log::error('Invoice WhatsApp Error (Order #' . $order->id . '): ' . $e->getMessage());
        }
    }

    // GET /orders/{id}/invoice
    public function invoice($id)
    {
        try {
            $order = Order::placed()->with(['items.productSizeStock'])->findOrFail($id);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'Order not found.'], 404);
        }

        try {
            $data = $this->buildInvoiceData($order);
            return response()->json(['status' => 'success', 'data' => $data], 200);
        } catch (Exception $e) {
            Log::error('Order Invoice Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to generate invoice.'], 500);
        }
    }

    // POST /orders/{id}/invoice-mail
    public function invoiceMail($id)
    {
        try {
            $order = Order::placed()->with(['items.productSizeStock'])->findOrFail($id);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'Order not found.'], 404);
        }

        try {
            $data = $this->buildInvoiceData($order);
            Mail::to($order->customer_email)->send(new InvoiceMail($order, $data));
            $this->sendInvoiceWhatsApp($order, $data);

            return response()->json([
                'status' => 'success',
                'message' => 'Invoice emailed to ' . $order->customer_email,
            ], 200);
        } catch (Exception $e) {
            Log::error('Invoice Mail Error (Order #' . $order->id . '): ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to send invoice email.'], 500);
        }
    }

    private function sendInvoiceEmail(Order $order): void
    {
        try {
            $order->loadMissing('items.productSizeStock');
            $data = $this->buildInvoiceData($order);
            Mail::to($order->customer_email)->send(new InvoiceMail($order, $data));
            $this->sendInvoiceWhatsApp($order, $data);
        } catch (Exception $e) {
            Log::error('Invoice Email Error (Order #' . $order->id . '): ' . $e->getMessage());
        }
    }

    // Idempotent: invoice_number is the "already sent" guard.
    private function maybeSendInvoice(Order $order): void
    {
        if (!empty($order->invoice_number)) {
            return;
        }

        $this->sendInvoiceEmail($order);
    }

    // PATCH /orders/{id}/status
    public function updateStatus(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'delivery_status' => 'sometimes|string|in:' . implode(',', self::DELIVERY_STATUSES),
            'payment_status' => 'sometimes|string|in:' . implode(',', self::PAYMENT_STATUSES),
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        if (!$request->filled('delivery_status') && !$request->filled('payment_status')) {
            return response()->json([
                'status' => 'error',
                'message' => 'Provide at least one of delivery_status or payment_status.',
            ], 422);
        }

        try {
            $order = Order::findOrFail($id);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'Order not found.'], 404);
        }

        if (in_array($order->delivery_status, self::NON_CANCELLABLE_STATUSES) && $request->filled('delivery_status')) {
            if ($order->delivery_status !== $request->delivery_status) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Order is already '{$order->delivery_status}' and cannot be changed further.",
                ], 422);
            }
        }

        try {
            $wasPaid = $order->payment_status === 'Paid';

            if ($request->filled('delivery_status')) {
                $order->delivery_status = $request->delivery_status;
            }
            if ($request->filled('payment_status')) {
                $order->payment_status = $request->payment_status;
            }

            // Payment completed => order is placed.
            if ($order->payment_status === 'Paid' && empty($order->placed_at)) {
                $order->placed_at = now();
            }

            $order->save();

            if (!$wasPaid && $order->payment_status === 'Paid') {
                $this->maybeSendInvoice($order->fresh());
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Order status updated successfully.',
                'data' => $order->load('items'),
            ], 200);
        } catch (Exception $e) {
            Log::error('Order Status Update Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to update order status.'], 500);
        }
    }

    // POST /orders/{id}/cancel
    public function cancel(Request $request, $id)
    {
        try {
            $order = Order::with('items')->findOrFail($id);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'Order not found.'], 404);
        }

        if (in_array($order->delivery_status, self::NON_CANCELLABLE_STATUSES)) {
            return response()->json([
                'status' => 'error',
                'message' => "Order cannot be cancelled because it is already '{$order->delivery_status}'.",
            ], 422);
        }

        DB::beginTransaction();

        try {
            foreach ($order->items as $item) {
                if ($item->product_size_stock_id) {
                    $sizeStock = ProductSizeStock::find($item->product_size_stock_id);
                    if ($sizeStock) {
                        $sizeStock->increment('stock', $item->quantity);
                    } else {
                        Log::warning("Cancel Order #{$order->id}: size stock #{$item->product_size_stock_id} no longer exists, skipped stock reversal.");
                    }
                }
            }

            $order->delivery_status = 'Cancelled';
            if ($order->payment_status === 'Paid') {
                $order->payment_status = 'Refunded';
            }
            $order->save();

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Order cancelled successfully.',
                'data' => $order->load('items'),
            ], 200);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Order Cancel Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to cancel order.'], 500);
        }
    }

    // DELETE /orders/{id}
    public function destroy($id)
    {
        try {
            $order = Order::findOrFail($id);
            $order->delete();

            return response()->json(['status' => 'success', 'message' => 'Order deleted successfully.'], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'Order not found.'], 404);
        } catch (Exception $e) {
            Log::error('Order Delete Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to delete order.'], 500);
        }
    }
}
