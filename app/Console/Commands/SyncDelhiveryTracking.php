<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\OrderTrackingService;
use Illuminate\Console\Command;

class SyncDelhiveryTracking extends Command
{
    protected $signature = 'orders:sync-tracking';
    protected $description = 'Sync Delhivery tracking status into orders';

    public function handle(OrderTrackingService $svc): int
    {
        $count = 0;

        Order::whereNotNull('awb_number')
            ->whereNotIn('delivery_status', ['Delivered', 'Cancelled', 'Refunded', 'RTO'])
            ->chunkById(50, function ($orders) use ($svc, &$count) {
                foreach ($orders as $order) {
                    $svc->sync($order, true);
                    $count++;
                }
            });

        $this->info("Synced {$count} orders.");
        return self::SUCCESS;
    }
}
