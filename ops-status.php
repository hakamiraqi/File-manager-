<?php
use App\Models\File;
$tz='Asia/Baghdad';
echo "LAST: ".App\Models\Setting::where('key','covers_last_run')->value('value')."\n";
echo "BY STATUS: ".json_encode(File::query()->selectRaw('coalesce(cover_status,\'-\') s, count(*) c')->groupBy('s')->pluck('c','s'))."\n";
foreach (File::whereIn('cover_status',['found','manual'])->orderBy('cover_checked_at')->get() as $f) {
  echo "COVER #{$f->id} | {$f->name} | {$f->cover_source} | ".($f->cover_isbn?:'-')." | ".optional($f->cover_checked_at)->timezone($tz)?->format('H:i')."\n";
}
