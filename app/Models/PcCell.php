<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PcCell extends Model
{
    protected $fillable = [
        'tenant_id',
        'row',
        'col',
        'zone_id',
        'pc_id',
        'label',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function pc()
    {
        return $this->belongsTo(Pc::class);
    }

    public function zone()
    {
        return $this->belongsTo(Zone::class);
    }
}
