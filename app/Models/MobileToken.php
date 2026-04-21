<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MobileToken extends Model
{
    protected $connection = 'pgsql';
    protected $table = 'mobile_tokens';

    protected $fillable = [
        'mobile_user_id',
        'token_hash',
        'expires_at',
        'last_used_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'last_used_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(MobileUser::class, 'mobile_user_id');
    }
}
