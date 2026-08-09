<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DelhiveryService
{
    protected string $baseUrl;
    protected string $token;

    public function __construct()
    {
        $this->baseUrl = config('services.delhivery.base_url');
        $this->token = config('services.delhivery.token');
    }

    protected function client()
    {
        return Http::withHeaders([
            'Authorization' => 'Token ' . $this->token,
            'Accept' => 'application/json',
        ])->baseUrl($this->baseUrl);
    }

    public function checkServiceability(string $pincode): array
    {
        $res = $this->client()->get('/c/api/pin-codes/json/', [
            'filter_codes' => $pincode,
        ]);

        if ($res->failed()) {
            Log::warning('Delhivery serviceability API failed', [
                'pincode' => $pincode,
                'status' => $res->status(),
                'body' => $res->body(),
            ]);

            return [
                'serviceable' => null, // unknown, not confirmed false
                'reason' => 'API_ERROR',
                'status_code' => $res->status(),
                'raw' => $res->json() ?? $res->body(),
            ];
        }

        $data = $res->json();
        $codes = data_get($data, 'delivery_codes', []);

        if (empty($codes)) {
            return ['serviceable' => false, 'reason' => 'NSZ', 'raw' => $data];
        }

        $postal = data_get($codes, '0.postal_code', []);
        $remark = data_get($postal, 'remarks', '');

        return [
            'serviceable' => empty($remark),
            'embargo' => $remark === 'Embargo',
            'cod_available' => data_get($postal, 'cash') === 'Y',
            'prepaid_available' => data_get($postal, 'pre_paid') === 'Y',
            'raw' => $data,
        ];
    }

    public function fetchWaybill(int $count = 1): array
    {
        $res = $this->client()->get('/api/wbn/bulk.json', [
            'count' => $count,
        ]);

        if ($res->failed()) {
            Log::warning('Delhivery waybill fetch failed', [
                'status' => $res->status(),
                'body' => $res->body(),
            ]);

            return [
                'success' => false,
                'reason' => 'API_ERROR',
                'status_code' => $res->status(),
                'raw' => $res->json() ?? $res->body(),
            ];
        }

        $data = $res->json();
        $waybills = data_get($data, 'wbns', []);

        if (empty($waybills)) {
            return [
                'success' => false,
                'reason' => 'NO_WAYBILLS_RETURNED',
                'raw' => $data,
            ];
        }

        return [
            'success' => true,
            'waybills' => $waybills,
            'raw' => $data,
        ];
    }

    public function getTAT(string $originPin, string $destPin, string $paymentMode = 'Prepaid'): array
    {
        $res = $this->client()->get('/api/dc/expected_tat', [
            'origin_pin' => $originPin,
            'destination_pin' => $destPin,
            'mot' => 'S',
            'pdt' => $paymentMode,
        ]);

        if ($res->failed()) {
            Log::warning('Delhivery TAT fetch failed', [
                'origin' => $originPin,
                'destination' => $destPin,
                'status' => $res->status(),
                'body' => $res->body(),
            ]);
            return [
                'success' => false,
                'reason' => 'API_ERROR',
                'status_code' => $res->status(),
                'raw' => $res->json() ?? $res->body(),
            ];
        }

        $data = $res->json();
        $tat = data_get($data, 'data.tat');

        return [
            'success' => true,
            'tat' => $tat,
            'expected_date' => $tat ? now()->addDays($tat)->toDateString() : null,
            'raw' => $data,
        ];
    }

   public function createShipment(array $shipment): array
{
    $shipment = array_merge([
        'country' => 'India',
        'order_date' => now()->format('Y-m-d H:i:s'),
        'seller_name' => config('services.delhivery.seller_name'),
        'seller_add' => config('services.delhivery.seller_address'),
        'seller_gst_tin' => config('services.delhivery.seller_gst_tin'),
    ], $shipment);

    $payload = [
        'pickup_location' => [
            'name' => config('services.delhivery.pickup_location'),
        ],
        'shipments' => [$shipment],
    ];

    $res = $this->client()->asForm()->post('/api/cmu/create.json', [
        'format' => 'json',
        'data' => json_encode($payload),
    ]);

    if ($res->failed()) {
        Log::warning('Delhivery shipment creation HTTP failure', [
            'order_id' => $shipment['order'] ?? null,
            'status' => $res->status(),
            'body' => $res->body(),
        ]);
        return [
            'success' => false,
            'reason' => 'API_ERROR',
            'status_code' => $res->status(),
            'raw' => $res->json() ?? $res->body(),
        ];
    }

    $data = $res->json();
    $success = (bool) data_get($data, 'success', false);
    $package = data_get($data, 'packages.0', []);

    if (!$success || data_get($package, 'status') === 'Fail') {
        $errCode = data_get($package, 'err_code');

        Log::warning('Delhivery rejected shipment', [
            'order_id' => $shipment['order'] ?? null,
            'err_code' => $errCode,
            'raw' => $data,
        ]);

        if ($errCode === 'ER0005') {
            return [
                'success' => false,
                'reason' => 'Delhivery flagged this order/consignee as suspicious (ER0005). Retry with a fresh order_id, verify the phone/address, or contact client.support@delhivery.com if it persists.',
                'err_code' => $errCode,
                'raw' => $data,
            ];
        }

        return [
            'success' => false,
            'reason' => data_get($package, 'remarks.0', 'REJECTED_BY_DELHIVERY'),
            'err_code' => $errCode,
            'raw' => $data,
        ];
    }

    return [
        'success' => true,
        'waybill' => data_get($package, 'waybill'),
        'refnum' => data_get($package, 'refnum'),
        'raw' => $data,
    ];
}

    public function cancelShipment(string $waybill): array
    {
        $res = $this->client()->post('/api/p/edit', [
            'waybill' => $waybill,
            'cancellation' => 'true',
        ]);

        if ($res->failed()) {
            Log::warning('Delhivery cancellation failed', [
                'waybill' => $waybill,
                'status' => $res->status(),
                'body' => $res->body(),
            ]);
            return [
                'success' => false,
                'reason' => 'API_ERROR',
                'status_code' => $res->status(),
                'raw' => $res->json() ?? $res->body(),
            ];
        }

        return ['success' => true, 'raw' => $res->json()];
    }

    public function trackShipment(string $waybill): array
{
    $res = $this->client()->get('/api/v1/packages/json/', [
        'waybill' => $waybill,
    ]);
    if ($res->failed()) {
        Log::warning('Delhivery tracking failed', [
            'waybill' => $waybill,
            'status' => $res->status(),
            'body' => $res->body(),
        ]);
        return [
            'success' => false,
            'reason' => 'API_ERROR',
            'status_code' => $res->status(),
            'raw' => $res->json() ?? $res->body(),
        ];
    }
    $data = $res->json();
    $shipment = data_get($data, 'ShipmentData.0.Shipment');

    return [
        'success' => true,
        'status' => data_get($shipment, 'Status.Status'),
        // ADD — NDR detection fields.
        // StatusType 'UD' = undelivered attempt (this is what an NDR is).
        // Instructions/remark carries Delhivery's stated reason (e.g.
        // "Consignee not available", "Address incorrect").
        'status_type' => data_get($shipment, 'Status.StatusType'),
        'status_datetime' => data_get($shipment, 'Status.StatusDateTime'),
        'ndr_reason' => data_get($shipment, 'Status.Instructions')
            ?? data_get($shipment, 'Status.StatusLocation')
            ?? null,
        'raw' => $data,
    ];
}



    public function calculateShippingCost(
    string $originPin,
    string $destPin,
    int $weightGrams,
    string $paymentMode = 'Prepaid',
    float $codAmount = 0,
    string $mode = 'S'
): array {
    $res = $this->client()->get('/api/kinko/v1/invoice/charges/.json', [
        'md'    => $mode,
        'ss'    => 'Delivered',
        'o_pin' => $originPin,
        'd_pin' => $destPin,
        'cgm'   => $weightGrams,
        'pt'    => $paymentMode,
        'cod'   => $paymentMode === 'COD' ? $codAmount : 0,
    ]);

    if ($res->failed()) {
        Log::warning('Delhivery shipping cost fetch failed', [
            'origin' => $originPin, 'destination' => $destPin,
            'status' => $res->status(), 'body' => $res->body(),
        ]);
        return ['success' => false, 'reason' => 'API_ERROR', 'status_code' => $res->status(), 'raw' => $res->json() ?? $res->body()];
    }

    $data = $res->json();
    $charge = data_get($data, '0', []);

    if (empty($charge)) {
        return ['success' => false, 'reason' => 'NO_RATE_AVAILABLE', 'raw' => $data];
    }

    return [
        'success'      => true,
        'total_amount' => data_get($charge, 'total_amount'),
        'gross_amount' => data_get($charge, 'gross_amount'),
        'tax_data'     => data_get($charge, 'tax_data'),
        'raw'          => $data,
    ];
}


public function generateLabel(string $waybill): array
{
    $res = $this->client()->get('/api/p/packing_slip', [
        'wbns' => $waybill,
        'pdf'  => 'true',
    ]);

    if ($res->failed()) {
        Log::warning('Delhivery label generation failed', ['waybill' => $waybill, 'status' => $res->status(), 'body' => $res->body()]);
        return ['success' => false, 'reason' => 'API_ERROR', 'status_code' => $res->status(), 'raw' => $res->json() ?? $res->body()];
    }

    $data = $res->json();
    $pdfUrl = data_get($data, 'packages.0.pdf_download_link');

    if (!$pdfUrl) {
        return ['success' => false, 'reason' => 'LABEL_NOT_READY', 'raw' => $data];
    }

    return ['success' => true, 'pdf_url' => $pdfUrl, 'raw' => $data];
}


public function createPickupRequest(array $data): array
{
    $payload = [
        'pickup_time'            => $data['pickup_time'],
        'pickup_date'            => $data['pickup_date'],
        'pickup_location'        => $data['pickup_location'] ?? config('services.delhivery.pickup_location'),
        'expected_package_count' => $data['expected_package_count'],
    ];

    $res = $this->client()->post('/fm/request/new/', $payload);

    if ($res->failed()) {
        Log::warning('Delhivery pickup request failed', ['status' => $res->status(), 'body' => $res->body()]);
        return ['success' => false, 'reason' => 'API_ERROR', 'status_code' => $res->status(), 'raw' => $res->json() ?? $res->body()];
    }

    return ['success' => true, 'raw' => $res->json()];
}


public function updateNDR(string $waybill, string $action, ?string $comment = null): array
{
    // $action: 'RE-ATTEMPT', 'DEFERRED', or 'RTO'
    $payload = [
        'data' => json_encode([[
            'waybill' => $waybill,
            'act'     => $action,
            'comment' => $comment ?? '',
        ]]),
    ];

    $res = $this->client()->asForm()->post('/api/p/update', $payload);

    if ($res->failed()) {
        Log::warning('Delhivery NDR update failed', ['waybill' => $waybill, 'action' => $action, 'status' => $res->status(), 'body' => $res->body()]);
        return ['success' => false, 'reason' => 'API_ERROR', 'status_code' => $res->status(), 'raw' => $res->json() ?? $res->body()];
    }

    return ['success' => true, 'raw' => $res->json()];
}

public function updateEwaybill(string $waybill, string $ewaybillNumber): array
{
    $res = $this->client()->post('/api/p/edit', [
        'waybill' => $waybill,
        'ewbn'    => $ewaybillNumber,
    ]);

    if ($res->failed()) {
        Log::warning('Delhivery eWaybill update failed', ['waybill' => $waybill, 'status' => $res->status(), 'body' => $res->body()]);
        return ['success' => false, 'reason' => 'API_ERROR', 'status_code' => $res->status(), 'raw' => $res->json() ?? $res->body()];
    }

    return ['success' => true, 'raw' => $res->json()];
}
}