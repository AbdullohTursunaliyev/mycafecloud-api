<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MobileUser extends Model
{
    protected $connection = 'pgsql';

    protected $fillable = [
        'login',
        'password_hash',
        'first_name',
        'last_name',
        'avatar_url',
    ];

    public function tokens()
    {
        return $this->hasMany(MobileToken::class);
    }
}
