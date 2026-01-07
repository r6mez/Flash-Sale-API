<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Webhook;
use App\Services\RedisStockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function __construct(private RedisStockService $redisStock) {}

    public function handlePayment(Request $request)
    {
        $request->validate([
            'idempotency_key' => 'required|string',
            'order_reference' => 'required|string',
            'status' => 'required|in:success,failure',
        ]);

        $idempotencyKey = $request->input('idempotency_key');
        $orderReference = $request->input('order_reference');
        $paymentStatus = $request->input('status');

        $lockAcquiredAt = microtime(true);
        
        return Cache::lock("webhook:{$idempotencyKey}", 10)->block(10, function () use ($idempotencyKey, $orderReference, $paymentStatus, $lockAcquiredAt) {
            $lockWaitTime = round((microtime(true) - $lockAcquiredAt) * 1000, 2);
            
            if ($lockWaitTime > 100) {
                Log::warning('Webhook lock contention detected', [
                    'idempotency_key' => $idempotencyKey,
                    'order_reference' => $orderReference,
                    'lock_wait_ms' => $lockWaitTime,
                ]);
            }
            
            return DB::transaction(function () use ($idempotencyKey, $orderReference, $paymentStatus) {
                $existingWebhook = Webhook::where('idempotency_key', $idempotencyKey)->first();

                if ($existingWebhook && $existingWebhook->status === 'processed') {
                    return response()->json([
                        'message' => 'Webhook already processed',
                        'idempotency_key' => $idempotencyKey,
                    ], 200);
                }

                $order = Order::where('order_reference', $orderReference)->lockForUpdate()->first();

                if (!$order) {
                    // webhook arrived before order was created (Async race condition)
                    Log::warning('Webhook arrived before order creation, queued for retry', [
                        'idempotency_key' => $idempotencyKey,
                        'order_ref' => $orderReference,
                        'payment_status' => $paymentStatus,
                    ]);
                    
                    $this->recordWebhook($idempotencyKey, [
                        'order_reference' => $orderReference,
                        'status' => $paymentStatus,
                    ], 'pending');

                    return response()->json([
                        'message' => 'Order not found, webhook recorded for later processing',
                        'idempotency_key' => $idempotencyKey,
                    ], 202);
                }

                if ($order->status !== 'pending') {
                    $this->recordWebhook($idempotencyKey, [
                        'order_reference' => $orderReference,
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

                    Log::info('Payment successful, order paid', [
                        'order_ref' => $orderReference,
                        'amount' => $order->amount_cents,
                    ]);

                    $this->recordWebhook($idempotencyKey, [
                        'order_reference' => $orderReference,
                        'status' => $paymentStatus,
                    ], 'processed');

                    return response()->json([
                        'message' => 'Payment successful, order marked as paid',
                        'order_ref' => $orderReference,
                    ], 200);
                } else { // payment failed
                    $order->status = 'cancelled';
                    $order->save();

                    $this->redisStock->incrementStock($order->product_id, $order->qty);
                    
                    Log::info('Payment failed - stock restored to Redis', [
                        'order_ref' => $orderReference,
                        'product_id' => $order->product_id,
                        'qty_restored' => $order->qty,
                    ]);

                    $this->recordWebhook($idempotencyKey, [
                        'order_reference' => $orderReference,
                        'status' => $paymentStatus,
                    ], 'processed');

                    return response()->json([
                        'message' => 'Payment failed, order cancelled and stock released',
                        'order_ref' => $orderReference,
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
