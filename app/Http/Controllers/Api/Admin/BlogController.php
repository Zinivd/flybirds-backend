<?php
namespace App\Http\Controllers\Api\Admin;
use App\Http\Controllers\Controller;
use App\Models\Blog;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class BlogController extends Controller
{
    /* =========================================================
     |  ADMIN — CRUD
     * =======================================================*/

    /**
     * List all blogs (admin) — with product + optional filters
     */
    public function index(Request $request)
    {
        try {
            $query = Blog::with('product');
            if ($request->filled('search')) {
                $query->where('title', 'like', '%' . $request->search . '%');
            }
            if ($request->filled('product_id')) {
                $query->where('product_id', $request->product_id);
            }
            if ($request->filled('status')) {
                // status=published | unpublished
                $query->where('is_published', $request->status === 'published');
            }
            $blogs = $query->ordered()->paginate($request->get('per_page', 10));

            // Append resolved cover image URL to each item on this page
            $blogs->getCollection()->transform(function ($blog) {
                return $this->appendUrls($blog);
            });

            return response()->json([
                'status'  => 'success',
                'message' => 'Blogs fetched successfully',
                'data'    => $blogs,
            ], 200);
        } catch (Exception $e) {
            Log::error('Blog Index Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to fetch blogs.'], 500);
        }
    }

    /**
     * Store a new blog (admin) — uploads cover image to S3, saves its
     * storage key (path) on the model, DB stores only that key.
     */
    public function store(Request $request)
    {
        $request->validate([
            'title'          => 'required|string|max:255',
            'sub_title'      => 'nullable|string|max:255',
            'description_1'  => 'nullable|string',
            'description_2'  => 'nullable|string',
            'description_3'  => 'nullable|string',
            'cover_image'    => 'required|image|mimes:jpeg,png,jpg,webp|max:2048',
            'product_id'     => 'required|integer|exists:products,id',
            'is_published'   => 'nullable|boolean',
        ]);
        DB::beginTransaction();
        try {
            $blog = new Blog($request->only([
                'title', 'sub_title', 'description_1', 'description_2', 'description_3', 'product_id',
            ]));

            if ($request->hasFile('cover_image')) {
                // Store on S3 (public disk config assumed, same as CategoryController)
                // and save the returned KEY/PATH to the DB column, not a full URL.
                $blog->cover_image_path = $request->file('cover_image')->store('blogs/cover', 's3');
            }

            $isPublished = $request->boolean('is_published');
            $blog->is_published = $isPublished;
            $blog->published_at = $isPublished ? now() : null;
            $blog->save();

            DB::commit();

            return response()->json([
                'status'  => 'success',
                'message' => 'Blog created successfully',
                'data'    => $this->appendUrls($blog->load('product')),
            ], 201);
        } catch (Exception $e) {
            DB::rollBack();
            // If the image was uploaded but DB save failed, clean it up
            if (!empty($blog->cover_image_path)) {
                Storage::disk('s3')->delete($blog->cover_image_path);
            }
            Log::error('Blog Store Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to create blog.'], 500);
        }
    }

    /**
     * Show single blog (admin)
     */
    public function show($id)
    {
        try {
            $blog = Blog::with('product')->findOrFail($id);
            return response()->json([
                'status'  => 'success',
                'message' => 'Blog fetched successfully',
                'data'    => $this->appendUrls($blog),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'Blog not found.'], 404);
        } catch (Exception $e) {
            Log::error('Blog Show Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Blog not found.'], 404);
        }
    }

    /**
     * Update blog (admin) — replaces cover image on S3 if a new one is sent
     */
    public function update(Request $request, $id)
    {
        $request->validate([
            'title'          => 'required|string|max:255',
            'sub_title'      => 'nullable|string|max:255',
            'description_1'  => 'nullable|string',
            'description_2'  => 'nullable|string',
            'description_3'  => 'nullable|string',
            'cover_image'    => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'product_id'     => 'required|integer|exists:products,id',
            'is_published'   => 'nullable|boolean',
        ]);
        DB::beginTransaction();
        try {
            $blog = Blog::findOrFail($id);
            $blog->fill($request->only([
                'title', 'sub_title', 'description_1', 'description_2', 'description_3', 'product_id',
            ]));

            if ($request->hasFile('cover_image')) {
                $oldPath = $blog->cover_image_path;
                $blog->cover_image_path = $request->file('cover_image')->store('blogs/cover', 's3');
                // Delete old image only after the new one is confirmed stored
                if ($oldPath) {
                    Storage::disk('s3')->delete($oldPath);
                }
            }

            if ($request->has('is_published')) {
                $newStatus = $request->boolean('is_published');
                // only stamp published_at the moment it flips false -> true
                if ($newStatus && !$blog->is_published) {
                    $blog->published_at = now();
                }
                $blog->is_published = $newStatus;
            }

            $blog->save();
            DB::commit();

            return response()->json([
                'status'  => 'success',
                'message' => 'Blog updated successfully',
                'data'    => $this->appendUrls($blog->load('product')),
            ], 200);
        } catch (ModelNotFoundException $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => 'Blog not found.'], 404);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Blog Update Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to update blog.'], 500);
        }
    }

    /**
     * Quick publish / unpublish toggle (admin) — no other fields required
     */
    public function toggleStatus($id)
    {
        DB::beginTransaction();
        try {
            $blog = Blog::findOrFail($id);
            $blog->is_published = !$blog->is_published;
            if ($blog->is_published) {
                $blog->published_at = now();
            }
            $blog->save();
            DB::commit();

            return response()->json([
                'status'  => 'success',
                'message' => $blog->is_published ? 'Blog published' : 'Blog unpublished',
                'data'    => $this->appendUrls($blog),
            ], 200);
        } catch (ModelNotFoundException $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => 'Blog not found.'], 404);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Blog Toggle Status Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to update status.'], 500);
        }
    }

    /**
     * Delete blog (admin) — removes cover image from S3
     */
    public function destroy($id)
    {
        DB::beginTransaction();
        try {
            $blog = Blog::findOrFail($id);
            if ($blog->cover_image_path) {
                Storage::disk('s3')->delete($blog->cover_image_path);
            }
            $blog->delete();
            DB::commit();

            return response()->json([
                'status'  => 'success',
                'message' => 'Blog deleted successfully',
            ], 200);
        } catch (ModelNotFoundException $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => 'Blog not found.'], 404);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Blog Destroy Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to delete blog.'], 500);
        }
    }

    /**
     * Helper — resolve the S3 key stored in cover_image_path into a
     * usable temporary URL, same pattern as CategoryController::appendUrls().
     */
    private function appendUrls($blog)
    {
        $blog->cover_image_url = $blog->cover_image_path
            ? Storage::disk('s3')->temporaryUrl($blog->cover_image_path, now()->addMinutes(30))
            : null;
        return $blog;
    }

    /* =========================================================
     |  USER (PUBLIC) — READ ONLY, PUBLISHED BLOGS ONLY
     * =======================================================*/

    /**
     * Public list of published blogs
     */
    public function publicIndex(Request $request)
    {
        try {
            $query = Blog::with('product')->published();
            if ($request->filled('product_id')) {
                $query->where('product_id', $request->product_id);
            }
            $blogs = $query->orderByDesc('published_at')
                            ->paginate($request->get('per_page', 10));

            $blogs->getCollection()->transform(function ($blog) {
                return $this->appendUrls($blog);
            });

            return response()->json([
                'status'  => 'success',
                'message' => 'Blogs fetched successfully',
                'data'    => $blogs,
            ], 200);
        } catch (Exception $e) {
            Log::error('Blog Public Index Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to fetch blogs.'], 500);
        }
    }

    /**
     * Public single published blog
     */
    public function publicShow($id)
    {
        try {
            $blog = Blog::with('product')->published()->findOrFail($id);
            return response()->json([
                'status'  => 'success',
                'message' => 'Blog fetched successfully',
                'data'    => $this->appendUrls($blog),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'Blog not found.'], 404);
        } catch (Exception $e) {
            Log::error('Blog Public Show Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Blog not found.'], 404);
        }
    }
}
