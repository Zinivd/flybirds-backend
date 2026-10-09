<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateShipmentRequest;
use App\Models\Order;
use App\Models\ProductSizeStock;
use App\Services\DelhiveryService;
use App\Services\OrderTrackingService;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DelhiveryController extends Controller
{
    // Mirrors OrderController::NON_CANCELLABLE_STATUSES.
    private const NON_CANCELLABLE_STATUSES = ['Delivered', 'Cancelled', 'Refunded'];

    public function __construct(protected DelhiveryService $delhivery)
    {
    }

    public function checkServiceability($pincode)
    {
        if (!preg_match('/^\d{6}$/', $pincode)) {
            return response()->json(['error' => 'Invalid pincode'], 422);
        }
        try {
            return response()->json($this->delhivery->checkServiceability($pincode));
        } catch (Exception $e) {
            Log::error('Delhivery serviceability check exception', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Serviceability check failed'], 502);
        }
    }

    public function fetchWaybill(Request $request)
    {
        $count = (int) $request->query('count', 1);
        try {
            $result = $this->delhivery->fetchWaybill($count);
            return response()->json($result, $result['success'] ? 200 : 502);
        } catch (Exception $e) {
            Log::error('Delhivery waybill fetch exception', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Waybill fetch failed'], 502);
        }
    }

    public function getTAT(Request $request)
    {
        $validated = $request->validate([
            'origin_pin' => 'required|digits:6',
            'destination_pin' => 'required|digits:6',
        ]);
        try {
            $result = $this->delhivery->getTAT($validated['origin_pin'], $validated['destination_pin']);
            return response()->json($result, $result['success'] ? 200 : 502);
        } catch (Exception $e) {
            Log::error('Delhivery TAT exception', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'TAT fetch failed'], 502);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // GET /user/delhivery/track/{orderId}?user_id=XXXX
    // Customer-facing tracking (Flipkart-style payload).
    // ═══════════════════════════════════════════════════════════════
    public function trackMyOrder(Request $request, string $orderId, OrderTrackingService $tracking)
    {
        $userId = auth()->user()->user_id ?? $request->query('user_id');
        if (!$userId) {
            return response()->json(['status' => 'error', 'message' => 'user_id is required.'], 422);
        }

        $order = Order::placed()
            ->where('order_id', $orderId)
            ->where('customer_id', $userId)
            ->first();

        if (!$order) {
            return response()->json(['status' => 'error', 'message' => 'Order not found.'], 404);
        }

        if ($order->awb_number) {
            $tracking->sync($order);
            $order->refresh();
        }

        return response()->json(['status' => 'success', 'data' => $tracking->present($order)], 200);
    }

    // ═══════════════════════════════════════════════════════════════
    // GET /admin/orders/{id}/tracking?refresh=1
    // ═══════════════════════════════════════════════════════════════
    public function adminTracking(Request $request, $id, OrderTrackingService $tracking)
    {
        $order = Order::findOrFail($id);

        if ($order->awb_number) {
            $tracking->sync($order, $request->boolean('refresh'));
            $order->refresh();
        }

        return response()->json(['status' => 'success', 'data' => $tracking->present($order, true)], 200);
    }

    // ═══════════════════════════════════════════════════════════════
    // POST /admin/delhivery/shipment/cancel
    // Mirrors OrderController::cancel(): restores stock, sets
    // delivery_status = 'Cancelled' (+ 'Refunded' if it was 'Paid').
    // ═══════════════════════════════════════════════════════════════
    public function cancelShipment(Request $request)
    {
        $validated = $request->validate([
            'order_id' => 'nullable|integer|exists:orders,id',
            'waybill' => 'nullable|string',
        ]);

        if (empty($validated['order_id']) && empty($validated['waybill'])) {
            return response()->json(['error' => 'Provide order_id or waybill.'], 422);
        }

        $order = null;
        $waybill = $validated['waybill'] ?? null;

        if (!empty($validated['order_id'])) {
            $order = Order::findOrFail($validated['order_id']);
        } elseif ($waybill) {
            $order = Order::where('awb_number', $waybill)->first();
        }

        if ($order) {
            $waybill = $waybill ?? $order->awb_number;
            if (!$waybill) {
                return response()->json(['error' => 'This order has no waybill to cancel.'], 422);
            }
            if (in_array($order->delivery_status, self::NON_CANCELLABLE_STATUSES)) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Order cannot be cancelled because it is already '{$order->delivery_status}'.",
                ], 422);
            }
        }

        try {
            $result = $this->delhivery->cancelShipment($waybill);

            if ($result['success'] && $order) {
                DB::transaction(function () use ($order) {
                    foreach ($order->items as $item) {
                        if ($item->product_size_stock_id) {
                            $sizeStock = ProductSizeStock::find($item->product_size_stock_id);
                            if ($sizeStock) {
                                $sizeStock->increment('stock', $item->quantity);
                            } else {
                                Log::warning("Cancel Shipment: size stock #{$item->product_size_stock_id} no longer exists for order #{$order->id}, skipped stock reversal.");
                            }
                        }
                    }

                    $order->shipment_status = 'cancelled';
                    $order->delivery_status = 'Cancelled';
                    if ($order->payment_status === 'Paid') {
                        $order->payment_status = 'Refunded';
                    }
                    $order->save();
                });
            }

            return response()->json($result, $result['success'] ? 200 : 502);
        } catch (Exception $e) {
            Log::error('Delhivery cancellation exception', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Cancellation failed'], 502);
        }
    }

    public function trackShipment(string $waybill)
    {
        try {
            $result = $this->delhivery->trackShipment($waybill);
            return response()->json($result, $result['success'] ? 200 : 502);
        } catch (Exception $e) {
            Log::error('Delhivery admin tracking exception', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Tracking failed'], 502);
        }
    }

    public function calculateShippingCost(Request $request)
    {
        $validated = $request->validate([
            'origin_pin' => 'nullable|digits:6',
            'destination_pin' => 'required|digits:6',
            'weight' => 'required|integer|min:1',
            'payment_mode' => 'nullable|in:COD,Prepaid',
            'cod_amount' => 'nullable|numeric|min:0',
        ]);

        $originPin = config('services.delhivery.origin_pin', '641603');

        try {
            $result = $this->delhivery->calculateShippingCost(
                $originPin,
                $validated['destination_pin'],
                $validated['weight'],
                $validated['payment_mode'] ?? 'Prepaid',
                $validated['cod_amount'] ?? 0,
            );
            return response()->json($result, $result['success'] ? 200 : 502);
        } catch (ConnectionException $e) {
            Log::error('Delhivery shipping cost — connection failure', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Could not reach Delhivery. Try again shortly.'], 504);
        } catch (Exception $e) {
            Log::error('Delhivery shipping cost exception', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Shipping cost calculation failed'], 502);
        }
    }

    public function getLabel(string $waybill)
    {
        try {
            $result = $this->delhivery->generateLabel($waybill);
            return response()->json($result, $result['success'] ? 200 : 502);
        } catch (ConnectionException $e) {
            Log::error('Delhivery label — connection failure', ['waybill' => $waybill, 'error' => $e->getMessage()]);
            return response()->json(['error' => 'Could not reach Delhivery. Try again shortly.'], 504);
        } catch (Exception $e) {
            Log::error('Delhivery label exception', ['waybill' => $waybill, 'error' => $e->getMessage()]);
            return response()->json(['error' => 'Label generation failed'], 502);
        }
    }

    public function createPickup(Request $request)
    {
        $validated = $request->validate([
            'pickup_date' => 'required|date|after_or_equal:today',
            'pickup_time' => 'required|date_format:H:i:s',
            'expected_package_count' => 'required|integer|min:1',
            'pickup_location' => 'nullable|string',
        ]);
        try {
            $result = $this->delhivery->createPickupRequest($validated);
            return response()->json($result, $result['success'] ? 200 : 502);
        } catch (ConnectionException $e) {
            Log::error('Delhivery pickup — connection failure', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Could not reach Delhivery. Try again shortly.'], 504);
        } catch (Exception $e) {
            Log::error('Delhivery pickup exception', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Pickup request failed'], 502);
        }
    }

    public function updateEwaybill(Request $request)
    {
        $validated = $request->validate([
            'waybill' => 'required|string',
            'ewbn' => 'required|digits:12',
        ]);

        try {
            $result = $this->delhivery->updateEwaybill($validated['waybill'], $validated['ewbn']);

            if ($result['success']) {
                Order::where('awb_number', $validated['waybill'])
                    ->update(['ewbn' => $validated['ewbn']]);
            }

            return response()->json($result, $result['success'] ? 200 : 502);
        } catch (ConnectionException $e) {
            Log::error('Delhivery eWaybill — connection failure', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Could not reach Delhivery. Try again shortly.'], 504);
        } catch (Exception $e) {
            Log::error('Delhivery eWaybill exception', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'eWaybill update failed'], 502);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // PRIVATE HELPERS
    // ═══════════════════════════════════════════════════════════════
    private function buildProductsDescription(Order $order): string
    {
        $items = $order->items()->get();
        $names = $items->pluck('product_name')->unique()->values();
        if ($names->count() <= 3) {
            return $names->implode(', ');
        }
        return $names->take(3)->implode(', ') . ' and ' . ($names->count() - 3) . ' more';
    }

    private function calculateOrderWeight(Order $order): int
    {
        $items = $order->items()->with('product')->get();
        $total = 0;
        foreach ($items as $item) {
            $total += ($item->product->weight ?? 0) * $item->quantity;
        }
        return $total > 0 ? (int) $total : (int) config('services.delhivery.default_weight_grams', 500);
    }

    private function sanitizePhone(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        return substr($digits, -10);
    }

    // ═══════════════════════════════════════════════════════════════
    // POST /admin/delhivery/shipment/create
    // Sets delivery_status = 'Shipped' and shipped_at on success.
    // Only placed orders can be shipped; COD is detected case-insensitively.
    // ═══════════════════════════════════════════════════════════════
    public function createShipment(CreateShipmentRequest $request)
    {
        $validated = $request->validated();
        $order = Order::placed()->where('order_id', $validated['order_id'])->firstOrFail();

        if ($order->awb_number) {
            return response()->json([
                'status' => 'error',
                'message' => "Order already has a waybill ({$order->awb_number}). Cancel it first if you need to re-create.",
            ], 422);
        }

        $pin = $validated['pin'] ?? $order->shipping_pincode;
        $city = $validated['city'] ?? $order->shipping_city;
        $state = $validated['state'] ?? $order->shipping_state;

        if (!$pin || !$city || !$state) {
            return response()->json([
                'status' => 'error',
                'message' => 'pin, city, and state are required (either on the order or in the request).',
            ], 422);
        }

        $phone = $this->sanitizePhone($order->customer_phone);
        if (strlen($phone) !== 10) {
            return response()->json([
                'status' => 'error',
                'message' => 'Customer phone number is invalid or missing — Delhivery requires a 10-digit number.',
            ], 422);
        }

        if (!$order->customer_name || !trim((string) $order->shipping_address)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Customer name and shipping address are required.',
            ], 422);
        }

        $preFetchedWaybill = $validated['waybill'] ?? null;
        $isCod = strtolower((string) $order->payment_method) === 'cod';

        $shipmentPayload = [
            'order' => $order->order_id,
            'name' => trim($order->customer_name),
            'add' => trim($order->shipping_address),
            'pin' => $pin,
            'city' => $city,
            'state' => $state,
            'phone' => $phone,
            'payment_mode' => $isCod ? 'COD' : 'Prepaid',
            'products_desc' => $this->buildProductsDescription($order),
            'total_amount' => $order->amount,
            'cod_amount' => $isCod ? $order->amount : 0,
            'quantity' => $order->items()->count() ?: 1,
            'weight' => $validated['weight'] ?? $this->calculateOrderWeight($order),
            'shipment_length' => $validated['shipment_length'] ?? null,
            'shipment_width' => $validated['shipment_width'] ?? null,
            'shipment_height' => $validated['shipment_height'] ?? null,
        ];

        if ($preFetchedWaybill) {
            $shipmentPayload['waybill'] = $preFetchedWaybill;
        }

        try {
            $result = $this->delhivery->createShipment($shipmentPayload);

            if ($result['success']) {
                $finalWaybill = $result['waybill'] ?? $preFetchedWaybill;

                $order->update([
                    'awb_number' => $finalWaybill,
                    'shipment_status' => 'created',
                    'delivery_status' => 'Shipped',
                    'shipped_at' => now(),
                    'delhivery_status' => $result['status'] ?? $result['data']['status'] ?? 'Manifested',
                ]);
            }

            return response()->json($result, $result['success'] ? 200 : 502);
        } catch (ConnectionException $e) {
            Log::error('Delhivery shipment creation — connection failure', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['error' => 'Could not reach Delhivery. Try again shortly.'], 504);
        } catch (Exception $e) {
            Log::error('Delhivery shipment creation exception', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['error' => 'Shipment creation failed'], 502);
        }
    }

    public function listNdr(Request $request)
    {
        $query = Order::whereNotNull('ndr_status')
            ->whereNotNull('awb_number');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('order_id', 'like', "%{$search}%")
                    ->orWhere('awb_number', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%");
            });
        }

        $perPage = (int) $request->query('per_page', 20);
        $orders = $query->orderByDesc('ndr_updated_at')->paginate($perPage > 0 ? $perPage : 20);

        return response()->json(['status' => 'success', 'data' => $orders], 200);
    }

    // ═══════════════════════════════════════════════════════════════
    // POST /admin/delhivery/ndr/sync
    // ═══════════════════════════════════════════════════════════════
    public function syncNdrStatus(Request $request, OrderTrackingService $tracking)
    {
        $orders = Order::whereNotNull('awb_number')
            ->whereNotIn('delivery_status', ['Delivered', 'Cancelled', 'Refunded', 'RTO'])
            ->get();

        $errors = 0;
        foreach ($orders as $order) {
            if (!$tracking->sync($order, true)) {
                $errors++;
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => "Checked {$orders->count()} orders, {$errors} errors.",
            'data' => ['checked' => $orders->count(), 'errors' => $errors],
        ], 200);
    }

    // ═══════════════════════════════════════════════════════════════
    // POST /admin/delhivery/ndr/update
    // RE-ATTEMPT / DEFERRED keep the NDR open; RTO moves the order to
    // the terminal 'RTO' status and clears the NDR flag.
    // ═══════════════════════════════════════════════════════════════
    public function updateNDR(Request $request)
    {
        $validated = $request->validate([
            'waybill' => 'required|string',
            'action' => 'required|in:RE-ATTEMPT,DEFERRED,RTO',
            'comment' => 'nullable|string|max:255',
        ]);
        try {
            $result = $this->delhivery->updateNDR($validated['waybill'], $validated['action'], $validated['comment'] ?? null);

            if ($result['success'] ?? false) {
                $order = Order::where('awb_number', $validated['waybill'])->first();
                if ($order) {
                    if ($validated['action'] === 'RTO') {
                        $order->forceFill([
                            'delivery_status' => 'RTO',
                            'ndr_status' => null,
                            'ndr_reason' => null,
                            'ndr_updated_at' => now(),
                        ])->save();
                    } else {
                        $order->forceFill([
                            'ndr_status' => 'open',
                            'ndr_reason' => $validated['action'] . ($validated['comment'] ? ': ' . $validated['comment'] : ''),
                            'ndr_updated_at' => now(),
                        ])->save();
                    }
                }
            }

            return response()->json($result, $result['success'] ? 200 : 502);
        } catch (ConnectionException $e) {
            Log::error('Delhivery NDR — connection failure', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Could not reach Delhivery. Try again shortly.'], 504);
        } catch (Exception $e) {
            Log::error('Delhivery NDR exception', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'NDR update failed'], 502);
        }
    }
}
