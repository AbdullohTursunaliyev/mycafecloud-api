<?php

// app/Models/Tariff.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tariff extends Model
{
    protected $fillable = ['tenant_id','name','price_per_hour','zone','is_active'];

    public function tenant() {
        return $this->belongsTo(Tenant::class);
    }
}

