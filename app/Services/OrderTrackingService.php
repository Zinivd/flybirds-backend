<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderTrackingEvent;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderTrackingService
{
    private const RANK = [
        'Pending' => 0,
        'Packed' => 1,
        'Shipped' => 2,
        'In Transit' => 3,
        'Out For Delivery' => 4,
        'Delivered' => 5,
    ];

    public function __construct(protected DelhiveryService $delhivery) {}

    private function parse(?string $at): ?Carbon
    {
        return $at ? Carbon::parse($at, 'Asia/Kolkata')->setTimezone(config('app.timezone')) : null;
    }

    private function mapStatus(?string $status, ?string $type): ?string
    {
        $s = strtolower(trim((string) $status));
        return match (true) {
            in_array($s, ['rto', 'dto', 'returned']) || $type === 'RT' => 'RTO',
            $s === 'delivered' || $type === 'DL'                        => 'Delivered',
            $s === 'dispatched'                                         => 'Out For Delivery',
            in_array($s, ['in transit', 'pending'])                     => 'In Transit',
            in_array($s, ['manifested', 'not picked'])                  => 'Shipped',
            default                                                     => null,
        };
    }

    public function sync(Order $order, bool $force = false): bool
{
    if (!$order->awb_number) {
        return false;
    }
    if (!$force && $order->last_tracked_at && $order->last_tracked_at->gt(now()->subMinutes(5))) {
        return true;
    }

    try {
        $r = $this->delhivery->trackDetailed($order->awb_number);
    } catch (\Throwable $e) {
        Log::error('Tracking sync exception', [
            'order_id' => $order->id,
            'awb' => $order->awb_number,
            'error' => $e->getMessage(),
        ]);
        return false;
    }

    if (!($r['success'] ?? false)) {
        Log::warning('Tracking sync: Delhivery returned no data', [
            'order_id' => $order->id,
            'awb' => $order->awb_number,
        ]);
        return false;
    }

    Log::info('Tracking sync ok', [
        'order_id' => $order->id,
        'awb' => $order->awb_number,
        'delhivery_status' => $r['status'] ?? null,
        'scans' => count($r['scans'] ?? []),
    ]);

    DB::transaction(function () use ($order, $r) {
            foreach ($r['scans'] as $scan) {
                $at = $this->parse($scan['at']);
                OrderTrackingEvent::firstOrCreate(
                    [
                        'order_table_id' => $order->id,
                        'hash' => sha1($order->awb_number . '|' . $scan['code'] . '|' . $scan['status'] . '|' . $at->timestamp . '|' . $scan['remarks']),
                    ],
                    [
                        'awb_number' => $order->awb_number,
                        'status' => $scan['status'],
                        'status_code' => $scan['code'],
                        'remarks' => $scan['remarks'],
                        'location' => $scan['location'],
                        'scanned_at' => $at,
                    ]
                );
            }

            $latest = $order->trackingEvents()->first();
            $mapped = $this->mapStatus($r['status'], $r['status_type']);
            $current = $order->delivery_status;

            $order->delhivery_status = $r['status'] ?? $order->delhivery_status;
            $order->last_scan_remarks = $latest?->remarks;
            $order->last_scan_location = $latest?->location;
            $order->expected_delivery_at = $this->parse($r['expected_date']) ?? $order->expected_delivery_at;
            $order->last_tracked_at = now();
            $order->shipment_snapshot = [
                'origin' => $r['origin'],
                'destination' => $r['destination'],
                'status_type' => $r['status_type'],
                'instructions' => $r['instructions'],
            ];

            if ($mapped && !in_array($current, ['Cancelled', 'Refunded', 'Delivered'], true)) {
                $forward = $mapped === 'RTO' || (self::RANK[$mapped] ?? 0) >= (self::RANK[$current] ?? 0);
                if ($forward) {
                    $order->delivery_status = $mapped;
                }
                if ($mapped === 'Delivered') {
                    $order->delivered_at = $this->parse($r['delivered_at']) ?? now();
                    if (strtolower($order->payment_method) === 'cod' && $order->payment_status === 'Pending') {
                        $order->payment_status = 'Paid';
                    }
                }
            }

            // NDR = Delhivery status "Pending" (failed attempt). StatusType "UD" alone is NOT an NDR.
            $s = strtolower((string) $r['status']);
            if ($s === 'pending') {
                $order->ndr_status = 'open';
                $order->ndr_reason = $r['instructions'] ?? 'Delivery attempt failed';
                $order->ndr_updated_at = now();
            } elseif ($order->ndr_status && in_array($mapped, ['Delivered', 'RTO'], true)) {
                $order->ndr_status = null;
                $order->ndr_reason = null;
                $order->ndr_updated_at = now();
            }

            $order->save();
        });

        return true;
    }

    public function present(Order $order, bool $admin = false): array
    {
        $order->loadMissing('trackingEvents');
        $events = $order->trackingEvents;

        $first = fn(array $names) => $events->reverse()->first(
            fn($e) => in_array(strtolower($e->status), $names, true)
        )?->scanned_at;

        $flow = [
            ['key' => 'placed',           'label' => 'Order Placed',     'at' => $order->created_at],
            ['key' => 'packed',           'label' => 'Packed',           'at' => null],
            ['key' => 'shipped',          'label' => 'Shipped',          'at' => $order->shipped_at ?? $first(['manifested'])],
            ['key' => 'in_transit',       'label' => 'In Transit',       'at' => $first(['in transit'])],
            ['key' => 'out_for_delivery', 'label' => 'Out For Delivery', 'at' => $first(['dispatched'])],
            ['key' => 'delivered',        'label' => 'Delivered',        'at' => $order->delivered_at],
        ];

        $rank = self::RANK[$order->delivery_status] ?? 0;
        $returned = $order->delivery_status === 'RTO';
        if ($returned) {
            $rank = 3;
            $flow[4] = ['key' => 'rto', 'label' => 'Returning to Seller', 'at' => $first(['rto'])];
            unset($flow[5]);
            $flow = array_values($flow);
        }

        $progress = collect($flow)->map(fn($s, $i) => [
            'key' => $s['key'],
            'label' => $s['label'],
            'completed' => $returned ? $i <= 4 : $i <= $rank,
            'current' => $returned ? $i === 4 : $i === $rank,
            'at' => $s['at']?->toIso8601String(),
        ])->all();

        $data = [
            'order_id' => $order->order_id,
            'awb_number' => $order->awb_number,
            'courier' => 'Delhivery',
            'tracking_url' => $order->awb_number ? 'https://www.delhivery.com/track/package/' . $order->awb_number : null,
            'delivery_status' => $order->delivery_status,
            'current_status' => $order->delivery_status,
            'last_update' => $order->last_scan_remarks,
            'last_location' => $order->last_scan_location,
            'expected_delivery' => $order->expected_delivery_at?->toDateString(),
            'delivered_at' => $order->delivered_at?->toIso8601String(),
            'progress' => $progress,
            'timeline' => $events->map(fn($e) => [
                'status' => $e->status,
                'remarks' => $e->remarks,
                'location' => $e->location,
                'at' => $e->scanned_at->toIso8601String(),
            ])->values()->all(),
            'last_synced_at' => $order->last_tracked_at?->toIso8601String(),
        ];

        if ($admin) {
            $isCod = strtolower($order->payment_method) === 'cod';
            $data['shipment'] = [
                'delhivery_status' => $order->delhivery_status,
                'shipment_status' => $order->shipment_status,
                'payment_mode' => $isCod ? 'COD' : 'Prepaid',
                'cod_amount' => $isCod ? (float) $order->amount : 0,
                'order_amount' => (float) $order->amount,
                'destination_pin' => $order->shipping_pincode,
                'destination_city' => $order->shipping_city,
                'destination_state' => $order->shipping_state,
                'shipped_at' => $order->shipped_at?->toIso8601String(),
                'ndr_status' => $order->ndr_status,
                'ndr_reason' => $order->ndr_reason,
                'carrier' => $order->shipment_snapshot,
            ];
        }

        return $data;
    }
}
