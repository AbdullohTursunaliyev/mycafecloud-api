<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MobileFriendship extends Model
{
    protected $fillable = [
        'mobile_user_id',
        'friend_mobile_user_id',
        'requested_by_mobile_user_id',
        'status',
        'accepted_at',
    ];

    protected $casts = [
        'accepted_at' => 'datetime',
    ];
}
