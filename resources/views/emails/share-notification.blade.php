@component('mail::message')
# تمت مشاركة ملف معك

قام **{{ $senderName }}** بمشاركة "{{ $itemName }}" معك عبر {{ config('app.name') }}.

@if($message)
> {{ $message }}
@endif

@component('mail::button', ['url' => $url])
عرض / تنزيل
@endcomponent

@if($expiresAt)
ينتهي هذا الرابط في {{ $expiresAt->translatedFormat('d F Y، H:i') }}.
@endif

شكراً لاستخدامك {{ config('app.name') }}.
@endcomponent
