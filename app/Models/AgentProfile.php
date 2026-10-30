<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\AgentProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property CarbonImmutable|null $last_auto_assigned_at
 */
#[Fillable(['auto_assignment_enabled'])]
class AgentProfile extends Model
{
    /** @use HasFactory<AgentProfileFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'agent_profiles';

    protected $attributes = ['is_active' => true];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'deleted_at' => 'immutable_datetime',
            'auto_assignment_enabled' => 'boolean',
            'last_auto_assigned_at' => 'immutable_datetime',
        ];
    }

    /** @param Builder<static> $query */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where($this->qualifyColumn('is_active'), true);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }
}
