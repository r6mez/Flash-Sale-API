<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessOrderCreation;
use App\Models\Order;
use App\Models\Product;
use App\Models\Webhook;
use App\Services\RedisStockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OrderController extends Controller
{
    public function __construct(private RedisStockService $redisStock) {}

    function create(Request $request)
    {
        $request->validate([
            'product_id' => 'required|integer',
            'qty' => 'required|integer|min:1|max:10',
        ]);

        $productId = $request->input('product_id');
        $qty = $request->input('qty');

        if (!$this->redisStock->productExists($productId)) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        $secured = $this->redisStock->decrementStock($productId, $qty);

        if (!$secured) {
            return response()->json(['message' => 'Insufficient stock'], 409);
        }

        $reference = Str::uuid()->toString();

        ProcessOrderCreation::dispatch($productId, $qty, $reference)->onQueue('orders');

        return response()->json([
            'message' => 'Reservation Successful! Order is being processed.',
            'order_reference' => $reference
        ], 202);
    }
}
