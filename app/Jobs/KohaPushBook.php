<?php

namespace App\Jobs;

use App\Models\Library\Book;
use App\Services\Library\Koha\KohaBridge;
use App\Services\Library\Koha\KohaSettings;
use App\Services\Library\Koha\KohaSync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Validation\ValidationException;

/** A book catalogued or edited in FileNest, sent to Koha (its record and its new copies). */
class KohaPushBook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 10;

    public array $backoff = [60, 300];

    /** Two pushes of one book never run together (each would add the record to Koha). */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('koha-book-'.$this->book->id))->releaseAfter(20)->expireAfter(300)];
    }

    public function __construct(public Book $book, public bool $replace = false) {}

    public function handle(KohaBridge $bridge): void
    {
        if (! KohaSettings::enabled() || ! KohaSettings::push()) {
            return;
        }
        try {
            $bridge->pushBook($this->book->fresh() ?? $this->book, $this->replace);
        } catch (ValidationException $e) {
            report($e);
            $this->fail($e);
        }
    }

    /** After a save in FileNest (not while pulling from Koha). */
    public static function afterSave(Book $book, bool $replace = false): void
    {
        if (KohaSync::$pulling || ! KohaSettings::enabled() || ! KohaSettings::push()) {
            return;
        }
        self::dispatch($book, $replace)->afterCommit();
    }
}
