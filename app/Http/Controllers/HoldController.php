<?php

namespace App\Http\Controllers;

use App\Models\Hold;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class HoldController extends Controller
{
    private const CACHE_TTL = 3600; // 1 hour

    function create(Request $req){
        $product_id = $req->input("product_id");
        $qty = $req->input("qty");
        $cacheKey = "product:{$product_id}";

        return Cache::lock("hold:{$product_id}", 5)->block(5, function() use ($product_id, $qty, $cacheKey){
            $product = Cache::get($cacheKey);
            
            if ($product === null) {
                $product = Product::find($product_id);
            }
            
            if ($product === null) {
                return response()->json(['error' => 'Product not found'], 404);
            }

            if ($product->stock < $qty) {
                return response()->json(['error' => 'Out of stock'], 409);
            }

            $product->stock -= $qty;
            $product->save();
            
            Cache::forget($cacheKey);
            Cache::put($cacheKey, $product, self::CACHE_TTL);

            $hold = Hold::create([
                'product_id' => $product_id,
                'qty' => $qty,
                'expire_at' => now()->addMinutes(2)
            ]);

            return response()->json(['data' => ["hold" => $hold->id, "expire_at" => $hold->expire_at]], 201);
        });
    }
}
