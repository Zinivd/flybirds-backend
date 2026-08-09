<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateShipmentRequest;
use App\Models\DelhiveryShipment;
use App\Models\Order;
use App\Models\ProductSizeStock;
use App\Services\DelhiveryService;
use Doctrine\DBAL\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Exception;
use Log;

class DelhiveryController extends Controller
{
    // Mirrors OrderController::DELIVERY_STATUSES / NON_CANCELLABLE_STATUSES.
    // Duplicated here (rather than shared) only because these two
    // controllers currently live independently — if you refactor later,
    // pull both into a shared OrderStatus enum/service so this list can't
    // drift between the two files again.
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
    // GET /user/delhivery/track/{orderId}
    // Customer-facing tracking — looks up the order by its order_id
    // string (e.g. FLYODR-0807&A00011), resolves its waybill, and
    // returns live shipment status. Never exposes the raw waybill/
    // carrier payload to the storefront beyond what's needed.
    // ═══════════════════════════════════════════════════════════════
    public function trackMyOrder(string $orderId)
    {
        $order = Order::where('order_id', $orderId)->first();
        if (!$order) {
            return response()->json([
                'status' => 'error',
                'message' => 'Order not found.',
            ], 404);
        }
        if (!$order->awb_number) {
            return response()->json([
                'status' => 'success',
                'data' => [
                    'order_id' => $order->order_id,
                    'shipment_status' => 'not_shipped',
                    'message' => 'This order has not been shipped yet.',
                ],
            ], 200);
        }
        try {
            $result = $this->delhivery->trackShipment($order->awb_number);
            if (!$result['success']) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unable to fetch tracking status right now.',
                ], 502);
            }
            return response()->json([
                'status' => 'success',
                'data' => [
                    'order_id' => $order->order_id,
                    'awb_number' => $order->awb_number,
                    'delivery_status' => $order->delivery_status,
                    'shipment_status' => $result['status'],
                ],
            ], 200);
        } catch (ConnectionException $e) {
            Log::error('Delhivery user tracking — connection failure', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['error' => 'Could not reach Delhivery. Try again shortly.'], 504);
        } catch (Exception $e) {
            Log::error('Delhivery user tracking exception', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['error' => 'Tracking failed'], 502);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // POST /admin/delhivery/shipment/cancel
    //
    // FIX: now mirrors OrderController::cancel() — restores stock for
    // every item and sets delivery_status = 'Cancelled' (+ payment_status
    // = 'Refunded' if it was 'Paid'), the same as cancelling an order
    // directly. Previously this only touched shipment_status, so a
    // shipment cancelled from here left the order looking untouched
    // everywhere else in the admin UI — same class of bug as
    // createShipment() not updating delivery_status.
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
            // Look up by waybill too, so we can still sync delivery_status
            // even when the caller only passed a waybill, not an order_id.
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

    // public function updateNDR(Request $request)
    // {
    //     $validated = $request->validate([
    //         'waybill' => 'required|string',
    //         'action' => 'required|in:RE-ATTEMPT,DEFERRED,RTO',
    //         'comment' => 'nullable|string|max:255',
    //     ]);
    //     try {
    //         $result = $this->delhivery->updateNDR($validated['waybill'], $validated['action'], $validated['comment'] ?? null);

    //         // NOTE: not wired to delivery_status yet — 'RE-ATTEMPT'/
    //         // 'DEFERRED'/'RTO' don't map cleanly onto
    //         // OrderController::DELIVERY_STATUSES as-is. Decide the
    //         // intended mapping (e.g. RTO -> a new 'RTO' status, or reuse
    //         // 'Cancelled') before wiring this the same way createShipment
    //         // and cancelShipment now are.

    //         return response()->json($result, $result['success'] ? 200 : 502);
    //     } catch (ConnectionException $e) {
    //         Log::error('Delhivery NDR — connection failure', ['error' => $e->getMessage()]);
    //         return response()->json(['error' => 'Could not reach Delhivery. Try again shortly.'], 504);
    //     } catch (Exception $e) {
    //         Log::error('Delhivery NDR exception', ['error' => $e->getMessage()]);
    //         return response()->json(['error' => 'NDR update failed'], 502);
    //     }
    // }

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
    // PRIVATE HELPER: Build a short products description from order items.
    // ═══════════════════════════════════════════════════════════════
    private function buildProductsDescription(Order $order): string
    {
        $items = $order->items()->get(); // fresh Collection, not a Relation/Builder
        $names = $items->pluck('product_name')->unique()->values();
        if ($names->count() <= 3) {
            return $names->implode(', ');
        }
        return $names->take(3)->implode(', ') . ' and ' . ($names->count() - 3) . ' more';
    }

    // ═══════════════════════════════════════════════════════════════
    // PRIVATE HELPER: Compute total order weight in grams from items.
    // Falls back to a configured default if no product weights are set.
    // ═══════════════════════════════════════════════════════════════
    private function calculateOrderWeight(Order $order): int
    {
        $items = $order->items()->with('product')->get(); // fresh Collection
        $total = 0;
        foreach ($items as $item) {
            $total += ($item->product->weight ?? 0) * $item->quantity;
        }
        return $total > 0 ? (int) $total : (int) config('services.delhivery.default_weight_grams', 500);
    }

    // ═══════════════════════════════════════════════════════════════
    // PRIVATE HELPER: Strip a phone number down to the last 10 digits,
    // stripping any leading 0, +91, or 91 country-code prefix.
    // ═══════════════════════════════════════════════════════════════
    private function sanitizePhone(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        return substr($digits, -10);
    }

    // ═══════════════════════════════════════════════════════════════
    // POST /admin/delhivery/shipment/create
    //
    // FIX: on success, this now ALSO sets delivery_status = 'Shipped'
    // and shipped_at = now() — not just awb_number/shipment_status.
    // delivery_status is the field OrderController::index()/show() and
    // your Angular order list actually read, so without this the order
    // kept showing "Pending" even after a shipment was created.
    // ═══════════════════════════════════════════════════════════════
    public function createShipment(CreateShipmentRequest $request)
    {
        $validated = $request->validated();
        $order = Order::where('order_id', $validated['order_id'])->firstOrFail();

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

        // Optional pre-fetched waybill (from GET /admin/delhivery/waybill).
        // If the frontend fetched one first, we tell Delhivery to use it
        // instead of letting Delhivery auto-assign one during creation.
        $preFetchedWaybill = $validated['waybill'] ?? null;

        $shipmentPayload = [
            'order' => $order->order_id,
            'name' => trim($order->customer_name),
            'add' => trim($order->shipping_address),
            'pin' => $pin,
            'city' => $city,
            'state' => $state,
            'phone' => $phone,
            'payment_mode' => $order->payment_method === 'COD' ? 'COD' : 'Prepaid',
            'products_desc' => $this->buildProductsDescription($order),
            'total_amount' => $order->amount,
            'cod_amount' => $order->payment_method === 'COD' ? $order->amount : 0,
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
                // Prefer whatever Delhivery echoes back; fall back to the
                // waybill we supplied if the response doesn't include one.
                $finalWaybill = $result['waybill'] ?? $preFetchedWaybill;

                $order->update([
                    'awb_number' => $finalWaybill,
                    'shipment_status' => 'created',
                    // THE FIX — this is the field the order list/detail
                    // endpoints and the Angular UI actually display.
                    'delivery_status' => 'Shipped',
                    'shipped_at' => now(),
                    // Optional: raw carrier-side status, if Delhivery's
                    // response includes one — falls back to a sensible
                    // default label rather than staying null.
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



    public function syncNdrStatus(Request $request)
    {
        $terminalStatuses = ['Delivered', 'Cancelled', 'Refunded', 'RTO'];

        $orders = Order::whereNotNull('awb_number')
            ->whereNotIn('delivery_status', $terminalStatuses)
            ->get();

        $checked = 0;
        $flagged = 0;
        $errors = 0;

        foreach ($orders as $order) {
            $checked++;
            try {
                $result = $this->delhivery->trackShipment($order->awb_number);
                if (!($result['success'] ?? false)) {
                    $errors++;
                    continue;
                }

                $statusType = $result['status_type'] ?? null;

                if ($statusType === 'UD') {
                    // Undelivered attempt — flag/refresh the NDR.
                    $order->forceFill([
                        'ndr_status' => 'open',
                        'ndr_reason' => $result['ndr_reason'] ?? $result['status'] ?? 'Delivery attempt failed',
                        'ndr_updated_at' => now(),
                        'delhivery_status' => $result['status'] ?? $order->delhivery_status,
                    ])->save();
                    $flagged++;
                } elseif ($order->ndr_status && in_array($statusType, ['DL', 'RT'], true)) {
                    // Was flagged, now resolved on Delhivery's side (delivered
                    // or returned) — clear the local flag so it drops off the list.
                    $order->forceFill([
                        'ndr_status' => null,
                        'ndr_reason' => null,
                        'ndr_updated_at' => now(),
                        'delhivery_status' => $result['status'] ?? $order->delhivery_status,
                    ])->save();
                } elseif ($result['status'] ?? null) {
                    // Not an NDR, just refresh the raw carrier status.
                    $order->forceFill(['delhivery_status' => $result['status']])->save();
                }
            } catch (Exception $e) {
                $errors++;
                Log::error('NDR sync failed for order ' . $order->id . ': ' . $e->getMessage());
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => "Checked {$checked} orders — {$flagged} in NDR, {$errors} errors.",
            'data' => ['checked' => $checked, 'flagged' => $flagged, 'errors' => $errors],
        ], 200);
    }

    // ═══════════════════════════════════════════════════════════════
// POST /admin/delhivery/ndr/update  (EXISTING — updated below)
//
// FIX: now also updates the order's local ndr_status after a
// successful action, instead of leaving it stale until the next sync:
//   - RE-ATTEMPT / DEFERRED → NDR stays open, but ndr_reason records
//     the action taken and a fresh ndr_updated_at.
//   - RTO → order moves to a terminal 'RTO' delivery_status and the
//     NDR flag clears (nothing left to resolve).
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