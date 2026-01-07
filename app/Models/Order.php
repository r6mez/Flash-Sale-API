<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'order_reference',
        'product_id',
        'qty',
        'amount_cents',
        'status',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
