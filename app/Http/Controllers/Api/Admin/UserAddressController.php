<?php
namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\UserAddress;
use App\Models\FlyUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Exception;

class UserAddressController extends Controller
{
    /**
     * POST /admin/users/{userId}/addresses
     */
    public function store(Request $request, $userId)
    {
        try {
            $user = FlyUser::where('user_type', 'user')->where('user_id', $userId)->first();
            if (!$user) {
                return response()->json(['status' => 'error', 'message' => 'User not found.'], 404);
            }
        } catch (Exception $e) {
            Log::error('Address Store - User Lookup Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to verify user.'], 500);
        }

        try {
            $validated = $request->validate([
                'address_type'    => 'required|in:home,work,other',
                'full_name'       => 'required|string|max:255',
                'phone'           => 'required|string|max:20',
                'address_line_1'  => 'required|string|max:255',
                'address_line_2'  => 'nullable|string|max:255',
                'city'            => 'required|string|max:100',
                'state'           => 'required|string|max:100',
                'postal_code'     => 'required|string|max:20',
                'country'         => 'nullable|string|max:100',
                'is_default'      => 'boolean',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error', 'message' => 'Validation failed.', 'errors' => $e->errors(),
            ], 422);
        }

        DB::beginTransaction();
        try {
            if (!empty($validated['is_default']) && $validated['is_default']) {
                UserAddress::where('user_id', $userId)->update(['is_default' => false]);
            }

            $address = UserAddress::create([
                'user_id'         => $userId,
                'address_type'    => $validated['address_type'],
                'full_name'       => $validated['full_name'],
                'phone'           => $validated['phone'],
                'address_line_1'  => $validated['address_line_1'],
                'address_line_2'  => $validated['address_line_2'] ?? null,
                'city'            => $validated['city'],
                'state'           => $validated['state'],
                'postal_code'     => $validated['postal_code'],
                'country'         => $validated['country'] ?? 'India',
                'is_default'      => $validated['is_default'] ?? false,
            ]);

            DB::commit();

            return response()->json([
                'status' => 'success', 'message' => 'Address added successfully.', 'data' => $address,
            ], 201);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Address Store Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to add address.'], 500);
        }
    }

    /**
     * GET /admin/users/{userId}/addresses
     */
    public function getByUserId(Request $request, $userId)
    {
        try {
            $user = FlyUser::where('user_type', 'user')->where('user_id', $userId)->first();
            if (!$user) {
                return response()->json(['status' => 'error', 'message' => 'User not found.'], 404);
            }

            $query = UserAddress::where('user_id', $userId);

            if ($request->filled('address_type')) {
                $query->where('address_type', $request->address_type);
            }

            $addresses = $query->orderByDesc('is_default')->latest()->get();

            return response()->json(['status' => 'success', 'data' => $addresses], 200);
        } catch (Exception $e) {
            Log::error('Address GetByUserId Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to retrieve addresses.'], 500);
        }
    }

    /**
     * GET /admin/users/{userId}/addresses/{addressId}
     */
    public function show($userId, $addressId)
    {
        try {
            $address = UserAddress::where('id', $addressId)->where('user_id', $userId)->firstOrFail();
            return response()->json(['status' => 'success', 'data' => $address], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'Address not found.'], 404);
        } catch (Exception $e) {
            Log::error('Address Show Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to retrieve address.'], 500);
        }
    }

    /**
     * PATCH /admin/users/{userId}/addresses/{addressId}
     */
    public function update(Request $request, $userId, $addressId)
    {
        try {
            $address = UserAddress::where('id', $addressId)->where('user_id', $userId)->firstOrFail();
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'Address not found.'], 404);
        } catch (Exception $e) {
            Log::error('Address Update - Lookup Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to retrieve address.'], 500);
        }

        try {
            $validated = $request->validate([
                'address_type'    => 'sometimes|in:home,work,other',
                'full_name'       => 'sometimes|string|max:255',
                'phone'           => 'sometimes|string|max:20',
                'address_line_1'  => 'sometimes|string|max:255',
                'address_line_2'  => 'nullable|string|max:255',
                'city'            => 'sometimes|string|max:100',
                'state'           => 'sometimes|string|max:100',
                'postal_code'     => 'sometimes|string|max:20',
                'country'         => 'nullable|string|max:100',
                'is_default'      => 'boolean',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error', 'message' => 'Validation failed.', 'errors' => $e->errors(),
            ], 422);
        }

        DB::beginTransaction();
        try {
            if (!empty($validated['is_default']) && $validated['is_default']) {
                UserAddress::where('user_id', $userId)->where('id', '!=', $addressId)->update(['is_default' => false]);
            }

            $address->update($validated);

            DB::commit();

            return response()->json([
                'status' => 'success', 'message' => 'Address updated successfully.', 'data' => $address->fresh(),
            ], 200);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Address Update Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to update address.'], 500);
        }
    }

    /**
     * DELETE /admin/users/{userId}/addresses/{addressId}
     */
    public function destroy($userId, $addressId)
    {
        try {
            $address = UserAddress::where('id', $addressId)->where('user_id', $userId)->first();

            if (!$address) {
                return response()->json(['status' => 'error', 'message' => 'Address not found.'], 404);
            }

            $address->delete();

            return response()->json(['status' => 'success', 'message' => 'Address deleted successfully.'], 200);
        } catch (Exception $e) {
            Log::error('Address Delete Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to delete address.'], 500);
        }
    }
}