<?php

namespace App\Models;

use Database\Factories\PriorityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'slug', 'sort_order'])]
class Priority extends Model
{
    /** @use HasFactory<PriorityFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'priorities';

    protected $attributes = ['is_active' => true];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'deleted_at' => 'immutable_datetime',
            'sort_order' => 'integer',
        ];
    }

    /** @param Builder<static> $query */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where($this->qualifyColumn('is_active'), true);
    }

    /** @return HasMany<Ticket, $this> */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'priority_id');
    }

    /** @return HasMany<SlaPolicyTarget, $this> */
    public function slaTargets(): HasMany
    {
        return $this->hasMany(SlaPolicyTarget::class, 'priority_id');
    }
}
