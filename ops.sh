docker ps --format '{{.Names}} {{.Image}} {{.Status}}' | grep -E "office"
docker exec filenest-web sh -c 'wget -qO- http://office-editor/healthcheck; echo " <- health"; wget -qO- http://127.0.0.1/office-brand/logo | head -c 200; echo; wget -qS -O /dev/null http://127.0.0.1/office/web-apps/apps/api/documents/api.js 2>&1 | grep -E "HTTP/|Content-Length" | head -3'
cat > /tmp/t.php <<'PHP'
<?php
$u = App\Models\User::firstOrCreate(['email' => 'brandcheck@filenest.test'], ['name' => 'Brand Check', 'password' => bcrypt(Illuminate\Support\Str::random(40)), 'role' => 'user', 'is_active' => true, 'email_verified_at' => now()]);
$disk = Storage::disk(config('filenest.disk'));
$rel = $u->id.'/'.Illuminate\Support\Str::uuid().'.docx';
$disk->makeDirectory((string) $u->id);
copy(resource_path('office-templates/blank.docx'), $disk->path($rel));
$f = App\Models\File::create(['user_id' => $u->id, 'folder_id' => null, 'name' => 'brand-check.docx', 'original_name' => 'brand-check.docx', 'extension' => 'docx', 'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'path' => $rel, 'size' => filesize($disk->path($rel))]);
$link = URL::temporarySignedRoute('app.open', now()->addMinutes(20), ['user' => $u->id, 'page' => 'office', 'ids' => [$f->id]], false);
openssl_public_encrypt($link, $enc, file_get_contents('/tmp/pub.pem'));
echo 'ENC:'.base64_encode($enc)."\n";
try { $pdf = app(App\Services\Pdf\PdfTools::class); echo 'CAPS:'.json_encode($pdf->capabilities())."\n"; } catch (Throwable $e) { echo 'CAPS ERR '.$e->getMessage()."\n"; }
PHP
cat > /tmp/pub.pem <<'KEY'
-----BEGIN PUBLIC KEY-----
MIICIjANBgkqhkiG9w0BAQEFAAOCAg8AMIICCgKCAgEAjj/7fXNtIjKkC37rIjkX
4S5+IC5nUxrthi647QWJXarKCZ/7ePPqSESeoUz+tP7lepoqNUMhqc+VExfLs6kX
wmr/Q0zZhD45O9ft1pQVQ2NCM6x5veoX/YPQ+HUnV8S3NNGfmIBQmbkf7Dnp3A39
cXcbHVmNgUJ7Dz046/phuuxjyTVluDkVb3Hi5mhALqGkmsBVFfRB29rE8a6Aqu3F
xgZguY5ZVADvyrtnZPPdEZY7RAL+TQzIqDO6XLoFmLhzgcsC4DTU6WwsKlAZy8rV
JPiJyrsRWKwk7wkLeo2NGyFZOg/NYw0roYEUwdSQ1MjL1rZKoT87HCwEwqv2y+8z
qANomk4bmJp9r0QyOmhOeFqlvUcOOENXrGQ/pYLXhyRSRGju/TPAMqoFLK4420dg
AVxZ+8oUFnVSzmrHWix0pf+f7GCK4WPhwGU97/zZfRnjJ1ApqnuIL2xHaXgEgL4b
YWdkfbilWgkrD+GeahE9gIhJHpQhJxluWUU1/Qe+FfL//jSwk2Fpp+A2SGIOzWlD
Xy0SVnvQ+UgvE6xEhz4zhX2ylbczOk3XTrsEhRdihuiWuqGEpPZf2hRC2M1PevH4
de5RNYrOxauQsHC9GcIOvpj9CII6rG9wHLUui9M9D92pZ99Ws708+ldyXVGtgZ5e
1Z2JovxIHU3D2xNXHj6LehsCAwEAAQ==
-----END PUBLIC KEY-----
KEY
docker cp /tmp/t.php filenest-app:/tmp/t.php; docker cp /tmp/pub.pem filenest-app:/tmp/pub.pem
docker exec -u www-data -w /var/www/html filenest-app php -r "require 'vendor/autoload.php'; \$app = require 'bootstrap/app.php'; \$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); require '/tmp/t.php';" 2>&1 | cut -c1-900
rm -f /tmp/t.php /tmp/pub.pem
