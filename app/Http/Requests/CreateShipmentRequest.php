<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateShipmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'order_id' => 'required|string|exists:orders,order_id',
            // THE FIX — without this rule, $request->validated() silently
            // drops 'waybill' even when the frontend sends it, so the
            // pre-fetched waybill never reaches DelhiveryController's
            // $shipmentPayload, and awb_number never gets set.
            'waybill' => 'nullable|string',
            'pin' => 'nullable|digits:6',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'weight' => 'nullable|numeric|min:0',
            'shipment_length' => 'nullable|numeric',
            'shipment_width' => 'nullable|numeric',
            'shipment_height' => 'nullable|numeric',
        ];
    }
}