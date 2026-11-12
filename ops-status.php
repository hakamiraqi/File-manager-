<?php
// One-off check of the office editor: the document server fetches a real file through the
// signed link and converts it (no data is changed).
use App\Models\File;
use Illuminate\Support\Facades\Http;
$office = app(App\Services\Office\OnlyOffice::class);
echo 'ENABLED: '.json_encode($office->enabled())."\n";
echo 'HEALTH: '.Http::timeout(20)->get(config('services.onlyoffice.internal_url').'/healthcheck')->body()."\n";
foreach (['docx', 'xlsx', 'pptx'] as $ext) {
    $f = File::where('extension', $ext)->where('size', '<', 5e6)->first();
    if (! $f) { echo "$ext: none\n"; continue; }
    $cfg = $office->config($f, $f->user, 'ar');
    $req = ['async' => false, 'filetype' => $ext, 'key' => 'chk-'.$f->id.'-'.time(), 'outputtype' => 'pdf', 'title' => 'x.'.$ext, 'url' => $cfg['document']['url']];
    $req['token'] = $office->sign($req);
    $r = Http::timeout(120)->acceptJson()->post(config('services.onlyoffice.internal_url').'/ConvertService.ashx', $req);
    $j = $r->json() ?? [];
    $pdf = isset($j['fileUrl']) ? Http::timeout(60)->get($office->internalise($j['fileUrl'])) : null;
    echo "$ext #{$f->id}: HTTP ".$r->status().' end='.json_encode($j['endConvert'] ?? null).' error='.json_encode($j['error'] ?? null).' pdf='.($pdf ? $pdf->status().' '.strlen($pdf->body()).'B '.substr($pdf->body(), 0, 5) : '-')."\n";
}
