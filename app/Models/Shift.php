<?php

// app/Models/Shift.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Shift extends Model
{
    protected $fillable = [
        'tenant_id',
        'opened_by_operator_id','closed_by_operator_id',
        'opened_at','closed_at',
        'opening_cash','closing_cash',
        'topups_cash_total','topups_card_total',
        'packages_cash_total','packages_card_total',
        'returns_total',
        'diff_overage','diff_shortage',
        'adjustments_total',
        'status','meta'
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'meta' => 'array',
    ];

    public function transactions()
    {
        return $this->hasMany(ClientTransaction::class, 'shift_id');
    }


    public function openedBy()
    {
        return $this->belongsTo(Operator::class, 'opened_by_operator_id');
    }

    public function closedBy()
    {
        return $this->belongsTo(Operator::class, 'closed_by_operator_id');
    }

    public function expenses()
    {
        return $this->hasMany(\App\Models\ShiftExpense::class);
    }

}
