<?php

namespace App\Models;

use Database\Factories\BusinessCalendarFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'timezone'])]
class BusinessCalendar extends Model
{
    /** @use HasFactory<BusinessCalendarFactory> */
    use HasFactory;

    protected $table = 'business_calendars';

    /** @return HasMany<CalendarWindow, $this> */
    public function windows(): HasMany
    {
        return $this->hasMany(CalendarWindow::class, 'business_calendar_id');
    }

    /** @return HasMany<CalendarHoliday, $this> */
    public function holidays(): HasMany
    {
        return $this->hasMany(CalendarHoliday::class, 'business_calendar_id');
    }

    /** @return HasMany<Ticket, $this> */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'business_calendar_id');
    }
}
