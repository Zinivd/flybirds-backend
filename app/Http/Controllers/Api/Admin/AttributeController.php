<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Color;
use App\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Exception;

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
        // Safety Check: Validate the existence of the file array
        if (!$request->hasFile('files')) {
            return response()->json(['status' => 'error', 'message' => 'No files provided.'], 400);
        }

        $request->validate(['files.*' => 'required|file|max:5120']);

        try {
            $uploadedData = [];
            foreach ($request->file('files') as $file) {
                $path = $file->store('uploads', 's3');
                $media = Media::create([
                    'file_name' => $file->getClientOriginalName(),
                    'file_size' => round($file->getSize() / 1024, 2) . ' KB',
                    'file_url'  => Storage::disk('s3')->url($path),
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
}
