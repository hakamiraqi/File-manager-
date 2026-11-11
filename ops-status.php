<?php
use App\Jobs\QariTrialRun; use Illuminate\Support\Facades\Cache;
foreach (Cache::get('qari-trial:list', []) as $id) {
    $t = Cache::get(QariTrialRun::key($id)); if (! $t) continue;
    foreach ($t['results'] as $e => $r) {
        $x = preg_replace('/\s+/u', ' ', (string) ($r['text'] ?? ''));
        echo "RESULT {$t['name']} p{$t['page']} $e {$r['state']} sec=".($r['seconds'] ?? '-')." chars=".mb_strlen($x)." arabic=".preg_match_all('/\p{Arabic}/u', $x)." ptok=".($r['prompt_tokens'] ?? '-')." tok=".($r['tokens'] ?? '-').' '.($r['error'] ?? '')."\n";
        echo "SAMPLE $e: ".mb_substr($x, 0, 200)."\n";
    }
}
echo 'PS '.trim(shell_exec('ps aux | grep -c ops-status') ?? '')."\n";
