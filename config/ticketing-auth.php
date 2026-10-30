<?php

return [
    'require_email_verification' => env('TICKETING_REQUIRE_EMAIL_VERIFICATION', true),
    'two_factor_enabled' => filter_var(env('TICKETING_TWO_FACTOR_ENABLED', true), FILTER_VALIDATE_BOOL),
    'two_factor_forced_roles' => env('TICKETING_TWO_FACTOR_FORCED_ROLES', 'admin,agent'),
    'two_factor_skip_roles' => env('TICKETING_TWO_FACTOR_SKIP_ROLES', 'customer'),
];
