<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class Operator extends Authenticatable
{
    use HasApiTokens, HasFactory;

    protected $fillable = ['tenant_id','name','login','password','role','is_active'];
    protected $hidden = ['password'];

    public function tenant() {
        return $this->belongsTo(Tenant::class);
    }

    public function openedShifts()
    {
        return $this->hasMany(Shift::class, 'opened_by_operator_id');
    }

    public function closedShifts()
    {
        return $this->hasMany(Shift::class, 'closed_by_operator_id');
    }
}
