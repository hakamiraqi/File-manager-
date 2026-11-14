cat > /tmp/t.php <<'PHP'
<?php
$u = App\Models\User::where('email', 'brandcheck@filenest.test')->first();
if (! $u) { echo "NO USER\n"; return; }
foreach (App\Models\File::where('user_id', $u->id)->get() as $f) {
  $disk = $f->disk(); clearstatcache();
  echo "FILE #{$f->id} size_db={$f->size} size_disk=".(@filesize($disk->path($f->path)) ?: 0)." updated={$f->updated_at}\n";
  $zip = new ZipArchive; if ($zip->open($disk->path($f->path)) === true) { $x = (string) $zip->getFromName('word/document.xml'); echo "TEXT SAVED: ".(str_contains($x, 'save test 12345') ? 'yes' : 'no')."\n"; $zip->close(); }
  try { $out = app(App\Services\Office\OnlyOffice::class)->convert($f, 'pdf', 120); echo "CONVERT PDF: ".($out && is_file($out) ? filesize($out).' bytes '.substr((string) file_get_contents($out), 0, 5) : 'failed')."\n"; if ($out) @unlink($out); } catch (Throwable $e) { echo "CONVERT ERR ".$e->getMessage()."\n"; }
  @unlink($disk->path($f->path)); $f->forceDelete();
}
DB::table('activity_logs')->where('user_id', $u->id)->delete();
$u->forceDelete();
echo "CLEANED users=".App\Models\User::where('email', 'brandcheck@filenest.test')->count()."\n";
PHP
docker cp /tmp/t.php filenest-app:/tmp/t.php
docker exec -u www-data -w /var/www/html filenest-app php -r "require 'vendor/autoload.php'; \$app = require 'bootstrap/app.php'; \$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); require '/tmp/t.php';" 2>&1 | cut -c1-400
rm -f /tmp/t.php; docker exec filenest-app rm -f /tmp/t.php
docker ps -a --format '{{.Names}} {{.Status}}' | grep -i office; docker volume ls -q | grep -i office
docker stats --no-stream --format '{{.Name}} {{.MemUsage}}' filenest-office
