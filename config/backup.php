<?php

/*
 * Backups of the database and uploaded files (App\Services\DatabaseBackupService).
 * The offline package sets BACKUP_PATH to a folder outside the program folder
 * (C:\FileNestBackups by default, from config.txt's BACKUP_DIR) and turns
 * BACKUP_DAILY on.
 */
return [
    // One zip per backup holding database.sql, and an `uploads` folder beside them mirroring the files.
    'path' => env('BACKUP_PATH') ?: storage_path('app/private/backups'),

    // How many backups to keep; older ones are deleted. 0 keeps all.
    'keep' => (int) env('BACKUP_KEEP', 30),

    'daily' => (bool) env('BACKUP_DAILY', false),
];
