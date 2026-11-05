<?php

namespace App\Http\Controllers\Offline;

use App\Http\Controllers\Controller;
use App\Support\OfflineLicensing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;

/**
 * The offline server's activation page: the university's FileNest code from the
 * licensing portal is sent once; the signed licence is kept in storage/app.
 */
class ActivationController extends Controller
{
    public function show(): View|RedirectResponse
    {
        abort_unless(OfflineLicensing::required(), 404);
        if (OfflineLicensing::current()) {
            return redirect('/login');
        }

        return view('activate', [
            'machineId' => OfflineLicensing::machineId(),
            'machineName' => OfflineLicensing::machineName(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(OfflineLicensing::required(), 404);
        $request->validate(['code' => ['required', 'string', 'max:40']]);

        try {
            $response = Http::timeout(30)->acceptJson()->post(config('offline.license_server').'/api/v1/offline/activate', [
                'code' => $request->input('code'),
                'product' => config('offline.product'),
                'machine_id' => OfflineLicensing::machineId(),
                'machine_name' => OfflineLicensing::machineName(),
                'build' => OfflineLicensing::installedBuild(),
            ]);
        } catch (\Throwable $e) {
            report($e);

            return back()->withInput()->withErrors(['code' => 'تعذّر الاتصال بخادم التراخيص. التفعيل يحتاج اتصال إنترنت مرة واحدة فقط، تأكد من الاتصال وحاول مرة أخرى.']);
        }

        if (! $response->successful()) {
            return back()->withInput()->withErrors(['code' => $response->json('message') ?: 'تعذّر التفعيل، حاول مرة أخرى.']);
        }

        $license = $response->json('license');
        $data = OfflineLicensing::verify(is_array($license) ? $license : null);
        if (! $data || ($data['machine_id'] ?? null) !== OfflineLicensing::machineId() || ($data['product'] ?? null) !== config('offline.product')) {
            return back()->withInput()->withErrors(['code' => 'ردّ الخادم غير صالح، حاول مرة أخرى.']);
        }

        OfflineLicensing::save($license);

        return redirect('/login')->with('status', 'تم تفعيل النظام لـ '.$data['university'].'.');
    }
}
