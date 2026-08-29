<?php
namespace App\Http\Controllers\Api\Admin;
use App\Http\Controllers\Controller;
use App\Models\VideoReel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;
class VideoReelController extends Controller
{
    /**
     * List all reels (with optional filters).
     */
    public function index(Request $request)
    {
        try {
            $query = VideoReel::query();
            if ($request->has('is_published')) {
                $query->where('is_published', $request->boolean('is_published'));
            }
            $reels = $query->orderBy('created_at', 'desc')
                ->paginate($request->input('per_page', 15));
            return response()->json([
                'status' => 'success',
                'data'   => $reels,
            ], 200);
        } catch (Throwable $e) {
            Log::error('VideoReel index failed: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to fetch video reels',
            ], 500);
        }
    }
    /**
     * Store a new video reel.
     */
    public function store(Request $request)
    {
        // ── TEMP DEBUG: log the raw PHP upload state before validation runs ──
        // Remove this block once the upload issue is confirmed fixed.
        $videoFile = $request->file('video');
        Log::info('VideoReel upload debug', [
            'has_file'      => $request->hasFile('video'),
            'all_files'     => array_keys($request->allFiles()),
            'content_type'  => $request->header('Content-Type'),
            'content_length'=> $request->header('Content-Length'),
            'error_code'    => $videoFile?->getError(),
            'error_message' => $videoFile?->getErrorMessage(),
            // Only touch size/mime/path when the upload actually succeeded,
            // otherwise these throw on an empty/invalid temp path.
            'client_size'   => $videoFile && $videoFile->getError() === UPLOAD_ERR_OK ? $videoFile->getSize() : null,
            'client_mime'   => $videoFile && $videoFile->getError() === UPLOAD_ERR_OK ? $videoFile->getClientMimeType() : null,
            'real_path'     => $videoFile?->getRealPath(),
            'is_valid'      => $videoFile?->isValid(),
        ]);

        // Validate first, outside try/catch, so ValidationException
        // returns Laravel's standard 422 JSON automatically.
        try {
            $validated = $request->validate([
                'video'        => 'required|file|mimetypes:video/mp4,video/quicktime',
                'title'        => 'required|string|max:255',
                'description'  => 'nullable|string',
                'is_published' => 'nullable|string',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Validation failed',
                'errors'  => $e->errors(),
            ], 422);
        }
        $uploadedPath = null;
        try {
            $reel = DB::transaction(function () use ($request, $validated, &$uploadedPath) {
                $file = $request->file('video');
                if (!$file || !$file->isValid()) {
                    throw new \RuntimeException('Uploaded file is invalid or missing.');
                }
                // 1. Upload to S3 first
                $path = $file->store('reels', 's3');
                if ($path === false || $path === null) {
                    throw new \RuntimeException('File upload to S3 failed.');
                }
                $uploadedPath = $path; // track so we can clean up on failure
                // 2. Verify it actually landed on S3 before trusting it
                if (!Storage::disk('s3')->exists($path)) {
                    throw new \RuntimeException('File upload verification failed on S3.');
                }
                // 3. Create DB record
                $reel = VideoReel::create([
                    'title'        => $validated['title'],
                    'description'  => $validated['description'] ?? null,
                    'file_name'    => $file->getClientOriginalName(),
                    'file_size'    => $file->getSize(),
                    'video_url'    => Storage::disk('s3')->url($path),
                    'file_type'    => $file->getMimeType(),
                    'is_published' => $request->boolean('is_published', false),
                ]);
                if (!$reel || !$reel->exists) {
                    throw new \RuntimeException('Failed to persist video reel record.');
                }
                return $reel;
            });
            return response()->json([
                'status'  => 'success',
                'message' => 'Video reel created successfully',
                'data'    => $reel,
            ], 201);
        } catch (Throwable $e) {
            // Roll back the S3 upload if DB failed after upload succeeded
            if ($uploadedPath) {
                try {
                    Storage::disk('s3')->delete($uploadedPath);
                } catch (Throwable $cleanupEx) {
                    Log::warning('Failed to clean up orphaned S3 file: ' . $cleanupEx->getMessage());
                }
            }
            Log::error('VideoReel store failed: ' . $e->getMessage(), [
                'exception' => $e,
                'trace'     => $e->getTraceAsString(),
            ]);
            return response()->json([
                'status'  => 'error',
                'message' => 'Upload failed: ' . $e->getMessage(),
            ], 500);
        }
    }
    /**
     * Publish / unpublish a reel.
     */
    public function updateStatus(Request $request, $id)
    {
        try {
            $validated = $request->validate([
                'is_published' => 'required|boolean',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Validation failed',
                'errors'  => $e->errors(),
            ], 422);
        }
        try {
            $reel = VideoReel::findOrFail($id);
            $reel->update([
                'is_published' => $validated['is_published'],
            ]);
            return response()->json([
                'status'  => 'success',
                'message' => 'Status updated successfully',
                'data'    => $reel->fresh(),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Reel not found',
            ], 404);
        } catch (Throwable $e) {
            Log::error('VideoReel updateStatus failed: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json([
                'status'  => 'error',
                'message' => 'Update failed: ' . $e->getMessage(),
            ], 500);
        }
    }
}
