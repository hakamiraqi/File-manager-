<?php

// The offline (one Windows PC) edition, see offline/README.md.
// The package's .env sets OFFLINE_EDITION=true; the hosted site leaves it off.
return [
    'edition' => (bool) env('OFFLINE_EDITION', false),

    // An offline copy works only after it is activated with a code from the
    // licensing portal (the clinic system's portal, shared by all three systems). Off only in the package's smoke test.
    'activation' => (bool) env('OFFLINE_ACTIVATION', true),

    // The system that issues and checks activation codes (the clinic system's
    // hosting runs the licensing portal for both systems).
    'license_server' => rtrim((string) env('OFFLINE_LICENSE_SERVER', 'https://dental.azoclinic.duckdns.org'), '/'),

    // This system's name on the licensing portal.
    'product' => 'exams',
];
