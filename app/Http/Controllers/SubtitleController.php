<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\File;
use App\Models\Subtitle;
use App\Services\SrtToVttConverter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response as ResponseFacade;
use Illuminate\Support\Str;

class SubtitleController extends Controller
{
    public function index(Request $request, File $file): JsonResponse
    {
        $this->authorize('view', $file);

        return response()->json([
            'subtitles' => $file->subtitles()->orderBy('label')->get(['id', 'language', 'label']),
        ]);
    }

    public function store(Request $request, File $file, SrtToVttConverter $converter): RedirectResponse
    {
        $this->authorize('update', $file);

        $data = $request->validate([
            'subtitle' => ['required', 'file', 'max:5120', 'extensions:srt,vtt'],
            'language' => ['required', 'string', 'max:10'],
            'label' => ['required', 'string', 'max:100'],
        ]);

        $upload = $data['subtitle'];
        $content = file_get_contents($upload->getRealPath());
        $isSrt = strtolower($upload->getClientOriginalExtension()) === 'srt' || $converter->looksLikeSrt($content);
        $vtt = $isSrt ? $converter->convert($content) : $content;

        $path = "{$file->user_id}/subtitles/".Str::uuid().'.vtt';
        $file->disk()->put($path, $vtt);

        $subtitle = Subtitle::create([
            'file_id' => $file->id,
            'language' => $data['language'],
            'label' => $data['label'],
            'path' => $path,
        ]);

        ActivityLog::record('subtitle.uploaded', "أضاف ترجمة \"{$subtitle->label}\" للملف \"{$file->name}\"", $file);

        return back()->with('success', 'تمت إضافة الترجمة.');
    }

    public function show(Request $request, Subtitle $subtitle)
    {
        $this->authorize('view', $subtitle->file);

        $content = $subtitle->file->disk()->get($subtitle->path);

        return ResponseFacade::make($content, 200, [
            'Content-Type' => 'text/vtt; charset=utf-8',
        ]);
    }

    public function destroy(Request $request, Subtitle $subtitle): RedirectResponse
    {
        $this->authorize('update', $subtitle->file);

        $subtitle->file->disk()->delete($subtitle->path);
        $file = $subtitle->file;
        $label = $subtitle->label;
        $subtitle->delete();

        ActivityLog::record('subtitle.deleted', "حذف ترجمة \"{$label}\" من الملف \"{$file->name}\"", $file);

        return back()->with('success', 'تم حذف الترجمة.');
    }
}
