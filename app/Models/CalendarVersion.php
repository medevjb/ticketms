<?php

namespace App\Models;

use App\Enums\PublicationState;
use Database\Factories\CalendarVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property PublicationState $publication_state
 */
#[Fillable(['name', 'timezone'])]
class CalendarVersion extends Model
{
    /** @use HasFactory<CalendarVersionFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'calendar_versions';

    protected $attributes = ['is_active' => true];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'deleted_at' => 'immutable_datetime',
            'version_no' => 'integer',
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

    /** @return HasMany<CalendarWindow, $this> */
    public function windows(): HasMany
    {
        return $this->hasMany(CalendarWindow::class, 'calendar_version_id');
    }

    /** @return HasMany<CalendarHoliday, $this> */
    public function holidays(): HasMany
    {
        return $this->hasMany(CalendarHoliday::class, 'calendar_version_id');
    }

    /** @return HasMany<SlaPolicy, $this> */
    public function slaPolicies(): HasMany
    {
        return $this->hasMany(SlaPolicy::class, 'calendar_version_id');
    }

    /** @return HasMany<Ticket, $this> */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'calendar_version_id');
    }
}
