<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReturnRecord extends Model
{
    protected $table = 'returns';

    protected $fillable = [
        'tenant_id',
        'client_id',
        'operator_id',
        'shift_id',
        'type',
        'amount',
        'payment_method',
        'source_type',
        'source_id',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function operator()
    {
        return $this->belongsTo(Operator::class);
    }

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }
}

