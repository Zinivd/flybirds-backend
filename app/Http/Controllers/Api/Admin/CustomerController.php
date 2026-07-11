<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\FlyUser;
use Exception;

class CustomerController extends Controller
{
    /**
     * GET: List all customers (where user_type is 'user')
     */
    public function index()
    {
        try {
            $customers = FlyUser::where('user_type', 'user')->get();
            return response()->json([
                'status' => 'success',
                'data' => $customers
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve customers.'
            ], 500);
        }
    }

    /**
     * DELETE: Delete a customer (where user_type is 'user')
     */
    public function destroy($id)
    {
        try {
            $customer = FlyUser::where('user_type', 'user')->where('user_id', $id)->first();
            if (!$customer) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Customer not found.'
                ], 404);
            }

            $customer->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'Customer deleted successfully.'
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete customer.'
            ], 500);
        }
    }

    /**
     * PATCH: Lock a customer (set is_locked to true)
     */
    public function lock($id)
    {
        try {
            $customer = FlyUser::where('user_type', 'user')->where('user_id', $id)->first();
            if (!$customer) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Customer not found.'
                ], 404);
            }

            $customer->is_locked = true;
            $customer->save();

            return response()->json([
                'status' => 'success',
                'message' => 'Customer locked successfully.',
                'data' => $customer
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to lock customer.'
            ], 500);
        }
    }


     public function unlock($id)
    {
        try {
            $customer = FlyUser::where('user_type', 'user')->where('user_id', $id)->first();
            if (!$customer) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Customer not found.'
                ], 404);
            }
            $customer->is_locked = false;
            $customer->save();
            return response()->json([
                'status' => 'success',
                'message' => 'Customer unlocked successfully.',
                'data' => $customer
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to unlock customer.'
            ], 500);
        }
    }
}
