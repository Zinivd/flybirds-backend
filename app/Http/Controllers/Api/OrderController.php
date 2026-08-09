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
    ) {
    }
    private const DELIVERY_STATUSES = ['Packed', 'Shipped', 'Out For Delivery', 'Delivered', 'RTO', 'Cancelled', 'Refunded'];
    private const PAYMENT_STATUSES = ['Pending', 'Paid', 'Failed', 'Refunded'];
    private const NON_CANCELLABLE_STATUSES = ['Delivered', 'Cancelled', 'Refunded'];
    // Product prices are GST-inclusive, so GST is never added on top here.
    private const GST_RATE = 0.18;
    // Fallback flat shipping charge, used only if Delhivery's live quote
    // can't be resolved (no pincode, or the carrier API is down).
    private const FALLBACK_SHIPPING_CHARGE = 49.0;
    private const COUPONS = [
        'SAVE10' => 10,
        'SAVE20' => 20,
        'FLAT50' => 50,
    ];
    // Relations needed to expose full product/category/image details on order items
    private const ITEM_DETAIL_RELATIONS = [
        'items.product.category',
        'items.product.colorVariants.color',
        'items.productColorVariant.color',
        'items.productColorVariant.galleryImages',
        'items.productColorVariant.thumbnailImage',
        'items.productSizeStock',
    ];
    // ═══════════════════════════════════════════════════════════════
    // PRIVATE HELPER: Warehouse/pickup pincode used for every live
    // Delhivery shipping quote — pulled from config, not hardcoded
    // per-file (both this controller and DelhiveryController must
    // agree on the same origin, or quotes will silently diverge).
    // ═══════════════════════════════════════════════════════════════
    private function warehousePincode(): string
    {
        return config('services.delhivery.origin_pin', '641603');
    }
    // ═══════════════════════════════════════════════════════════════
    // PRIVATE HELPER: Generate unique 18-char order ID
    // Format: FLYODR-MMDD&A00001  (rolls to B00001 after 99999, etc.)
    // ═══════════════════════════════════════════════════════════════
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
    // ═══════════════════════════════════════════════════════════════
    // PRIVATE HELPER: Generate a unique sequential invoice number.
    // Format: INV-00001, INV-00002, ...
    // ═══════════════════════════════════════════════════════════════
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
    // ═══════════════════════════════════════════════════════════════
    // PRIVATE HELPER: Clamp any stock number so it's never negative.
    // ═══════════════════════════════════════════════════════════════
    private function clampStock($stock): int
    {
        return max(0, (int) $stock);
    }
    // ═══════════════════════════════════════════════════════════════
    // PRIVATE HELPER: Atomically decrement stock without going below zero.
    // ═══════════════════════════════════════════════════════════════
    private function decrementStockSafely(int $sizeStockId, int $quantity): bool
    {
        $affected = DB::table('product_size_stocks')
            ->where('id', $sizeStockId)
            ->where('stock', '>=', $quantity)
            ->decrement('stock', $quantity);
        return $affected > 0;
    }
    // ═══════════════════════════════════════════════════════════════
    // PRIVATE HELPER: Compute the true, discounted, GST-inclusive unit
    // price to charge for a line item — mirrors how `effective_price`
    // is derived for product listing/detail endpoints. NEVER trust a
    // client-supplied price; always recompute it here from the product
    // and (if present) its size-stock override, honoring the discount
    // window (discount_start_date / discount_end_date).
    // ═══════════════════════════════════════════════════════════════
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
    // ═══════════════════════════════════════════════════════════════
    // PRIVATE HELPER: Resolve a coupon code into a discount amount.
    // Only a fixed, server-known whitelist of codes is honored — the
    // client can never dictate the discount amount directly.
    // ═══════════════════════════════════════════════════════════════
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
    // ═══════════════════════════════════════════════════════════════
    // PRIVATE HELPER: Attach full product/category/image detail to
    // every OrderItem on a single Order, a plain collection of Orders,
    // or a paginator of Orders — without disturbing the frozen
    // snapshot fields (product_name, color, size, price) captured at
    // checkout time. Adds a 'product_details' attribute to each item.
    // ═══════════════════════════════════════════════════════════════
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
    // GET /orders/check-stock?product_id=5&product_size_stock_id=34&quantity=2
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
    // ═══════════════════════════════════════════════════════════════
    // PRIVATE HELPER: Resolve the checkout line items. Prices are ALWAYS
    // recomputed server-side from the product/size-stock records — a
    // client can never dictate the unit price, discount, shipping, or
    // tax that ends up on the order.
    // ═══════════════════════════════════════════════════════════════
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
            $unitPrice = $this->calculateEffectiveUnitPrice($product, $sizeStock); // discounted price
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
                'weight_kg' => (float) ($product->weight ?? 0.3), // used to compute shipping weight
                'mrp' => $mrp,
                'mrp_total' => round($mrp * $quantity, 2),
                'price' => $unitPrice,             // discounted unit price — stored on OrderItem
                'quantity' => $quantity,
                'total' => round($unitPrice * $quantity, 2), // discounted line total — stored on OrderItem
            ];
        }
        return $resolved;
    }
    // ═══════════════════════════════════════════════════════════════
    // PRIVATE HELPER: Total shipment weight in grams from resolved
    // checkout lines (used before the Order/OrderItems exist yet).
    // ═══════════════════════════════════════════════════════════════
    private function calculateWeightFromLines(array $lines): int
    {
        $totalGrams = 0;
        foreach ($lines as $line) {
            $totalGrams += ($line['weight_kg'] ?? 0.3) * 1000 * $line['quantity'];
        }
        return max(1, (int) round($totalGrams));
    }
    // ═══════════════════════════════════════════════════════════════
    // PRIVATE HELPER: Total shipment weight in grams from an already-
    // persisted Order's items (used by confirmCod()/shippingQuote()).
    // Falls back to a configured default per item if product weight
    // isn't set.
    // ═══════════════════════════════════════════════════════════════
    private function calculateOrderWeight(Order $order): int
    {
        $items = $order->items()->with('product')->get(); // fresh Collection
        $totalGrams = 0;
        foreach ($items as $item) {
            $weightKg = (float) ($item->product->weight ?? 0.3);
            $totalGrams += $weightKg * 1000 * $item->quantity;
        }
        return $totalGrams > 0
            ? (int) round($totalGrams)
            : (int) config('services.delhivery.default_weight_grams', 500);
    }
    // ═══════════════════════════════════════════════════════════════
    // PRIVATE HELPER: Pull a 6-digit pincode out of a free-text address
    // string when a structured shipping_pincode column isn't set.
    // ═══════════════════════════════════════════════════════════════
    private function extractPincodeFromAddress(?string $address): ?string
    {
        if ($address && preg_match('/(\d{6})/', $address, $m)) {
            return $m[1];
        }
        return null;
    }
    // ═══════════════════════════════════════════════════════════════
    // PRIVATE HELPER: Single source of truth for shipping cost — always
    // a LIVE quote from Delhivery, keyed by payment method (COD carries
    // a cod_amount, Prepaid doesn't). Falls back to a flat charge only
    // if there's no pincode to quote against or Delhivery is unreachable,
    // so checkout never hard-fails just because the carrier API hiccupped.
    // ═══════════════════════════════════════════════════════════════
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
                // Discount, shipping, and tax are NEVER accepted from the
                // client — they are always recomputed below. Only a coupon
                // *code* (validated against a server-side whitelist) may
                // be supplied.
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
            // Spec #2: subtotal = sum of MRP × qty (pre-discount)
            $subtotal = round(array_sum(array_column($lines, 'mrp_total')), 2);
            // Spec #3: product discount = sum of (mrp - discountedPrice) × qty
            $productDiscount = round($subtotal - array_sum(array_column($lines, 'total')), 2);
            // Coupon discount (server-whitelisted only), applied on top of product discount
            [$couponCode, $couponDiscount] = $this->resolveCouponDiscount(
                $validated['coupon_code'] ?? null,
                round($subtotal - $productDiscount, 2),
            );
            $discount = round($productDiscount + $couponDiscount, 2);
            // Spec #4: taxable amount = subtotal - discount
            $taxableAmount = round($subtotal - $discount, 2);
            // Spec #5: tax is FRESH, additive — NOT reverse-extracted from an inclusive price.
            $tax = round($taxableAmount * self::GST_RATE, 2);
            // Placeholder shipping — replaced with a live Delhivery quote
            // once the Order + its items actually exist below (the quote
            // needs the order's weight/pincode/payment method).
            $shippingCharge = self::FALLBACK_SHIPPING_CHARGE;
            $amount = round($taxableAmount + $tax + $shippingCharge, 2);
            if (round($taxableAmount, 2) < 0) {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => 'Discount cannot exceed order subtotal.'], 422);
            }
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
            ]);
            // Link this order to a pre-created Razorpay Transaction
            // (from PaymentController::createOrder), so verifyPayment()
            // can later find it and update payment_status correctly.
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
            // Now that items are persisted, get the real, live shipping
            // quote for the chosen payment method and correct the order's
            // shipping/amount before it's committed.
            $order->refresh();
            $liveShippingCharge = $this->resolveShippingCharge($order, $taxableAmount, $validated['payment_method']);
            if ($liveShippingCharge !== $shippingCharge) {
                $amount = round($taxableAmount + $tax + $liveShippingCharge, 2);
                $order->shipping = $liveShippingCharge;
                $order->amount = $amount;
                $order->save();
            }
            if ($usedCart) {
                CartWishlistData::where('user_id', $userId)->where('type', 'cart')->delete();
            }
            DB::commit();
            // ─────────────────────────────────────────────────────────
            // FIX: COD orders never transition to payment_status =
            // 'Paid' at placement time (that only happens later, on
            // delivery, via updateStatus()). So the invoice email/
            // WhatsApp — which used to be wired ONLY to that 'Paid'
            // transition — never fired for COD orders at all.
            //
            // For COD, "order placed" IS the moment to send the
            // invoice (payment is collected later, but the order/
            // invoice itself is confirmed now). Fire it here, outside
            // the DB transaction (it's an external I/O call — email/
            // WhatsApp — and must never roll back the order if it
            // fails; sendInvoiceEmail() already swallows its own
            // errors internally).
            // ─────────────────────────────────────────────────────────
            if (strtolower($validated['payment_method']) === 'cod') {
                $this->maybeSendInvoice($order->fresh()->load('items.productSizeStock'));
            }
            return response()->json([
                'status' => 'success',
                'message' => 'Order placed successfully.',
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
    //
    // Frontend calls THIS to preview shipping when the customer switches
    // payment method — never calls Delhivery directly. Persists the
    // result on the order so confirmCod()/verifyPayment() charge exactly
    // what was quoted here.
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
            $tax = round($taxableAmount * self::GST_RATE, 2);
            $amount = round($taxableAmount + $tax + $shippingCharge, 2);
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
    //
    // Called from the payment page when the customer picks Cash on
    // Delivery instead of Razorpay. The amount/shipping figures are NOT
    // taken from the request body — they're recomputed here from a live
    // Delhivery quote using the order's already-frozen subtotal/discount,
    // via the same resolveShippingCharge() used everywhere else. This
    // guarantees the stored order total is always internally consistent,
    // and a tampered request body can't change what's charged.
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
            $tax = round($taxableAmount * self::GST_RATE, 2);
            $amount = round($taxableAmount + $tax + $shippingCharge, 2);
            $order->payment_method = 'cod';
            $order->payment_status = 'Pending'; // collected on delivery, marked Paid later via updateStatus()
            $order->shipping = $shippingCharge;
            $order->tax = $tax;
            $order->amount = $amount;
            $order->save();
            // ─────────────────────────────────────────────────────────
            // FIX: if the frontend's COD flow goes through THIS endpoint
            // (rather than payment_method='cod' being set at checkout
            // time), this is the actual "order placed" moment for COD —
            // so send the invoice here too. maybeSendInvoice() checks
            // invoice_number first, so if checkout() already sent it,
            // this is a safe no-op (no duplicate email/WhatsApp).
            // ─────────────────────────────────────────────────────────
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
    // GET /orders  — Admin list with search & filtering
    // ═══════════════════════════════════════════════════════════════
    public function index(Request $request)
    {
        try {
            $query = Order::with(array_merge(['items'], self::ITEM_DETAIL_RELATIONS));
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('order_id', 'like', "%{$search}%")
                        ->orWhere('invoice_number', 'like', "%{$search}%")
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
            $perPage = (int) $request->query('per_page', 20);
            $orders = $query->orderBy('created_at', 'desc')->paginate($perPage > 0 ? $perPage : 20);
            $this->attachFullItemDetails($orders);
            return response()->json(['status' => 'success', 'data' => $orders], 200);
        } catch (Exception $e) {
            Log::error('Order Index Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to retrieve orders.'], 500);
        }
    }
    // ═══════════════════════════════════════════════════════════════
    // GET /orders/{id}  — Admin order detail
    // ═══════════════════════════════════════════════════════════════
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
    // ═══════════════════════════════════════════════════════════════
    // GET /users/{userId}/orders  — Customer's own order history
    // ═══════════════════════════════════════════════════════════════
    public function myOrders(Request $request, $userId)
    {
        try {
            $query = Order::with(array_merge(['items'], self::ITEM_DETAIL_RELATIONS))->where('customer_id', $userId);
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
    // ═══════════════════════════════════════════════════════════════
    // PRIVATE HELPER: Build the invoice data array for a given order.
    // Used by invoice(), invoiceMail(), and sendInvoiceEmail().
    // ═══════════════════════════════════════════════════════════════
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
    // ═══════════════════════════════════════════════════════════════
    // PRIVATE HELPER: Render the invoice PDF and upload it to S3,
    // returning a short-lived signed URL — WhatsApp/rcloud fetch the
    // document header from the public internet, so it can't live on
    // local/public disk unless this app itself is internet-reachable.
    //
    // The S3 object is NOT made public (invoices carry customer PII).
    // A 30-minute signed URL is generated instead — plenty of time for
    // rcloud to fetch and attach it once, then it stops working.
    // ═══════════════════════════════════════════════════════════════
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
    // ═══════════════════════════════════════════════════════════════
    // PRIVATE HELPER: Send the invoice via WhatsApp using the
    // 'payment_invoice_success' template (id 4388937431421342, en).
    // Body placeholders: {{1}} customer name, {{2}} amount, {{3}} order
    // id, {{4}} payment date. Header is the invoice PDF as a document.
    //
    // Never throws — logs and swallows errors, same pattern as
    // sendInvoiceEmail(), so a WhatsApp failure never breaks checkout
    // or status updates.
    // ═══════════════════════════════════════════════════════════════
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
            // The approved template's type is "Document" — it has a
            // mandatory document header. Sending without one always
            // fails (#132012), so skip the API call entirely if the
            // PDF didn't generate rather than send a doomed request.
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
    // ═══════════════════════════════════════════════════════════════
    // GET /orders/{id}/invoice
    // Returns invoice JSON data for the frontend Angular view to render.
    // ═══════════════════════════════════════════════════════════════
    public function invoice($id)
    {
        try {
            $order = Order::with(['items.productSizeStock'])->findOrFail($id);
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
    // ═══════════════════════════════════════════════════════════════
    // POST /orders/{id}/invoice-mail
    // Generates the invoice PDF and emails + WhatsApps it to the
    // customer on demand.
    // ═══════════════════════════════════════════════════════════════
    public function invoiceMail($id)
    {
        try {
            $order = Order::with(['items.productSizeStock'])->findOrFail($id);
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
    // ═══════════════════════════════════════════════════════════════
    // PRIVATE HELPER: Silently send the invoice email + WhatsApp (used
    // internally after a payment status transitions to 'Paid', or right
    // after a COD order is placed/confirmed). Never throws — logs and
    // swallows errors so it doesn't break the calling flow.
    // ═══════════════════════════════════════════════════════════════
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
    // ═══════════════════════════════════════════════════════════════
    // PRIVATE HELPER: Idempotent wrapper around sendInvoiceEmail().
    //
    // An order's invoice can now be triggered from three places:
    //   1. checkout()    — immediately, for COD orders
    //   2. confirmCod()  — if the frontend confirms COD via a separate
    //                      step rather than at checkout
    //   3. updateStatus()— when payment_status flips to 'Paid'
    //                      (prepaid/Razorpay orders)
    //
    // buildInvoiceData() stamps invoice_number on first call, so we use
    // that as the "already sent" guard — this prevents duplicate
    // emails/WhatsApp messages if more than one of the above fires for
    // the same order.
    // ═══════════════════════════════════════════════════════════════
    private function maybeSendInvoice(Order $order): void
    {
        if (!empty($order->invoice_number)) {
            return;
        }
        $this->sendInvoiceEmail($order);
    }
    // ═══════════════════════════════════════════════════════════════
    // PATCH /orders/{id}/status
    // ═══════════════════════════════════════════════════════════════
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
    // ═══════════════════════════════════════════════════════════════
    // POST /orders/{id}/cancel
    // ═══════════════════════════════════════════════════════════════
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
    // ═══════════════════════════════════════════════════════════════
    // DELETE /orders/{id}
    // ═══════════════════════════════════════════════════════════════
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