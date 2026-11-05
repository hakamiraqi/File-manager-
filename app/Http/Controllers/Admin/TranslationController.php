<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Language;
use App\Models\User;
use App\Support\Locales;
use Illuminate\Validation\Rule;
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
        $locales = Locales::all();
        $locale = in_array($request->string('locale')->toString(), array_keys($locales), true)
            ? $request->string('locale')->toString()
            : 'ar';

        // Reference language shown under each text (English when editing Arabic, Arabic otherwise).
        $refLocale = $locale === 'ar' ? 'en' : 'ar';

        $file = $this->baseStrings($locale);
        $ref = $this->baseStrings($refLocale);
        // Many screens pass their Arabic text as a fallback in code (t(key, 'نص')), so those keys
        // are editable too even when lang/ar.json does not list them.
        $code = self::codeFallbacks();
        $overrides = TranslationOverride::forLocale($locale);

        $keys = collect($file)->keys()->merge(array_keys($overrides))->merge(array_keys($code))->unique()->sort()->values();

        $rows = $keys->map(function ($key) use ($file, $ref, $code, $overrides, $locale, $refLocale) {
            $base = $file[$key] ?? ($locale === 'ar' ? ($code[$key] ?? '') : '');
            $reference = $ref[$key] ?? ($refLocale === 'ar' ? ($code[$key] ?? '') : '');

            return [
                'key' => $key,
                'group' => str_contains($key, '.') ? strstr($key, '.', true) : 'other',
                'base' => $base,
                'value' => $overrides[$key] ?? $base,
                'reference' => $reference,
                'overridden' => array_key_exists($key, $overrides),
                'missing' => $base === '' && ! array_key_exists($key, $overrides),
            ];
        });

        return Inertia::render('Admin/Translations', [
            'locale' => $locale,
            'refLocale' => $refLocale,
            'locales' => $locales,
            'rows' => $rows,
            'languages' => \Illuminate\Support\Facades\Schema::hasTable('languages')
                ? Language::orderBy('sort')->orderBy('id')->get()->map(fn (Language $l) => [
                    'id' => $l->id, 'code' => $l->code, 'name' => $l->name, 'dir' => $l->dir, 'flag' => $l->flag,
                    'is_active' => $l->is_active,
                    'builtin' => in_array($l->code, Language::BUILTIN, true),
                    'is_default' => $this->isDefault($l),
                    'users' => User::where('locale', $l->code)->count(),
                ]) : [],
        ]);
    }

    /** Save several texts at once (the sticky save bar). */
    public function bulk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'locale' => ['required', 'string', Rule::in(array_keys(Locales::all()))],
            'items' => ['required', 'array', 'max:500'],
            'items.*.key' => ['required', 'string', 'max:190'],
            'items.*.value' => ['required', 'string', 'max:1000'],
        ]);

        foreach ($data['items'] as $item) {
            TranslationOverride::updateOrCreate(
                ['locale' => $data['locale'], 'key' => $item['key']],
                ['value' => $item['value']],
            );
        }

        ActivityLog::record('admin.translation_updated', 'حدّث '.count($data['items'])." نصاً في الترجمة ({$data['locale']})");

        return back()->with('success', 'تم حفظ '.count($data['items']).' نص.');
    }

    /** Add an interface language. */
    public function storeLanguage(Request $request): RedirectResponse
    {
        $data = $request->validate($this->languageRules() + [
            'code' => ['required', 'string', 'regex:/^[a-z]{2,3}(-[A-Z]{2})?$/', 'max:5', Rule::unique('languages', 'code')],
        ]);

        Language::create($data + ['sort' => (int) Language::max('sort') + 1]);
        ActivityLog::record('admin.translation_updated', "أضاف لغة \"{$data['name']}\" ({$data['code']})");

        return redirect()->route('admin.translations.index', ['locale' => $data['code']])->with('success', 'تمت إضافة اللغة. ابدأ بترجمة النصوص.');
    }

    public function updateLanguage(Request $request, Language $language): RedirectResponse
    {
        $data = $request->validate($this->languageRules());

        if (! $data['is_active'] && $this->isDefault($language)) {
            return back()->withErrors(['is_active' => 'لا يمكن إيقاف اللغة الافتراضية للنظام.']);
        }

        $language->update($data);
        if (! $language->is_active) {
            User::where('locale', $language->code)->update(['locale' => null]);
        }
        ActivityLog::record('admin.translation_updated', "عدّل اللغة \"{$language->name}\" ({$language->code})");

        return back()->with('success', 'تم حفظ اللغة.');
    }

    public function destroyLanguage(Language $language): RedirectResponse
    {
        if (in_array($language->code, Language::BUILTIN, true) || $this->isDefault($language)) {
            return back()->withErrors(['language' => 'لا يمكن حذف هذه اللغة.']);
        }

        User::where('locale', $language->code)->update(['locale' => null]);
        TranslationOverride::where('locale', $language->code)->delete();
        Cache::forget("translations.{$language->code}");
        $language->delete();
        ActivityLog::record('admin.translation_updated', "حذف اللغة \"{$language->name}\" ({$language->code})");

        return redirect()->route('admin.translations.index')->with('success', 'تم حذف اللغة.');
    }

    private function languageRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
            'dir' => ['required', 'in:rtl,ltr'],
            'flag' => ['nullable', 'string', 'max:30', 'regex:/^([a-z]{2}(-[a-z]{2,3})?|icon:[a-z-]+)$/'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    private function isDefault(Language $language): bool
    {
        return \App\Models\Setting::get('default_locale', config('app.locale')) === $language->code;
    }

    /**
     * Keys and Arabic fallback texts written in the Vue code as t('key', 'text').
     *
     * @return array<string, string>
     */
    public static function codeFallbacks(): array
    {
        $dir = resource_path('js');
        if (! is_dir($dir)) {
            return [];
        }

        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $f) {
            if (! in_array($f->getExtension(), ['vue', 'js'], true)) {
                continue;
            }
            preg_match_all("/\\bt\\(\\s*'([a-z0-9_]+(?:\\.[a-z0-9_]+)+)'\\s*,\\s*'((?:[^'\\\\]|\\\\.)*)'/i", (string) file_get_contents($f->getPathname()), $m, PREG_SET_ORDER);
            foreach ($m as [, $key, $text]) {
                $found[$key] ??= stripcslashes($text);
            }
        }

        return $found;
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
