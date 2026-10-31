<?php

namespace App\Models;

use Database\Factories\SlaPolicyTargetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['priority_id', 'target_seconds'])]
class SlaPolicyTarget extends Model
{
    /** @use HasFactory<SlaPolicyTargetFactory> */
    use HasFactory;

    protected $table = 'sla_policy_targets';

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'target_seconds' => 'integer',
        ];
    }

    /** @return BelongsTo<SlaPolicy, $this> */
    public function slaPolicy(): BelongsTo
    {
        return $this->belongsTo(SlaPolicy::class, 'sla_policy_id');
    }

    /** @return BelongsTo<Priority, $this> */
    public function priority(): BelongsTo
    {
        return $this->belongsTo(Priority::class, 'priority_id')->withTrashed();
    }
}
