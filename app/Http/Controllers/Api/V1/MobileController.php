<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\ContactController as WebContact;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\ActivityLog;
use App\Models\ContactMessage;
use App\Models\File;
use App\Models\Folder;
use App\Models\SavedItem;
use App\Models\UserNotification;
use App\Services\Notify;
use App\Services\Theses\ThesisCatalog;
use App\Support\PublicSite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The parts of the website the mobile apps were missing (2026-11-06, by request):
 * theses, notifications, «المحفوظات», «تواصل معنا» and its inbox, the profile picture
 * and the visitors' site switches.
 */
class MobileController extends Controller
{
    /** Which sections the visitors' site shows; the app's login screen uses it. */
    public function site(): JsonResponse
    {
        return response()->json([
            ...PublicSite::sections(),
            'site_name' => \App\Models\Setting::get('site_name', config('app.name')),
        ]);
    }

    // ---- Theses ----

    public function theses(Request $request, ThesisCatalog $catalog): JsonResponse
    {
        $user = $request->user('sanctum');
        abort_unless($user || PublicSite::theses(), 401, 'سجّل الدخول لتصفح الرسائل والأطاريح.');

        $filters = $request->only(['q', 'field', 'degree', 'year', 'department', 'subject', 'language']);
        $page = $catalog->query($user, $filters)->orderByDesc('publication_year')->orderBy('thesis_title')->paginate(20);

        return response()->json([
            'data' => collect($page->items())->map(fn (Folder $f) => [
                'id' => $f->id,
                'title' => $f->thesis_title ?: $f->name,
                'researcher' => $f->researcher_name,
                'supervisor' => $f->supervisor_name,
                'degree' => $f->degree,
                'year' => $f->publication_year,
                'department' => $f->department,
                'subject' => $f->subject,
                'keywords' => $f->keywords,
                'abstract' => $f->abstract,
                'language' => $f->language,
                'pages' => $f->pages,
                'university' => $f->university,
                'college' => $f->college,
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
            'facets' => $request->integer('page', 1) === 1 ? $catalog->facets($user) : null,
            'degree_labels' => ThesisCatalog::DEGREE_LABELS,
            'language_labels' => ThesisCatalog::LANGUAGE_LABELS,
        ]);
    }

    // ---- Notifications ----

    public function notifications(Request $request): JsonResponse
    {
        $user = $request->user();
        $filter = in_array($request->query('filter'), ['unread', ...UserNotification::CATEGORIES], true) ? $request->query('filter') : 'all';
        $page = UserNotification::where('user_id', $user->id)
            ->when($filter === 'unread', fn ($q) => $q->whereNull('read_at'))
            ->when(in_array($filter, UserNotification::CATEGORIES, true), fn ($q) => $q->where('category', $filter))
            ->latest()->latest('id')->paginate(30);

        return response()->json([
            'data' => collect($page->items())->map(fn (UserNotification $n) => [...$n->toCard(), 'url' => $n->url]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
            'unread' => UserNotification::unreadCount($user),
        ]);
    }

    /** Badges for the app's tabs: unread notifications, library notices, new contact messages. */
    public function badges(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'notifications' => UserNotification::unreadCount($user),
            'contact_new' => $user->can('manage-contact') ? ContactMessage::where('status', 'new')->count() : 0,
        ]);
    }

    public function readNotification(Request $request, UserNotification $notification): JsonResponse
    {
        abort_unless($notification->user_id === $request->user()->id, 404);
        $notification->update(['read_at' => $notification->read_at ?? now()]);

        return response()->json(['ok' => true]);
    }

    public function readAllNotifications(Request $request): JsonResponse
    {
        UserNotification::where('user_id', $request->user()->id)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }

    public function deleteNotification(Request $request, UserNotification $notification): JsonResponse
    {
        abort_unless($notification->user_id === $request->user()->id, 404);
        $notification->delete();

        return response()->json(['ok' => true]);
    }

    // ---- Saved and downloaded ----

    public function saved(Request $request): JsonResponse
    {
        $user = $request->user();
        $saved = SavedItem::where('user_id', $user->id)->with('savable')->latest('id')->get()
            ->filter(fn (SavedItem $s) => $s->savable && $user->can('view', $s->savable))
            ->map(fn (SavedItem $s) => [
                'type' => $s->savable instanceof Folder ? 'folder' : 'file',
                'saved_at' => $s->created_at?->toIso8601String(),
                'item' => $this->present($s->savable),
            ])->values();

        $rows = ActivityLog::query()
            ->where('user_id', $user->id)->where('action', 'file.downloaded')
            ->where('subject_type', (new File)->getMorphClass())->whereNull('downloads_hidden_at')
            ->selectRaw('subject_id, MAX(created_at) as last_at, COUNT(*) as times')
            ->groupBy('subject_id')->orderByDesc('last_at')->limit(300)->get();
        $files = File::whereIn('id', $rows->pluck('subject_id'))->get()->keyBy('id');
        $downloads = $rows->filter(fn ($d) => ($f = $files->get($d->subject_id)) && $user->can('view', $f))
            ->map(fn ($d) => [
                'type' => 'file',
                'downloaded_at' => Carbon::parse($d->last_at)->toIso8601String(),
                'times' => (int) $d->times,
                'item' => $this->present($files->get($d->subject_id)),
            ])->values();

        return response()->json(['saved' => $saved, 'downloads' => $downloads, 'ids' => SavedItem::idsFor($user)]);
    }

    public function toggleSaved(Request $request): JsonResponse
    {
        $data = $request->validate(['type' => ['required', 'in:file,folder'], 'id' => ['required', 'integer']]);
        $model = $data['type'] === 'folder' ? Folder::findOrFail($data['id']) : File::findOrFail($data['id']);
        $this->authorize('view', $model);

        $keys = ['user_id' => $request->user()->id, 'savable_type' => $model->getMorphClass(), 'savable_id' => $model->getKey()];
        $existing = SavedItem::where($keys)->first();
        $existing ? $existing->delete() : SavedItem::create($keys);

        return response()->json(['saved' => ! $existing]);
    }

    public function forgetDownload(Request $request): JsonResponse
    {
        $data = $request->validate(['id' => ['nullable', 'integer']]);
        ActivityLog::where('user_id', $request->user()->id)->where('action', 'file.downloaded')
            ->where('subject_type', (new File)->getMorphClass())
            ->when($data['id'] ?? null, fn ($q, $id) => $q->where('subject_id', $id))
            ->whereNull('downloads_hidden_at')->update(['downloads_hidden_at' => now()]);

        return response()->json(['ok' => true]);
    }

    // ---- Contact ----

    public function contactInfo(Request $request): JsonResponse
    {
        abort_unless(PublicSite::contact(), 404);
        $user = $request->user('sanctum');

        return response()->json([
            'kinds' => ContactMessage::KINDS,
            'statuses' => ContactMessage::STATUSES,
            'mine' => $user ? ContactMessage::where('user_id', $user->id)->latest()->limit(30)->get()
                ->map(fn (ContactMessage $m) => [
                    'id' => $m->id, 'kind' => $m->kind, 'subject' => $m->subject, 'message' => $m->message, 'status' => $m->status,
                    'reply' => $m->reply, 'about_title' => $m->aboutTitle(),
                    'created_at' => $m->created_at?->toIso8601String(), 'replied_at' => $m->replied_at?->toIso8601String(),
                ]) : [],
        ]);
    }

    public function contactStore(Request $request): JsonResponse
    {
        abort_unless(PublicSite::contact(), 404);
        $user = $request->user('sanctum');
        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(ContactMessage::KINDS))],
            'name' => [$user ? 'nullable' : 'required', 'string', 'max:120'],
            'email' => [$user ? 'nullable' : 'required_without:phone', 'nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'subject' => ['required', 'string', 'max:190'],
            'message' => ['required', 'string', 'max:5000'],
            'about_type' => ['nullable', 'in:book,thesis'],
            'about_id' => ['nullable', 'integer'],
        ], ['email.required_without' => 'اكتب بريدك الإلكتروني أو رقم هاتفك حتى نرد عليك.']);

        $msg = ContactMessage::create([
            ...$data,
            'name' => ($data['name'] ?? null) ?: $user?->name,
            'email' => ($data['email'] ?? null) ?: $user?->email,
            'user_id' => $user?->id,
            'ip_address' => $request->ip(),
        ]);
        $kind = ContactMessage::KINDS[$msg->kind];
        Notify::to(WebContact::staffFor($msg->kind), 'admin', 'contact_message', "{$kind} جديد: {$msg->subject}", "من {$msg->name}", route('contact.inbox', ['open' => $msg->id], false));

        return response()->json(['message' => "تم إرسال {$kind} برقم #{$msg->id}. سنرد عليك قريباً.", 'id' => $msg->id], 201);
    }

    public function inbox(Request $request): JsonResponse
    {
        $status = $request->query('status', 'open');
        $kind = $request->query('kind');
        $q = trim((string) $request->query('q', ''));
        $base = ContactMessage::query()
            ->when($kind && isset(ContactMessage::KINDS[$kind]), fn ($w) => $w->where('kind', $kind))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('subject', 'like', "%{$q}%")->orWhere('message', 'like', "%{$q}%")
                ->orWhere('name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%")->orWhere('phone', 'like', "%{$q}%")));
        $counts = (clone $base)->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status');

        $page = (clone $base)
            ->when($status === 'open', fn ($w) => $w->where('status', '!=', 'closed'))
            ->when(isset(ContactMessage::STATUSES[$status]), fn ($w) => $w->where('status', $status))
            ->with(['user:id,name,avatar_path', 'handler:id,name'])
            ->orderByRaw("CASE WHEN status = 'new' THEN 0 WHEN status = 'in_progress' THEN 1 ELSE 2 END")
            ->latest()->latest('id')->paginate(30);

        return response()->json([
            'data' => collect($page->items())->map(fn (ContactMessage $m) => [
                ...$m->only(['id', 'kind', 'name', 'email', 'phone', 'subject', 'message', 'status', 'reply', 'about_type', 'about_id']),
                'about_title' => $m->aboutTitle(),
                'created_at' => $m->created_at?->toIso8601String(),
                'replied_at' => $m->replied_at?->toIso8601String(),
                'user' => $m->user ? ['id' => $m->user->id, 'name' => $m->user->name, 'avatar_url' => $m->user->avatar_url] : null,
                'handler' => $m->handler?->name,
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
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

    public function inboxUpdate(Request $request, ContactMessage $message): JsonResponse
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
                    Mail::to($message->email)->queue(new \App\Mail\EventNotificationMail($subject, $data['reply']));
                } catch (\Throwable $e) {
                    Log::warning("Contact reply mail failed: {$e->getMessage()}");
                }
            }
            Notify::to($message->user, 'account', 'contact_reply', $subject, Str::limit($data['reply'], 200), route('contact.create', [], false));
        }
        ActivityLog::record('admin.contact_handled', "تابع طلب التواصل #{$message->id} ({$message->subject})");

        return response()->json(['message' => $newReply ? 'تم حفظ الرد وإرساله.' : 'تم تحديث الطلب.']);
    }

    public function inboxDestroy(ContactMessage $message): JsonResponse
    {
        $message->delete();

        return response()->json(['message' => 'تم حذف الرسالة.']);
    }

    // ---- Profile picture ----

    public function avatar(Request $request): JsonResponse
    {
        app(\App\Http\Controllers\ProfileController::class)->saveAvatar($request);

        return response()->json(['user' => new UserResource($request->user()->fresh())]);
    }

    public function deleteAvatar(Request $request): JsonResponse
    {
        app(\App\Http\Controllers\ProfileController::class)->removeAvatar($request);

        return response()->json(['user' => new UserResource($request->user()->fresh())]);
    }

    private function present(File|Folder $m): array
    {
        // The same shapes as /browse, so the app reuses its file and folder tiles.
        return $m instanceof Folder
            ? (new \App\Http\Resources\FolderResource($m))->resolve(request())
            : (new \App\Http\Resources\FileResource($m))->resolve(request());
    }
}
