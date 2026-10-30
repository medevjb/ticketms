<?php

namespace App\Models;

use App\Authorization\PermissionRegistry;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $avatar_path
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'avatar_path', 'password'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, SoftDeletes;

    protected $attributes = ['is_active' => true];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'immutable_datetime',
            'is_active' => 'boolean',
            'authorization_version' => 'integer',
            'deleted_at' => 'immutable_datetime',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted',
            'two_factor_confirmed_at' => 'immutable_datetime',
            'password' => 'hashed',
        ];
    }

    /** @param Builder<static> $query */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where($this->qualifyColumn('is_active'), true);
    }

    /** @return HasMany<Ticket, $this> */
    public function ownedTickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'customer_id');
    }

    /** @return HasMany<Ticket, $this> */
    public function createdTickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'created_by');
    }

    /** @return HasMany<Ticket, $this> */
    public function assignedTickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'assigned_agent_id');
    }

    /** @return HasOne<AgentProfile, $this> */
    public function agentProfile(): HasOne
    {
        return $this->hasOne(AgentProfile::class);
    }

    /** @return list<string> */
    public function tyroRoleSlugs(): array
    {
        return array_values($this->activeSpatieRoles()
            ->map(static fn (Role $role): string => $role->name)
            ->all());
    }

    /** @return list<string> */
    public function tyroPrivilegeSlugs(): array
    {
        $registry = app(PermissionRegistry::class);

        return array_values($this->activeSpatieRoles()
            ->flatMap(static fn (Role $role): array => $role->permissions
                ->pluck('name')
                ->map(static fn (mixed $name): string => (string) $name)
                ->all())
            ->filter(fn (string $name): bool => $registry->recognizes($name))
            ->unique()
            ->all());
    }

    public function hasPrivilege(string $privilege): bool
    {
        return in_array($privilege, $this->tyroPrivilegeSlugs(), true);
    }

    public function isSuspended(): bool
    {
        return ! $this->is_active || $this->trashed();
    }

    /** @return Collection<int, Role> */
    private function activeSpatieRoles(): Collection
    {
        if (! $this->exists || $this->trashed() || ! $this->is_active) {
            return new Collection;
        }

        return Role::query()
            ->whereIn('id', $this->roles()->select('access_roles.id'))
            ->where('guard_name', 'web')
            ->where('is_active', true)
            ->with('permissions')
            ->get();
    }
}
