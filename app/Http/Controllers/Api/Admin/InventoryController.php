<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProductSizeStock;
use App\Models\InventoryLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Exception;

class InventoryController extends Controller
{
    // 1. GET: List all stocks (with pagination and variant details)
    public function index()
    {
        try {
            $stocks = ProductSizeStock::with(['colorVariant.product', 'colorVariant.color'])
                ->latest()
                ->paginate(20);
            return response()->json(['status' => 'success', 'data' => $stocks], 200);
        } catch (Exception $e) {
            Log::error('Inventory Index Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to retrieve stocks.'], 500);
        }
    }

    // 2. GET: Stock by Product ID
    public function getByProduct($productId)
    {
        try {
            $stocks = ProductSizeStock::whereHas('colorVariant', function ($query) use ($productId) {
                $query->where('product_id', $productId);
            })->with(['colorVariant.color'])->get();

            if ($stocks->isEmpty()) {
                return response()->json(['status' => 'error', 'message' => 'No stock found for this product.'], 404);
            }
            return response()->json(['status' => 'success', 'data' => $stocks], 200);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => 'Failed to retrieve stocks.'], 500);
        }
    }

    // 3. GET: Stock by Size
    public function getBySize($size)
    {
        try {
            $stocks = ProductSizeStock::where('size', $size)
                ->with(['colorVariant.product', 'colorVariant.color'])
                ->get();
            return response()->json(['status' => 'success', 'data' => $stocks], 200);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => 'Failed to retrieve stocks.'], 500);
        }
    }

    // 4. POST: Update stock (with Audit Log and Concurrency Safety)
    public function updateStockByProduct(Request $request, $productId)
    {
        try {
            $validated = $request->validate([
                'color_id' => 'required|exists:colors,id',
                'size' => 'required|string',
                'adjustment' => 'required|integer',
                'reason' => 'required|string|max:255',
            ]);

            return DB::transaction(function () use ($validated, $productId) {
                $stockItem = ProductSizeStock::whereHas('colorVariant', function ($query) use ($productId, $validated) {
                    $query->where('product_id', $productId)
                        ->where('color_id', $validated['color_id']);
                })
                    ->where('size', $validated['size'])
                    ->lockForUpdate()
                    ->firstOrFail();

                $previousStock = $stockItem->stock;
                $newStock = $previousStock + $validated['adjustment'];

                if ($newStock < 0) {
                    throw new Exception("Insufficient stock.");
                }

                $stockItem->update(['stock' => $newStock]);

                // HARDCODED FIX: Manually setting admin_id to 1
                InventoryLog::create([
                    'product_id' => $productId,
                    'color_id' => $validated['color_id'],
                    'size' => $validated['size'],
                    'previous_stock' => $previousStock,
                    'adjustment_amount' => $validated['adjustment'],
                    'new_stock' => $newStock,
                    'reason' => $validated['reason'],
                    'admin_id' => 1, // Hardcoded for debugging
                ]);

                return response()->json([
                    'status' => 'success',
                    'message' => 'Stock updated successfully.',
                    'data' => ['new_stock' => $newStock]
                ], 200);
            });

        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'Variant not found.'], 404);
        } catch (Exception $e) {
            Log::error('Inventory Update Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}