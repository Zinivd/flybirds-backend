<?php
namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\FamilyColor;
use App\Models\FamilyColorChild;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Exception;

class FamilyColorController extends Controller
{
    // ═══════════════════════════════════════════════════════════
    // GET /admin/family-colors
    // ═══════════════════════════════════════════════════════════
    public function index()
    {
        try {
            $familyColors = FamilyColor::with('children')->latest()->get();
            return response()->json(['status' => 'success', 'data' => $familyColors], 200);
        } catch (Exception $e) {
            Log::error('Family Color Index Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to retrieve family colors.'], 500);
        }
    }

    // ═══════════════════════════════════════════════════════════
    // GET /admin/family-colors/{id}
    // ═══════════════════════════════════════════════════════════
    public function show($id)
    {
        try {
            $familyColor = FamilyColor::with('children')->findOrFail($id);
            return response()->json(['status' => 'success', 'data' => $familyColor], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'Family color not found.'], 404);
        } catch (Exception $e) {
            Log::error('Family Color Show Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to retrieve family color.'], 500);
        }
    }

    // ═══════════════════════════════════════════════════════════
    // POST /admin/family-colors
    // Payload:
    // {
    //   "name": "Blue Family",
    //   "code": "#0000FF",
    //   "children": [
    //     { "name": "Sky Blue", "code": "#87CEEB" },
    //     { "name": "Navy Blue", "code": "#000080" }
    //   ]
    // }
    // ═══════════════════════════════════════════════════════════
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'name'                => 'required|string|max:255',
                'code'                => 'required|string|max:20',
                'children'            => 'nullable|array',
                'children.*.name'     => 'required_with:children|string|max:255',
                'children.*.code'     => 'required_with:children|string|max:20',
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
            $familyColor = FamilyColor::create([
                'name' => $validated['name'],
                'code' => $validated['code'],
            ]);

            foreach ($validated['children'] ?? [] as $child) {
                FamilyColorChild::create([
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

    // ═══════════════════════════════════════════════════════════
    // POST /admin/family-colors/{id}
    // Same payload shape as store(). Children WITHOUT an 'id' are
    // created new; children WITH an 'id' are updated. Any existing
    // child not present in the payload is left untouched (use the
    // destroyChild endpoint to remove one explicitly).
    // ═══════════════════════════════════════════════════════════
    public function update(Request $request, $id)
    {
        try {
            $familyColor = FamilyColor::findOrFail($id);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'Family color not found.'], 404);
        }

        try {
            $validated = $request->validate([
                'name'                => 'sometimes|string|max:255',
                'code'                => 'sometimes|string|max:20',
                'children'            => 'nullable|array',
                'children.*.id'       => 'nullable|exists:family_color_children,id',
                'children.*.name'     => 'required_with:children|string|max:255',
                'children.*.code'     => 'required_with:children|string|max:20',
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
            $familyColor->update(array_filter($validated, fn($k) => !in_array($k, ['children']), ARRAY_FILTER_USE_KEY));

            foreach ($validated['children'] ?? [] as $child) {
                if (!empty($child['id'])) {
                    FamilyColorChild::where('id', $child['id'])
                        ->where('family_color_id', $familyColor->id)
                        ->update(['name' => $child['name'], 'code' => $child['code']]);
                } else {
                    FamilyColorChild::create([
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

    // ═══════════════════════════════════════════════════════════
    // PATCH /admin/family-colors/{id}/toggle
    // ═══════════════════════════════════════════════════════════
    public function toggleActive($id)
    {
        try {
            $familyColor = FamilyColor::findOrFail($id);
            $familyColor->is_active = !$familyColor->is_active;
            $familyColor->save();
            return response()->json([
                'status'  => 'success',
                'message' => $familyColor->is_active ? 'Activated.' : 'Deactivated.',
                'data'    => $familyColor,
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'Family color not found.'], 404);
        } catch (Exception $e) {
            Log::error('Family Color Toggle Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to toggle status.'], 500);
        }
    }

    // ═══════════════════════════════════════════════════════════
    // DELETE /admin/family-colors/{id}
    // (children cascade-delete via FK)
    // ═══════════════════════════════════════════════════════════
    public function destroy($id)
    {
        try {
            $familyColor = FamilyColor::findOrFail($id);
            $familyColor->delete();
            return response()->json(['status' => 'success', 'message' => 'Family color deleted successfully.'], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'Family color not found.'], 404);
        } catch (Exception $e) {
            Log::error('Family Color Delete Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to delete family color.'], 500);
        }
    }

    // ═══════════════════════════════════════════════════════════
    // DELETE /admin/family-colors/{familyColorId}/children/{childId}
    // ═══════════════════════════════════════════════════════════
    public function destroyChild($familyColorId, $childId)
    {
        try {
            $child = FamilyColorChild::where('id', $childId)
                ->where('family_color_id', $familyColorId)
                ->firstOrFail();
            $child->delete();
            return response()->json(['status' => 'success', 'message' => 'Child color deleted successfully.'], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'Child color not found.'], 404);
        } catch (Exception $e) {
            Log::error('Family Color Child Delete Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to delete child color.'], 500);
        }
    }
}
