<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Complaint extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',  // add this
        'complaint',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Все рекомендации для этой жалобы
    public function recommendations()
    {
        return $this->hasMany(Recommendation::class);
    }

    // ДОБАВЬ ЭТОТ МЕТОД - последняя рекомендация
    public function latestRecommendation()
    {
        return $this->hasOne(Recommendation::class)->latestOfMany();
    }
}