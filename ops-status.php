<?php
use App\Models\File; use App\Models\Library\Book; use App\Services\Covers\CoverSettings;
echo "GOOGLE ON: ".CoverSettings::get('covers_google')." KEY SET: ".(CoverSettings::googleKey() ? 'yes' : 'no')."\n";
try { $r = Illuminate\Support\Facades\Http::timeout(15)->get('https://www.googleapis.com/books/v1/volumes', ['q' => 'isbn:9780323073585', 'key' => CoverSettings::googleKey()]);
  echo "GOOGLE TEST: HTTP ".$r->status()." items=".count($r->json('items') ?? [])." ".($r->status() != 200 ? substr((string) $r->json('error.message'), 0, 150) : '')."\n"; } catch (Throwable $e) { echo "GOOGLE TEST ERR: ".get_class($e)."\n"; }
echo "LAST: ".App\Models\Setting::where('key','covers_last_run')->value('value')."\n";
echo "FILES: ".json_encode(File::query()->whereNotNull('cover_status')->selectRaw('cover_status s, count(*) c')->groupBy('s')->pluck('c','s'))." google=".File::where('cover_source','google')->count()."\n";
echo "BOOKS: ".json_encode(Book::query()->selectRaw("coalesce(cover_status,'-') s, count(*) c")->groupBy('s')->pluck('c','s'))." google=".Book::where('cover_source','google')->count()." with=".Book::whereNotNull('cover_path')->count()."\n";
echo "JOBS: ".DB::table('jobs')->count()."\n";
