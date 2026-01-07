<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\Product;
use App\Models\Webhook;
use App\Services\RedisStockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessOrderCreation implements ShouldQueue
{
    use Queueable;
    
    public function __construct(public int $productId, public int $qty, public string $orderReference)
    {
        //        
    }

    public function handle(RedisStockService $redisStockService): void
    {
        $product = Product::find($this->productId);
        
        if (!$product) {
            Log::error("Product {$this->productId} not found for order {$this->orderReference}");
            return;
        }

        // avoid double processing
        if (Order::where('order_reference', $this->orderReference)->exists()) { return; }

        DB::transaction(function () use ($product, $redisStockService) {
            $order = Order::create([
                'product_id' => $this->productId,
                'qty' => $this->qty,
                'order_reference' => $this->orderReference,
                'amount_cents' => $product->price * $this->qty,
                'status' => 'pending',
            ]);

            Log::info("Order {$this->orderReference} created for product {$this->productId}, qty {$this->qty}");

            // Check for pending webhook that arrived early
            $webhook = Webhook::where('payload->order_reference', $this->orderReference)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->first();

            if ($webhook) {
                $paymentStatus = $webhook->payload['status'] ?? 'failure';

                if ($paymentStatus === 'success') {
                    $order->update(['status' => 'paid']);
                    Log::info("Order {$this->orderReference} immediately updated to paid from pending webhook");
                } else {
                    $order->update(['status' => 'cancelled']);
                    $redisStockService->incrementStock($this->productId, $this->qty);
                    Log::info("Order {$this->orderReference} immediately cancelled from pending webhook and stock restored");
                }

                $webhook->update(['status' => 'processed']);
            }
        });
    }
}
