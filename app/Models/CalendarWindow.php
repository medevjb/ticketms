<?php

namespace App\Models;

use Database\Factories\CalendarWindowFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['weekday', 'opens_at', 'closes_at'])]
class CalendarWindow extends Model
{
    /** @use HasFactory<CalendarWindowFactory> */
    use HasFactory;

    protected $table = 'calendar_windows';

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
        ];
    }

    /** @return BelongsTo<CalendarVersion, $this> */
    public function calendarVersion(): BelongsTo
    {
        return $this->belongsTo(CalendarVersion::class, 'calendar_version_id')->withTrashed();
    }
}
