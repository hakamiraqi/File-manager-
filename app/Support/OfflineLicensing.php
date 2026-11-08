<?php

namespace App\Support;

/**
 * Activation of the offline server (2026-11-05, by request), through the same
 * licensing portal as the clinic system: the university's exam-system code is sent
 * once to the licensing server, which ties it to this computer and returns a
 * licence signed with its Ed25519 key. The licence is kept in
 * storage/app/offline-license.json and is valid only for the exam system on this computer.
 *
 * The machine id is a hash of Windows' MachineGuid, which the control panel
 * passes as EXAMNEST_MACHINE_GUID and writes to storage/app/machine-guid.txt.
 */
class OfflineLicensing
{
    /** The licence's details when its signature is genuine, else null. */
    public static function verify(?array $license): ?array
    {
        $payload = $license['payload'] ?? null;
        $signature = base64_decode((string) ($license['signature'] ?? ''), true);
        $key = base64_decode((string) ($license['public_key'] ?? ''), true);
        if (! is_string($payload) || ! $signature || ! $key || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return null;
        }
        try {
            if (! sodium_crypto_sign_verify_detached($signature, $payload, $key)) {
                return null;
            }
        } catch (\SodiumException) {
            return null;
        }
        $data = json_decode($payload, true);

        return is_array($data) ? $data : null;
    }

    public static function path(): string
    {
        return storage_path('app/offline-license.json');
    }

    /** The saved licence, when it is genuine, for this system and for this computer. */
    public static function current(): ?array
    {
        $file = self::path();
        $saved = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $data = self::verify(is_array($saved) ? $saved : null);

        return $data
            && ($data['product'] ?? null) === config('offline.product')
            && hash_equals((string) ($data['machine_id'] ?? ''), self::machineId()) ? $data : null;
    }

    public static function save(array $license): void
    {
        file_put_contents(self::path(), json_encode($license, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    public static function required(): bool
    {
        return config('offline.edition') && config('offline.activation');
    }

    private static ?string $machineId = null;

    /** This computer's id, shown on the activation page: XXXX-XXXX-XXXX-XXXX. */
    public static function machineId(): string
    {
        if (self::$machineId !== null) {
            return self::$machineId;
        }
        $guid = trim((string) getenv('EXAMNEST_MACHINE_GUID'));
        $file = storage_path('app/machine-guid.txt');
        if ($guid === '' && is_file($file)) {
            $guid = trim((string) file_get_contents($file));
        }
        if ($guid === '' && PHP_OS_FAMILY === 'Windows' && function_exists('shell_exec')) {
            $out = (string) @shell_exec('reg query "HKLM\SOFTWARE\Microsoft\Cryptography" /v MachineGuid /reg:64 2>NUL');
            $guid = preg_match('/MachineGuid\s+REG_SZ\s+(\S+)/i', $out, $m) ? $m[1] : '';
        }
        if ($guid === '') {
            $guid = is_file('/etc/machine-id') ? trim((string) file_get_contents('/etc/machine-id')) : (string) gethostname();
        }
        $hash = strtoupper(substr(hash('sha256', 'examnest|'.strtolower($guid)), 0, 16));

        return self::$machineId = implode('-', str_split($hash, 4));
    }

    public static function forgetMachineId(): void
    {
        self::$machineId = null;
    }

    public static function machineName(): string
    {
        return (string) (getenv('COMPUTERNAME') ?: gethostname());
    }

    /** This package's build number (version.txt beside the app folder), if known. */
    public static function installedBuild(): ?int
    {
        $file = base_path('../version.txt');
        $build = is_file($file) ? trim((string) file_get_contents($file)) : '';

        return ctype_digit($build) ? (int) $build : null;
    }
}
