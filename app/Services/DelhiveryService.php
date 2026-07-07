<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class DelhiveryService
{
    protected string $baseUrl;
    protected string $token;

    public function __construct()
    {
        $this->baseUrl = env('DELHIVERY_BASE_URL', 'https://track.delhivery.com');
        $this->token = env('DELHIVERY_API_TOKEN', '');
    }

    /**
     * Check pincode serviceability.
     */
    public function checkPincodeServiceability(string $pincode)
    {
        if (empty($this->token)) {
            throw new Exception("Delhivery API token is not configured.");
        }

        $url = rtrim($this->baseUrl, '/') . '/c/api/pin-codes/json/';

        $response = Http::withHeaders([
            'Authorization' => 'Token ' . $this->token,
        ])->get($url, [
            'filter_codes' => $pincode
        ]);

        if ($response->failed()) {
            Log::error('Delhivery Pincode Serviceability Check Failed', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);
            throw new Exception("Failed to check pincode serviceability: " . $response->body());
        }

        return $response->json();
    }

    /**
     * Generate Shipping Label (Packing Slip) for Waybill Numbers.
     */
    public function generateShippingLabel(string $waybills)
    {
        if (empty($this->token)) {
            throw new Exception("Delhivery API token is not configured.");
        }

        $url = rtrim($this->baseUrl, '/') . '/api/p/packing_slip';

        $response = Http::withHeaders([
            'Authorization' => 'Token ' . $this->token,
        ])->get($url, [
            'wbns' => $waybills
        ]);

        if ($response->failed()) {
            Log::error('Delhivery Shipping Label Generation Failed', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);
            throw new Exception("Failed to generate shipping label: " . $response->body());
        }

        // The packing slip API typically returns HTML content or PDF data
        return [
            'format' => $response->header('Content-Type'),
            'content' => $response->body()
        ];
    }

    /**
     * Track shipment status by waybill number or client order reference ID.
     */
    public function trackShipment(string $waybillOrRefId)
    {
        if (empty($this->token)) {
            throw new Exception("Delhivery API token is not configured.");
        }

        $url = rtrim($this->baseUrl, '/') . '/api/v1/packages/json/';

        // Determine if it is likely a waybill (numeric, typically 12+ digits) or a reference ID
        $params = [];
        if (is_numeric($waybillOrRefId) && strlen($waybillOrRefId) >= 10) {
            $params['waybill'] = $waybillOrRefId;
        } else {
            $params['ref_ids'] = $waybillOrRefId;
        }

        $response = Http::withHeaders([
            'Authorization' => 'Token ' . $this->token,
        ])->get($url, $params);

        if ($response->failed()) {
            Log::error('Delhivery Shipment Tracking Failed', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);
            throw new Exception("Failed to track shipment: " . $response->body());
        }

        return $response->json();
    }

    /**
     * Create/Book a B2C shipment with Delhivery.
     */
    public function createShipment(array $shipmentData)
    {
        if (empty($this->token)) {
            throw new Exception("Delhivery API token is not configured.");
        }

        $url = rtrim($this->baseUrl, '/') . '/api/cmu/create.json';

        // Format payload as form parameters: format=json and data=<json_string>
        $response = Http::asForm()->withHeaders([
            'Authorization' => 'Token ' . $this->token,
        ])->post($url, [
            'format' => 'json',
            'data' => json_encode($shipmentData)
        ]);

        if ($response->failed()) {
            Log::error('Delhivery Shipment Creation Failed', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);
            throw new Exception("Failed to create shipment: " . $response->body());
        }

        return $response->json();
    }

    /**
     * Calculate Shipping Cost / Rates.
     */
    public function calculateShippingCost(array $params)
    {
        if (empty($this->token)) {
            throw new Exception("Delhivery API token is not configured.");
        }

        $url = rtrim($this->baseUrl, '/') . '/api/kinko/v1/invoice/charges/.json';

        // Prepare query parameters
        $query = [
            'md' => $params['md'] ?? 'E', // E for Express, S for Surface
            'cgm' => $params['cgm'] ?? 500, // weight in grams
            'ss' => $params['ss'] ?? 'Delivered',
            'o_pin' => $params['o_pin'] ?? '',
            'd_pin' => $params['d_pin'] ?? '',
        ];

        $response = Http::withHeaders([
            'Authorization' => 'Token ' . $this->token,
        ])->get($url, $query);

        if ($response->failed()) {
            Log::error('Delhivery Shipping Cost Calculation Failed', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);
            throw new Exception("Failed to calculate shipping cost: " . $response->body());
        }

        return $response->json();
    }
}
