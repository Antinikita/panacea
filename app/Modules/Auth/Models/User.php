<?php

namespace App\Modules\Auth\Models;

use App\Modules\Anamnesis\Models\Anamnesis;
use App\Modules\Chat\Models\Chat;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

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
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'age' => 'integer',
        ];
    }

    public function chats()
    {
        return $this->hasMany(Chat::class);
    }

    public function anamneses()
    {
        return $this->hasMany(Anamnesis::class);
    }
}
