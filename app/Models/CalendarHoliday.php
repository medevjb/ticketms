<?php

namespace App\Models;

use Database\Factories\CalendarHolidayFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['holiday_date', 'name'])]
class CalendarHoliday extends Model
{
    /** @use HasFactory<CalendarHolidayFactory> */
    use HasFactory;

    protected $table = 'calendar_holidays';

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'holiday_date' => 'immutable_date',
        ];
    }

    /** @return BelongsTo<CalendarVersion, $this> */
    public function calendarVersion(): BelongsTo
    {
        return $this->belongsTo(CalendarVersion::class, 'calendar_version_id')->withTrashed();
    }
}
