<?php

namespace App\Http\Controllers\Library;

use App\Http\Controllers\Controller;
use App\Models\Library\Holiday;
use App\Models\Setting;
use App\Services\Library\LibraryRules;
use App\Services\Library\Marc\SruClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/** Lending rules per reader type and the library's general settings. */
class SettingsController extends Controller
{
    public function index(LibraryRules $rules): Response
    {
        return Inertia::render('Library/Manage/Settings', [
            'rules' => $rules->all(),
            'holidays' => Holiday::where('date', '>=', now()->subMonths(1)->toDateString())->orderBy('date')->get(['id', 'date', 'name']),
            'sruServers' => SruClient::servers(),
            'sruDefaults' => SruClient::DEFAULT_SERVERS,
        ]);
    }

    public function addHoliday(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'name' => ['nullable', 'string', 'max:255'],
        ]);
        $day = Carbon::parse($data['from']);
        $end = Carbon::parse($data['to'] ?? $data['from']);
        for ($i = 0; $day->lte($end) && $i < 120; $i++, $day = $day->addDay()) {
            Holiday::updateOrCreate(['date' => $day->toDateString()], ['name' => $data['name'] ?? null]);
        }

        return back()->with('success', 'تمت إضافة العطلة.');
    }

    public function removeHoliday(Holiday $holiday): RedirectResponse
    {
        $holiday->delete();

        return back()->with('success', 'تم حذف العطلة.');
    }

    public function update(Request $request): RedirectResponse
    {
        $rules = [];
        foreach (array_keys(LibraryRules::DEFAULTS) as $type) {
            foreach (array_keys(LibraryRules::DEFAULTS['student']) as $key) {
                $rules["types.{$type}.{$key}"] = ['required', 'integer', 'min:0', 'max:100000'];
            }
        }
        foreach (array_keys(LibraryRules::GENERAL) as $key) {
            $rules["general.{$key}"] = ['required', 'integer', 'min:0', 'max:100000'];
        }
        $data = $request->validate($rules);

        $values = [];
        foreach ($data['types'] as $type => $set) {
            foreach ($set as $key => $v) {
                $values["library_{$type}_{$key}"] = (string) $v;
            }
        }
        foreach ($data['general'] as $key => $v) {
            $values["library_{$key}"] = (string) $v;
        }
        Setting::setMany($values);

        return back()->with('success', 'تم حفظ قواعد الإعارة.');
    }

    /** The world catalogues searched when cataloguing a book (SRU / Z39.50), 2026-11-14. */
    public function saveSruServers(Request $request): RedirectResponse
    {
        $index = ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9._-]+$/'];
        $data = $request->validate([
            'servers' => ['present', 'array', 'max:15'],
            'servers.*.name' => ['required', 'string', 'max:80'],
            'servers.*.url' => ['required', 'url:http,https', 'max:300'],
            'servers.*.isbn' => $index,
            'servers.*.title' => $index,
            'servers.*.author' => $index,
            'servers.*.schema' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9._-]+$/'],
            'servers.*.on' => ['boolean'],
        ]);
        $servers = array_map(fn ($s) => [...$s, 'on' => (bool) ($s['on'] ?? true)], $data['servers']);
        Setting::set('library_sru_servers', $servers ? json_encode(array_values($servers), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null);

        return back()->with('success', 'تم حفظ الفهارس العالمية.');
    }
}
