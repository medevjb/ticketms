<?php

namespace App\Models;

use Database\Factories\TicketSequenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TicketSequence extends Model
{
    /** @use HasFactory<TicketSequenceFactory> */
    use HasFactory;

    protected $guarded = ['*'];

    protected $table = 'ticket_sequences';

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'year';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'last_number' => 'integer',
        ];
    }
}
