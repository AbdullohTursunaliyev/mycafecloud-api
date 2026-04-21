<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClientIdentity extends Model
{
    protected $fillable = ['login','password'];

    protected $hidden = ['password'];

    public function memberships()
    {
        return $this->hasMany(ClientMembership::class, 'identity_id');
    }
}

