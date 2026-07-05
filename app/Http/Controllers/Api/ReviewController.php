<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Exception;

class ReviewController extends Controller
{
    /**
     * GET: List all reviews (with optional filtering by customer or product)
     * Query params: ?product_id=1 ?customer_id=FYB-USR-XXXXXX
     */
    public function index(Request $request)
    {
        try {
            $query = Review::with(['customer', 'product']);

            if ($request->filled('product_id')) {
                $query->where('product_id', $request->product_id);
            }

            if ($request->filled('customer_id')) {
                $query->where('customer_id', $request->customer_id);
            }

            $reviews = $query->orderBy('created_at', 'desc')->paginate(15);

            return response()->json([
                'status' => 'success',
                'data' => $reviews
            ], 200);
        } catch (Exception $e) {
            Log::error('Review Index Error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve reviews.'
            ], 500);
        }
    }

    /**
     * POST: Store a new review
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'customer_id' => 'required|string|exists:fly_users,user_id',
            'product_id' => 'required|integer|exists:products,id',
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $review = Review::create([
                'customer_id' => $request->customer_id,
                'product_id' => $request->product_id,
                'rating' => $request->rating,
                'comment' => $request->comment,
            ]);

            // Load relations to return complete details
            $review->load(['customer', 'product']);

            return response()->json([
                'status' => 'success',
                'message' => 'Review submitted successfully.',
                'data' => $review
            ], 201);
        } catch (Exception $e) {
            Log::error('Review Store Error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to store review.'
            ], 500);
        }
    }

    /**
     * DELETE: Delete a review
     */
    public function destroy($id)
    {
        try {
            $review = Review::findOrFail($id);
            $review->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'Review deleted successfully.'
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Review not found or failed to delete.'
            ], 404);
        }
    }
}
