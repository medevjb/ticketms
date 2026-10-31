<?php

namespace App\Models;

use Database\Factories\SlaPolicyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'warning_percent'])]
class SlaPolicy extends Model
{
    /** @use HasFactory<SlaPolicyFactory> */
    use HasFactory;

    protected $table = 'sla_policies';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'warning_percent' => 'integer',
        ];
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
