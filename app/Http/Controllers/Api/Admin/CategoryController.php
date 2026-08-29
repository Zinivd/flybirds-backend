<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Exception;

class CategoryController extends Controller
{
    /**
     * GET: List all categories with public URLs
     */
    public function index()
    {
        try {
            $categories = Category::all()->map(function ($category) {
                return $this->appendUrls($category);
            });
            return response()->json(['status' => 'success', 'data' => $categories], 200);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => 'Failed to retrieve categories.'], 500);
        }
    }

    /**
     * GET: Single category by id (needed for Edit / View pages)
     */
    public function show($id)
    {
        try {
            $category = Category::findOrFail($id);
            return response()->json(['status' => 'success', 'data' => $this->appendUrls($category)], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'Category not found.'], 404);
        } catch (Exception $e) {
            Log::error('Category Show Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to retrieve category.'], 500);
        }
    }

    /**
     * POST: Create a new category
     */
    public function store(Request $request)
    {
        $request->validate([
            'name'        => 'required|string|max:255',
            'type'        => 'required|string',
            'parent_id'   => 'nullable',
            'order_level' => 'nullable|integer',
            'banner'      => 'required|image|mimes:jpeg,png,jpg|max:2048',
            'icon'        => 'image|mimes:jpeg,png,jpg|max:512',
            'cover'       => 'image|mimes:jpeg,png,jpg|max:2048',
        ]);

        DB::beginTransaction();
        try {
            $category = new Category($request->only(['name', 'type', 'parent_id', 'order_level']));

            if ($request->hasFile('banner')) {
                $category->banner_path = $request->file('banner')->store('categories/banners', 's3');
            }
            if ($request->hasFile('icon')) {
                $category->icon_path = $request->file('icon')->store('categories/icons', 's3');
            }
            if ($request->hasFile('cover')) {
                $category->cover_path = $request->file('cover')->store('categories/covers', 's3');
            }

            $category->save();
            DB::commit();

            return response()->json([
                'status'  => 'success',
                'message' => 'Category created',
                'data'    => $this->appendUrls($category),
            ], 201);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Category Store Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to create category.'], 500);
        }
    }

    /**
     * POST (as PUT): Update category
     * Only name, type, and the three images are editable.
     */
    public function update(Request $request, $id)
    {
        $category = Category::findOrFail($id);

        $request->validate([
            'name'   => 'sometimes|required|string|max:255',
            'type'   => 'sometimes|required|string',
            'banner' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
            'icon'   => 'nullable|image|mimes:jpeg,png,jpg|max:512',
            'cover'  => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
        ]);

        DB::beginTransaction();
        try {
            foreach (['banner', 'icon', 'cover'] as $field) {
                if ($request->hasFile($field)) {
                    $pathField = "{$field}_path";
                    if ($category->$pathField) {
                        Storage::disk('s3')->delete($category->$pathField);
                    }
                    $category->$pathField = $request->file($field)->store("categories/{$field}s", 's3');
                }
            }

            // Only name/type are updatable text fields now
            $category->update($request->only(['name', 'type']));

            DB::commit();

            return response()->json([
                'status'  => 'success',
                'message' => 'Category updated',
                'data'    => $this->appendUrls($category),
            ], 200);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Category Update Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Update failed.'], 500);
        }
    }

    /**
     * Helper to append S3 URLs
     */
    private function appendUrls($category)
    {
        foreach (['banner_path', 'icon_path', 'cover_path'] as $field) {
            $urlField = str_replace('_path', '_url', $field);

            // Generate a temporary URL valid for 30 minutes
            $category->$urlField = $category->$field
                ? Storage::disk('s3')->temporaryUrl($category->$field, now()->addMinutes(30))
                : null;
        }
        return $category;
    }

    public function destroy($id)
    {
        try {
            $category = Category::findOrFail($id);
            foreach (['banner_path', 'icon_path', 'cover_path'] as $path) {
                if ($category->$path) Storage::disk('s3')->delete($category->$path);
            }
            $category->delete();
            return response()->json(['status' => 'success', 'message' => 'Category deleted successfully'], 200);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => 'Delete failed.'], 500);
        }
    }
}
