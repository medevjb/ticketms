<?php

namespace App\Models;

use App\Enums\ImportRowResult;
use Carbon\CarbonImmutable;
use Database\Factories\ImportRowFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $import_run_id
 * @property int $row_number
 * @property string|null $external_id
 * @property ImportRowResult $result
 * @property int|null $ticket_id
 * @property list<string>|null $error_codes
 * @property CarbonImmutable $processed_at
 */
class ImportRow extends Model
{
    /** @use HasFactory<ImportRowFactory> */
    use HasFactory;

    protected $guarded = ['*'];

    protected $table = 'import_rows';

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'row_number' => 'integer',
            'result' => ImportRowResult::class,
            'error_codes' => 'array',
            'processed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<ImportRun, $this> */
    public function importRun(): BelongsTo
    {
        return $this->belongsTo(ImportRun::class, 'import_run_id')->withTrashed();
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id')->withTrashed();
    }
}
