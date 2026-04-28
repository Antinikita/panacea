<?php

namespace App\Modules\Anamnesis\Models;

use App\Modules\Auth\Models\User;
use App\Modules\Chat\Models\Chat;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Anamnesis extends Model
{
    use HasFactory, LogsActivity;

    protected $table = 'anamneses';

    protected $fillable = [
        'user_id',
        'chat_id',
        'chief_complaint',
        'history_present_illness',
        'past_medical_history',
        'family_history',
        'social_history',
        'allergies',
        'medications',
        'review_of_systems',
        'generated_at',
    ];

    protected $casts = [
        'ai_raw_response' => 'array',
        'generated_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function chat()
    {
        return $this->belongsTo(Chat::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
