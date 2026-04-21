<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class SuperAdmin extends Authenticatable
{
    use HasApiTokens, HasFactory;

    protected $fillable = ['name','email','password','is_active'];
    protected $hidden = ['password'];
}
