<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProductSupportQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Exception;

class ProductSupportQueryController extends Controller
{
    /**
     * GET: List all product support queries
     */
    public function index(Request $request)
    {
        try {
            $query = ProductSupportQuery::query();

            // Search
            if ($request->has('search') && !empty($request->search)) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('product_name', 'like', "%{$search}%")
                      ->orWhere('user_name', 'like', "%{$search}%")
                      ->orWhere('question', 'like', "%{$search}%")
                      ->orWhere('status', 'like', "%{$search}%");
                });
            }

            // Filter by status
            if ($request->has('status') && !empty($request->status)) {
                $query->where('status', $request->status);
            }

            $queries = $query->orderBy('created_at', 'desc')->get();

            return response()->json([
                'status' => 'success',
                'data' => $queries
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve support queries.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * GET: Show a specific query
     */
    public function show($id)
    {
        try {
            $query = ProductSupportQuery::with(['product', 'user'])->find($id);

            if (!$query) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Support query not found.'
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'data' => $query
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve support query details.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * POST/PUT/PATCH: Reply to a support query
     */
    public function reply(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'reply' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $query = ProductSupportQuery::find($id);

            if (!$query) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Support query not found.'
                ], 404);
            }

            $query->reply = $request->reply;
            $query->status = 'Replied';
            $query->save();

            return response()->json([
                'status' => 'success',
                'message' => 'Reply submitted successfully.',
                'data' => $query
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to submit reply.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * DELETE: Delete a support query
     */
    public function destroy($id)
    {
        try {
            $query = ProductSupportQuery::find($id);

            if (!$query) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Support query not found.'
                ], 404);
            }

            $query->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'Support query deleted successfully.'
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete support query.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
