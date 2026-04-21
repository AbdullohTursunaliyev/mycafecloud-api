<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClientSubscription extends Model {
    protected $fillable = [
        'tenant_id','client_id','subscription_plan_id','zone_id',
        'status','starts_at','ends_at','payment_method','shift_id','operator_id',
        'amount','meta'
    ];

    protected $casts = [
        'meta' => 'array',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function client() { return $this->belongsTo(Client::class); }
    public function plan() { return $this->belongsTo(SubscriptionPlan::class,'subscription_plan_id'); }
    public function zone() { return $this->belongsTo(Zone::class); }
}

