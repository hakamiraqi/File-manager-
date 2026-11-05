@php
    $siteName = \App\Models\Setting::get('site_name', config('app.name', 'FileNest'));
    $cards = [
        'android' => ['title' => 'أندرويد', 'sub' => 'ملف APK للهواتف والأجهزة اللوحية', 'icon' => 'android', 'hint' => 'افتح الملف بعد التنزيل واسمح بالتثبيت من هذا المصدر.'],
        'ios' => ['title' => 'آيفون', 'sub' => 'ملف IPA (غير موقّع)', 'icon' => 'ios', 'hint' => 'يُثبَّت عبر Sideloadly أو AltStore باستخدام حساب Apple الخاص بك.'],
        'windows' => ['title' => 'ويندوز', 'sub' => 'برنامج تثبيت لأجهزة الكمبيوتر', 'icon' => 'windows', 'hint' => 'شغّل ملف التثبيت واتبع الخطوات.'],
        'offline_server' => ['title' => 'سيرفر بدون إنترنت', 'sub' => 'نسخة كاملة تعمل على كمبيوتر ويندوز داخل الشبكة المحلية', 'icon' => 'server', 'hint' => 'فك الضغط في مجلد مثل C:\FileNestOffline ثم شغّل Start.bat.'],
    ];
    $svg = [
        'android' => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M17.6 9.48l1.84-3.18a.38.38 0 0 0-.66-.38l-1.86 3.22A11.5 11.5 0 0 0 12 8.08c-1.77 0-3.43.39-4.92 1.06L5.22 5.92a.38.38 0 0 0-.66.38L6.4 9.48A10.8 10.8 0 0 0 1 18h22a10.8 10.8 0 0 0-5.4-8.52zM7 15.25a1.25 1.25 0 1 1 0-2.5 1.25 1.25 0 0 1 0 2.5zm10 0a1.25 1.25 0 1 1 0-2.5 1.25 1.25 0 0 1 0 2.5z"/></svg>',
        'ios' => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M16.37 12.6c-.02-2.3 1.88-3.4 1.97-3.46a4.24 4.24 0 0 0-3.33-1.8c-1.42-.14-2.77.84-3.49.84-.72 0-1.83-.82-3.01-.8a4.47 4.47 0 0 0-3.78 2.29c-1.61 2.8-.41 6.94 1.16 9.21.77 1.11 1.68 2.36 2.88 2.31 1.16-.05 1.59-.75 2.99-.75 1.4 0 1.79.75 3.01.72 1.24-.02 2.03-1.13 2.79-2.24a9.9 9.9 0 0 0 1.26-2.6 4.02 4.02 0 0 1-2.45-3.72zM14.08 5.84A4.03 4.03 0 0 0 15.03 2.9a4.1 4.1 0 0 0-2.65 1.37 3.84 3.84 0 0 0-.97 2.83 3.4 3.4 0 0 0 2.67-1.26z"/></svg>',
        'windows' => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M3 5.5l7.5-1.03v7.03H3V5.5zm0 13l7.5 1.03V12.6H3v5.9zm8.4 1.15L21 21V12.6h-9.6v7.05zM11.4 4.35V11.5H21V3l-9.6 1.35z"/></svg>',
        'server' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="7" rx="2"/><rect x="3" y="13" width="18" height="7" rx="2"/><path d="M7 7.5h.01M7 16.5h.01M11 7.5h6M11 16.5h6"/></svg>',
    ];
    $human = function ($bytes) {
        if (! $bytes) return '';
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = (int) floor(log($bytes, 1024));
        return round($bytes / (1024 ** $i), 1).' '.$units[$i];
    };
@endphp
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>تنزيل التطبيقات - {{ $siteName }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <style>
        :root { --bg: #f4f6fb; --card: #fff; --text: #1f2937; --muted: #6b7280; --accent: #4f46e5; --line: #e5e7eb; }
        @media (prefers-color-scheme: dark) { :root { --bg: #0f172a; --card: #1e293b; --text: #e5e7eb; --muted: #94a3b8; --accent: #818cf8; --line: #334155; } }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Tahoma, "Segoe UI", sans-serif; background: var(--bg); color: var(--text); }
        main { max-width: 820px; margin: 0 auto; padding: 32px 16px 48px; }
        h1 { font-size: 26px; margin: 0 0 6px; }
        p.lead { color: var(--muted); margin: 0 0 24px; }
        .grid { display: grid; gap: 14px; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: 16px; padding: 18px; display: flex; flex-direction: column; gap: 8px; }
        .head { display: flex; align-items: center; gap: 10px; }
        .icon { width: 48px; height: 48px; border-radius: 14px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .icon svg { width: 26px; height: 26px; }
        .icon-android { background: #dcfce7; color: #16a34a; } .icon-ios { background: #e5e7eb; color: #111827; }
        .icon-windows { background: #dbeafe; color: #2563eb; } .icon-server { background: #ede9fe; color: #6d28d9; }
        @media (prefers-color-scheme: dark) { .icon-android { background: #14532d; color: #86efac; } .icon-ios { background: #374151; color: #f9fafb; } .icon-windows { background: #1e3a8a; color: #93c5fd; } .icon-server { background: #3b0764; color: #c4b5fd; } }
        .brand { display: flex; align-items: center; gap: 12px; margin-bottom: 6px; } .brand img { width: 48px; height: 48px; border-radius: 12px; }
        .title { font-weight: bold; font-size: 18px; }
        .sub, .hint, .ver { color: var(--muted); font-size: 13px; }
        a.btn { margin-top: auto; display: block; text-align: center; background: var(--accent); color: #fff; text-decoration: none; padding: 11px; border-radius: 10px; font-weight: bold; }
        .none { color: var(--muted); font-size: 14px; margin-top: auto; }
        .back { display: inline-block; margin-top: 26px; color: var(--accent); }
    </style>
</head>
<body>
<main>
    <div class="brand"><img src="{{ asset('favicon.svg') }}" alt=""><h1>تنزيل {{ $siteName }}</h1></div>
    <p class="lead">التطبيقات تتصل بهذا الموقع مباشرة، ويمكن ربطها بسيرفر محلي (بدون إنترنت) من «إعدادات الخادم» داخل التطبيق.</p>
    <div class="grid">
        @foreach ($cards as $key => $card)
            @php $rel = $releases[$key] ?? null; @endphp
            <div class="card">
                <div class="head"><div class="icon icon-{{ $card['icon'] }}">{!! $svg[$card['icon']] !!}</div><div><div class="title">{{ $card['title'] }}</div><div class="sub">{{ $card['sub'] }}</div></div></div>
                <div class="hint">{{ $card['hint'] }}</div>
                @if ($rel)
                    <div class="ver">الإصدار {{ $rel['version'] }} @if ($rel['size']) · {{ $human($rel['size']) }} @endif</div>
                    <a class="btn" href="{{ $rel['url'] }}" download>تنزيل</a>
                @else
                    <div class="none">غير متوفر حالياً، يجهز قريباً.</div>
                @endif
            </div>
        @endforeach
    </div>
    <a class="back" href="{{ url('/login') }}">← العودة لتسجيل الدخول</a>
</main>
</body>
</html>
