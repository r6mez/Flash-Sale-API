<?php

namespace App\Http\Controllers;

use App\Models\Hold;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
                'qty' => $hold->qty,
                'amount_cents' => $product->price * $hold->qty,
                'status' => 'pending',
            ]);

            $hold->delete();

            return $order;
        });

        if ($order === null) {
            return response()->json(['error' => 'Hold expired'], 410);
        }

        return response()->json(['data' => $order], 201);
    }
}
