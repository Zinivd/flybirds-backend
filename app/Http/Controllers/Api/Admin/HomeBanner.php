<?php
namespace App\Http\Controllers\Api\Admin;
use App\Http\Controllers\Controller;
use App\Models\home_banner;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class HomeBanner extends Controller
{
    /**
     * List all home banners (with categories eager-loaded)
     */
    public function index(Request $request)
    {
        try {
            $query = home_banner::with('categories');
            if ($request->filled('search')) {
                $query->where('title', 'like', '%' . $request->search . '%');
            }
            if ($request->filled('category_id')) {
                $query->whereHas('categories', function ($q) use ($request) {
                    $q->where('categories.id', $request->category_id);
                });
            }
            $banners = $query->ordered()
                              ->paginate($request->get('per_page', 10));
            return response()->json([
                'status'  => 'success',
                'message' => 'Home banners fetched successfully',
                'data'    => $banners,
            ], 200);
        } catch (Exception $e) {
            Log::error('HomeBanner Index Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to fetch home banners.'], 500);
        }
    }

    /**
     * Store a new home banner (web + mobile images uploaded to S3)
     * Accepts category_ids as an array (single or multiple values):
     *   category_ids[] = 1
     *   category_ids[] = 2
     */
    public function store(Request $request)
    {
        $request->validate([
            'title'             => 'required|string|max:255',
            'order_level'       => 'nullable|integer',
            'web_banner'        => 'required|image|mimes:jpeg,png,jpg,webp|max:2048',
            'mobile_banner'     => 'required|image|mimes:jpeg,png,jpg,webp|max:2048',
            'category_ids'      => 'nullable|array',
            'category_ids.*'    => 'integer|exists:categories,id',
        ]);
        DB::beginTransaction();
        try {
            $banner = new home_banner($request->only(['title', 'order_level']));
            if ($request->hasFile('web_banner')) {
                $banner->web_banner_path = $request->file('web_banner')->store('home-banners/web', 's3');
            }
            if ($request->hasFile('mobile_banner')) {
                $banner->mobile_banner_path = $request->file('mobile_banner')->store('home-banners/mobile', 's3');
            }
            $banner->save();

            if ($request->filled('category_ids')) {
                $banner->categories()->sync($request->category_ids);
            }

            DB::commit();
            return response()->json([
                'status'  => 'success',
                'message' => 'Home banner created successfully',
                'data'    => $banner->load('categories'),
            ], 201);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('HomeBanner Store Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to create home banner.'], 500);
        }
    }

    /**
     * Show single home banner
     */
    public function show($id)
    {
        try {
            $banner = home_banner::with('categories')->findOrFail($id);
            return response()->json([
                'status'  => 'success',
                'message' => 'Home banner fetched successfully',
                'data'    => $banner,
            ], 200);
        } catch (Exception $e) {
            Log::error('HomeBanner Show Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Home banner not found.'], 404);
        }
    }

    /**
     * Update home banner (replace images if new ones provided, delete old ones from S3)
     * category_ids fully replaces the banner's category assignments when present.
     */
    public function update(Request $request, $id)
    {
        $request->validate([
            'title'             => 'required|string|max:255',
            'order_level'       => 'nullable|integer',
            'web_banner'        => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'mobile_banner'     => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'category_ids'      => 'nullable|array',
            'category_ids.*'    => 'integer|exists:categories,id',
        ]);
        DB::beginTransaction();
        try {
            $banner = home_banner::findOrFail($id);
            $banner->fill($request->only(['title', 'order_level']));
            if ($request->hasFile('web_banner')) {
                if ($banner->web_banner_path) {
                    Storage::disk('s3')->delete($banner->web_banner_path);
                }
                $banner->web_banner_path = $request->file('web_banner')->store('home-banners/web', 's3');
            }
            if ($request->hasFile('mobile_banner')) {
                if ($banner->mobile_banner_path) {
                    Storage::disk('s3')->delete($banner->mobile_banner_path);
                }
                $banner->mobile_banner_path = $request->file('mobile_banner')->store('home-banners/mobile', 's3');
            }
            $banner->save();

            if ($request->has('category_ids')) {
                $banner->categories()->sync($request->category_ids ?? []);
            }

            DB::commit();
            return response()->json([
                'status'  => 'success',
                'message' => 'Home banner updated successfully',
                'data'    => $banner->load('categories'),
            ], 200);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('HomeBanner Update Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to update home banner.'], 500);
        }
    }

    /**
     * Delete home banner (S3 files + pivot rows auto-removed via model hook / FK cascade)
     */
    public function destroy($id)
    {
        DB::beginTransaction();
        try {
            $banner = home_banner::findOrFail($id);
            $banner->delete();
            DB::commit();
            return response()->json([
                'status'  => 'success',
                'message' => 'Home banner deleted successfully',
            ], 200);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('HomeBanner Destroy Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to delete home banner.'], 500);
        }
    }

    /**
     * Download banner image (streams file from S3)
     * type = web|mobile
     */
    public function download($id, $type)
    {
        try {
            $banner = home_banner::findOrFail($id);
            $path = $type === 'web' ? $banner->web_banner_path : $banner->mobile_banner_path;
            if (!$path || !Storage::disk('s3')->exists($path)) {
                return response()->json(['status' => 'error', 'message' => 'File not found.'], 404);
            }
            $fileContents = Storage::disk('s3')->get($path);
            $fileName = $banner->title . '-' . $type . '.' . pathinfo($path, PATHINFO_EXTENSION);
            return response($fileContents, 200)
                ->header('Content-Type', Storage::disk('s3')->mimeType($path))
                ->header('Content-Disposition', 'attachment; filename="' . $fileName . '"');
        } catch (Exception $e) {
            Log::error('HomeBanner Download Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to download file.'], 500);
        }
    }
}
