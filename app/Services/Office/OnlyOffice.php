<?php

namespace App\Services\Office;

use App\Models\File;
use App\Models\User;
use Illuminate\Support\Facades\URL;
use RuntimeException;

/**
 * The office editor (ONLYOFFICE Docs Community, 2026-11-12, by request: «WPS for every file»):
 * Word, Excel, PowerPoint and PDF files open for editing in the site and the apps.
 *
 * The browser loads the editor from /office; the editor fetches the file from this site and
 * posts it back when everyone closes it, both by short-lived signed links on the server's
 * private network, and every message between them carries a token signed with the shared
 * secret (JWT, HS256), so nothing outside can read or overwrite a file through it.
 */
class OnlyOffice
{
    /** extension => [document type, editable here] */
    public const FORMATS = [
        'docx' => ['word', true], 'odt' => ['word', true], 'rtf' => ['word', true], 'txt' => ['word', true],
        'doc' => ['word', false], 'docm' => ['word', false], 'dotx' => ['word', false],
        'xlsx' => ['cell', true], 'ods' => ['cell', true], 'csv' => ['cell', true],
        'xls' => ['cell', false], 'xlsm' => ['cell', false],
        'pptx' => ['slide', true], 'odp' => ['slide', true],
        'ppt' => ['slide', false], 'ppsx' => ['slide', false],
        'pdf' => ['pdf', true],
    ];

    /** The blank files «new document» starts from. */
    public const NEW = ['docx' => 'blank.docx', 'xlsx' => 'blank.xlsx', 'pptx' => 'blank.pptx'];

    public function enabled(): bool
    {
        return filled(config('services.onlyoffice.secret'));
    }

    public function supports(?string $extension): bool
    {
        return isset(self::FORMATS[strtolower((string) $extension)]);
    }

    public function canEdit(File $file, User $user): bool
    {
        [, $editable] = self::FORMATS[strtolower((string) $file->extension)] ?? [null, false];

        return $editable && $user->can('update', $file)
            && ($file->user_id === $user->id || $user->canPerform('upload'));
    }

    /** Changes whenever the file does, so the editor never shows an old copy. */
    public function key(File $file): string
    {
        return $file->id.'-'.substr(sha1($file->size.'|'.optional($file->updated_at)->timestamp.'|'.$file->path), 0, 16);
    }

    public function scriptUrl(): string
    {
        return rtrim((string) config('services.onlyoffice.public_url'), '/').'/web-apps/apps/api/documents/api.js';
    }

    /** The editor's settings for one user and one file, signed. */
    public function config(File $file, User $user, string $locale = 'ar'): array
    {
        $ext = strtolower((string) $file->extension);
        [$type] = self::FORMATS[$ext];
        $edit = $this->canEdit($file, $user);
        $internal = rtrim((string) config('services.onlyoffice.app_internal_url'), '/');

        $config = [
            'documentType' => $type,
            'document' => [
                'fileType' => $ext,
                'key' => $this->key($file),
                'title' => $file->name,
                'url' => $internal.URL::temporarySignedRoute('office.download', now()->addHours(12), ['file' => $file->id], false),
                'permissions' => [
                    'edit' => $edit,
                    'comment' => $edit,
                    'review' => $edit,
                    'fillForms' => $edit,
                    'download' => $user->canPerform('download'),
                    'print' => $user->canPerform('download'),
                    'copy' => true,
                    'chat' => false,
                    'protect' => false,
                ],
            ],
            'editorConfig' => [
                'mode' => $edit ? 'edit' : 'view',
                'lang' => $locale === 'en' ? 'en' : 'ar',
                'region' => $locale === 'en' ? 'en-GB' : 'ar-IQ',
                'callbackUrl' => $internal.URL::temporarySignedRoute('office.callback', now()->addDays(2), ['file' => $file->id], false),
                'user' => ['id' => (string) $user->id, 'name' => $user->name],
                'customization' => [
                    'autosave' => true,
                    'forcesave' => true,
                    'compactHeader' => true,
                    'hideRightMenu' => false,
                    'uiTheme' => 'theme-system',
                    'feedback' => false,
                    // No links to the editor's own project in the menus: the editor is the site's.
                    'suggestFeature' => false,
                    'about' => false,
                    'help' => false,
                    'plugins' => false,
                    'goback' => false,
                ],
            ],
            'type' => 'desktop',
            'height' => '100%',
            'width' => '100%',
        ];
        $config['token'] = $this->sign($config);

        return $config;
    }

    // ---- JWT (HS256) ----

    public function sign(array $payload): string
    {
        $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $head = $b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $body = $b64(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return "$head.$body.".$b64(hash_hmac('sha256', "$head.$body", (string) config('services.onlyoffice.secret'), true));
    }

    /** The token's payload if its signature is ours, else an exception. */
    public function verify(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3 || ! $this->enabled()) {
            throw new RuntimeException('Bad token.');
        }
        [$head, $body, $sig] = $parts;
        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', "$head.$body", (string) config('services.onlyoffice.secret'), true)), '+/', '-_'), '=');
        if (! hash_equals($expected, $sig)) {
            throw new RuntimeException('Bad signature.');
        }
        $data = json_decode(base64_decode(strtr($body, '-_', '+/')), true);
        if (! is_array($data) || (isset($data['exp']) && $data['exp'] < time())) {
            throw new RuntimeException('Expired token.');
        }

        return $data;
    }

    /** The editor's own address for a URL it gave us (it may name the public host). */
    public function internalise(string $url): string
    {
        $internal = rtrim((string) config('services.onlyoffice.internal_url'), '/');
        $public = rtrim((string) config('services.onlyoffice.public_url'), '/');
        if ($internal === '') {
            return $url;
        }
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $query = parse_url($url, PHP_URL_QUERY);
        $prefix = str_starts_with($public, 'http') ? (parse_url($public, PHP_URL_PATH) ?? '') : $public;
        if ($prefix !== '' && str_starts_with($path, $prefix.'/')) {
            $path = substr($path, strlen($prefix));
        }

        return $internal.$path.($query ? "?$query" : '');
    }

    /**
     * Converts a file with the editor's converter (2026-11-12, PDF tools): Word/Excel/PowerPoint
     * to PDF where LibreOffice is missing (the offline server), and PDF to Word with its layout.
     * Returns the converted bytes, or null.
     */
    public function convert(File $file, string $to, int $timeout = 300): ?string
    {
        if (! $this->enabled()) {
            return null;
        }
        $internal = rtrim((string) config('services.onlyoffice.app_internal_url'), '/');
        $req = [
            'async' => false,
            'filetype' => strtolower((string) $file->extension),
            'key' => 'cv-'.$this->key($file).'-'.$to,
            'outputtype' => $to,
            'title' => 'file.'.strtolower((string) $file->extension),
            'url' => $internal.URL::temporarySignedRoute('office.download', now()->addHour(), ['file' => $file->id], false),
        ];
        $req['token'] = $this->sign($req);
        try {
            $r = \Illuminate\Support\Facades\Http::timeout($timeout)->acceptJson()
                ->post(rtrim((string) config('services.onlyoffice.internal_url'), '/').'/ConvertService.ashx', $req);
            $url = $r->json('fileUrl');
            if (! $r->successful() || ! $r->json('endConvert') || ! $url) {
                return null;
            }
            $out = \Illuminate\Support\Facades\Http::timeout($timeout)->get($this->internalise($url));

            return $out->successful() ? $out->body() : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
