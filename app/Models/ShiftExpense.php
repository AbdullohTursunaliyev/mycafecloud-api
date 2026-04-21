<?php

// app/Models/ShiftExpense.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShiftExpense extends Model
{
    protected $fillable = [
        'tenant_id',
        'shift_id',
        'operator_id',
        'amount',
        'category',
        'title',
        'note',
        'spent_at',
    ];

    protected $casts = [
        'spent_at' => 'datetime',
    ];

    public function operator()
    {
        return $this->belongsTo(Operator::class, 'operator_id');
    }

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }
}

