<?php

namespace App\Http\Controllers\Library;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\Library\LibraryRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Lending rules per reader type and the library's general settings. */
class SettingsController extends Controller
{
    public function index(LibraryRules $rules): Response
    {
        return Inertia::render('Library/Manage/Settings', [
            'rules' => $rules->all(),
            'holidays' => \App\Models\Library\Holiday::where('date', '>=', now()->subMonths(1)->toDateString())->orderBy('date')->get(['id', 'date', 'name']),
        ]);
    }

    public function addHoliday(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'name' => ['nullable', 'string', 'max:255'],
        ]);
        $day = \Illuminate\Support\Carbon::parse($data['from']);
        $end = \Illuminate\Support\Carbon::parse($data['to'] ?? $data['from']);
        for ($i = 0; $day->lte($end) && $i < 120; $i++, $day = $day->addDay()) {
            \App\Models\Library\Holiday::updateOrCreate(['date' => $day->toDateString()], ['name' => $data['name'] ?? null]);
        }

        return back()->with('success', 'تمت إضافة العطلة.');
    }

    public function removeHoliday(\App\Models\Library\Holiday $holiday): RedirectResponse
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
}
