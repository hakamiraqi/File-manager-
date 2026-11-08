<?php

namespace App\Console\Commands;

use App\Services\Library\ReminderService;
use Illuminate\Console\Command;

class LibraryDaily extends Command
{
    protected $signature = 'library:remind';

    protected $description = 'Send the library due-date reminders and the staff summary';

    public function handle(ReminderService $reminders): int
    {
        $sent = $reminders->run();
        $this->info("Reminders: {$sent['before']} soon, {$sent['today']} today, {$sent['late']} late.");

        return self::SUCCESS;
    }
}
