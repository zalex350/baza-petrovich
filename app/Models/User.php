<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'organization_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'blocked_at' => 'datetime',
        ];
    }

    /**
     * Заблокированный пользователь не может войти (SRS §10.5, BR-115).
     */
    public function isBlocked(): bool
    {
        return $this->blocked_at !== null;
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isClient(): bool
    {
        return $this->role === UserRole::Client;
    }

    /**
     * Правила для organization_id (BR-111): клиент обязан быть привязан
     * к организации, администратор — нет.
     */
    public static function organizationRules(UserRole|string|null $role): array
    {
        $role = is_string($role) ? UserRole::tryFrom($role) : $role;

        return [
            $role === UserRole::Client ? 'required' : 'nullable',
            'integer',
            'exists:organizations,id',
        ];
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }
}