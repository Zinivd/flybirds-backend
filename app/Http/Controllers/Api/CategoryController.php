<?php
namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CategoryController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string',
            'type' => 'required|string',
            'parent_id' => 'nullable|exists:categories,id',
            'order_level' => 'integer',
            'banner' => 'image|mimes:jpeg,png|max:2048',
            'icon' => 'image|mimes:jpeg,png|max:512',
            'cover' => 'image|mimes:jpeg,png|max:2048',
        ]);

        $category = new Category($request->only(['name', 'type', 'parent_id', 'order_level']));

        // Handle File Uploads to S3
        if ($request->hasFile('banner'))
            $category->banner_path = $request->file('banner')->store('categories/banners', 's3');
        if ($request->hasFile('icon'))
            $category->icon_path = $request->file('icon')->store('categories/icons', 's3');
        if ($request->hasFile('cover'))
            $category->cover_path = $request->file('cover')->store('categories/covers', 's3');

        $category->save();

        return response()->json(['message' => 'Category created successfully', 'data' => $category], 201);
    }
}