<?php
// One-off: the first nightly cover runs on the server.
use App\Models\File;
echo 'LAST: '.App\Models\Setting::get('covers_last_run')."\n";
echo 'BY STATUS: '.json_encode(File::whereIn('extension', ['pdf', 'epub'])->selectRaw('cover_status, count(*) n')->groupBy('cover_status')->pluck('n', 'cover_status'))."\n";
foreach (File::whereNotNull('cover_source')->latest('cover_checked_at')->take(15)->get() as $f) {
    echo "COVER #{$f->id} {$f->name} [{$f->cover_source}] isbn={$f->cover_isbn}\n";
}
