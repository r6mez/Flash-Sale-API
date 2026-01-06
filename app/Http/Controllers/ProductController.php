<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Support\Facades\Cache;

class ProductController extends Controller
{
    function show(int $id)
    {
        $product = Cache::remember("product:{$id}", 3600, function () use ($id) {
            return Product::find($id);
        });

        if (!$product) {
            return response()->json(['message' => 'Product Not found'], 404);
        }

        return $product;
    }
}
