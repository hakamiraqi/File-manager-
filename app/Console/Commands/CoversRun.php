<?php

namespace App\Console\Commands;

use App\Models\File;
use App\Models\Library\Book;
use App\Models\Setting;
use App\Services\Covers\CoverFinder;
use App\Services\Covers\CoverSettings;
use App\Services\Covers\CoverStore;
use App\Services\Covers\LibraryCovers;
use App\Services\Pdf\PdfTools;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * The automatic cover search (2026-11-12): every 10 minutes inside its hours (20:00–06:00 by
 * default), a batch of books not looked at yet. Politely paced; stops at once when the
 * internet cannot be reached (the offline server) without marking any file.
 */
class CoversRun extends Command
{
    protected $signature = 'covers:run {--force : run now, outside the hours} {--limit= : files this run}';

    protected $description = 'Look for book covers online (Open Library, Google Books)';

    public function handle(CoverFinder $finder, PdfTools $pdf, LibraryCovers $library): int
    {
        if (! CoverSettings::on('covers_enabled') && ! $this->option('force')) {
            return self::SUCCESS;
        }
        if (! $this->option('force') && ! CoverSettings::inWindow()) {
            return self::SUCCESS;
        }
        if (! CoverSettings::on('covers_openlibrary') && ! (CoverSettings::on('covers_google') && CoverSettings::googleKey())) {
            $this->record(0, 0, 'لا توجد خدمة مفعّلة.');

            return self::SUCCESS;
        }

        $limit = (int) ($this->option('limit') ?: CoverSettings::int('covers_batch') ?: 40);
        $files = self::candidates()->limit($limit)->get();
        $minPages = CoverSettings::int('covers_min_pages');
        $done = 0;
        $found = 0;
        $error = null;
        foreach ($files as $file) {
            if (! $this->option('force') && ! CoverSettings::inWindow()) {
                break;
            }
            try {
                if ($minPages > 0 && strtolower((string) $file->extension) === 'pdf' && $pdf->qpdf()) {
                    $pages = (int) ($file->ocr_pages ?: 0) ?: $pdf->pageCount($file->disk()->path($file->path));
                    if ($pages > 0 && $pages < $minPages) {
                        $file->forceFill(['cover_status' => 'small', 'cover_checked_at' => now()])->save();
                        $done++;

                        continue;
                    }
                }
                $hit = $finder->findFor($file);
                $jpeg = $hit ? $finder->download($hit['url']) : null;
                if ($jpeg) {
                    CoverStore::apply($file, $jpeg, $hit['source'], 'found', $hit['isbn'] ?? null);
                    $found++;
                    $this->line("#{$file->id} {$file->name}: {$hit['how']} → {$hit['title']}");
                } else {
                    $file->forceFill(['cover_status' => 'none', 'cover_checked_at' => now()])->save();
                }
                $done++;
            } catch (Throwable $e) {
                if (CoverFinder::offline($e)) {
                    $error = 'لا يوجد اتصال بالإنترنت.';
                    break;
                }
                $file->forceFill(['cover_status' => 'error', 'cover_checked_at' => now()])->save();
                $done++;
            }
            // Polite to the free services.
            if (! app()->runningUnitTests()) {
                sleep(2);
            }
        }
        [$bDone, $bFound] = CoverSettings::on('covers_library') ? $this->books($library, $limit, $error === null) : [0, 0];
        $this->record($done, $found, $error, $bDone, $bFound);
        $this->info("checked {$done}, found {$found}; shelf books checked {$bDone}, found {$bFound}".($error ? " ({$error})" : ''));

        return self::SUCCESS;
    }

    /**
     * The books on the shelves without a cover: the matching PDF in the drive first (offline
     * too), then online while the internet answers. @return array{0: int, 1: int}
     */
    private function books(LibraryCovers $library, int $limit, bool $online): array
    {
        $done = 0;
        $found = 0;
        foreach (self::bookCandidates()->limit($limit)->get() as $book) {
            if (! $this->option('force') && ! CoverSettings::inWindow()) {
                break;
            }
            try {
                if ($hit = $library->localFor($book)) {
                    $library->apply($book, $hit[1], 'drive');
                    $found++;
                    $done++;
                    $this->line("book #{$book->id} {$book->title}: drive → #{$hit[0]->id}");

                    continue;
                }
                if (! $online) {
                    continue; // looked at again when the internet is back
                }
                $hit = $library->onlineFor($book);
                if ($hit) {
                    $library->apply($book, $hit[0], $hit[1]);
                    $found++;
                    $this->line("book #{$book->id} {$book->title}: {$hit[1]}");
                } else {
                    $book->forceFill(['cover_status' => 'none', 'cover_checked_at' => now()])->save();
                }
                $done++;
            } catch (Throwable $e) {
                if (CoverFinder::offline($e)) {
                    $online = false;

                    continue;
                }
                $book->forceFill(['cover_status' => 'error', 'cover_checked_at' => now()])->save();
                $done++;
            }
            if ($online && ! app()->runningUnitTests()) {
                sleep(2);
            }
        }

        return [$done, $found];
    }

    /** Shelf books with no cover, never looked at first; the not-found ones again after a while. */
    public static function bookCandidates(): Builder
    {
        $retry = now()->subDays(max(1, CoverSettings::int('covers_retry_days')));

        return Book::query()->whereNull('cover_path')
            ->where(fn (Builder $q) => $q->whereNull('cover_status')
                ->orWhere(fn (Builder $w) => $w->whereIn('cover_status', ['none', 'error'])->where('cover_checked_at', '<', $retry)))
            ->orderByRaw('cover_status is not null')
            ->orderBy('id');
    }

    /** Books never looked at, then those not found a while ago (the services keep growing). */
    public static function candidates(): Builder
    {
        $retry = now()->subDays(max(1, CoverSettings::int('covers_retry_days')));

        return File::query()
            ->whereIn('extension', CoverSettings::kinds())
            ->where(fn (Builder $q) => $q->whereNull('cover_status')
                ->orWhere(fn (Builder $w) => $w->whereIn('cover_status', ['none', 'error', 'small'])->where('cover_checked_at', '<', $retry)))
            ->orderByRaw('cover_status is not null')
            ->orderByDesc('content_indexed_at')
            ->orderByDesc('id');
    }

    private function record(int $done, int $found, ?string $error, int $bDone = 0, int $bFound = 0): void
    {
        Setting::set('covers_last_run', json_encode(['at' => now()->toIso8601String(), 'checked' => $done, 'found' => $found,
            'books_checked' => $bDone, 'books_found' => $bFound, 'error' => $error], JSON_UNESCAPED_UNICODE));
    }
}
