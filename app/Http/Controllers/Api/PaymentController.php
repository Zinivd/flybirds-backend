<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Exception;

class PaymentController extends Controller
{
    /**
     * POST: Create a Razorpay Order
     */
    public function createOrder(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:1',
            'currency' => 'sometimes|string|max:10',
            'order_table_id' => 'sometimes|exists:orders,id',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors()
            ], 422);
        }
        try {
            $keyId = config('services.razorpay.key');
$keySecret = config('services.razorpay.secret');
            if (empty($keyId) || empty($keySecret)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Razorpay API credentials are not configured on the server.'
                ], 500);
            }
            $amount = $request->amount;
            $currency = $request->input('currency', 'INR');
            $orderTableId = $request->input('order_table_id');
            $amountInPaise = intval(round($amount * 100));
            $response = Http::withBasicAuth($keyId, $keySecret)
                ->post('https://api.razorpay.com/v1/orders', [
                    'amount' => $amountInPaise,
                    'currency' => $currency,
                    'receipt' => 'rcpt_' . uniqid(),
                ]);
            if ($response->failed()) {
                Log::error('Razorpay Order Creation Failed', [
                    'response' => $response->body(),
                    'status' => $response->status()
                ]);
                return response()->json([
                    'status' => 'error',
                    'message' => 'Failed to create order with Razorpay.',
                    'details' => $response->json()
                ], $response->status());
            }
            $razorpayOrder = $response->json();
            $transaction = Transaction::create([
                'order_table_id' => $orderTableId,
                'razorpay_order_id' => $razorpayOrder['id'],
                'amount' => $amount,
                'currency' => $currency,
                'status' => 'Pending',
                'payload' => $razorpayOrder,
            ]);
            return response()->json([
                'status' => 'success',
                'message' => 'Razorpay order created successfully.',
                'data' => [
                    'razorpay_order_id' => $razorpayOrder['id'],
                    'amount' => $razorpayOrder['amount'],
                    'currency' => $razorpayOrder['currency'],
                    'key_id' => $keyId,
                    'transaction_id' => $transaction->id,
                ]
            ], 201);
        } catch (Exception $e) {
            Log::error('Error creating Razorpay Order', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'An internal server error occurred while initiating the payment.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * POST: Verify Razorpay Payment Signature
     */
    public function verifyPayment(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'razorpay_order_id' => 'required|string',
            'razorpay_payment_id' => 'required|string',
            'razorpay_signature' => 'required|string',
            'order_table_id' => 'sometimes|exists:orders,id',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors()
            ], 422);
        }
        try {
		            $keyId = config('services.razorpay.key');
$keySecret = config('services.razorpay.secret');

		if (empty($keySecret)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Razorpay API credentials are not configured on the server.'
                ], 500);
            }
            $razorpayOrderId = $request->razorpay_order_id;
            $razorpayPaymentId = $request->razorpay_payment_id;
            $razorpaySignature = $request->razorpay_signature;

            $expectedSignature = hash_hmac('sha256', $razorpayOrderId . '|' . $razorpayPaymentId, $keySecret);

            $transaction = Transaction::where('razorpay_order_id', $razorpayOrderId)->first();

            $fallbackOrderTableId = $request->input('order_table_id');
            if ($transaction && !$transaction->order_table_id && $fallbackOrderTableId) {
                $transaction->order_table_id = $fallbackOrderTableId;
            }

            $resolvedOrderTableId = $transaction->order_table_id ?? $fallbackOrderTableId ?? null;

            if (hash_equals($expectedSignature, $razorpaySignature)) {
                if ($transaction) {
                    $transaction->razorpay_payment_id = $razorpayPaymentId;
                    $transaction->razorpay_signature = $razorpaySignature;
                    $transaction->status = 'Success';
                    $transaction->payload = array_merge((array) $transaction->payload, [
                        'verification_response' => $request->all()
                    ]);
                    $transaction->save();
                }

                if ($resolvedOrderTableId) {
                    $order = Order::find($resolvedOrderTableId);
                    if ($order) {
                        $order->update(['payment_status' => 'Paid']);
                    } else {
                        Log::warning("Verify Payment: order_table_id {$resolvedOrderTableId} not found while marking Paid.");
                    }
                } else {
                    Log::warning("Verify Payment: no order_table_id resolvable for razorpay_order_id {$razorpayOrderId}. Payment verified but no order updated.");
                }

                return response()->json([
                    'status' => 'success',
                    'message' => 'Payment verified successfully.',
                    'data' => [
                        'order_table_id' => $resolvedOrderTableId,
                        'payment_status' => $resolvedOrderTableId ? 'Paid' : null,
                    ],
                ], 200);
            } else {
                if ($transaction) {
                    $transaction->razorpay_payment_id = $razorpayPaymentId;
                    $transaction->razorpay_signature = $razorpaySignature;
                    $transaction->status = 'Failed';
                    $transaction->payload = array_merge((array) $transaction->payload, [
                        'verification_failure' => 'Signature mismatch',
                        'received_data' => $request->all()
                    ]);
                    $transaction->save();
                }

                if ($resolvedOrderTableId) {
                    $order = Order::find($resolvedOrderTableId);
                    if ($order) {
                        $order->update(['payment_status' => 'Failed']);
                    }
                }

                return response()->json([
                    'status' => 'error',
                    'message' => 'Payment signature verification failed.'
                ], 400);
            }
        } catch (Exception $e) {
            Log::error('Error verifying Razorpay payment', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'An internal server error occurred while verifying the payment.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
