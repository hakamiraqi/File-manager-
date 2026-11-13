<?php
use App\Models\File;
$v = File::where('mime_type','like','video/%');
echo "VIDEOS: ".(clone $v)->count()." WITH Q: ".(clone $v)->whereHas('qualities')->count()." QROWS: ".App\Models\VideoQuality::count()."\n";
echo "SET: enabled=".json_encode(App\Models\Setting::get('video_transcoding_enabled', 'default'))." targets=".App\Models\Setting::get('video_quality_targets','default')." ffmpeg=".(app(App\Services\VideoTranscoder::class)->ffmpegAvailable()?'y':'n')."\n";
foreach ((clone $v)->latest()->limit(8)->get() as $f) { echo "V #{$f->id} ".round($f->size/1048576)."MB q=".$f->qualities()->pluck('height')->implode(',')."\n"; }
echo "QJOBS: ".DB::table('jobs')->where('payload','like','%Transcode%')->count()." FAILED: ".DB::table('failed_jobs')->where('payload','like','%Transcode%')->count()."\n";
