<?php

namespace App\Http\Controllers\Library;

use App\Http\Controllers\Controller;
use App\Jobs\KohaSyncNow;
use App\Models\Setting;
use App\Services\Library\Koha\KohaClient;
use App\Services\Library\Koha\KohaError;
use App\Services\Library\Koha\KohaSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * «الربط مع Koha» (الخيار ب): Koha lends, FileNest is the front. Only site admins change it:
 * the API key opens Koha's circulation.
 */
class KohaController extends Controller
{
    public function save(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate([
            'koha_enabled' => ['required', 'boolean'],
            'koha_url' => ['nullable', 'url:http,https', 'max:255'],
            'koha_client_id' => ['nullable', 'string', 'max:255'],
            'koha_client_secret' => ['nullable', 'string', 'max:255'],
            'koha_library' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9_-]*$/'],
            'koha_match' => ['required', 'in:cardnumber,email'],
            'koha_create_patrons' => ['required', 'boolean'],
            'koha_categories' => ['required', 'array'],
            'koha_categories.student' => ['required', 'string', 'max:20'],
            'koha_categories.teacher' => ['required', 'string', 'max:20'],
            'koha_categories.staff' => ['required', 'string', 'max:20'],
            'koha_item_type' => ['nullable', 'string', 'max:20'],
            'koha_push' => ['required', 'boolean'],
        ]);
        if ($data['koha_enabled'] && (blank($data['koha_url']) || blank($data['koha_client_id']) || (blank($data['koha_client_secret']) && ! KohaSettings::secret()))) {
            return back()->withErrors(['koha_url' => 'لتشغيل الربط أدخل عنوان Koha ومفتاح الربط (Client ID و Secret).']);
        }

        foreach (['koha_enabled', 'koha_create_patrons', 'koha_push'] as $k) {
            $data[$k] = $data[$k] ? '1' : '0';
        }
        $data['koha_url'] = rtrim((string) $data['koha_url'], '/');
        $data['koha_categories'] = json_encode($data['koha_categories']);
        if (filled($data['koha_client_secret'] ?? null)) {
            Setting::set('koha_client_secret', Crypt::encryptString($data['koha_client_secret']));
        }
        unset($data['koha_client_secret']);
        Setting::setMany(array_map(fn ($v) => (string) $v, $data));

        return back()->with('success', $data['koha_enabled'] === '1' ? 'تم حفظ الربط مع Koha وتشغيله.' : 'تم حفظ إعدادات Koha (الربط متوقف).');
    }

    /** Signs in with the key, lists Koha's libraries and reads which API routes this Koha has. */
    public function test(Request $request, KohaClient $koha): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        if (KohaSettings::url() === '' || KohaSettings::get('koha_client_id') === '' || ! KohaSettings::secret()) {
            return response()->json(['ok' => false, 'message' => 'احفظ عنوان Koha ومفتاح الربط أولاً.'], 422);
        }
        try {
            $koha->token(true);
            $libraries = collect($koha->get('/libraries', ['_per_page' => 200]))
                ->map(fn ($l) => ['code' => $l['library_id'], 'name' => $l['name']])->values();
            $routes = [];
            try {
                $spec = Http::acceptJson()->timeout(20)->get($koha->base().'/')->json();
                $routes = array_keys((array) ($spec['paths'] ?? []));
            } catch (Throwable) {
            }
            $caps = array_filter(['routes' => $routes ?: null, 'version' => $spec['info']['version'] ?? null, 'checked_at' => now()->toIso8601String()]);
            KohaSettings::setState('koha_caps', $caps);
        } catch (KohaError $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        $missing = $routes ? array_values(array_diff(['/checkouts', '/checkins', '/holds', '/biblios', '/items', '/patrons'], $routes)) : [];

        return response()->json([
            'ok' => true,
            'message' => 'الاتصال بـ Koha يعمل.'.($libraries->isNotEmpty() ? ' عدد المكتبات (الفروع): '.$libraries->count().'.' : ''),
            'libraries' => $libraries,
            'missing' => $missing,
            'caps' => $caps,
        ]);
    }

    /** «زامن الآن»: in the background; the page shows the result when it is done. */
    public function sync(Request $request): JsonResponse
    {
        abort_unless($request->user()->canManageLibrary(), 403);
        abort_unless(KohaSettings::enabled(), 422, 'الربط مع Koha متوقف.');
        KohaSyncNow::dispatch($request->boolean('full'));

        return response()->json(['message' => 'بدأت المزامنة مع Koha. تظهر النتيجة هنا خلال دقائق.']);
    }

    public function status(Request $request): JsonResponse
    {
        abort_unless($request->user()->canManageLibrary(), 403);

        return response()->json(['last_sync' => KohaSettings::state('koha_last_sync'), 'enabled' => KohaSettings::enabled()]);
    }
}
