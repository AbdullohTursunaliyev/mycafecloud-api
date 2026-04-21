<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    protected $fillable = [
        'tenant_id','account_id','login','password',
        'balance','bonus','status','expires_at','phone','username','name'
    ];

    protected $hidden = ['password'];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function transactions()
    {
        return $this->hasMany(ClientTransaction::class);
    }
}
