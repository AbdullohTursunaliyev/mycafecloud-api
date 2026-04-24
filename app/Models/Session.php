<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Session extends Model
{
    protected $fillable = [
        'tenant_id','pc_id','operator_id','user_id','tariff_id', 'last_billed_at',
        'started_at','ended_at','price_total','status', 'client_id', 'client_package_id', 'is_package', 'paused_at'
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at'   => 'datetime',
        'last_billed_at' => 'datetime',
        'paused_at' => 'datetime',
    ];

    public function pc() {
        return $this->belongsTo(Pc::class);
    }
    public function tariff() {
        return $this->belongsTo(Tariff::class);
    }

    public function operator() {
        return $this->belongsTo(Operator::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
    public function clientPackage()
    {
        return $this->belongsTo(ClientPackage::class);
    }

    public function chargeEvents()
    {
        return $this->hasMany(SessionChargeEvent::class);
    }

}

