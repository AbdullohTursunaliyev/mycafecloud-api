<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'status', 'saas_plan_id'];

    public function licenseKeys(){ return $this->hasMany(\App\Models\LicenseKey::class); }

    public function saasPlan()
    {
        return $this->belongsTo(\App\Models\SaasPlan::class, 'saas_plan_id');
    }

    public function pcs()
    {
        return $this->hasMany(\App\Models\Pc::class);
    }

}
