<?php

namespace App\Http\Controllers;

use App\Models\Hold;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class HoldController extends Controller
{
    function create(Request $req){
        $req->validate([
            'product_id' => 'required|integer|exists:products,id',
            'qty' => 'required|integer|min:1',
        ]);

        $product_id = $req->input("product_id");
        $qty = $req->input("qty");
        $cacheKey = "product:{$product_id}";

        $lockAcquiredAt = microtime(true);
        
        return Cache::lock("hold:{$product_id}", 5)->block(5, function() use ($product_id, $qty, $cacheKey, $lockAcquiredAt){
            $lockWaitTime = round((microtime(true) - $lockAcquiredAt) * 1000, 2);
            
            if ($lockWaitTime > 100) {
                Log::warning('Hold lock contention detected', [
                    'product_id' => $product_id,
                    'lock_wait_ms' => $lockWaitTime,
                ]);
            }
            
            $product = Product::lockForUpdate()->find($product_id);
            
            if ($product === null) {
                return response()->json(['error' => 'Product not found'], 404);
            }

            if ($product->stock < $qty) {
                Log::info('Hold rejected - insufficient stock', [
                    'product_id' => $product_id,
                    'requested_qty' => $qty,
                    'available_stock' => $product->stock,
                ]);
                return response()->json(['error' => 'Out of stock'], 409);
            }

            $product->stock -= $qty;
            $product->save();
            
            // Invalidating cache so next read gets fresh data
            Cache::forget($cacheKey);

            $hold = Hold::create([
                'product_id' => $product_id,
                'qty' => $qty,
                'expire_at' => now()->addMinutes(2)
            ]);

            Log::info('Hold created successfully', [
                'hold_id' => $hold->id,
                'product_id' => $product_id,
                'qty' => $qty,
                'remaining_stock' => $product->stock,
                'expires_at' => $hold->expire_at->toIso8601String(),
            ]);

            return response()->json(['data' => ["hold_id" => $hold->id, "expires_at" => $hold->expire_at]], 201);
        });
    }
}
