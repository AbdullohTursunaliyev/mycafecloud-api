<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PackageSale extends Model
{
    protected $table = 'package_sales';

    protected $fillable = [
        'tenant_id',
        'client_id',
        'package_id',
        'payment_method',
        'shift_id',
        'operator_id',
        'amount',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];
}
