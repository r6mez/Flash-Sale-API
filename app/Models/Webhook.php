<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Webhook extends Model
{
    protected $fillable = [
        'order_reference',
        'payload',
        'attempts',
        'status',
    ];

    protected $casts = [
        'payload' => 'array',
    ];
}
