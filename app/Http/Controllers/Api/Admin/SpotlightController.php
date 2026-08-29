<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Spotlight;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Exception;

class SpotlightController extends Controller
{
    // ═══════════════════════════════════════════════════════════════
    // PRIVATE HELPER: Load spotlight with product (id, name) attached
    // ═══════════════════════════════════════════════════════════════
    private function loadSpotlight(int $id): Spotlight
    {
        return $this->appendUrl(
            Spotlight::with(['product:id,name,unit_price'])->findOrFail($id)
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // PRIVATE HELPER: Attach a temporary signed S3 URL for the image
    // The `image` column stores the raw S3 path (e.g. "spotlights/xyz.jpg")
    // ═══════════════════════════════════════════════════════════════
    private function appendUrl($spotlight)
    {
        $spotlight->image_url = $spotlight->image
            ? Storage::disk('s3')->temporaryUrl($spotlight->image, now()->addMinutes(30))
            : null;
        return $spotlight;
    }

    private function appendUrlToCollection($spotlights)
    {
        $spotlights->getCollection()->transform(function ($sp) {
            return $this->appendUrl($sp);
        });
        return $spotlights;
    }

    // ═══════════════════════════════════════════════════════════════
    // GET /admin/spotlights?status=active|inactive|all&is_published=1
    // ═══════════════════════════════════════════════════════════════
    public function index(Request $request)
    {
        try {
            $query = Spotlight::with(['product:id,name,unit_price']);

            $status = $request->query('status', 'active');
            if ($status === 'active') {
                $query->where('is_active', true);
            } elseif ($status === 'inactive') {
                $query->where('is_active', false);
            }

            if ($request->filled('is_published')) {
                $query->where('is_published', filter_var($request->is_published, FILTER_VALIDATE_BOOLEAN));
            }

            $spotlights = $query->orderBy('sort_order')->latest()->paginate(15);
            $spotlights = $this->appendUrlToCollection($spotlights);

            return response()->json(['status' => 'success', 'data' => $spotlights], 200);
        } catch (Exception $e) {
            Log::error('Spotlight Index Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to retrieve spotlights.'], 500);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // GET /admin/spotlights/{id}
    // ═══════════════════════════════════════════════════════════════
    public function show($id)
    {
        try {
            $spotlight = $this->loadSpotlight($id);
            return response()->json(['status' => 'success', 'data' => $spotlight], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'Spotlight not found.'], 404);
        } catch (Exception $e) {
            Log::error('Spotlight Show Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to retrieve spotlight.'], 500);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // POST /admin/spotlights   (multipart/form-data, field name: "image")
    // ═══════════════════════════════════════════════════════════════
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'title'        => 'required|string|max:255',
                'product_id'   => 'required|exists:products,id',
                'image'        => 'required|image|mimes:jpeg,png,jpg,webp|max:2048',
                'is_published' => 'nullable|boolean',
                'is_active'    => 'nullable|boolean',
                'sort_order'   => 'nullable|integer|min:0',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Validation failed.',
                'errors'  => $e->errors(),
            ], 422);
        }

        DB::beginTransaction();
        try {
            $imagePath = $request->file('image')->store('spotlights', 's3');

            $spotlight = Spotlight::create([
                'title'        => $validated['title'],
                'product_id'   => $validated['product_id'],
                'image'        => $imagePath,
                'is_published' => $request->boolean('is_published', true),
                'is_active'    => $request->boolean('is_active', true),
                'sort_order'   => $validated['sort_order'] ?? 0,
            ]);

            DB::commit();

            return response()->json([
                'status'  => 'success',
                'message' => 'Spotlight created successfully.',
                'data'    => $this->loadSpotlight($spotlight->id),
            ], 201);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Spotlight Store Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to create spotlight.'], 500);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // POST /admin/spotlights/{id}   (acts as update; multipart/form-data)
    // Image is OPTIONAL on update — only replaced if a new file is sent.
    // ═══════════════════════════════════════════════════════════════
    public function update(Request $request, $id)
    {
        try {
            $spotlight = Spotlight::findOrFail($id);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'Spotlight not found.'], 404);
        }

        try {
            $validated = $request->validate([
                'title'        => 'sometimes|required|string|max:255',
                'product_id'   => 'sometimes|required|exists:products,id',
                'image'        => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
                'is_published' => 'nullable|boolean',
                'is_active'    => 'nullable|boolean',
                'sort_order'   => 'nullable|integer|min:0',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Validation failed.',
                'errors'  => $e->errors(),
            ], 422);
        }

        DB::beginTransaction();
        try {
            $updateData = array_filter(
                $validated,
                fn($k) => $k !== 'image',
                ARRAY_FILTER_USE_KEY
            );

            if ($request->hasFile('image')) {
                // delete old file from S3 before replacing
                if ($spotlight->image) {
                    Storage::disk('s3')->delete($spotlight->image);
                }
                $updateData['image'] = $request->file('image')->store('spotlights', 's3');
            }

            if ($request->has('is_published')) {
                $updateData['is_published'] = $request->boolean('is_published');
            }
            if ($request->has('is_active')) {
                $updateData['is_active'] = $request->boolean('is_active');
            }

            $spotlight->update($updateData);
            DB::commit();

            return response()->json([
                'status'  => 'success',
                'message' => 'Spotlight updated successfully.',
                'data'    => $this->loadSpotlight($spotlight->id),
            ], 200);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Spotlight Update Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to update spotlight.'], 500);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // PATCH /admin/spotlights/{id}/publish
    // ═══════════════════════════════════════════════════════════════
    public function togglePublish($id)
    {
        try {
            $spotlight = Spotlight::findOrFail($id);
            $spotlight->is_published = !$spotlight->is_published;
            $spotlight->save();

            return response()->json([
                'status'  => 'success',
                'message' => $spotlight->is_published ? 'Spotlight published.' : 'Spotlight unpublished.',
                'data'    => ['id' => $spotlight->id, 'is_published' => $spotlight->is_published],
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'Spotlight not found.'], 404);
        } catch (Exception $e) {
            Log::error('Spotlight Toggle Publish Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to update publish status.'], 500);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // PATCH /admin/spotlights/{id}/toggle-active
    // ═══════════════════════════════════════════════════════════════
    public function toggleActive($id)
    {
        try {
            $spotlight = Spotlight::findOrFail($id);
            $spotlight->is_active = !$spotlight->is_active;
            $spotlight->save();

            return response()->json([
                'status'  => 'success',
                'message' => $spotlight->is_active ? 'Spotlight activated.' : 'Spotlight deactivated.',
                'data'    => ['id' => $spotlight->id, 'is_active' => $spotlight->is_active],
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'Spotlight not found.'], 404);
        } catch (Exception $e) {
            Log::error('Spotlight Toggle Active Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to update active status.'], 500);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // DELETE /admin/spotlights/{id}
    // ═══════════════════════════════════════════════════════════════
    public function destroy($id)
    {
        try {
            $spotlight = Spotlight::findOrFail($id);
            if ($spotlight->image) {
                Storage::disk('s3')->delete($spotlight->image);
            }
            $spotlight->delete();

            return response()->json(['status' => 'success', 'message' => 'Spotlight deleted successfully.'], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'Spotlight not found.'], 404);
        } catch (Exception $e) {
            Log::error('Spotlight Delete Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to delete spotlight.'], 500);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // GET /spotlights  — Public/storefront: published + active only
    // ═══════════════════════════════════════════════════════════════
    public function publicIndex()
    {
        try {
            $spotlights = Spotlight::with(['product:id,name,unit_price'])
                ->where('is_published', true)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get()
                ->map(fn($sp) => $this->appendUrl($sp));

            return response()->json(['status' => 'success', 'data' => $spotlights], 200);
        } catch (Exception $e) {
            Log::error('Spotlight Public Index Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to retrieve spotlights.'], 500);
        }
    }
}
