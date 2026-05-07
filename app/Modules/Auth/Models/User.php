<?php

namespace App\Modules\Auth\Models;

use App\Modules\Anamnesis\Models\Anamnesis;
use App\Modules\Auth\Notifications\ResetPasswordNotification;
use App\Modules\Chat\Models\Chat;
use Illuminate\Auth\MustVerifyEmail;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Activitylog\Contracts\Activity;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements MustVerifyEmailContract, CanResetPasswordContract
{
    use CanResetPassword, HasApiTokens, HasFactory, HasRoles, LogsActivity, MustVerifyEmail, Notifiable;

    protected $guard_name = 'web';

    protected $fillable = [
        'name',
        'email',
        'password',
        'sex',
        'age',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'email_hash',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'age' => 'integer',
            'name' => 'encrypted',
            'sex' => 'encrypted',
            'email' => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $user) {
            if ($user->isDirty('email')) {
                $user->email_hash = static::hashEmail($user->email);
            }
        });
    }

    public static function hashEmail(string $email): string
    {
        $key = config('app.key');
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7));
        }
        return hash_hmac('sha256', mb_strtolower(trim($email)), $key);
    }

    public static function byEmail(string $email): ?self
    {
        return static::where('email_hash', static::hashEmail($email))->first();
    }

    public function chats()
    {
        return $this->hasMany(Chat::class);
    }

    public function anamneses()
    {
        return $this->hasMany(Anamnesis::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'sex', 'age'])
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
