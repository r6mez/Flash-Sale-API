<?php

namespace App\Console\Commands;

use App\Services\RedisStockService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;


class CancelUnpaidOrders extends Command
{
    protected $signature = 'orders:release';
    protected $description = 'Cancel unpaid orders and restore Redis stock';
 
    public function handle(RedisStockService $redisStock)
    {

        $expiredOrders = \App\Models\Order::where('status', 'pending')
            ->where('created_at', '<', now()->subMinutes(2))
            ->get();

        foreach ($expiredOrders as $order) {
            DB::transaction(function () use ($order, $redisStock) {
                $order->status = 'cancelled';
                $order->save();
                $redisStock->incrementStock($order->product_id, $order->qty);
                $this->info("Cancelled order {$order->order_reference} and restored stock for product {$order->product_id}");
            });
        }
    }
}
