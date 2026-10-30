<?php

namespace App\Models;

use App\Enums\BulkItemResult;
use Database\Factories\BulkOperationItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $bulk_operation_id
 * @property string $subject_type
 * @property int $subject_id
 * @property BulkItemResult $result
 * @property string|null $error_code
 */
class BulkOperationItem extends Model
{
    /** @use HasFactory<BulkOperationItemFactory> */
    use HasFactory;

    protected $guarded = ['*'];

    protected $table = 'bulk_operation_items';

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expected_version' => 'integer',
            'expected_updated_at' => 'immutable_datetime',
            'result' => BulkItemResult::class,
        ];
    }

    /** @return BelongsTo<BulkOperation, $this> */
    public function bulkOperation(): BelongsTo
    {
        return $this->belongsTo(BulkOperation::class, 'bulk_operation_id');
    }
}
