<?php

namespace App\Modules\Health\Models;

use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HealthMetric extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'type',
        'value',
        'unit',
        'source',
        'recorded_at',
        'metadata',
    ];

    protected $casts = [
        'value' => 'float',
        'recorded_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
