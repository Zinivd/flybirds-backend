<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Exception;

class OrderController extends Controller
{
    /**
     * GET: List all orders with searching & filtering
     */
    public function index(Request $request)
    {
        try {
            $query = Order::with('items');

            // Search
            if ($request->has('search') && !empty($request->search)) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('order_id', 'like', "%{$search}%")
                      ->orWhere('customer_name', 'like', "%{$search}%")
                      ->orWhere('customer_email', 'like', "%{$search}%")
                      ->orWhere('seller_name', 'like', "%{$search}%")
                      ->orWhere('delivery_status', 'like', "%{$search}%")
                      ->orWhere('payment_method', 'like', "%{$search}%")
                      ->orWhere('payment_status', 'like', "%{$search}%");
                });
            }

            // Filter by delivery status
            if ($request->has('delivery_status') && !empty($request->delivery_status)) {
                $query->where('delivery_status', $request->delivery_status);
            }

            // Filter by payment status
            if ($request->has('payment_status') && !empty($request->payment_status)) {
                $query->where('payment_status', $request->payment_status);
            }

            $orders = $query->orderBy('created_at', 'desc')->get();

            return response()->json([
                'status' => 'success',
                'data' => $orders
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve orders.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * GET: View order details
     */
    public function show($id)
    {
        try {
            $order = Order::with(['items', 'customer'])->find($id);

            if (!$order) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Order not found.'
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'data' => $order
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve order details.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * PATCH: Update order delivery and/or payment status
     */
    public function updateStatus(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'delivery_status' => 'sometimes|string|in:Pending,Shipped,Out For Delivery,Delivered,Cancelled,Refunded',
            'payment_status' => 'sometimes|string|in:Paid,Pending,Refunded,Failed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $order = Order::find($id);

            if (!$order) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Order not found.'
                ], 404);
            }

            if ($request->has('delivery_status')) {
                $order->delivery_status = $request->delivery_status;
            }

            if ($request->has('payment_status')) {
                $order->payment_status = $request->payment_status;
            }

            $order->save();

            return response()->json([
                'status' => 'success',
                'message' => 'Order status updated successfully.',
                'data' => $order->load('items')
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update order status.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * DELETE: Delete an order
     */
    public function destroy($id)
    {
        try {
            $order = Order::find($id);

            if (!$order) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Order not found.'
                ], 404);
            }

            $order->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'Order deleted successfully.'
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete order.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
