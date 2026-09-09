<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\File;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Downloads a remote URL into the current user's drive (VFM's "remote
 * uploader"). Deliberately paranoid about SSRF: only plain http(s) is
 * accepted, the resolved IP must be public/routable, redirects are not
 * followed blindly, and the download is capped at max_upload_mb.
 */
class RemoteUploadService
{
    public function __construct(
        private readonly StorageQuotaService $quota,
        private readonly ExtensionFilter $extensionFilter,
    ) {}

    public function download(User $user, string $url, ?int $folderId): File
    {
        $this->assertSafeUrl($url);

        $name = $this->guessName($url);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if (in_array($extension, config('filenest.forbidden_extensions'), true) || ! $this->extensionFilter->isAllowed($extension)) {
            throw ValidationException::withMessages(['url' => 'نوع هذا الملف غير مسموح به.']);
        }

        $maxBytes = config('filenest.max_upload_mb') * 1024 * 1024;

        $response = Http::withOptions(['allow_redirects' => false])
            ->timeout(30)
            ->connectTimeout(5)
            ->get($url);

        if ($response->redirect() || $response->status() >= 300 && $response->status() < 400) {
            throw ValidationException::withMessages(['url' => 'لا يمكن رفع روابط تعيد التوجيه.']);
        }

        if (! $response->successful()) {
            throw ValidationException::withMessages(['url' => 'تعذّر تنزيل الملف من الرابط المحدد.']);
        }

        $body = $response->body();
        $size = strlen($body);

        if ($size > $maxBytes) {
            throw ValidationException::withMessages(['url' => 'حجم الملف يتجاوز الحد الأقصى المسموح به.']);
        }

        $this->quota->assertHasSpace($user, $size);

        $disk = Storage::disk(config('filenest.disk'));
        $storedName = Str::uuid().($extension ? ".{$extension}" : '');
        $relativePath = "{$user->id}/{$storedName}";
        $disk->makeDirectory((string) $user->id);
        $disk->put($relativePath, $body);

        $file = File::create([
            'user_id' => $user->id,
            'folder_id' => $folderId,
            'name' => UniqueNamer::forFile($user->id, $folderId, $name),
            'disk' => config('filenest.disk'),
            'path' => $relativePath,
            'extension' => $extension ?: null,
            'mime_type' => $response->header('Content-Type') ?: 'application/octet-stream',
            'size' => $size,
        ]);

        $this->quota->increment($user, $size);

        ActivityLog::record('file.remote_uploaded', "رفع الملف \"{$file->name}\" من رابط خارجي", $file);

        return $file;
    }

    private function guessName(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '';
        $name = basename($path);

        return $name !== '' ? $name : ('remote-file-'.Str::random(6));
    }

    private function assertSafeUrl(string $url): void
    {
        $parts = parse_url($url);

        if (! $parts || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])) {
            throw ValidationException::withMessages(['url' => 'الرابط غير صالح.']);
        }

        $host = $parts['host'];
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);

        if ($ips === []) {
            throw ValidationException::withMessages(['url' => 'تعذّر تحليل عنوان الخادم.']);
        }

        foreach ($ips as $ip) {
            if (! $this->isPublicIp($ip)) {
                throw ValidationException::withMessages(['url' => 'لا يمكن رفع الملفات من عناوين شبكة داخلية.']);
            }
        }
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }
}
