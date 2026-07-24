<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Modules\Branches\Models\Branch;
use App\Support\AuthSessionCache;
use App\Support\SystemRoles;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use OwenIt\Auditing\Auditable as AuditableConcern;
use OwenIt\Auditing\Contracts\Auditable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements Auditable
{
    /** @use HasFactory<UserFactory> */
    use AuditableConcern, HasFactory, HasRoles, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'branch_id',
        'name',
        'email',
        'is_active',
        'password',
        'force_password_change',
        'last_login_at',
        'last_login_ip',
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
            'is_active' => 'boolean',
            'force_password_change' => 'boolean',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function accessibleBranches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class)
            ->withTimestamps()
            ->orderBy('name');
    }

    public function isSuperAdministrator(): bool
    {
        return AuthSessionCache::isSuperAdministrator($this);
    }

    /**
     * Devuelve la sucursal principal y las sucursales adicionales configuradas.
     *
     * @return array<int>
     */
    public function accessibleBranchIds(): array
    {
        return AuthSessionCache::accessibleBranchIdsFor($this);
    }

    public function scopeWithoutSystemSuperadmins(Builder $query): Builder
    {
        return $query->whereDoesntHave('roles', fn (Builder $roleQuery) => $roleQuery
            ->whereIn('name', SystemRoles::reserved()));
    }
}
