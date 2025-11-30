<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ProductController extends Controller
{
    function show(int $id){
        return Cache::remember("product:{$id}", 60, function () use ($id) {
            return Product::findOr($id, function () {
                abort(404, 'Product not found');
            });
        });
    }
}
