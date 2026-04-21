<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LicenseKey extends Model
{
    use HasFactory;

    protected $fillable = ['tenant_id','key','status','expires_at','last_used_at'];
    protected $casts = ['expires_at' => 'datetime', 'last_used_at' => 'datetime'];

    public function tenant() {
        return $this->belongsTo(Tenant::class);
    }
    public function scopeActive($query) {
        return $query->where('status', 'active');
    }

}
