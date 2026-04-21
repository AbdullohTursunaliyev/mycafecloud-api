<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClientTransfer extends Model
{
    protected $table = 'client_transfers';

    protected $fillable = [
        'tenant_id',
        'from_client_id',
        'to_client_id',
        'operator_id',
        'shift_id',
        'amount',
    ];

    public function fromClient()
    {
        return $this->belongsTo(Client::class, 'from_client_id');
    }

    public function toClient()
    {
        return $this->belongsTo(Client::class, 'to_client_id');
    }

    public function operator()
    {
        return $this->belongsTo(Operator::class, 'operator_id');
    }

    public function shift()
    {
        return $this->belongsTo(Shift::class, 'shift_id');
    }
}
