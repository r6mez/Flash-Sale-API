<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use App\Models\Webhook;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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

        return Cache::lock("webhook:{$idempotencyKey}", 10)->block(10, function () use ($idempotencyKey, $orderId, $paymentStatus) {
            return DB::transaction(function () use ($idempotencyKey, $orderId, $paymentStatus) {
                $existingWebhook = Webhook::where('idempotency_key', $idempotencyKey)->first();

                if ($existingWebhook && $existingWebhook->status === 'processed') {
                    return response()->json([
                        'message' => 'Webhook already processed',
                        'idempotency_key' => $idempotencyKey,
                    ], 200);
                }

                $order = Order::lockForUpdate()->find($orderId);

                if (!$order) {
                    // webhook arrived before order was created
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
