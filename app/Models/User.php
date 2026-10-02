<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A person who signs in to the dashboard. The full name, position and department
 * are printed on the reports they generate.
 *
 *   admin   Administrator: full control (devices, routers, captive portal, RADIUS, logs, settings, system users)
 *   user    User: the dashboard, and the Users page with report downloads
 *   viewer  Viewer: the dashboard only
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ROLES = [
        'admin' => ['label' => 'Administrator', 'level' => 3, 'access' => 'Full control: everything, including settings and system users'],
        'user' => ['label' => 'User', 'level' => 2, 'access' => 'Dashboard, and the Users page with report downloads'],
        'viewer' => ['label' => 'Viewer', 'level' => 1, 'access' => 'Dashboard only'],
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'position',
        'department',
        'contact',
        'role',
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
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /** At least this role (viewer < user < admin). */
    public function hasRole(string $role): bool
    {
        return (self::ROLES[$this->role]['level'] ?? 0) >= (self::ROLES[$role]['level'] ?? PHP_INT_MAX);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function roleLabel(): string
    {
        return self::ROLES[$this->role]['label'] ?? 'No access';
    }

    /** "Juan Dela Cruz, Network Engineer, System & Network Department" for reports. */
    public function signature(): string
    {
        return implode(', ', array_filter([$this->name, $this->position, $this->department]));
    }
}
