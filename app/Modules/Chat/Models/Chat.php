<?php

namespace App\Modules\Chat\Models;

use App\Modules\Anamnesis\Models\Anamnesis;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Chat extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'user_id',
        'title',
        'conversation_id',
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

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['title', 'user_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
