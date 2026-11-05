@php
    $siteName = \App\Models\Setting::get('site_name', config('app.name', 'FileNest'));
    $cards = [
        'android' => ['title' => 'أندرويد', 'sub' => 'ملف APK للهواتف والأجهزة اللوحية', 'icon' => '🤖', 'hint' => 'افتح الملف بعد التنزيل واسمح بالتثبيت من هذا المصدر.'],
        'ios' => ['title' => 'آيفون', 'sub' => 'ملف IPA (غير موقّع)', 'icon' => '', 'hint' => 'يُثبَّت عبر Sideloadly أو AltStore باستخدام حساب Apple الخاص بك.'],
        'windows' => ['title' => 'ويندوز', 'sub' => 'برنامج تثبيت لأجهزة الكمبيوتر', 'icon' => '🪟', 'hint' => 'شغّل ملف التثبيت واتبع الخطوات.'],
        'offline_server' => ['title' => 'سيرفر بدون إنترنت', 'sub' => 'نسخة كاملة تعمل على كمبيوتر ويندوز داخل الشبكة المحلية', 'icon' => '🖥️', 'hint' => 'فك الضغط في مجلد مثل C:\FileNestOffline ثم شغّل Start.bat.'],
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
        .icon { font-size: 30px; width: 44px; text-align: center; }
        .title { font-weight: bold; font-size: 18px; }
        .sub, .hint, .ver { color: var(--muted); font-size: 13px; }
        a.btn { margin-top: auto; display: block; text-align: center; background: var(--accent); color: #fff; text-decoration: none; padding: 11px; border-radius: 10px; font-weight: bold; }
        .none { color: var(--muted); font-size: 14px; margin-top: auto; }
        .back { display: inline-block; margin-top: 26px; color: var(--accent); }
    </style>
</head>
<body>
<main>
    <h1>تنزيل {{ $siteName }}</h1>
    <p class="lead">التطبيقات تتصل بهذا الموقع مباشرة، ويمكن ربطها بسيرفر محلي (بدون إنترنت) من «إعدادات الخادم» داخل التطبيق.</p>
    <div class="grid">
        @foreach ($cards as $key => $card)
            @php $rel = $releases[$key] ?? null; @endphp
            <div class="card">
                <div class="head"><div class="icon">{{ $card['icon'] }}</div><div><div class="title">{{ $card['title'] }}</div><div class="sub">{{ $card['sub'] }}</div></div></div>
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
