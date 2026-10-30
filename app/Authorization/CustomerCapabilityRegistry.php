<?php

namespace App\Authorization;

class CustomerCapabilityRegistry
{
    /** @var list<string> */
    private const CAPABILITIES = [
        'tickets.create', 'tickets.view', 'tickets.search', 'tickets.update_content',
        'tickets.close', 'tickets.reopen', 'tickets.reply_public', 'tickets.attach',
        'tickets.audit_view', 'notifications.view', 'notifications.mark_read',
    ];

    /** @return list<string> */
    public function all(): array
    {
        return self::CAPABILITIES;
    }

    public function allows(string $capability): bool
    {
        return in_array($capability, self::CAPABILITIES, true);
    }
}
