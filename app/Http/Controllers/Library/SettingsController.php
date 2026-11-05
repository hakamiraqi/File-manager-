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
        return Inertia::render('Library/Manage/Settings', ['rules' => $rules->all()]);
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
