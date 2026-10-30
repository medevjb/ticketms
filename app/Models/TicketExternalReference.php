<?php

namespace App\Models;

use Database\Factories\TicketExternalReferenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketExternalReference extends Model
{
    /** @use HasFactory<TicketExternalReferenceFactory> */
    use HasFactory;

    protected $guarded = ['*'];

    protected $table = 'ticket_external_references';

    public $timestamps = false;

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id')->withTrashed();
    }

    /** @return BelongsTo<ImportRun, $this> */
    public function importRun(): BelongsTo
    {
        return $this->belongsTo(ImportRun::class, 'import_run_id')->withTrashed();
    }
}
