<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Webhook extends Model
{
    protected $fillable = [
        'idempotency_key',
        'payload',
        'attempts',
        'status',
    ];

    protected $casts = [
        'payload' => 'array',
    ];
}
