<?php

namespace App\Models;

use Database\Factories\TicketingSettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketingSetting extends Model
{
    /** @use HasFactory<TicketingSettingFactory> */
    use HasFactory;

    protected $guarded = ['*'];

    protected $table = 'ticketing_settings';

    public $incrementing = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'lock_version' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by')->withTrashed();
    }
}
