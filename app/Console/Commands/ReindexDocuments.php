<?php

namespace App\Console\Commands;

use App\Jobs\ExtractFileText;
use App\Models\File;
use App\Services\DocumentTextExtractor;
use Illuminate\Console\Command;

/**
 * Queues text extraction again for every stored document, e.g. after the extractor improved
 * (LibreOffice added on the server). Runs in the background queue; uploads keep working.
 */
class ReindexDocuments extends Command
{
    protected $signature = 'filenest:reindex-documents';

    protected $description = 'Re-extract the searchable text of all documents (Word, PowerPoint, Excel, PDF, text)';

    public function handle(DocumentTextExtractor $extractor): int
    {
        $count = 0;
        File::query()->whereNull('deleted_at')->select(['id', 'extension'])->chunkById(500, function ($files) use ($extractor, &$count) {
            foreach ($files as $file) {
                if ($extractor->supports((string) $file->extension)) {
                    ExtractFileText::dispatch($file->id);
                    $count++;
                }
            }
        });
        $this->info("Queued {$count} documents.");

        return self::SUCCESS;
    }
}
