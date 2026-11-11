<?php
use App\Jobs\QariTrialRun; use Illuminate\Support\Facades\Cache;
echo 'DONE '.(@file_get_contents('/tmp/qari-done.txt') ?: 'no')."\n";
foreach (Cache::get('qari-trial:list', []) as $id) {
    $t = Cache::get(QariTrialRun::key($id)); if (! $t) continue;
    foreach ($t['results'] as $e => $r) {
        $x = preg_replace('/\s+/u', ' ', (string) ($r['text'] ?? ''));
        echo "RESULT ".mb_substr($t['name'], 0, 30)." $e {$r['state']} sec=".($r['seconds'] ?? '-')." chars=".mb_strlen($x)." arabic=".preg_match_all('/\p{Arabic}/u', $x)." ptok=".($r['prompt_tokens'] ?? '-')." tok=".($r['tokens'] ?? '-').' '.mb_substr($r['error'] ?? '', 0, 100)."\n";
        if (preg_match_all('/\p{Arabic}/u', $x) > 50) echo "SAMPLE $e: ".mb_substr($x, 0, 260)."\n";
    }
}
