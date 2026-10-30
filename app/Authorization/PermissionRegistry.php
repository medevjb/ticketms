<?php

namespace App\Authorization;

class PermissionRegistry
{
    /** @var array<string, list<string>> */
    private const ROLE_PERMISSIONS = [
        'admin' => [
            'tickets.create', 'tickets.view', 'tickets.search', 'tickets.update_content',
            'tickets.start', 'tickets.wait', 'tickets.resume', 'tickets.resolve', 'tickets.close', 'tickets.reopen',
            'tickets.assign', 'tickets.change_priority', 'tickets.change_category', 'tickets.claim',
            'tickets.reply_public', 'tickets.attach', 'tickets.note_internal', 'tickets.audit_view',
            'tickets.manage_availability', 'tickets.archive', 'tickets.restore', 'tickets.bulk',
            'messages.moderate', 'attachments.moderate', 'users.manage', 'agents.manage', 'roles.manage',
            'categories.manage', 'priorities.manage', 'sla.manage', 'calendars.manage',
            'imports.manage', 'imports.download_errors', 'analytics.view', 'audit.view_all',
            'operations.view', 'operations.retry', 'notifications.view', 'notifications.mark_read',
        ],
        'agent' => [
            'tickets.create', 'tickets.view', 'tickets.search', 'tickets.start', 'tickets.wait',
            'tickets.resume', 'tickets.resolve', 'tickets.change_priority', 'tickets.change_category',
            'tickets.claim', 'tickets.reply_public', 'tickets.attach', 'tickets.note_internal',
            'tickets.audit_view', 'analytics.view', 'notifications.view', 'notifications.mark_read',
        ],
    ];

    /** @return array<string, list<string>> */
    public function rolePermissions(): array
    {
        return self::ROLE_PERMISSIONS;
    }

    /** @return list<string> */
    public function permissions(): array
    {
        return array_values(array_unique(array_merge(...array_values(self::ROLE_PERMISSIONS))));
    }

    public function recognizes(string $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }
}
