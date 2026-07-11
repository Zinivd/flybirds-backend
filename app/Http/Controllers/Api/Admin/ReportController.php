<?php
namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductSizeStock;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Exception;

/**
 * ═══════════════════════════════════════════════════════════════
 * ReportController
 * Admin-only reporting endpoints:
 *   - Sales Report            GET /admin/reports/sales
 *   - Product Inventory Report GET /admin/reports/inventory
 *   - Order Report             GET /admin/reports/orders
 *   - Transaction Report       GET /admin/reports/transactions
 *
 * Created with:
 *   php artisan make:controller Api/Admin/ReportController
 * ═══════════════════════════════════════════════════════════════
 */
class ReportController extends Controller
{
    private const GROUP_BY_OPTIONS = ['daily', 'weekly', 'monthly'];

    // ═══════════════════════════════════════════════════════════════
    // PRIVATE HELPER: Resolve & validate a date range.
    // Defaults to the last 30 days (inclusive) when not provided.
    // ═══════════════════════════════════════════════════════════════
    private function resolveDateRange(Request $request): array
    {
        $to   = $request->filled('date_to')
            ? Carbon::parse($request->date_to)->endOfDay()
            : Carbon::now()->endOfDay();

        $from = $request->filled('date_from')
            ? Carbon::parse($request->date_from)->startOfDay()
            : (clone $to)->subDays(29)->startOfDay();

        return [$from, $to];
    }

    // ═══════════════════════════════════════════════════════════════
    // PRIVATE HELPER: MySQL DATE_FORMAT pattern for the grouping mode.
    // ═══════════════════════════════════════════════════════════════
    private function dateFormatFor(string $groupBy): string
    {
        return match ($groupBy) {
            'monthly' => '%Y-%m',
            'weekly'  => '%x-W%v', // ISO year-week, e.g. 2026-W28
            default   => '%Y-%m-%d',
        };
    }

    // ═══════════════════════════════════════════════════════════════
    // GET /admin/reports/sales
    // Query params:
    //   date_from, date_to        (Y-m-d, default: last 30 days)
    //   group_by                  daily|weekly|monthly (default: daily)
    //   payment_status            filter e.g. Paid
    //   delivery_status           filter e.g. Delivered
    //   top_limit                 how many top products to return (default 10)
    // ═══════════════════════════════════════════════════════════════
    public function salesReport(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'date_from'       => 'nullable|date',
            'date_to'         => 'nullable|date|after_or_equal:date_from',
            'group_by'        => 'nullable|in:' . implode(',', self::GROUP_BY_OPTIONS),
            'payment_status'  => 'nullable|string|max:50',
            'delivery_status' => 'nullable|string|max:50',
            'top_limit'       => 'nullable|integer|min:1|max:50',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        try {
            [$from, $to] = $this->resolveDateRange($request);
            $groupBy   = $request->input('group_by', 'daily');
            $topLimit  = (int) $request->input('top_limit', 10);
            $dateFmt   = $this->dateFormatFor($groupBy);

            // Base filtered order query (reused for multiple aggregates)
            $baseQuery = Order::whereBetween('orders.created_at', [$from, $to]);
            if ($request->filled('payment_status')) {
                $baseQuery->where('payment_status', $request->payment_status);
            }
            if ($request->filled('delivery_status')) {
                $baseQuery->where('delivery_status', $request->delivery_status);
            }

            // ── Summary totals ──────────────────────────────────────
            $summary = (clone $baseQuery)->selectRaw('
                    COUNT(*) as total_orders,
                    COALESCE(SUM(amount), 0) as total_revenue,
                    COALESCE(SUM(subtotal), 0) as total_subtotal,
                    COALESCE(SUM(discount), 0) as total_discount,
                    COALESCE(SUM(shipping), 0) as total_shipping,
                    COALESCE(SUM(tax), 0) as total_tax,
                    COALESCE(AVG(amount), 0) as average_order_value
                ')->first();

            $cancelledCount = (clone $baseQuery)->where('delivery_status', 'Cancelled')->count();
            $refundedCount  = (clone $baseQuery)->where('payment_status', 'Refunded')->count();

            // ── Sales over time (grouped) ───────────────────────────
            $salesOverTime = (clone $baseQuery)
                ->selectRaw("DATE_FORMAT(orders.created_at, '{$dateFmt}') as period")
                ->selectRaw('COUNT(*) as orders_count')
                ->selectRaw('COALESCE(SUM(amount), 0) as revenue')
                ->groupBy('period')
                ->orderBy('period')
                ->get();

            // ── Top selling products ────────────────────────────────
            $topProducts = OrderItem::join('orders', 'orders.id', '=', 'order_items.order_table_id')
                ->whereBetween('orders.created_at', [$from, $to])
                ->when($request->filled('payment_status'), fn ($q) => $q->where('orders.payment_status', $request->payment_status))
                ->when($request->filled('delivery_status'), fn ($q) => $q->where('orders.delivery_status', $request->delivery_status))
                ->select('order_items.product_id', 'order_items.product_name')
                ->selectRaw('SUM(order_items.quantity) as units_sold')
                ->selectRaw('SUM(order_items.total) as revenue')
                ->groupBy('order_items.product_id', 'order_items.product_name')
                ->orderByDesc('units_sold')
                ->limit($topLimit)
                ->get();

            // ── Payment method breakdown ────────────────────────────
            $paymentMethodBreakdown = (clone $baseQuery)
                ->select('payment_method')
                ->selectRaw('COUNT(*) as orders_count')
                ->selectRaw('COALESCE(SUM(amount), 0) as revenue')
                ->groupBy('payment_method')
                ->orderByDesc('revenue')
                ->get();

            // ── Delivery status breakdown ───────────────────────────
            $deliveryStatusBreakdown = (clone $baseQuery)
                ->select('delivery_status')
                ->selectRaw('COUNT(*) as count')
                ->groupBy('delivery_status')
                ->get();

            return response()->json([
                'status' => 'success',
                'data'   => [
                    'range' => [
                        'date_from' => $from->toDateString(),
                        'date_to'   => $to->toDateString(),
                        'group_by'  => $groupBy,
                    ],
                    'summary' => [
                        'total_orders'         => (int) $summary->total_orders,
                        'total_revenue'        => round((float) $summary->total_revenue, 2),
                        'total_subtotal'       => round((float) $summary->total_subtotal, 2),
                        'total_discount'       => round((float) $summary->total_discount, 2),
                        'total_shipping'       => round((float) $summary->total_shipping, 2),
                        'total_tax'            => round((float) $summary->total_tax, 2),
                        'average_order_value'  => round((float) $summary->average_order_value, 2),
                        'cancelled_orders'     => $cancelledCount,
                        'refunded_orders'      => $refundedCount,
                    ],
                    'sales_over_time'          => $salesOverTime,
                    'top_selling_products'     => $topProducts,
                    'payment_method_breakdown' => $paymentMethodBreakdown,
                    'delivery_status_breakdown'=> $deliveryStatusBreakdown,
                ],
            ], 200);
        } catch (Exception $e) {
            Log::error('Sales Report Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to generate sales report.'], 500);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // GET /admin/reports/inventory
    // Query params:
    //   category_id        filter by product category
    //   low_stock_threshold default 5 — items at/under this qty are "low stock"
    //   search              filter by product name
    //   top_limit           how many "top value" items to return (default 10)
    // ═══════════════════════════════════════════════════════════════
    // ═══════════════════════════════════════════════════════════════
// GET /admin/reports/inventory
// Query params:
//   category_id          filter by product category
//   low_stock_threshold   default 5 — items at/under this qty are "low stock"
//   search                filter by product name
//   top_limit             how many "top value" items to return (default 10)
//   stock_status          all|in_stock|low_stock|out_of_stock (default: all) — applies to all_products list
//   sort_by               name|stock|value (default: value)
//   sort_dir              asc|desc (default: desc)
//   page                  page number for all_products list (default 1)
//   per_page               rows per page for all_products list (default 15, max 100)
// ═══════════════════════════════════════════════════════════════
public function productInventoryReport(Request $request)
{
    $validator = Validator::make($request->all(), [
        'category_id'         => 'nullable|exists:categories,id',
        'low_stock_threshold' => 'nullable|integer|min:0',
        'search'              => 'nullable|string|max:255',
        'top_limit'           => 'nullable|integer|min:1|max:50',
        'stock_status'        => 'nullable|in:all,in_stock,low_stock,out_of_stock',
        'sort_by'             => 'nullable|in:name,stock,value',
        'sort_dir'            => 'nullable|in:asc,desc',
        'page'                => 'nullable|integer|min:1',
        'per_page'            => 'nullable|integer|min:1|max:100',
    ]);
    if ($validator->fails()) {
        return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
    }
    try {
        $threshold  = (int) $request->input('low_stock_threshold', 5);
        $topLimit   = (int) $request->input('top_limit', 10);
        $stockStatus = $request->input('stock_status', 'all');
        $sortBy     = $request->input('sort_by', 'value');
        $sortDir    = $request->input('sort_dir', 'desc');
        $perPage    = (int) $request->input('per_page', 15);

        $baseQuery = ProductSizeStock::query()
            ->join('product_color_variants', 'product_color_variants.id', '=', 'product_size_stocks.product_color_variant_id')
            ->join('products', 'products.id', '=', 'product_color_variants.product_id')
            ->when($request->filled('category_id'), fn ($q) => $q->where('products.category_id', $request->category_id))
            ->when($request->filled('search'), fn ($q) => $q->where('products.name', 'like', '%' . $request->search . '%'));

        // ── Summary totals ──────────────────────────────────────
        $summary = (clone $baseQuery)->selectRaw('
                COUNT(DISTINCT products.id) as total_products,
                COUNT(product_size_stocks.id) as total_variants,
                COALESCE(SUM(product_size_stocks.stock), 0) as total_stock_units,
                COALESCE(SUM(product_size_stocks.stock * product_size_stocks.price), 0) as total_stock_value
            ')->first();

        $outOfStockCount = (clone $baseQuery)->where('product_size_stocks.stock', '<=', 0)->count();
        $lowStockCount   = (clone $baseQuery)
            ->where('product_size_stocks.stock', '>', 0)
            ->where('product_size_stocks.stock', '<=', $threshold)
            ->count();

        // ── Low stock item list (variant-level, capped at 100) ─
        $lowStockItems = (clone $baseQuery)
            ->where('product_size_stocks.stock', '<=', $threshold)
            ->select(
                'products.id as product_id',
                'products.name as product_name',
                'product_size_stocks.id as size_stock_id',
                'product_size_stocks.sku',
                'product_size_stocks.size',
                'product_size_stocks.stock',
                'product_size_stocks.price'
            )
            ->orderBy('product_size_stocks.stock')
            ->limit(100)
            ->get();

        // ── Top products by stock value ─────────────────────────
        $topByValue = (clone $baseQuery)
            ->select('products.id as product_id', 'products.name as product_name')
            ->selectRaw('SUM(product_size_stocks.stock) as total_stock')
            ->selectRaw('SUM(product_size_stocks.stock * product_size_stocks.price) as stock_value')
            ->groupBy('products.id', 'products.name')
            ->orderByDesc('stock_value')
            ->limit($topLimit)
            ->get();

        // ── Stock grouped by category ───────────────────────────
        $stockByCategory = ProductSizeStock::query()
            ->join('product_color_variants', 'product_color_variants.id', '=', 'product_size_stocks.product_color_variant_id')
            ->join('products', 'products.id', '=', 'product_color_variants.product_id')
            ->join('categories', 'categories.id', '=', 'products.category_id')
            ->when($request->filled('category_id'), fn ($q) => $q->where('products.category_id', $request->category_id))
            ->select('categories.id as category_id', 'categories.name as category_name')
            ->selectRaw('COALESCE(SUM(product_size_stocks.stock), 0) as total_stock_units')
            ->selectRaw('COALESCE(SUM(product_size_stocks.stock * product_size_stocks.price), 0) as total_stock_value')
            ->groupBy('categories.id', 'categories.name')
            ->orderByDesc('total_stock_value')
            ->get();

        // ── ALL PRODUCTS — full per-product stock + value list ──
        // (paginated, searchable, filterable by category/stock status, sortable)
        $allProductsQuery = ProductSizeStock::query()
            ->join('product_color_variants', 'product_color_variants.id', '=', 'product_size_stocks.product_color_variant_id')
            ->join('products', 'products.id', '=', 'product_color_variants.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->when($request->filled('category_id'), fn ($q) => $q->where('products.category_id', $request->category_id))
            ->when($request->filled('search'), fn ($q) => $q->where('products.name', 'like', '%' . $request->search . '%'))
            ->select(
                'products.id as product_id',
                'products.name as product_name',
                'categories.name as category_name'
            )
            ->selectRaw('COUNT(product_size_stocks.id) as variant_count')
            ->selectRaw('COALESCE(SUM(product_size_stocks.stock), 0) as total_stock')
            ->selectRaw('COALESCE(SUM(product_size_stocks.stock * product_size_stocks.price), 0) as stock_value')
            ->selectRaw('COALESCE(MIN(product_size_stocks.price), 0) as min_price')
            ->selectRaw('COALESCE(MAX(product_size_stocks.price), 0) as max_price')
            ->groupBy('products.id', 'products.name', 'categories.name');

        // Stock status filter applies on the aggregated total_stock, so use HAVING
        if ($stockStatus === 'out_of_stock') {
            $allProductsQuery->havingRaw('COALESCE(SUM(product_size_stocks.stock), 0) <= 0');
        } elseif ($stockStatus === 'low_stock') {
            $allProductsQuery
                ->havingRaw('COALESCE(SUM(product_size_stocks.stock), 0) > 0')
                ->havingRaw('COALESCE(SUM(product_size_stocks.stock), 0) <= ?', [$threshold]);
        } elseif ($stockStatus === 'in_stock') {
            $allProductsQuery->havingRaw('COALESCE(SUM(product_size_stocks.stock), 0) > ?', [$threshold]);
        }

        $sortColumnMap = [
            'name'  => 'product_name',
            'stock' => 'total_stock',
            'value' => 'stock_value',
        ];
        $allProductsQuery->orderBy($sortColumnMap[$sortBy] ?? 'stock_value', $sortDir);

        // Wrap in a subquery-based paginate since we group+having on aggregates
        $allProductsPaginated = $allProductsQuery->paginate($perPage)->withQueryString();

        return response()->json([
            'status' => 'success',
            'data'   => [
                'filters' => [
                    'category_id'         => $request->category_id,
                    'low_stock_threshold' => $threshold,
                    'stock_status'        => $stockStatus,
                    'sort_by'             => $sortBy,
                    'sort_dir'            => $sortDir,
                ],
                'summary' => [
                    'total_products'     => (int) $summary->total_products,
                    'total_variants'     => (int) $summary->total_variants,
                    'total_stock_units'  => (int) $summary->total_stock_units,
                    'total_stock_value'  => round((float) $summary->total_stock_value, 2),
                    'out_of_stock_count' => $outOfStockCount,
                    'low_stock_count'    => $lowStockCount,
                ],
                'low_stock_items'       => $lowStockItems,
                'top_products_by_value' => $topByValue,
                'stock_by_category'     => $stockByCategory,
                'all_products'          => [
                    'data'         => $allProductsPaginated->items(),
                    'current_page' => $allProductsPaginated->currentPage(),
                    'last_page'    => $allProductsPaginated->lastPage(),
                    'per_page'     => $allProductsPaginated->perPage(),
                    'total'        => $allProductsPaginated->total(),
                ],
            ],
        ], 200);
    } catch (Exception $e) {
        Log::error('Product Inventory Report Error: ' . $e->getMessage());
        return response()->json(['status' => 'error', 'message' => 'Failed to generate inventory report.'], 500);
    }
}

    // ═══════════════════════════════════════════════════════════════
    // GET /admin/reports/orders
    // Query params:
    //   date_from, date_to  (Y-m-d, default: last 30 days)
    //   delivery_status, payment_status
    //   recent_limit         how many recent orders to include (default 10)
    // ═══════════════════════════════════════════════════════════════
    public function orderReport(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'date_from'       => 'nullable|date',
            'date_to'         => 'nullable|date|after_or_equal:date_from',
            'delivery_status' => 'nullable|string|max:50',
            'payment_status'  => 'nullable|string|max:50',
            'recent_limit'    => 'nullable|integer|min:1|max:50',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        try {
            [$from, $to] = $this->resolveDateRange($request);
            $recentLimit = (int) $request->input('recent_limit', 10);

            $baseQuery = Order::whereBetween('created_at', [$from, $to]);
            if ($request->filled('delivery_status')) {
                $baseQuery->where('delivery_status', $request->delivery_status);
            }
            if ($request->filled('payment_status')) {
                $baseQuery->where('payment_status', $request->payment_status);
            }

            $totalOrders = (clone $baseQuery)->count();
            $totalRevenue = (clone $baseQuery)->where('payment_status', 'Paid')->sum('amount');

            $ordersByDeliveryStatus = (clone $baseQuery)
                ->select('delivery_status')
                ->selectRaw('COUNT(*) as count')
                ->groupBy('delivery_status')
                ->get();

            $ordersByPaymentStatus = (clone $baseQuery)
                ->select('payment_status')
                ->selectRaw('COUNT(*) as count')
                ->groupBy('payment_status')
                ->get();

            $cancelledCount = (clone $baseQuery)->where('delivery_status', 'Cancelled')->count();
            $cancellationRate = $totalOrders > 0 ? round(($cancelledCount / $totalOrders) * 100, 2) : 0;

            $deliveredCount = (clone $baseQuery)->where('delivery_status', 'Delivered')->count();
            $fulfillmentRate = $totalOrders > 0 ? round(($deliveredCount / $totalOrders) * 100, 2) : 0;

            $recentOrders = (clone $baseQuery)
                ->with('items')
                ->orderByDesc('created_at')
                ->limit($recentLimit)
                ->get();

            // ── Average items per order ──────────────────────────────
            $avgItemsPerOrder = OrderItem::join('orders', 'orders.id', '=', 'order_items.order_table_id')
                ->whereBetween('orders.created_at', [$from, $to])
                ->select('orders.id')
                ->selectRaw('SUM(order_items.quantity) as item_count')
                ->groupBy('orders.id')
                ->get()
                ->avg('item_count');

            return response()->json([
                'status' => 'success',
                'data'   => [
                    'range' => [
                        'date_from' => $from->toDateString(),
                        'date_to'   => $to->toDateString(),
                    ],
                    'summary' => [
                        'total_orders'          => $totalOrders,
                        'total_revenue'         => round((float) $totalRevenue, 2),
                        'cancelled_orders'      => $cancelledCount,
                        'cancellation_rate_pct' => $cancellationRate,
                        'delivered_orders'      => $deliveredCount,
                        'fulfillment_rate_pct'  => $fulfillmentRate,
                        'average_items_per_order' => round((float) ($avgItemsPerOrder ?? 0), 2),
                    ],
                    'orders_by_delivery_status' => $ordersByDeliveryStatus,
                    'orders_by_payment_status'  => $ordersByPaymentStatus,
                    'recent_orders'             => $recentOrders,
                ],
            ], 200);
        } catch (Exception $e) {
            Log::error('Order Report Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to generate order report.'], 500);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // GET /admin/reports/transactions
    // Query params:
    //   date_from, date_to  (Y-m-d, default: last 30 days)
    //   status               Pending|Success|Failed
    //   group_by             daily|weekly|monthly (default: daily)
    // ═══════════════════════════════════════════════════════════════
    public function transactionReport(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'date_from' => 'nullable|date',
            'date_to'   => 'nullable|date|after_or_equal:date_from',
            'status'    => 'nullable|string|max:50',
            'group_by'  => 'nullable|in:' . implode(',', self::GROUP_BY_OPTIONS),
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        try {
            [$from, $to] = $this->resolveDateRange($request);
            $groupBy = $request->input('group_by', 'daily');
            $dateFmt = $this->dateFormatFor($groupBy);

            $baseQuery = Transaction::whereBetween('created_at', [$from, $to]);
            if ($request->filled('status')) {
                $baseQuery->where('status', $request->status);
            }

            $totalTransactions = (clone $baseQuery)->count();
            $totalAmount       = (clone $baseQuery)->sum('amount');

            $byStatus = (clone $baseQuery)
                ->select('status')
                ->selectRaw('COUNT(*) as count')
                ->selectRaw('COALESCE(SUM(amount), 0) as total_amount')
                ->groupBy('status')
                ->get();

            $successCount = (clone $baseQuery)->where('status', 'Success')->count();
            $failedCount  = (clone $baseQuery)->where('status', 'Failed')->count();
            $pendingCount = (clone $baseQuery)->where('status', 'Pending')->count();
            $successRate  = $totalTransactions > 0 ? round(($successCount / $totalTransactions) * 100, 2) : 0;

            $volumeOverTime = (clone $baseQuery)
                ->selectRaw("DATE_FORMAT(created_at, '{$dateFmt}') as period")
                ->selectRaw('COUNT(*) as transactions_count')
                ->selectRaw('COALESCE(SUM(amount), 0) as amount')
                ->groupBy('period')
                ->orderBy('period')
                ->get();

            $recentTransactions = (clone $baseQuery)
                ->orderByDesc('created_at')
                ->limit(20)
                ->get(['id', 'order_table_id', 'razorpay_order_id', 'razorpay_payment_id', 'amount', 'currency', 'status', 'created_at']);

            return response()->json([
                'status' => 'success',
                'data'   => [
                    'range' => [
                        'date_from' => $from->toDateString(),
                        'date_to'   => $to->toDateString(),
                        'group_by'  => $groupBy,
                    ],
                    'summary' => [
                        'total_transactions' => $totalTransactions,
                        'total_amount'       => round((float) $totalAmount, 2),
                        'success_count'      => $successCount,
                        'failed_count'       => $failedCount,
                        'pending_count'      => $pendingCount,
                        'success_rate_pct'   => $successRate,
                    ],
                    'by_status'           => $byStatus,
                    'volume_over_time'    => $volumeOverTime,
                    'recent_transactions' => $recentTransactions,
                ],
            ], 200);
        } catch (Exception $e) {
            Log::error('Transaction Report Error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to generate transaction report.'], 500);
        }
    }
}