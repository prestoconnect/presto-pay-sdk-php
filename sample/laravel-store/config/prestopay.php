<?php

return [
    'environment' => env('PRESTOPAY_ENV', 'staging'),
    'mid' => env('PRESTOPAY_MID'),
    'mrn' => env('PRESTOPAY_MRN'),
    'private_key_file' => env('PRESTOPAY_PRIVATE_KEY_FILE'),
    'private_key_password' => env('PRESTOPAY_PRIVATE_KEY_PASSWORD'),
    'public_key_file' => env('PRESTOPAY_PUBLIC_KEY_FILE'),

    // Presto POSTs webhooks from its own infrastructure, so this must be a publicly reachable origin --
    // localhost only works behind a tunnel (e.g. ngrok). See sample/README.md.
    'public_base_url' => env('PUBLIC_URL', 'http://localhost:8000'),
];
