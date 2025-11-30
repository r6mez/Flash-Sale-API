<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use App\Models\Webhook;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function handlePayment(Request $request)
    {
        $request->validate([
            'idempotency_key' => 'required|string',
            'order_id' => 'required|integer',
            'status' => 'required|in:success,failure',
        ]);

        $idempotencyKey = $request->input('idempotency_key');
        $orderId = $request->input('order_id');
        $paymentStatus = $request->input('status');

        $lockAcquiredAt = microtime(true);
        
        return Cache::lock("webhook:{$idempotencyKey}", 10)->block(10, function () use ($idempotencyKey, $orderId, $paymentStatus, $lockAcquiredAt) {
            $lockWaitTime = round((microtime(true) - $lockAcquiredAt) * 1000, 2);
            
            if ($lockWaitTime > 100) {
                Log::warning('Webhook lock contention detected', [
                    'idempotency_key' => $idempotencyKey,
                    'order_id' => $orderId,
                    'lock_wait_ms' => $lockWaitTime,
                ]);
            }
            
            return DB::transaction(function () use ($idempotencyKey, $orderId, $paymentStatus) {
                $existingWebhook = Webhook::where('idempotency_key', $idempotencyKey)->first();

                if ($existingWebhook && $existingWebhook->status === 'processed') {
                    Log::info('Webhook deduplicated - already processed', [
                        'idempotency_key' => $idempotencyKey,
                        'order_id' => $orderId,
                        'attempts' => $existingWebhook->attempts,
                    ]);
                    return response()->json([
                        'message' => 'Webhook already processed',
                        'idempotency_key' => $idempotencyKey,
                    ], 200);
                }

                $order = Order::lockForUpdate()->find($orderId);

                if (!$order) {
                    // webhook arrived before order was created
                    Log::warning('Webhook arrived before order creation - queued for retry', [
                        'idempotency_key' => $idempotencyKey,
                        'order_id' => $orderId,
                        'payment_status' => $paymentStatus,
                    ]);
                    
                    $this->recordWebhook($idempotencyKey, [
                        'order_id' => $orderId,
                        'status' => $paymentStatus,
                    ], 'pending');

                    return response()->json([
                        'message' => 'Order not found, webhook recorded for later processing',
                        'idempotency_key' => $idempotencyKey,
                    ], 202);
                }

                if ($order->status !== 'pending') {
                    $this->recordWebhook($idempotencyKey, [
                        'order_id' => $orderId,
                        'status' => $paymentStatus,
                    ], 'processed');

                    return response()->json([
                        'message' => 'Order already in final state',
                        'order_status' => $order->status,
                        'idempotency_key' => $idempotencyKey,
                    ], 200);
                }

                if ($paymentStatus === 'success') {
                    $order->status = 'paid';
                    $order->save();

                    Log::info('Payment successful - order paid', [
                        'idempotency_key' => $idempotencyKey,
                        'order_id' => $order->id,
                        'product_id' => $order->product_id,
                        'amount_cents' => $order->amount_cents,
                    ]);

                    $this->recordWebhook($idempotencyKey, [
                        'order_id' => $orderId,
                        'status' => $paymentStatus,
                    ], 'processed');

                    return response()->json([
                        'message' => 'Payment successful, order marked as paid',
                        'order_id' => $order->id,
                        'order_status' => $order->status,
                        'idempotency_key' => $idempotencyKey,
                    ], 200);
                } else { // payment failed
                    $order->status = 'cancelled';
                    $order->save();

                    $product = Product::lockForUpdate()->find($order->product_id);
                    
                    if ($product) {
                        $product->stock += $order->qty;
                        $product->save();
                        Cache::forget("product:{$product->id}");
                        
                        Log::info('Payment failed - stock restored', [
                            'idempotency_key' => $idempotencyKey,
                            'order_id' => $order->id,
                            'product_id' => $product->id,
                            'qty_restored' => $order->qty,
                            'new_stock' => $product->stock,
                        ]);
                    }

                    $this->recordWebhook($idempotencyKey, [
                        'order_id' => $orderId,
                        'status' => $paymentStatus,
                    ], 'processed');

                    return response()->json([
                        'message' => 'Payment failed, order cancelled and stock released',
                        'order_id' => $order->id,
                        'order_status' => $order->status,
                        'idempotency_key' => $idempotencyKey,
                    ], 200);
                }
            });
        });
    }

    private function recordWebhook(string $idempotencyKey, array $payload, string $status): Webhook
    {
        $webhook = Webhook::where('idempotency_key', $idempotencyKey)->first();
        
        if ($webhook) {
            $webhook->update([
                'payload' => $payload,
                'status' => $status,
                'attempts' => $webhook->attempts + 1,
            ]);
            return $webhook;
        }
        
        return Webhook::create([
            'idempotency_key' => $idempotencyKey,
            'payload' => $payload,
            'status' => $status,
            'attempts' => 1,
        ]);
    }
}
