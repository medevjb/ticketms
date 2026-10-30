<?php

return [
    'processing' => [
        'notification_batch_size' => 100,
        'notification_max_attempts' => 5,
        'notification_lease_seconds' => 85,
        'notification_backoff_seconds' => [10, 60, 300, 900],
        'notification_database_queue' => 'notifications-database',
        'notification_mail_queue' => 'notifications-mail',
        'sla_batch_size' => 200,
        'sla_queue' => 'sla',
    ],
    'imports' => [
        'max_file_bytes' => 100 * 1024 * 1024,
        'max_record_bytes' => 512000,
        'chunk_rows' => 100,
        'queue' => 'imports',
        'stale_after_minutes' => 10,
    ],
    'attachments' => [
        'disk' => env('TICKETING_ATTACHMENTS_DISK', 'local'),
        'max_files' => 5,
        'max_bytes' => 10 * 1024 * 1024,
        'allowed_types' => [
            'pdf' => ['application/pdf'],
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'txt' => ['text/plain'],
            'csv' => ['text/plain', 'text/csv', 'application/vnd.ms-excel'],
        ],
    ],
    'bootstrap_users' => [
        'agent' => [
            'name' => env('TICKETING_AGENT_NAME'),
            'email' => env('TICKETING_AGENT_EMAIL'),
            'password' => env('TICKETING_AGENT_PASSWORD'),
        ],
        'customer' => [
            'name' => env('TICKETING_CUSTOMER_NAME'),
            'email' => env('TICKETING_CUSTOMER_EMAIL'),
            'password' => env('TICKETING_CUSTOMER_PASSWORD'),
        ],
    ],
    'agent_invitation_expire_minutes' => (int) env('TICKETING_AGENT_INVITATION_EXPIRE_MINUTES', 1440),
];
