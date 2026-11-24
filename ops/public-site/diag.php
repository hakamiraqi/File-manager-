<?php
// Read-only: why visitors don't see the library map or the theses (2026-11-24).
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Setting; use App\Models\Folder; use App\Support\PublicSite;
foreach (['guest_access_enabled','public_library_enabled','public_theses_enabled','public_files_enabled'] as $k) {
    echo $k, ' = ', var_export(Setting::get($k, null), true), PHP_EOL;
}
echo 'sections = ', json_encode(PublicSite::sections()), PHP_EOL;
echo 'guest perms = ', json_encode(\App\Models\RolePermission::where('role','guest')->get()->toArray()), PHP_EOL;
echo 'shelves = ', \Illuminate\Support\Facades\Schema::hasTable('library_shelves') ? \App\Models\Library\LibraryShelf::count() : 'no table', PHP_EOL;
echo 'books = ', \App\Models\Library\Book::count(), PHP_EOL;
$t = Folder::where('folder_type','thesis')->get(['id','parent_id','name','is_public']);
echo 'thesis folders = ', $t->count(), ', public = ', $t->where('is_public',true)->count(), PHP_EOL;
echo 'visible to guests = ', count(app(App\Services\Theses\ThesisCatalog::class)->visibleIds(null)), PHP_EOL;
foreach ($t->pluck('parent_id')->unique()->filter() as $pid) {
    $p = Folder::withTrashed()->find($pid);
    echo "parent #$pid ", $p?->name, ' public=', var_export($p?->is_public, true), ' deleted=', var_export((bool)$p?->deleted_at, true), ' children=', $t->where('parent_id',$pid)->count(), PHP_EOL;
}
echo 'root public folders = ', Folder::whereNull('parent_id')->get(['id','name','is_public'])->toJson(JSON_UNESCAPED_UNICODE), PHP_EOL;
