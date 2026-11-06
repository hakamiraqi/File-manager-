<?php

namespace App\Http\Controllers;

use App\Mail\EventNotificationMail;
use App\Models\ActivityLog;
use App\Models\ContactMessage;
use App\Models\User;
use App\Services\Notify;
use App\Support\PublicSite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** «تواصل معنا»: visitors and users send a message or a request; staff answer it from the inbox. */
class ContactController extends Controller
{
    public function create(Request $request): Response
    {
        abort_unless(PublicSite::contact(), 404);
        $user = $request->user();

        $about = null;
        if ($request->integer('book')) {
            $book = \App\Models\Library\Book::find($request->integer('book'));
            $about = $book ? ['type' => 'book', 'id' => $book->id, 'title' => $book->title, 'kind' => 'book_request'] : null;
        } elseif ($request->integer('thesis')) {
            $thesis = \App\Models\Folder::where('folder_type', 'thesis')->find($request->integer('thesis'));
            $about = $thesis ? ['type' => 'thesis', 'id' => $thesis->id, 'title' => $thesis->thesis_title ?: $thesis->name, 'kind' => 'thesis_request'] : null;
        }

        return Inertia::render('Contact/Create', [
            'kinds' => ContactMessage::KINDS,
            'about' => $about,
            'mine' => $user ? ContactMessage::where('user_id', $user->id)->latest()->limit(20)->get()
                ->map(fn (ContactMessage $m) => [
                    'id' => $m->id, 'kind' => $m->kind, 'subject' => $m->subject, 'status' => $m->status,
                    'reply' => $m->reply, 'created_at' => $m->created_at?->toIso8601String(),
                ]) : [],
            'statuses' => ContactMessage::STATUSES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(PublicSite::contact(), 404);

        // A field people never see: bots fill it in.
        if (filled($request->input('website'))) {
            return back()->with('success', 'تم إرسال رسالتك.');
        }

        $user = $request->user();
        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(ContactMessage::KINDS))],
            'name' => [$user ? 'nullable' : 'required', 'string', 'max:120'],
            'email' => [$user ? 'nullable' : 'required_without:phone', 'nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'subject' => ['required', 'string', 'max:190'],
            'message' => ['required', 'string', 'max:5000'],
            'about_type' => ['nullable', 'in:book,thesis'],
            'about_id' => ['nullable', 'integer'],
        ], [
            'email.required_without' => 'اكتب بريدك الإلكتروني أو رقم هاتفك حتى نرد عليك.',
        ]);

        $msg = ContactMessage::create([
            ...$data,
            'name' => ($data['name'] ?? null) ?: $user?->name,
            'email' => $data['email'] ?? $user?->email,
            'user_id' => $user?->id,
            'ip_address' => $request->ip(),
        ]);

        $kind = ContactMessage::KINDS[$msg->kind];
        $staff = self::staffFor($msg->kind);
        Notify::to($staff, 'admin', 'contact_message', "{$kind} جديد: {$msg->subject}", "من {$msg->name}", route('contact.inbox', ['open' => $msg->id], false));

        return back()->with('success', "تم إرسال {$kind} برقم #{$msg->id}. سنرد عليك قريباً.");
    }

    public function inbox(Request $request): Response
    {
        $status = $request->query('status', 'open');
        $kind = $request->query('kind');
        $q = trim((string) $request->query('q', ''));

        $base = ContactMessage::query()
            ->when($kind && isset(ContactMessage::KINDS[$kind]), fn ($w) => $w->where('kind', $kind))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('subject', 'like', "%{$q}%")->orWhere('message', 'like', "%{$q}%")
                ->orWhere('name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%")->orWhere('phone', 'like', "%{$q}%")));

        $counts = (clone $base)->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status');

        $items = (clone $base)
            ->when($status === 'open', fn ($w) => $w->where('status', '!=', 'closed'))
            ->when(isset(ContactMessage::STATUSES[$status]), fn ($w) => $w->where('status', $status))
            ->with(['user:id,name,avatar_path', 'handler:id,name'])
            ->orderByRaw("CASE WHEN status = 'new' THEN 0 WHEN status = 'in_progress' THEN 1 ELSE 2 END")
            ->latest()->latest('id')
            ->paginate(20)->withQueryString()
            ->through(fn (ContactMessage $m) => [
                ...$m->only(['id', 'kind', 'name', 'email', 'phone', 'subject', 'message', 'status', 'reply', 'about_type', 'about_id']),
                'about_title' => $m->aboutTitle(),
                'created_at' => $m->created_at?->toIso8601String(),
                'replied_at' => $m->replied_at?->toIso8601String(),
                'user' => $m->user ? ['id' => $m->user->id, 'name' => $m->user->name, 'avatar_url' => $m->user->avatar_url] : null,
                'handler' => $m->handler?->name,
            ]);

        return Inertia::render('Contact/Inbox', [
            'items' => $items,
            'filters' => ['status' => $status, 'kind' => $kind, 'q' => $q, 'open' => $request->integer('open') ?: null],
            'counts' => [
                'new' => (int) ($counts['new'] ?? 0),
                'in_progress' => (int) ($counts['in_progress'] ?? 0),
                'closed' => (int) ($counts['closed'] ?? 0),
                'open' => (int) (($counts['new'] ?? 0) + ($counts['in_progress'] ?? 0)),
                'all' => (int) $counts->sum(),
            ],
            'kinds' => ContactMessage::KINDS,
            'statuses' => ContactMessage::STATUSES,
        ]);
    }

    public function update(Request $request, ContactMessage $message): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(ContactMessage::STATUSES))],
            'reply' => ['nullable', 'string', 'max:5000'],
        ]);

        $newReply = filled($data['reply'] ?? null) && trim($data['reply']) !== trim((string) $message->reply);
        $message->update([
            'status' => $data['status'],
            'reply' => $data['reply'] ?? $message->reply,
            'replied_at' => $newReply ? now() : $message->replied_at,
            'handled_by' => $request->user()->id,
        ]);

        if ($newReply) {
            $subject = 'رد على: '.$message->subject;
            if ($message->email) {
                try {
                    Mail::to($message->email)->queue(new EventNotificationMail($subject, $data['reply']));
                } catch (\Throwable $e) {
                    Log::warning("Contact reply mail failed: {$e->getMessage()}");
                }
            }
            Notify::to($message->user, 'account', 'contact_reply', $subject, \Illuminate\Support\Str::limit($data['reply'], 200), route('contact.create', [], false));
        }

        ActivityLog::record('admin.contact_handled', "تابع طلب التواصل #{$message->id} ({$message->subject})");

        return back()->with('success', $newReply ? 'تم حفظ الرد وإرساله.' : 'تم تحديث الطلب.');
    }

    public function destroy(ContactMessage $message): RedirectResponse
    {
        $message->delete();

        return back()->with('success', 'تم حذف الرسالة.');
    }

    /** Who hears about a new message: admins who manage settings, plus librarians for book and thesis requests. */
    public static function staffFor(string $kind)
    {
        $staff = Notify::admins('can_manage_settings');
        if (in_array($kind, ['book_request', 'thesis_request'], true)) {
            $staff = $staff->merge(User::where('is_active', true)->where('is_librarian', true)->get());
        }

        return $staff->unique('id');
    }
}
