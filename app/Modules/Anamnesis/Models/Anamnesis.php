<?php

namespace App\Modules\Anamnesis\Models;

use App\Modules\Auth\Models\User;
use App\Modules\Chat\Models\Chat;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Contracts\Activity;
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
        'health_context',
        'generated_at',
    ];

    protected $casts = [
        // Anamnesis text fields and the frozen health snapshot are
        // medical PII and never queried by content, so they encrypt
        // at rest. Reads/writes are transparent to controllers.
        'chief_complaint' => 'encrypted',
        'history_present_illness' => 'encrypted',
        'past_medical_history' => 'encrypted',
        'family_history' => 'encrypted',
        'social_history' => 'encrypted',
        'allergies' => 'encrypted',
        'medications' => 'encrypted',
        'review_of_systems' => 'encrypted',
        'health_context' => 'encrypted:array',
        'ai_raw_response' => 'encrypted:array',
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

    public function tapActivity(Activity $activity, string $eventName): void
    {
        $props = collect($activity->properties);
        $changed = array_keys((array) $props->get('attributes', []));
        $activity->properties = collect(['changed' => $changed]);
    }
}
