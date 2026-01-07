<?php

namespace App\Http\Controllers;

use App\Services\RedisStockService;

class ProductController extends Controller
{
    function show(int $id, RedisStockService $stockService)
    {
        $product = $stockService->getProduct($id);

        if (!$product) {
            return response()->json(['message' => 'Product Not found'], 404);
        }

        return $product;
    }
}
