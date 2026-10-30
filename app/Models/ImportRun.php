<?php

namespace App\Models;

use App\Enums\ImportStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ImportRunFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $source
 * @property int $uploaded_by
 * @property string $disk
 * @property string $path
 * @property ImportStatus $processing_status
 * @property int $processed_rows
 * @property int $created_rows
 * @property int $invalid_rows
 * @property int $duplicate_rows
 * @property int $last_row_number
 * @property int $last_byte_offset
 * @property string|null $failure_code
 * @property bool $is_active
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class ImportRun extends Model
{
    /** @use HasFactory<ImportRunFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $guarded = ['*'];

    protected $table = 'import_runs';

    protected $attributes = ['is_active' => true];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'deleted_at' => 'immutable_datetime',
            'processing_status' => ImportStatus::class,
            'processed_rows' => 'integer',
            'created_rows' => 'integer',
            'invalid_rows' => 'integer',
            'duplicate_rows' => 'integer',
            'last_row_number' => 'integer',
            'last_byte_offset' => 'integer',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    /** @param Builder<static> $query */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where($this->qualifyColumn('is_active'), true);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by')->withTrashed();
    }

    /** @return HasMany<ImportRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class, 'import_run_id');
    }

    /** @return HasMany<TicketExternalReference, $this> */
    public function externalReferences(): HasMany
    {
        return $this->hasMany(TicketExternalReference::class, 'import_run_id');
    }
}
