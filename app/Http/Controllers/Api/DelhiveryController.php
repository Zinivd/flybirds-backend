<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DelhiveryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Exception;

class DelhiveryController extends Controller
{
    protected DelhiveryService $delhiveryService;

    public function __construct(DelhiveryService $delhiveryService)
    {
        $this->delhiveryService = $delhiveryService;
    }

    /**
     * GET: Check pincode serviceability
     */
    public function checkServiceability(Request $request, $pincode)
    {
        // Simple numeric format validation
        if (!preg_match('/^[1-9][0-9]{5}$/', $pincode)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid pincode format. Must be a 6-digit number.'
            ], 400);
        }

        try {
            $data = $this->delhiveryService->checkPincodeServiceability($pincode);
            return response()->json([
                'status' => 'success',
                'data' => $data
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * GET: Generate shipping label (packing slip)
     */
    public function getLabel(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'waybills' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $labelData = $this->delhiveryService->generateShippingLabel($request->waybills);
            
            // If returning html or pdf, we can send it directly
            if (str_contains($labelData['format'], 'html')) {
                return response($labelData['content'], 200)
                    ->header('Content-Type', 'text/html');
            } else if (str_contains($labelData['format'], 'pdf')) {
                return response($labelData['content'], 200)
                    ->header('Content-Type', 'application/pdf')
                    ->header('Content-Disposition', 'attachment; filename="shipping_label.pdf"');
            }

            return response()->json([
                'status' => 'success',
                'data' => $labelData['content']
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * GET: Track shipment status
     */
    public function track(Request $request, $waybill)
    {
        if (empty($waybill)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Waybill number or reference ID is required.'
            ], 400);
        }

        try {
            $data = $this->delhiveryService->trackShipment($waybill);
            return response()->json([
                'status' => 'success',
                'data' => $data
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * POST: Create/Book a B2C shipment
     */
    public function createShipment(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'shipments' => 'required|array|min:1',
            'shipments.*.order' => 'required|string',
            'shipments.*.consignee.name' => 'required|string',
            'shipments.*.consignee.phone' => 'required|string',
            'shipments.*.consignee.address' => 'required|string',
            'shipments.*.consignee.city' => 'required|string',
            'shipments.*.consignee.state' => 'required|string',
            'shipments.*.consignee.pincode' => 'required|string',
            'shipments.*.pickup_location.name' => 'required|string',
            'shipments.*.pickup_location.phone' => 'required|string',
            'shipments.*.pickup_location.address' => 'required|string',
            'shipments.*.pickup_location.city' => 'required|string',
            'shipments.*.pickup_location.state' => 'required|string',
            'shipments.*.pickup_location.pincode' => 'required|string',
            'shipments.*.payment_mode' => 'required|string|in:Prepaid,COD',
            'shipments.*.total_amount' => 'required|numeric',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $data = $this->delhiveryService->createShipment($request->all());
            return response()->json([
                'status' => 'success',
                'data' => $data
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * GET: Calculate approximated shipping charges
     */
    public function calculateCost(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'o_pin' => 'required|string|size:6',
            'd_pin' => 'required|string|size:6',
            'cgm' => 'sometimes|numeric|min:1',
            'md' => 'sometimes|string|in:E,S',
            'ss' => 'sometimes|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $data = $this->delhiveryService->calculateShippingCost($request->all());
            return response()->json([
                'status' => 'success',
                'data' => $data
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
