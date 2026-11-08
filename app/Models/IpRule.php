<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['type', 'ip_or_cidr', 'note'])]
class IpRule extends Model
{
    /**
     * Whether the given client IP matches this rule's IP or CIDR range.
     */
    public function matches(string $clientIp): bool
    {
        if (! str_contains($this->ip_or_cidr, '/')) {
            return $this->ip_or_cidr === $clientIp;
        }

        [$subnet, $bits] = explode('/', $this->ip_or_cidr, 2);

        $ipBinary = @inet_pton($clientIp);
        $subnetBinary = @inet_pton($subnet);

        if ($ipBinary === false || $subnetBinary === false || strlen($ipBinary) !== strlen($subnetBinary)) {
            return false;
        }

        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        $remainderBits = $bits % 8;

        if ($bytes > 0 && strncmp($ipBinary, $subnetBinary, $bytes) !== 0) {
            return false;
        }

        if ($remainderBits === 0) {
            return true;
        }

        $mask = 0xFF << (8 - $remainderBits) & 0xFF;

        return (ord($ipBinary[$bytes]) & $mask) === (ord($subnetBinary[$bytes]) & $mask);
    }
}
