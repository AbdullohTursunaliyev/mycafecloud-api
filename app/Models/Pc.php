<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pc extends Model
{
    protected $fillable = [
        'tenant_id','code','zone_id','zone','status','ip_address','last_seen_at',
        'pos_x','pos_y','group','sort_order','notes','is_hidden'
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
    ];

    public function tenant() {
        return $this->belongsTo(Tenant::class);
    }

    public function sessions() {
        return $this->hasMany(Session::class);
    }

    public function activeSession()
    {
        return $this->hasOne(Session::class)->where('status', 'active');
    }

    // ✅ IMPORTANT: relationship nomi "zone" bo‘lmasin, chunki pcs.zone string column bilan to‘qnashadi
    public function zoneRel()
    {
        return $this->belongsTo(\App\Models\Zone::class, 'zone_id');
    }

    public function latestHeartbeat()
    {
        // Avoid one-of-many aggregate SQL that causes ambiguous column errors on some joins.
        return $this->hasOne(PcHeartbeat::class, 'pc_id', 'id')
            ->orderByDesc('received_at')
            ->orderByDesc('id');
    }
}
