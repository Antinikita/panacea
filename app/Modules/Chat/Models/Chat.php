<?php

namespace App\Modules\Chat\Models;

use App\Modules\Anamnesis\Models\Anamnesis;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Chat extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'title',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function messages()
    {
        return $this->hasMany(ChatMessage::class)->orderBy('created_at', 'asc');
    }

    public function anamneses()
    {
        return $this->hasMany(Anamnesis::class);
    }

    public function getRouteKeyName()
    {
        return 'id';
    }
}
