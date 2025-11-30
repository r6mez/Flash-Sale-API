<?php

namespace App\Http\Controllers;

use App\Models\Hold;
use App\Models\Order;
use App\Models\Product;
use App\Models\Webhook;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderController extends Controller
{
    function create(Request $request)
    {
        $request->validate([
            'hold_id' => 'required|exists:holds,id',
        ]);
        
        $hold_id = $request->input('hold_id');

        $hold = Hold::find($hold_id);

        if ($hold->expire_at < now()) {
            Log::info('Order rejected - hold expired', [
                'hold_id' => $hold_id,
                'expired_at' => $hold->expire_at->toIso8601String(),
            ]);
            return response()->json(['error' => 'Hold expired'], 410);
        }

        $order = DB::transaction(function () use ($hold) {
            $hold = Hold::lockForUpdate()->find($hold->id);

            if (!$hold || $hold->expire_at < now()) {
                return null;
            }

            $product = Product::find($hold->product_id);

            $order = Order::create([
                'product_id' => $hold->product_id,
                'hold_id' => $hold->id,
                'qty' => $hold->qty,
                'amount_cents' => $product->price_cents * $hold->qty,
                'status' => 'pending',
            ]);

            $hold->delete();

            return $order;
        });

        if ($order === null) {
            Log::info('Order rejected - hold expired during transaction', [
                'hold_id' => $hold_id,
            ]);
            return response()->json(['error' => 'Hold expired'], 410);
        }

        Log::info('Order created successfully', [
            'order_id' => $order->id,
            'product_id' => $order->product_id,
            'qty' => $order->qty,
            'amount_cents' => $order->amount_cents,
            'status' => $order->status,
        ]);

        $this->processPendingWebhooks($order); // if exist

        $order->refresh();

        return response()->json(['data' => $order], 201);
    }

    private function processPendingWebhooks(Order $order): void
    {
        $pendingWebhooks = Webhook::where('status', 'pending')
            ->whereJsonContains('payload->order_id', $order->id)
            ->get();

        foreach ($pendingWebhooks as $webhook) {
            $paymentStatus = $webhook->payload['status'] ?? null;
            
            if (!$paymentStatus) {
                continue;
            }

            Log::info('Processing pending webhook for newly created order', [
                'webhook_id' => $webhook->id,
                'idempotency_key' => $webhook->idempotency_key,
                'order_id' => $order->id,
                'payment_status' => $paymentStatus,
            ]);

            DB::transaction(function () use ($order, $webhook, $paymentStatus) {
                $order = Order::lockForUpdate()->find($order->id);
                
                if ($order->status !== 'pending') {
                    // Already processed
                    return;
                }

                if ($paymentStatus === 'success') {
                    $order->status = 'paid';
                    $order->save();

                    Log::info('Pending webhook processed - order paid', [
                        'order_id' => $order->id,
                        'idempotency_key' => $webhook->idempotency_key,
                    ]);
                } else {
                    $order->status = 'cancelled';
                    $order->save();

                    $product = Product::lockForUpdate()->find($order->product_id);
                    if ($product) {
                        $product->stock += $order->qty;
                        $product->save();
                        Cache::forget("product:{$product->id}");

                        Log::info('Pending webhook processed - payment failed, stock restored', [
                            'order_id' => $order->id,
                            'idempotency_key' => $webhook->idempotency_key,
                            'qty_restored' => $order->qty,
                            'new_stock' => $product->stock,
                        ]);
                    }
                }

                $webhook->update([
                    'status' => 'processed',
                    'attempts' => $webhook->attempts + 1,
                ]);
            });
        }
    }
}
