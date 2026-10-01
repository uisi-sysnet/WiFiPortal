<?php

namespace App\Http\Controllers;

use App\Jobs\PollNetworkDevices;
use App\Models\Barangay;
use App\Models\MikrotikRouter;
use App\Models\NetworkDevice;
use App\Services\Snmp\SnmpProbe;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use Throwable;

/** Access points and switches. $type comes from the route: ap | switch. */
class NetworkDeviceController extends Controller
{
    private const SECRETS = ['community', 'v3_auth_password', 'v3_priv_password'];

    public function index(Request $request, string $type)
    {
        $filter = $request->integer('barangay') ?: null;

        $devices = NetworkDevice::query()
            ->where('network_devices.type', $type)
            ->when($filter, fn ($q) => $q->where('network_devices.barangay_id', $filter))
            ->leftJoin('barangays', 'barangays.id', '=', 'network_devices.barangay_id')
            ->select('network_devices.*', 'barangays.name as barangay_name')
            ->orderByRaw('barangays.name is null, barangays.name')
            ->orderBy('network_devices.name')
            ->paginate(50)
            ->withQueryString();

        $counts = NetworkDevice::query()->where('type', $type)
            ->when($filter, fn ($q) => $q->where('barangay_id', $filter))
            ->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return view('devices.index', [
            'type' => $type,
            'info' => NetworkDevice::typeInfo($type),
            'devices' => $devices,
            'counts' => $counts,
            'barangays' => Barangay::query()->orderBy('name')->get(['id', 'name']),
            'filter' => $filter,
            // For the Add pop-up
            'blank' => new NetworkDevice(['type' => $type, 'snmp_port' => 161, 'snmp_version' => '2c', 'barangay_id' => $filter]),
            'routers' => MikrotikRouter::query()->orderBy('name')->get(['id', 'name', 'location']),
            'uplinkSwitches' => $this->switches(),
            'uplinkExclude' => [],
            'brands' => $this->brands(),
        ]);
    }

    /** Adding happens in a pop-up on the list; this URL (used by the Devices menu) opens it. */
    public function create(string $type)
    {
        return redirect()->route(NetworkDevice::typeInfo($type)['route'].'.index', ['add' => 1]);
    }

    public function edit(NetworkDevice $device)
    {
        return $this->form($device);
    }

    public function store(Request $request, string $type, SnmpProbe $probe)
    {
        $data = $this->validated($request, $type);
        $this->requireSecrets($data, null);

        $device = NetworkDevice::create([...$data, 'type' => $type]);
        $probe->refresh($device);

        return redirect()->route($device->info()['route'].'.index')->with('status', $device->status === 'online'
            ? "{$device->name} added and online."
            : "{$device->name} added, but it did not answer yet: {$device->last_error}");
    }

    public function update(Request $request, NetworkDevice $device, SnmpProbe $probe)
    {
        $data = $this->validated($request, $device->type, $device);

        // Blank secret fields mean "keep the current one".
        foreach (self::SECRETS as $field) {
            if (blank($data[$field] ?? null)) {
                unset($data[$field]);
            }
        }
        $this->requireSecrets($data, $device);

        $device->fill($data)->save();
        if ($device->type === 'switch' && $device->wasChanged(['mikrotik_router_id', 'uplink_device_id'])) {
            $device->propagateSiteRouter();
        }
        if ($device->wasChanged(['host', 'snmp_port', 'snmp_version', 'community', 'v3_username', 'v3_security_level',
            'v3_auth_protocol', 'v3_auth_password', 'v3_priv_protocol', 'v3_priv_password'])) {
            $probe->refresh($device);
        }

        return redirect()->route($device->info()['route'].'.index', array_filter(['barangay' => $request->input('return_barangay')]))
            ->with('status', "{$device->name} saved.");
    }

    /** Checkbox actions on the list: delete or re-check several devices at once. */
    public function bulk(Request $request)
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['delete', 'check'])],
            'ids' => ['required', 'array', 'max:500'],
            'ids.*' => ['integer'],
        ], ['ids.required' => 'Select at least one device first.']);

        $devices = NetworkDevice::query()->whereIn('id', $data['ids'])->get();
        $n = $devices->count();
        $label = $n === 1 ? 'device' : 'devices';

        if ($data['action'] === 'delete') {
            $devices->each->delete();

            return back()->with('status', "Removed {$n} {$label} from monitoring.");
        }

        PollNetworkDevices::dispatch($devices->pluck('id')->all());

        return back()->with('status', "Checking {$n} {$label} now. Refresh in a moment to see the results.");
    }

    /** AJAX: the "Test SNMP" button. Also returns any hardware details the device reports. */
    public function testSnmp(Request $request, SnmpProbe $probe)
    {
        $data = $request->validate([
            ...$this->snmpRules(editing: true),
            'host' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9.\-:]+$/'],
        ]);

        // On the edit form, blank secrets fall back to the saved ones.
        if ($existing = NetworkDevice::find($request->integer('device_id'))) {
            foreach (self::SECRETS as $field) {
                if (blank($data[$field] ?? null)) {
                    $data[$field] = $existing->{$field};
                }
            }
        }

        $device = new NetworkDevice($data);
        try {
            $facts = $probe->check($device);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        try {
            $hardware = $probe->inventory($device);
        } catch (Throwable) {
            $hardware = [];
        }

        return response()->json([
            ...$facts,
            'uptime' => SnmpProbe::uptimeLabel($facts['uptime_seconds']),
            'hardware' => $hardware,
        ]);
    }

    public function check(NetworkDevice $device, SnmpProbe $probe)
    {
        $probe->refresh($device);

        return back()->with('status', $device->status === 'online'
            ? "{$device->name} is online."
            : "{$device->name} did not answer: {$device->last_error}");
    }

    public function destroy(NetworkDevice $device)
    {
        $device->delete();

        return back()->with('status', "{$device->name} removed from monitoring.");
    }

    private function form(NetworkDevice $device)
    {
        return view('devices.form', [
            'device' => $device,
            'type' => $device->type,
            'info' => $device->info(),
            'editing' => $device->exists,
            'routers' => MikrotikRouter::query()->orderBy('name')->get(['id', 'name', 'location']),
            'barangays' => Barangay::query()->orderBy('name')->get(['id', 'name']),
            'uplinkSwitches' => $this->switches(),
            'brands' => $this->brands(),
            // A switch can't hang off itself or anything plugged into it.
            'uplinkExclude' => $device->exists && $device->type === 'switch' ? [$device->id, ...$device->downstreamIds()] : [],
        ]);
    }

    /** Brands typed so far, suggested as the admin types. */
    private function brands(): array
    {
        return NetworkDevice::query()->whereNotNull('brand')->distinct()->orderBy('brand')->pluck('brand')->all();
    }

    /** Switches for the "Connected to" list. */
    private function switches()
    {
        return NetworkDevice::query()->where('type', 'switch')->orderBy('name')->get(['id', 'name', 'location']);
    }

    /**
     * "Connected to" -> columns.
     *   ""          not set
     *   router:ID   plugged straight into that router (it is also the site router)
     *   switch:ID   plugged into that switch; the site router is the switch's
     * An access point or switch can hang off a switch; a switch can't hang off
     * itself or anything downstream of it.
     *
     * @return array{uplink_device_id:?int, mikrotik_router_id:?int}
     */
    private function resolveUplink(?string $value, ?NetworkDevice $device): array
    {
        if (blank($value)) {
            return ['uplink_device_id' => null, 'mikrotik_router_id' => null];
        }
        [$kind, $id] = explode(':', $value);
        $id = (int) $id;

        if ($kind === 'router') {
            if (! MikrotikRouter::query()->whereKey($id)->exists()) {
                throw ValidationException::withMessages(['uplink' => 'That router no longer exists.']);
            }

            return ['uplink_device_id' => null, 'mikrotik_router_id' => $id];
        }

        $switch = NetworkDevice::query()->where('type', 'switch')->find($id);
        if (! $switch) {
            throw ValidationException::withMessages(['uplink' => 'That switch no longer exists.']);
        }
        if ($device?->exists && ($id === $device->id || in_array($id, $device->downstreamIds(), true))) {
            throw ValidationException::withMessages(['uplink' => "{$device->name} can't connect to {$switch->name}: {$switch->name} is plugged into {$device->name}, so the chain would loop."]);
        }

        return ['uplink_device_id' => $switch->id, 'mikrotik_router_id' => $switch->mikrotik_router_id];
    }

    private function validated(Request $request, string $type, ?NetworkDevice $device = null): array
    {
        if ($request->filled('mac_address')) {
            $request->merge(['mac_address' => NetworkDevice::normalizeMac($request->input('mac_address')) ?? $request->input('mac_address')]);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:64', Rule::unique('network_devices')->where('type', $type)->ignore($device?->id)],
            'brand' => ['nullable', 'string', 'max:64'],
            'model' => ['nullable', 'string', 'max:64'],
            'mac_address' => ['nullable', 'regex:/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/', Rule::unique('network_devices')->ignore($device?->id)],
            'serial_number' => ['nullable', 'string', 'max:64'],
            'firmware_version' => ['nullable', 'string', 'max:64'],
            'barangay_id' => ['required', 'integer', 'exists:barangays,id'],
            'location' => ['nullable', 'string', 'max:255'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'uplink' => ['nullable', 'string', 'regex:/^(router|switch):\d+$/'],
            'host' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9.\-:]+$/',
                Rule::unique('network_devices')->where('snmp_port', (int) $request->input('snmp_port', 161))->ignore($device?->id)],
            ...$this->snmpRules(editing: true),
        ], [
            'host.unique' => 'A device with this IP and port is already monitored.',
            'host.regex' => 'Enter an IP address or hostname.',
            'mac_address.regex' => 'Enter a MAC address like AA:BB:CC:DD:EE:FF.',
            'mac_address.unique' => 'Another device already has this MAC address.',
            'barangay_id.required' => 'Choose the barangay where it is installed.',
            'latitude.between' => 'Latitude must be between -90 and 90.',
            'longitude.between' => 'Longitude must be between -180 and 180.',
            'uplink.regex' => 'Choose what this device is connected to from the list.',
        ]);

        $uplink = $data['uplink'] ?? null;
        unset($data['uplink']);

        return [...$data, ...$this->resolveUplink($uplink, $device)];
    }

    /**
     * Secrets are optional in the rules (so edits can keep the saved ones);
     * this makes sure the device still ends up with what its SNMP settings need.
     */
    private function requireSecrets(array $data, ?NetworkDevice $device): void
    {
        $has = fn ($field) => filled($data[$field] ?? null) || filled($device?->{$field});
        $version = $data['snmp_version'];
        $level = $data['v3_security_level'] ?? null;
        $errors = [];

        if ($version !== '3' && ! $has('community')) {
            $errors['community'] = 'Enter the community.';
        }
        if ($version === '3' && $level !== 'noAuthNoPriv' && ! $has('v3_auth_password')) {
            $errors['v3_auth_password'] = 'Enter the authentication password.';
        }
        if ($version === '3' && $level === 'authPriv' && ! $has('v3_priv_password')) {
            $errors['v3_priv_password'] = 'Enter the encryption password.';
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function snmpRules(bool $editing = false): array
    {
        $secret = ['nullable', 'string', 'max:128'];

        return [
            'snmp_port' => ['required', 'integer', 'between:1,65535'],
            'snmp_version' => ['required', Rule::in(['1', '2c', '3'])],
            'community' => ['exclude_if:snmp_version,3', ...$secret],
            'v3_username' => ['exclude_unless:snmp_version,3', 'required', 'string', 'max:64'],
            'v3_security_level' => ['exclude_unless:snmp_version,3', 'required', Rule::in(['noAuthNoPriv', 'authNoPriv', 'authPriv'])],
            'v3_auth_protocol' => ['exclude_unless:snmp_version,3', 'exclude_if:v3_security_level,noAuthNoPriv', 'required', Rule::in(['MD5', 'SHA', 'SHA256', 'SHA512'])],
            'v3_auth_password' => ['exclude_unless:snmp_version,3', 'exclude_if:v3_security_level,noAuthNoPriv', ...$secret, 'min:8'],
            'v3_priv_protocol' => ['exclude_unless:snmp_version,3', 'exclude_unless:v3_security_level,authPriv', 'required', Rule::in(['DES', 'AES'])],
            'v3_priv_password' => ['exclude_unless:snmp_version,3', 'exclude_unless:v3_security_level,authPriv', ...$secret, 'min:8'],
        ];
    }

    /**
     * Export the current device list (respecting the barangay filter) to a file
     * Excel opens natively. Uses SpreadsheetML 2003 so no composer package is needed.
     */
    public function export(Request $request, string $type)
    {
        $info = NetworkDevice::typeInfo($type);
        $filter = $request->integer('barangay') ?: null;

        $devices = NetworkDevice::query()
            ->where('network_devices.type', $type)
            ->when($filter, fn ($q) => $q->where('network_devices.barangay_id', $filter))
            ->leftJoin('barangays', 'barangays.id', '=', 'network_devices.barangay_id')
            ->select('network_devices.*', 'barangays.name as barangay_name')
            ->orderByRaw('barangays.name is null, barangays.name')
            ->orderBy('network_devices.name')
            ->get();

        $filename = sprintf(
            '%s-%s%s.xls',
            Str::slug($info['plural']),
            now()->format('Y-m-d-His'),
            $filter ? '-barangay-'.$filter : ''
        );

        $headers = [
            'No.',
            'Device name',
            'Status',
            'Brand',
            'Model',
            'IP address',
            'Port',
            'MAC address',
            'Serial number',
            'Firmware',
            'Barangay',
            'Location',
            'Latitude',
            'Longitude',
            'Site router',
            'Connected to',
            'Last checked',
            'Last error',
        ];

        return response()->streamDownload(function () use ($devices, $headers, $info) {
            $out = fopen('php://output', 'w');

            // XML header + Workbook/Worksheet so Excel opens it as a real spreadsheet
            fwrite($out, '<?xml version="1.0" encoding="UTF-8"?>'."\n");
            fwrite($out, '<?mso-application progid="Excel.Sheet"?>'."\n");
            fwrite($out, '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"'
                .' xmlns:o="urn:schemas-microsoft-com:office:office"'
                .' xmlns:x="urn:schemas-microsoft-com:office:excel"'
                .' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">'."\n");

            // Styles: bold header, freeze row 1, sane column widths
            fwrite($out, '<Styles>'."\n");
            fwrite($out, '<Style ss:ID="hdr"><Font ss:Bold="1"/><Interior ss:Color="#EAF6F1" ss:Pattern="Solid"/></Style>'."\n");
            fwrite($out, '<Style ss:ID="txt"><NumberFormat ss:Format="@"/></Style>'."\n");
            fwrite($out, '</Styles>'."\n");

            fwrite($out, '<Worksheet ss:Name="'.htmlspecialchars(substr($info['plural'], 0, 31), ENT_XML1).'">'."\n");
            fwrite($out, '<Table>'."\n");

            // Column widths
            $widths = [40, 140, 70, 90, 120, 110, 50, 120, 120, 100, 120, 180, 90, 90, 120, 120, 130, 220];
            foreach ($widths as $w) {
                fwrite($out, '<Column ss:Width="'.$w.'"/>');
            }
            fwrite($out, "\n");

            // Header row
            fwrite($out, '<Row ss:StyleID="hdr">');
            foreach ($headers as $h) {
                fwrite($out, '<Cell><Data ss:Type="String">'.htmlspecialchars($h, ENT_XML1).'</Data></Cell>');
            }
            fwrite($out, '</Row>'."\n");

            // Data rows
            foreach ($devices as $i => $d) {
                $siteRouter = $d->siteRouter?->name ?? '';
                $uplink = '';
                if ($d->uplink_device_id) {
                    $uplink = 'Switch: '.($d->uplinkDevice?->name ?? $d->uplink_device_id);
                } elseif ($d->mikrotik_router_id) {
                    $uplink = 'Router: '.$siteRouter;
                }

                $cells = [
                    $i + 1,
                    $d->name,
                    $d->statusLabel(),
                    $d->brand,
                    $d->model,
                    $d->host,
                    $d->snmp_port,
                    $d->mac_address,
                    $d->serial_number,
                    $d->firmware_version,
                    $d->barangay_name,
                    $d->location,
                    $d->latitude,
                    $d->longitude,
                    $siteRouter,
                    $uplink,
                    $d->last_checked_at?->toDateTimeString(),
                    $d->last_error,
                ];

                fwrite($out, '<Row>');
                foreach ($cells as $cell) {
                    $isNumeric = is_int($cell) || is_float($cell);
                    $type = $isNumeric ? 'Number' : 'String';
                    $value = $isNumeric ? (string) $cell : (string) ($cell ?? '');
                    fwrite($out, '<Cell><Data ss:Type="'.$type.'">'
                        .htmlspecialchars($value, ENT_XML1)
                        .'</Data></Cell>');
                }
                fwrite($out, '</Row>'."\n");
            }

            fwrite($out, '</Table>'."\n");

            // Freeze the header row so it stays visible while scrolling in Excel
            fwrite($out, '<WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">'
                .'<FreezePanes/><FrozenNoSplit/><SplitHorizontal>1</SplitHorizontal>'
                .'<TopRowBottomPane>1</TopRowBottomPane><ActivePane>2</ActivePane>'
                .'</WorksheetOptions>'."\n");

            fwrite($out, '</Worksheet>'."\n");
            fwrite($out, '</Workbook>'."\n");

            fclose($out);
        }, $filename, [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }
}
