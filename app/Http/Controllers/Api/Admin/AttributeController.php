<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Color;
use App\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Exception;
use Illuminate\Support\Facades\DB;

class AttributeController extends Controller
{
    // --- COLOR METHODS ---

    public function storeColors(Request $request)
    {
        $request->validate([
            'colors' => 'required|array',
            'colors.*.name' => 'required|string',
            'colors.*.code' => 'required|string',
        ]);

        try {
            foreach ($request->colors as $colorData) {
                Color::updateOrCreate(
                    ['name' => $colorData['name']],
                    ['code' => $colorData['code']]
                );
            }
            return response()->json(['status' => 'success', 'message' => 'Colors synchronized successfully'], 201);
        } catch (Exception $e) {
            Log::error('Color Sync Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to sync colors.'], 500);
        }
    }

    public function indexColors()
    {
        return response()->json(['status' => 'success', 'data' => Color::all()], 200);
    }

    public function deleteColor($id)
    {
        try {
            $color = Color::findOrFail($id);
            $color->delete();
            return response()->json(['status' => 'success', 'message' => 'Color deleted successfully'], 200);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => 'Color not found.'], 404);
        }
    }

    // --- MEDIA METHODS ---

    public function uploadFiles(Request $request)
    {
        if (!$request->hasFile('files')) {
            return response()->json(['status' => 'error', 'message' => 'No files provided.'], 400);
        }

        $request->validate(['files.*' => 'required|file|max:10240']);

        try {
            $uploadedData = [];
            foreach ($request->file('files') as $file) {
                $path = $file->store('uploads', 's3'); // no visibility option

                $media = Media::create([
                    'file_name' => $file->getClientOriginalName(),
                    'file_size' => round($file->getSize() / 1024, 2) . ' KB',
                    'file_url' => Storage::disk('s3')->url($path),
                    'mime_type' => $file->getClientMimeType(),
                ]);
                $uploadedData[] = $media;
            }
            return response()->json(['status' => 'success', 'data' => $uploadedData], 201);
        } catch (Exception $e) {
            Log::error('File Upload Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'File upload failed.', 'debug' => $e->getMessage()], 500);
        }
    }

    public function listFiles()
    {
        return response()->json(['status' => 'success', 'data' => Media::all()], 200);
    }

    public function deleteFile($id)
    {
        try {
            $media = Media::findOrFail($id);
            // S3 Cleanup
            $path = parse_url($media->file_url, PHP_URL_PATH);
            Storage::disk('s3')->delete(ltrim($path, '/'));
            $media->delete();
            return response()->json(['status' => 'success', 'message' => 'File deleted successfully'], 200);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => 'File not found.'], 404);
        }
    }



    // --- FAMILY COLOR METHODS ---
public function storeFamilyColor(Request $request)
{
    $request->validate([
        'name'                => 'required|string|max:255',
        'code'                => 'required|string|max:20',
        'children'            => 'nullable|array',
        'children.*.name'     => 'required_with:children|string|max:255',
        'children.*.code'     => 'required_with:children|string|max:20',
    ]);
    DB::beginTransaction();
    try {
        $familyColor = \App\Models\FamilyColor::create([
            'name' => $request->name,
            'code' => $request->code,
        ]);
        foreach ($request->children ?? [] as $child) {
            \App\Models\FamilyColorChild::create([
                'family_color_id' => $familyColor->id,
                'name'            => $child['name'],
                'code'            => $child['code'],
            ]);
        }
        DB::commit();
        return response()->json([
            'status'  => 'success',
            'message' => 'Family color created successfully.',
            'data'    => $familyColor->load('children'),
        ], 201);
    } catch (Exception $e) {
        DB::rollBack();
        Log::error('Family Color Store Error: ' . $e->getMessage());
        return response()->json(['status' => 'error', 'message' => 'Failed to create family color.'], 500);
    }
}

public function indexFamilyColors()
{
    return response()->json([
        'status' => 'success',
        'data'   => \App\Models\FamilyColor::with('children')->latest()->get(),
    ], 200);
}

public function showFamilyColor($id)
{
    try {
        $familyColor = \App\Models\FamilyColor::with('children')->findOrFail($id);
        return response()->json(['status' => 'success', 'data' => $familyColor], 200);
    } catch (Exception $e) {
        return response()->json(['status' => 'error', 'message' => 'Family color not found.'], 404);
    }
}

public function updateFamilyColor(Request $request, $id)
{
    try {
        $familyColor = \App\Models\FamilyColor::findOrFail($id);
    } catch (Exception $e) {
        return response()->json(['status' => 'error', 'message' => 'Family color not found.'], 404);
    }
    $request->validate([
        'name'                => 'sometimes|string|max:255',
        'code'                => 'sometimes|string|max:20',
        'children'            => 'nullable|array',
        'children.*.id'       => 'nullable|exists:family_color_children,id',
        'children.*.name'     => 'required_with:children|string|max:255',
        'children.*.code'     => 'required_with:children|string|max:20',
    ]);
    DB::beginTransaction();
    try {
        $familyColor->update($request->only(['name', 'code']));
        foreach ($request->children ?? [] as $child) {
            if (!empty($child['id'])) {
                \App\Models\FamilyColorChild::where('id', $child['id'])
                    ->where('family_color_id', $familyColor->id)
                    ->update(['name' => $child['name'], 'code' => $child['code']]);
            } else {
                \App\Models\FamilyColorChild::create([
                    'family_color_id' => $familyColor->id,
                    'name'            => $child['name'],
                    'code'            => $child['code'],
                ]);
            }
        }
        DB::commit();
        return response()->json([
            'status'  => 'success',
            'message' => 'Family color updated successfully.',
            'data'    => $familyColor->load('children'),
        ], 200);
    } catch (Exception $e) {
        DB::rollBack();
        Log::error('Family Color Update Error: ' . $e->getMessage());
        return response()->json(['status' => 'error', 'message' => 'Failed to update family color.'], 500);
    }
}

public function deleteFamilyColor($id)
{
    try {
        $familyColor = \App\Models\FamilyColor::findOrFail($id);
        $familyColor->delete(); // children cascade via FK
        return response()->json(['status' => 'success', 'message' => 'Family color deleted successfully.'], 200);
    } catch (Exception $e) {
        return response()->json(['status' => 'error', 'message' => 'Family color not found.'], 404);
    }
}

public function deleteFamilyColorChild($familyId, $childId)
{
    try {
        $child = \App\Models\FamilyColorChild::where('id', $childId)
            ->where('family_color_id', $familyId)
            ->firstOrFail();
        $child->delete();
        return response()->json(['status' => 'success', 'message' => 'Child color deleted successfully.'], 200);
    } catch (Exception $e) {
        return response()->json(['status' => 'error', 'message' => 'Child color not found.'], 404);
    }
}
}
