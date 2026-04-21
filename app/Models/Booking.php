<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Booking extends Model
{
    protected $fillable = [
        'tenant_id','pc_id','client_id','created_by_operator_id',
        'start_at','end_at','status','note'
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
    ];

    public function pc(): BelongsTo
    {
        return $this->belongsTo(Pc::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Operator::class, 'created_by_operator_id');
    }
}
