<?php

return [

    // Storage disk used to physically store uploaded files.
    'disk' => env('FILENEST_DISK', 'filemanager'),

    // Default per-user storage quota in megabytes. Overridable per-user
    // and per-installation via the admin "General Settings" page.
    'default_quota_mb' => (int) env('FILENEST_DEFAULT_QUOTA_MB', 5120),

    // Maximum size (in megabytes) accepted for a single uploaded file.
    'max_upload_mb' => (int) env('FILENEST_MAX_UPLOAD_MB', 2048),

    // Size (in megabytes) of each chunk sent by the resumable uploader.
    'chunk_size_mb' => (int) env('FILENEST_CHUNK_SIZE_MB', 5),

    // File extensions that can never be uploaded, regardless of settings.
    'forbidden_extensions' => ['php', 'phtml', 'php3', 'php4', 'php5', 'phar', 'exe', 'sh', 'bat', 'cmd'],

    // Number of days a trashed file/folder is kept before permanent purge.
    'trash_retention_days' => (int) env('FILENEST_TRASH_RETENTION_DAYS', 30),

    // Available UI skins. Admin picks one as the active site-wide skin,
    // or provides custom CSS in the "value" for a "custom" skin.
    'skins' => [
        'indigo', 'slate', 'emerald', 'rose', 'amber', 'sky',
        'violet', 'teal', 'orange', 'zinc', 'cyan', 'fuchsia',
    ],

    'supported_locales' => [
        'ar' => ['label' => 'العربية', 'dir' => 'rtl'],
        'en' => ['label' => 'English', 'dir' => 'ltr'],
    ],
];
