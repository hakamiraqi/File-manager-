<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\TranslationOverride;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class TranslationController extends Controller
{
    public function index(Request $request): Response
    {
        $locale = in_array($request->string('locale')->toString(), array_keys(config('filenest.supported_locales')), true)
            ? $request->string('locale')->toString()
            : 'ar';

        $base = $this->baseStrings($locale);
        $overrides = TranslationOverride::forLocale($locale);

        $rows = collect($base)->keys()->merge(collect($overrides)->keys())->unique()->sort()->values()
            ->map(fn ($key) => [
                'key' => $key,
                'base' => $base[$key] ?? '',
                'value' => $overrides[$key] ?? $base[$key] ?? '',
                'overridden' => array_key_exists($key, $overrides),
            ]);

        return Inertia::render('Admin/Translations', [
            'locale' => $locale,
            'locales' => config('filenest.supported_locales'),
            'rows' => $rows,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'locale' => ['required', 'string', 'max:10'],
            'key' => ['required', 'string', 'max:190'],
            'value' => ['required', 'string', 'max:1000'],
        ]);

        TranslationOverride::updateOrCreate(
            ['locale' => $data['locale'], 'key' => $data['key']],
            ['value' => $data['value']],
        );

        ActivityLog::record('admin.translation_updated', "حدّث نص الترجمة \"{$data['key']}\" ({$data['locale']})");

        return back()->with('success', 'تم حفظ الترجمة.');
    }

    public function reset(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'locale' => ['required', 'string', 'max:10'],
            'key' => ['required', 'string', 'max:190'],
        ]);

        TranslationOverride::where('locale', $data['locale'])->where('key', $data['key'])->delete();
        Cache::forget("translations.{$data['locale']}");

        return back()->with('success', 'تمت إعادة النص الأصلي.');
    }

    /**
     * @return array<string, string>
     */
    private function baseStrings(string $locale): array
    {
        $path = lang_path("{$locale}.json");

        return is_file($path) ? (json_decode(file_get_contents($path), true) ?: []) : [];
    }
}
