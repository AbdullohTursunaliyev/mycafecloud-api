<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClientTransaction extends Model
{
    protected $fillable = [
        'tenant_id','client_id','operator_id','shift_id',
        'type','amount','bonus_amount','payment_method','comment', 'promotion_id'
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}

