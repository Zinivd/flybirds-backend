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
     * GET: List all categories
     */
    public function index()
    {
        try {
            $categories = Category::all();
            return response()->json(['status' => 'success', 'data' => $categories], 200);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => 'Failed to retrieve categories.'], 500);
        }
    }

    /**
     * GET: Fetch category by ID
     */
    public function show($id)
    {
        try {
            $category = Category::findOrFail($id);
            return response()->json(['status' => 'success', 'data' => $category], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'Category not found.'], 404);
        }
    }

    /**
     * POST: Create a new category
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:255',
            'type'        => 'required|string',
            'parent_id'   => 'nullable|exists:categories,id',
            'order_level' => 'integer',
            'banner'      => 'nullable|image|mimes:jpeg,png|max:2048',
            'icon'        => 'nullable|image|mimes:jpeg,png|max:512',
            'cover'       => 'nullable|image|mimes:jpeg,png|max:2048',
        ]);

        DB::beginTransaction();
        try {
            $category = new Category($request->only(['name', 'type', 'parent_id', 'order_level']));

            if ($request->hasFile('banner')) $category->banner_path = $request->file('banner')->store('categories/banners', 's3');
            if ($request->hasFile('icon'))   $category->icon_path   = $request->file('icon')->store('categories/icons', 's3');
            if ($request->hasFile('cover'))  $category->cover_path  = $request->file('cover')->store('categories/covers', 's3');

            $category->save();

            DB::commit();
            return response()->json(['status' => 'success', 'message' => 'Category created successfully', 'data' => $category], 201);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Category Store Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to create category.'], 500);
        }
    }

    /**
     * PUT: Update category
     */
    public function update(Request $request, $id)
    {
        $category = Category::findOrFail($id);

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'banner' => 'nullable|image|max:2048',
        ]);

        DB::beginTransaction();
        try {
            if ($request->hasFile('banner')) {
                if ($category->banner_path) Storage::disk('s3')->delete($category->banner_path);
                $category->banner_path = $request->file('banner')->store('categories/banners', 's3');
            }

            $category->update($request->except(['banner', 'icon', 'cover']));
            
            DB::commit();
            return response()->json(['status' => 'success', 'message' => 'Category updated', 'data' => $category], 200);
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => 'Update failed.'], 500);
        }
    }

    /**
     * DELETE: Remove category
     */
    public function destroy($id)
    {
        try {
            $category = Category::findOrFail($id);
            
            // Delete files from S3
            foreach (['banner_path', 'icon_path', 'cover_path'] as $path) {
                if ($category->$path) Storage::disk('s3')->delete($category->$path);
            }

            $category->delete();
            return response()->json(['status' => 'success', 'message' => 'Category deleted successfully'], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'Category not found.'], 404);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => 'Delete failed.'], 500);
        }
    }
}