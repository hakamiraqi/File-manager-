<?php

namespace App\Services;

use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Thin wrapper around pragmarx/google2fa (TOTP secrets/verification only —
 * no server-side QR image rendering, so no Imagick/GD dependency; the
 * frontend renders the otpauth:// URI into a QR code itself).
 */
class TwoFactorAuthenticationService
{
    private Google2FA $engine;

    public function __construct()
    {
        $this->engine = new Google2FA;
    }

    public function generateSecretKey(): string
    {
        return $this->engine->generateSecretKey();
    }

    public function qrCodeUri(string $issuer, string $holderEmail, string $secret): string
    {
        return $this->engine->getQRCodeUrl($issuer, $holderEmail, $secret);
    }

    /**
     * @param  int  $window  Number of 30-second steps of clock drift to tolerate on either side.
     */
    public function verify(string $secret, string $code, int $window = 1): bool
    {
        return $this->engine->verifyKey($secret, $code, $window);
    }

    /**
     * @return array<int, string>
     */
    public function generateRecoveryCodes(int $count = 8): array
    {
        return collect()
            ->times($count, fn () => Str::lower(Str::random(10).'-'.Str::random(10)))
            ->all();
    }
}
