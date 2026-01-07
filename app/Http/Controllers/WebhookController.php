<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
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
        $signature = $request->header('X-Payment-Signature');
        $expectedSignature = hash_hmac('sha256', $request->getContent(), config('services.payment.secret'));
        
        if (!hash_equals($expectedSignature, $signature ?? '')) {
            return response()->json(['message' => 'Invalid signature'], 401);
        }
        
        $request->validate([
            'order_reference' => 'required|string',
            'status' => 'required|in:success,failure,processed',
        ]);

        $orderReference = $request->input('order_reference');
        $paymentStatus = $request->input('status');

        $lockAcquiredAt = microtime(true);
        
        return Cache::lock("webhook:{$orderReference}", 10)->block(10, function () use ($orderReference, $paymentStatus, $lockAcquiredAt) {
            $lockWaitTime = round((microtime(true) - $lockAcquiredAt) * 1000, 2);
            
            if ($lockWaitTime > 100) {
                Log::warning('Webhook lock contention detected', [
                    'order_reference' => $orderReference,
                    'lock_wait_ms' => $lockWaitTime,
                ]);
            }
            
            return DB::transaction(function () use ($orderReference, $paymentStatus) {
                $existingWebhook = Webhook::where('order_reference', $orderReference)->first();

                if ($existingWebhook && $existingWebhook->status === 'processed') {
                    return response()->json([
                        'message' => 'Webhook already processed',
                        'order_reference' => $orderReference,
                    ], 200);
                }

                $order = Order::where('order_reference', $orderReference)->lockForUpdate()->first();

                if (!$order) {
                    // webhook arrived before order was created (Async race condition)
                    Log::warning('Webhook arrived before order creation, queued for retry', [
                        'order_reference' => $orderReference,
                        'payment_status' => $paymentStatus,
                    ]);
                    
                    $this->recordWebhook($orderReference, [
                        'order_reference' => $orderReference,
                        'status' => $paymentStatus,
                    ], 'pending');

                    return response()->json([
                        'message' => 'Order not found, webhook recorded for later processing',
                        'order_reference' => $orderReference,
                    ], 202);
                }

                if ($order->status !== 'pending') {
                    $this->recordWebhook($orderReference, [
                        'order_reference' => $orderReference,
                        'status' => $paymentStatus,
                    ], 'processed');

                    return response()->json([
                        'message' => 'Order already in final state',
                        'order_status' => $order->status,
                        'order_reference' => $orderReference,
                    ], 200);
                }

                if ($paymentStatus === 'success') {
                    $order->status = 'paid';
                    $order->save();

                    $product = Product::find($order->product_id);
                    $product->stock -= $order->qty;
                    $product->save();

                    Log::info('Payment successful, order paid', [
                        'order_ref' => $orderReference,
                        'amount' => $order->amount_cents,
                    ]);

                    $this->recordWebhook($orderReference, [
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

                    $this->recordWebhook($orderReference, [
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

    private function recordWebhook(string $orderReference, array $payload, string $status): Webhook
    {
        $webhook = Webhook::where('order_reference', $orderReference)->first();
        
        if ($webhook) {
            $webhook->update([
                'payload' => $payload,
                'status' => $status,
                'attempts' => $webhook->attempts + 1,
            ]);
            return $webhook;
        }
        
        return Webhook::create([
            'order_reference' => $orderReference,
            'payload' => $payload,
            'status' => $status,
            'attempts' => 1,
        ]);
    }
}
