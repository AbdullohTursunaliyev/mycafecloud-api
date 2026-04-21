<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClubReview extends Model
{
    protected $fillable = [
        'tenant_id',
        'client_id',
        'rating',
        'atmosphere_rating',
        'cleanliness_rating',
        'technical_rating',
        'peripherals_rating',
        'comment',
    ];

    protected $casts = [
        'rating' => 'integer',
        'atmosphere_rating' => 'integer',
        'cleanliness_rating' => 'integer',
        'technical_rating' => 'integer',
        'peripherals_rating' => 'integer',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
