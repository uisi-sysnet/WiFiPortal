<?php

namespace App\Services\Snmp;

use App\Models\NetworkDevice;
use Carbon\CarbonInterval;
use RuntimeException;
use SNMP;
use Throwable;

/**
 * Online/offline check over SNMP using PHP's snmp extension.
 * Asks for three standard MIB-II values every device supports, so it
 * works on any vendor's AP or switch.
 */
class SnmpProbe
{
    private const SYS_DESCR = '1.3.6.1.2.1.1.1.0';
    private const SYS_UPTIME = '1.3.6.1.2.1.1.3.0';
    private const SYS_NAME = '1.3.6.1.2.1.1.5.0';

    /** @return array{sys_name:?string,sys_descr:?string,uptime_seconds:?int} */
    public function check(NetworkDevice $device): array
    {
        if (! extension_loaded('snmp')) {
            throw new RuntimeException('The PHP snmp extension is not enabled. Add extension=snmp to php.ini and restart.');
        }

        $session = $this->session($device);

        try {
            $raw = $session->get([self::SYS_DESCR, self::SYS_UPTIME, self::SYS_NAME]);
        } catch (Throwable $e) {
            throw new RuntimeException($this->explain($e->getMessage(), $device), 0, $e);
        } finally {
            $session->close();
        }

        // Keys come back as ".1.3.6..." or "1.3.6..." depending on the net-snmp build.
        $values = [];
        foreach ((array) $raw as $oid => $value) {
            $values[ltrim((string) $oid, '.')] = trim((string) $value, " \"\t\n\r");
        }

        $ticks = preg_match('/\d+/', $values[self::SYS_UPTIME] ?? '', $m) ? (int) $m[0] : null;

        return [
            'sys_name' => ($values[self::SYS_NAME] ?? '') ?: null,
            'sys_descr' => ($values[self::SYS_DESCR] ?? '') ?: null,
            'uptime_seconds' => $ticks === null ? null : intdiv($ticks, 100), // timeticks are 1/100 s
        ];
    }

    /**
     * Best-effort hardware details from standard MIBs (ENTITY-MIB, IF-MIB).
     * Many devices only fill some of these; missing ones are left out.
     *
     * @return array{model?:string,serial_number?:string,firmware_version?:string,mac_address?:string}
     */
    public function inventory(NetworkDevice $device): array
    {
        if (! extension_loaded('snmp')) {
            return [];
        }

        $session = $this->session($device);

        try {
            $found = [
                'model' => $this->firstValue($session, '1.3.6.1.2.1.47.1.1.1.1.13'),            // entPhysicalModelName
                'serial_number' => $this->firstValue($session, '1.3.6.1.2.1.47.1.1.1.1.11'),    // entPhysicalSerialNum
                'firmware_version' => $this->firstValue($session, '1.3.6.1.2.1.47.1.1.1.1.10') // entPhysicalSoftwareRev
                    ?? $this->firstValue($session, '1.3.6.1.2.1.47.1.1.1.1.9'),                  // entPhysicalFirmwareRev
                'mac_address' => $this->firstMac($session),                                      // ifPhysAddress
            ];
        } finally {
            $session->close();
        }

        return array_filter($found);
    }

    private function firstValue(SNMP $session, string $oid): ?string
    {
        try {
            $rows = $session->walk($oid, false, 20);
        } catch (Throwable) {
            return null; // not supported by this device
        }

        foreach ((array) $rows as $value) {
            $value = trim((string) $value, " \"\t\r\n");
            if ($value !== '') {
                return mb_substr($value, 0, 64);
            }
        }

        return null;
    }

    private function firstMac(SNMP $session): ?string
    {
        try {
            $rows = $session->walk('1.3.6.1.2.1.2.2.1.6', false, 20);
        } catch (Throwable) {
            return null;
        }

        foreach ((array) $rows as $value) {
            $mac = NetworkDevice::normalizeMac((string) $value, true);
            if ($mac && $mac !== '00:00:00:00:00:00') {
                return $mac;
            }
        }

        return null;
    }

    /** Runs a check and records the result on the device. */
    public function refresh(NetworkDevice $device): NetworkDevice
    {
        try {
            $facts = $this->check($device);
            $device->forceFill([
                ...$facts,
                'status' => 'online',
                'failures' => 0,
                'last_seen_at' => now(),
                'last_checked_at' => now(),
                'last_error' => null,
            ])->save();
        } catch (Throwable $e) {
            $failures = $device->failures + 1;
            $device->forceFill([
                'failures' => $failures,
                'status' => $failures >= config('devices.offline_after') ? 'offline' : $device->status,
                'last_checked_at' => now(),
                'last_error' => $e->getMessage(),
            ])->save();
        }

        return $device;
    }

    public static function uptimeLabel(?int $seconds): ?string
    {
        return $seconds === null ? null
            : CarbonInterval::seconds($seconds)->cascade()->forHumans(['short' => true, 'parts' => 2]);
    }

    private function session(NetworkDevice $d): SNMP
    {
        $host = $d->host.':'.$d->snmp_port;
        $timeout = config('devices.snmp_timeout_ms') * 1000; // microseconds
        $retries = config('devices.snmp_retries');

        if ($d->snmp_version === '3') {
            $session = new SNMP(SNMP::VERSION_3, $host, (string) $d->v3_username, $timeout, $retries);
            $level = $d->v3_security_level ?: 'noAuthNoPriv';
            $session->setSecurity(
                $level,
                $level === 'noAuthNoPriv' ? '' : (string) $d->v3_auth_protocol,
                $level === 'noAuthNoPriv' ? '' : (string) $d->v3_auth_password,
                $level === 'authPriv' ? (string) $d->v3_priv_protocol : '',
                $level === 'authPriv' ? (string) $d->v3_priv_password : '',
            );
        } else {
            $version = $d->snmp_version === '1' ? SNMP::VERSION_1 : SNMP::VERSION_2c;
            $session = new SNMP($version, $host, (string) $d->community, $timeout, $retries);
        }

        $session->oid_output_format = SNMP_OID_OUTPUT_NUMERIC;
        $session->valueretrieval = SNMP_VALUE_PLAIN;
        $session->quick_print = true;
        $session->exceptions_enabled = SNMP::ERRNO_ANY;

        return $session;
    }

    private function explain(string $raw, NetworkDevice $d): string
    {
        $target = "{$d->host}:{$d->snmp_port}";

        if (stripos($raw, 'timeout') !== false || stripos($raw, 'no response') !== false) {
            return "No answer from {$target}. Check the IP, that SNMP is enabled on the device, that it allows this server's IP"
                .($d->snmp_version === '3' ? ', and the SNMPv3 user and passwords.' : ', and the community string.');
        }
        if (stripos($raw, 'authentication') !== false || stripos($raw, 'unknown user') !== false) {
            return 'The device rejected the SNMPv3 user, password or protocol.';
        }
        if (stripos($raw, 'unknown host') !== false || stripos($raw, 'could not resolve') !== false) {
            return "Can't resolve {$d->host}. Use the device's IP address.";
        }

        return $raw;
    }
}