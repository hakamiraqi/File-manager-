<?php
// 2026-11-24, by request: the AWS server started with fresh data, so «تفعيل واجهة الزوار»
// was off and visitors were sent to the login page. Turn the visitors' site on, with the
// library (and its map) and the theses showing. Admins can switch it off in «الأمان».
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

App\Models\Setting::setMany([
    'guest_access_enabled' => '1',
    'public_library_enabled' => '1',
    'public_theses_enabled' => '1',
]);
echo 'enabled: ', json_encode(App\Support\PublicSite::sections()), PHP_EOL;
