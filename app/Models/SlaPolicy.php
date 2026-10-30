<?php

namespace App\Models;

use App\Enums\PublicationState;
use Database\Factories\SlaPolicyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property PublicationState $publication_state
 */
#[Fillable(['name', 'warning_percent'])]
class SlaPolicy extends Model
{
    /** @use HasFactory<SlaPolicyFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'sla_policies';

    protected $attributes = ['is_active' => true];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'deleted_at' => 'immutable_datetime',
            'version_no' => 'integer',
            'warning_percent' => 'integer',
            'publication_state' => PublicationState::class,
            'published_at' => 'immutable_datetime',
        ];
    }

    /** @param Builder<static> $query */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where($this->qualifyColumn('is_active'), true);
    }

    /** @return BelongsTo<CalendarVersion, $this> */
    public function calendarVersion(): BelongsTo
    {
        return $this->belongsTo(CalendarVersion::class, 'calendar_version_id')->withTrashed();
    }

    /** @return HasMany<SlaPolicyTarget, $this> */
    public function targets(): HasMany
    {
        return $this->hasMany(SlaPolicyTarget::class, 'sla_policy_id');
    }

    /** @return HasMany<Ticket, $this> */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'sla_policy_id');
    }
}
