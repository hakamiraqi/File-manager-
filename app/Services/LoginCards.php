<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * «بطاقات الدخول» (2026-11-09, by request, like the login cards of the clinics system,
 * cdclinics / dental-laravel BulkCredentials): pick students (one by one, a group, or all of a
 * search), give them new passwords, keep the login file, and print it as A4 cards to cut out
 * (10 a page) with the address, the server's IP, the Wi-Fi name and a QR code that signs the
 * student in. The same file uploaded on the offline hall server saves the same passwords there.
 */
class LoginCards
{
    public const COLUMNS = ['name', 'email', 'student_number', 'username', 'password', 'label', 'card_code'];

    public const PASSWORD_LENGTH = 8;

    public static function dir(): string
    {
        $dir = storage_path('app/private/login-cards');
        File::ensureDirectoryExists($dir, 0700);

        return $dir;
    }

    /** What the cards say besides each student: the addresses, the Wi-Fi and a note. */
    public static function settings(): array
    {
        $url = rtrim((string) config('app.url'), '/');

        return [
            'login_url' => (string) (Setting::get('cards_login_url') ?: $url),
            'alt_url' => (string) (Setting::get('cards_alt_url') ?: ''),
            'wifi' => (string) (Setting::get('cards_wifi') ?: ''),
            'note' => (string) (Setting::get('cards_note') ?: 'احتفظ بالبطاقة ولا تعطها لأحد. غيّر كلمة المرور بعد أول دخول إن طُلب منك.'),
            'title' => (string) (Setting::get('site_name') ?: config('app.name')),
        ];
    }

    /** Students (and other non-admin users) a card can be made for. */
    public static function query(array $filters): \Illuminate\Database\Eloquent\Builder
    {
        $q = trim((string) ($filters['q'] ?? ''));

        return User::query()->where('role', '!=', 'admin')
            ->when(($filters['who'] ?? 'students') === 'students', fn ($w) => $w->where('is_examiner', false)->where('is_invigilator', false))
            ->when(($filters['who'] ?? '') === 'staff', fn ($w) => $w->where(fn ($s) => $s->where('is_examiner', true)->orWhere('is_invigilator', true)))
            ->when(! empty($filters['group']), fn ($w) => $w->whereIn('id', DB::table('exam_group_user')->where('exam_group_id', (int) $filters['group'])->select('user_id')))
            ->when($q !== '', fn ($w) => $w->where(fn ($s) => $s->where('name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%")->orWhere('student_number', 'like', "%{$q}%")))
            ->when(isset($filters['active']) && $filters['active'] !== '', fn ($w) => $w->where('is_active', (bool) $filters['active']));
    }

    /**
     * New passwords (unless $newPasswords is false: a card with the QR code and the user name
     * only) and a new card code for each; the login file is kept and its name returned.
     *
     * @param  Collection<int, User>  $users
     */
    public static function make(Collection $users, bool $newPasswords, string $label): array
    {
        $groups = DB::table('exam_group_user')->join('exam_groups', 'exam_groups.id', '=', 'exam_group_user.exam_group_id')
            ->whereIn('exam_group_user.user_id', $users->pluck('id'))->orderBy('exam_groups.name')->get(['exam_group_user.user_id', 'exam_groups.name'])
            ->groupBy('user_id')->map(fn ($r) => $r->first()->name);
        $rows = [];
        DB::transaction(function () use ($users, $newPasswords, $label, $groups, &$rows) {
            foreach ($users->sortBy(fn (User $u) => [$groups[$u->id] ?? '', $u->name]) as $u) {
                $password = $newPasswords ? self::password() : '';
                if ($newPasswords) {
                    $u->forceFill(['password' => Hash::make($password)])->save();
                }
                $email = str_ends_with((string) $u->email, '@students.invalid') ? '' : (string) $u->email;
                $rows[] = [
                    'name' => $u->name, 'email' => $email, 'student_number' => (string) $u->student_number,
                    'username' => (string) ($u->student_number ?: $email),
                    'password' => $password, 'label' => $label !== '' ? $label : (string) ($groups[$u->id] ?? 'طالب'),
                    'card_code' => $u->renewCardCode(),
                ];
            }
        });

        return ['file' => self::save($rows, $label), 'count' => count($rows)];
    }

    /** Digits and lower-case letters without look-alikes (no 0/o, 1/l/i), easy to type from paper. */
    public static function password(): string
    {
        $chars = 'abcdefghjkmnpqrstuvwxyz23456789';
        $out = '';
        for ($i = 0; $i < self::PASSWORD_LENGTH; $i++) {
            $out .= $chars[random_int(0, strlen($chars) - 1)];
        }

        return $out;
    }

    public static function save(array $rows, string $label = ''): string
    {
        $slug = trim(preg_replace('/[^A-Za-z0-9\x{0600}-\x{06FF}]+/u', '-', $label), '-');
        $base = 'cards-'.now()->format('Ymd-His').($slug !== '' ? '-'.mb_substr($slug, 0, 40) : '');
        // Two files in the same second never overwrite each other.
        for ($n = 1, $name = $base.'.csv'; is_file(self::dir().DIRECTORY_SEPARATOR.$name); $n++) {
            $name = $base.'-'.($n + 1).'.csv';
        }
        $fh = fopen(self::dir().DIRECTORY_SEPARATOR.$name, 'w');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, self::COLUMNS);
        foreach ($rows as $r) {
            fputcsv($fh, array_map(fn ($c) => $r[$c] ?? '', self::COLUMNS));
        }
        fclose($fh);
        @chmod(self::dir().DIRECTORY_SEPARATOR.$name, 0600);

        return $name;
    }

    /** The kept login files, newest first. */
    public static function files(): array
    {
        return collect(File::files(self::dir()))->filter(fn ($f) => str_ends_with($f->getFilename(), '.csv'))
            ->sortByDesc(fn ($f) => $f->getMTime())
            ->map(fn ($f) => ['name' => $f->getFilename(), 'size' => $f->getSize(), 'time' => date(DATE_ATOM, $f->getMTime()), 'count' => max(0, count(file($f->getPathname(), FILE_SKIP_EMPTY_LINES)) - 1)])
            ->values()->all();
    }

    /** A kept file's path, only for a plain name in the folder. */
    public static function path(string $name): ?string
    {
        if (! preg_match('/^[\pL\pN._-]+\.csv$/u', $name) || str_contains($name, '..')) {
            return null;
        }
        $path = self::dir().DIRECTORY_SEPARATOR.$name;

        return is_file($path) ? $path : null;
    }

    /** @return list<array<string, string>> */
    public static function read(string $path): array
    {
        $fh = fopen($path, 'r');
        $head = fgetcsv($fh) ?: [];
        $head = array_map(fn ($h) => trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h)), $head);
        $rows = [];
        while (($r = fgetcsv($fh)) !== false) {
            if ($r === [null] || count($r) < 2) {
                continue;
            }
            $rows[] = array_combine($head, array_pad(array_slice($r, 0, count($head)), count($head), ''));
        }
        fclose($fh);

        return $rows;
    }

    /**
     * The offline hall server: the same passwords and card codes as the hosting's login file,
     * for the students found here by student number or e-mail.
     */
    public static function apply(array $rows): array
    {
        $done = 0;
        $missing = [];
        foreach ($rows as $r) {
            $user = null;
            if (($r['student_number'] ?? '') !== '') {
                $user = User::where('student_number', $r['student_number'])->first();
            }
            if (! $user && ($r['email'] ?? '') !== '') {
                $user = User::where('email', $r['email'])->first();
            }
            if (! $user || $user->role === 'admin') {
                $missing[] = $r['name'] ?? ($r['username'] ?? '');

                continue;
            }
            $fields = [];
            if (($r['password'] ?? '') !== '') {
                $fields['password'] = Hash::make($r['password']);
            }
            if (preg_match('/^EX[A-Z0-9]{14,38}$/', (string) ($r['card_code'] ?? ''))) {
                $fields['card_code'] = $r['card_code'];
            }
            if ($fields) {
                $user->forceFill($fields)->save();
                $done++;
            }
        }

        return ['updated' => $done, 'missing' => $missing];
    }

    public static function slugLabel(string $s): string
    {
        return Str::limit(trim($s), 60, '');
    }
}
