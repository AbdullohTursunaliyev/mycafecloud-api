<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SubscriptionPlan extends Model {
    protected $fillable = [
        'tenant_id','zone_id','name','duration_days','price','is_active'
    ];

    public function zone() { return $this->belongsTo(Zone::class); }
}

