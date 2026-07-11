<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductReview;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Exception;

/**
 * ═══════════════════════════════════════════════════════════════
 * ProductReviewController
 *
 * Endpoints:
 *   GET    /reviews                        list (filters: user_id, product_id, rating, search)
 *   POST   /reviews                        create a review
 *   GET    /reviews/{id}                   single review (with user + product)
 *   PATCH  /reviews/{id}                   update own review
 *   DELETE /reviews/{id}                   delete a review
 *   GET    /users/{userId}/reviews         reviews written by one customer
 *   GET    /products/{productId}/reviews   reviews for one product + rating stats
 *
 * NOTE: The user() relation on ProductReview points at App\Models\User,
 * which must have $table = 'fly_users' and $primaryKey = 'user_id' set
 * (string, non-incrementing). If that model config is wrong, ->load('user')
 * / with('user') will throw "Unknown column 'users.user_id'" even though
 * the review row itself was created successfully. Fix that in the User
 * model — this controller just avoids letting that failure look like a
 * failed create.
 * ═══════════════════════════════════════════════════════════════
 */
class ProductReviewController extends Controller
{
    private const RELATIONS = ['user', 'product'];

    // ═══════════════════════════════════════════════════════════════
    // PRIVATE HELPER: Build rating aggregate stats for a product.
    // ═══════════════════════════════════════════════════════════════
    private function ratingStatsForProduct(int $productId): array
    {
        $summary = ProductReview::where('product_id', $productId)
            ->selectRaw('
                COUNT(*) as total_reviews,
                COALESCE(AVG(rating), 0) as average_rating,
                COALESCE(MIN(rating), 0) as min_rating,
                COALESCE(MAX(rating), 0) as max_rating
            ')
            ->first();

        $totalReviews = (int) $summary->total_reviews;

        $breakdown = ProductReview::where('product_id', $productId)
            ->select('rating')
            ->selectRaw('COUNT(*) as count')
            ->groupBy('rating')
            ->pluck('count', 'rating');

        $distribution = [];
        for ($star = 5; $star >= 1; $star--) {
            $count = (int) ($breakdown[$star] ?? 0);
            $distribution[] = [
                'star' => $star,
                'count' => $count,
                'percentage' => $totalReviews > 0 ? round(($count / $totalReviews) * 100, 1) : 0,
            ];
        }

        return [
            'total_reviews' => $totalReviews,
            'average_rating' => round((float) $summary->average_rating, 1),
            'min_rating' => (int) $summary->min_rating,
            'max_rating' => (int) $summary->max_rating,
            'rating_range' => $totalReviews > 0
                ? ((int) $summary->min_rating . ' - ' . (int) $summary->max_rating)
                : null,
            'distribution' => $distribution,
        ];
    }

    // ═══════════════════════════════════════════════════════════════
    // Safely attach relations to a review without letting a broken
    // relation config (e.g. User model table mismatch) blow up the
    // whole request. Logs the issue and returns the review as-is.
    // ═══════════════════════════════════════════════════════════════
    private function safeLoad(ProductReview $review, array $relations = self::RELATIONS): ProductReview
    {
        try {
            $review->load($relations);
        } catch (Exception $e) {
            Log::warning('Review relation load failed (returning review without relations): ' . $e->getMessage());
        }
        return $review;
    }

    // ═══════════════════════════════════════════════════════════════
    // GET /reviews
    // Query params: user_id, product_id, rating, search (title), per_page
    // ═══════════════════════════════════════════════════════════════
    public function index(Request $request)
{
    try {
        $query = ProductReview::with([
            'user:user_id,name,email',
            'product:id,name,category_id',
            'product.category:id,name',
        ]);

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->query('product_id'));
        }
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->query('user_id'));
        }
        if ($request->filled('rating')) {
            $query->where('rating', $request->query('rating'));
        }
        if ($request->filled('search')) {
            $query->where('title', 'like', '%' . $request->query('search') . '%');
        }

        $perPage = (int) $request->query('per_page', 15);
        $reviews = $query->latest()->paginate($perPage > 0 ? $perPage : 15);

        $reviews->getCollection()->transform(function ($review) {
            return [
                'id'          => $review->id,
                'title'       => $review->title,
                'description' => $review->description,
                'rating'      => $review->rating,
                'created_at'  => $review->created_at,
                'user'        => $review->user ? [
                    'id'    => $review->user->user_id,
                    'name'  => $review->user->name,
                    'email' => $review->user->email,
                ] : null,
                'product'     => $review->product ? [
                    'id'            => $review->product->id,
                    'name'          => $review->product->name,
                    'category_name' => $review->product->category->name ?? null,
                ] : null,
            ];
        });

        return response()->json(['status' => 'success', 'data' => $reviews], 200);
    } catch (Exception $e) {
        Log::error('Review Index Error: ' . $e->getMessage());
        return response()->json(['status' => 'error', 'message' => 'Failed to retrieve reviews.'], 500);
    }
}



    // ═══════════════════════════════════════════════════════════════
    // POST /reviews
    // ═══════════════════════════════════════════════════════════════
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'user_id' => 'required|string|exists:fly_users,user_id',
                'product_id' => 'required|integer|exists:products,id',
                'title' => 'required|string|max:255',
                'description' => 'nullable|string',
                'rating' => 'required|integer|min:1|max:5',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        }

        try {
            $alreadyReviewed = ProductReview::where('user_id', $validated['user_id'])
                ->where('product_id', $validated['product_id'])
                ->exists();

            if ($alreadyReviewed) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'You have already reviewed this product.',
                ], 409);
            }

            // The insert itself — this is the part that must succeed for
            // the review to "count". Kept separate from relation loading.
            $review = ProductReview::create($validated);
        } catch (Exception $e) {
            Log::error('Review Store Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to submit review.'], 500);
        }

        // Relation loading is best-effort. If the User model's table/key
        // config is wrong, this will log a warning but still return 201
        // with the review the client just created.
        $review = $this->safeLoad($review);

        return response()->json([
            'status' => 'success',
            'message' => 'Review submitted successfully.',
            'data' => $review,
        ], 201);
    }

    // ═══════════════════════════════════════════════════════════════
    // GET /reviews/{id}
    // ═══════════════════════════════════════════════════════════════
    public function show($id)
{
    try {
        $review = ProductReview::with([
            'user:user_id,name,email',
            'product:id,name,category_id',
            'product.category:id,name',
        ])->findOrFail($id);

        $data = [
            'id'          => $review->id,
            'title'       => $review->title,
            'description' => $review->description,
            'rating'      => $review->rating,
            'created_at'  => $review->created_at,
            'user'        => $review->user ? [
                'id'    => $review->user->user_id,
                'name'  => $review->user->name,
                'email' => $review->user->email,
            ] : null,
            'product'     => $review->product ? [
                'id'            => $review->product->id,
                'name'          => $review->product->name,
                'category_name' => $review->product->category->name ?? null,
            ] : null,
        ];

        return response()->json(['status' => 'success', 'data' => $data], 200);
    } catch (ModelNotFoundException $e) {
        return response()->json(['status' => 'error', 'message' => 'Review not found.'], 404);
    } catch (Exception $e) {
        Log::error('Review Show Error: ' . $e->getMessage());
        return response()->json(['status' => 'error', 'message' => 'Failed to retrieve review.'], 500);
    }
}

    // ═══════════════════════════════════════════════════════════════
    // PATCH /reviews/{id}
    // ═══════════════════════════════════════════════════════════════
    public function update(Request $request, $id)
    {
        try {
            $review = ProductReview::findOrFail($id);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'Review not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'rating' => 'sometimes|integer|min:1|max:5',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        try {
            $review->update($validator->validated());
        } catch (Exception $e) {
            Log::error('Review Update Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to update review.'], 500);
        }

        $review = $this->safeLoad($review);

        return response()->json([
            'status' => 'success',
            'message' => 'Review updated successfully.',
            'data' => $review,
        ], 200);
    }

    // ═══════════════════════════════════════════════════════════════
    // DELETE /reviews/{id}
    // ═══════════════════════════════════════════════════════════════
    public function destroy($id)
    {
        try {
            $review = ProductReview::findOrFail($id);
            $review->delete();
            return response()->json(['status' => 'success', 'message' => 'Review deleted successfully.'], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'Review not found.'], 404);
        } catch (Exception $e) {
            Log::error('Review Delete Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to delete review.'], 500);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // GET /users/{userId}/reviews
    // All reviews written by one customer, with product info attached.
    // ═══════════════════════════════════════════════════════════════
    public function byCustomer(Request $request, $userId)
    {
        try {
            $query = ProductReview::with('product')->where('user_id', $userId);

            if ($request->filled('rating')) {
                $query->where('rating', $request->rating);
            }

            $reviews = $query->orderByDesc('created_at')->get();

            return response()->json([
                'status' => 'success',
                'data' => [
                    'user_id' => $userId,
                    'total_reviews' => $reviews->count(),
                    'reviews' => $reviews,
                ],
            ], 200);
        } catch (Exception $e) {
            Log::error('Reviews By Customer Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to retrieve customer reviews.'], 500);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // GET /products/{productId}/reviews
    // All reviews for one product, with user info attached, plus a
    // rating summary: average, min/max range, and star distribution.
    // ═══════════════════════════════════════════════════════════════
    public function byProduct(Request $request, $productId)
    {
        try {
            $product = Product::find($productId);
            if (!$product) {
                return response()->json(['status' => 'error', 'message' => 'Product not found.'], 404);
            }

            try {
                $query = ProductReview::with('user')->where('product_id', $productId);
                if ($request->filled('rating')) {
                    $query->where('rating', $request->rating);
                }
                $perPage = (int) $request->query('per_page', 20);
                $reviews = $query->orderByDesc('created_at')->paginate($perPage > 0 ? $perPage : 20);
            } catch (Exception $e) {
                Log::warning('Reviews By Product relation load failed, retrying without user relation: ' . $e->getMessage());
                $query = ProductReview::where('product_id', $productId);
                if ($request->filled('rating')) {
                    $query->where('rating', $request->rating);
                }
                $perPage = (int) $request->query('per_page', 20);
                $reviews = $query->orderByDesc('created_at')->paginate($perPage > 0 ? $perPage : 20);
            }

            return response()->json([
                'status' => 'success',
                'data' => [
                    'product' => [
                        'id' => $product->id,
                        'name' => $product->name,
                    ],
                    'rating_summary' => $this->ratingStatsForProduct((int) $productId),
                    'reviews' => $reviews,
                ],
            ], 200);
        } catch (Exception $e) {
            Log::error('Reviews By Product Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to retrieve product reviews.'], 500);
        }
    }
}