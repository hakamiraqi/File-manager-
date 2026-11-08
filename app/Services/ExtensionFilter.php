<?php

namespace App\Services;

use App\Models\Setting;

/**
 * Admin-editable allow/reject list layered on top of the hard-coded
 * "forbidden_extensions" security floor in config/filenest.php (which is
 * never bypassable through settings, matching VFM keeping php/html/htm
 * always rejected regardless of "selectivext").
 */
class ExtensionFilter
{
    public function isAllowed(string $extension): bool
    {
        $extension = strtolower(ltrim($extension, '.'));
        $mode = Setting::get('extension_filter_mode', 'reject');
        $list = $this->parsedList();

        if ($list === []) {
            return true;
        }

        return $mode === 'allow'
            ? in_array($extension, $list, true)
            : ! in_array($extension, $list, true);
    }

    /**
     * @return array<int, string>
     */
    private function parsedList(): array
    {
        $raw = (string) Setting::get('extension_list', '');

        return array_values(array_filter(array_map(
            fn ($ext) => strtolower(trim($ext, ". \t\n\r\0\x0B")),
            explode(',', $raw)
        )));
    }
}
