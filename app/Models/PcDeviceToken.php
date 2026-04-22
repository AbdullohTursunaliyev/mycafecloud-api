<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PcDeviceToken extends Model
{
    protected $fillable = [
        'tenant_id',
        'pc_id',
        'rotated_from_id',
        'token_hash',
        'expires_at',
        'revoked_at',
        'revocation_reason',
        'last_used_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'last_used_at' => 'datetime',
    ];

    public function rotatedFrom()
    {
        return $this->belongsTo(self::class, 'rotated_from_id');
    }
}
