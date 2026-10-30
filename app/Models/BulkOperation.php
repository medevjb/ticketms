<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\BulkOperationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $operation_key
 * @property int $actor_id
 * @property string $module
 * @property string $action
 * @property int $submitted_count
 * @property int $succeeded_count
 * @property int $failed_count
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $completed_at
 * @property array{request_hash?: string, succeeded?: int, failed?: int}|null $summary
 */
class BulkOperation extends Model
{
    /** @use HasFactory<BulkOperationFactory> */
    use HasFactory;

    protected $guarded = ['*'];

    protected $table = 'bulk_operations';

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'submitted_count' => 'integer',
            'succeeded_count' => 'integer',
            'failed_count' => 'integer',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'summary' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id')->withTrashed();
    }

    /** @return HasMany<BulkOperationItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(BulkOperationItem::class, 'bulk_operation_id');
    }
}
