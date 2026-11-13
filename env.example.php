<?php
// Copy to env.php (mode 640, readable by fbgroups) and fill in.
return [
    'APP_URL' => 'https://facebookgroups.fullstackpartner.com',
    'DB_PATH' => '/var/lib/fbgroups/app.sqlite',
    'ADMIN_EMAIL' => 'ole.oldhoj@gmail.com',
    'ADMIN_PASSWORD_HASH' => '',
    'EXTENSION_TOKEN' => '',
    // Facebook app (developers.facebook.com). Can also be set on the Settings page.
    'FB_APP_ID' => '',
    'FB_APP_SECRET' => '',
    'FB_GRAPH_VERSION' => 'v24.0',
    // Claude API key for the AI review. Can also be set on the Settings page.
    'ANTHROPIC_API_KEY' => '',
    'AI_MODEL' => 'claude-haiku-5-5',
];
