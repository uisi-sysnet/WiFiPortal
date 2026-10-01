<?php

namespace App\Http\Controllers;

use App\Models\Barangay;
use App\Models\Setting;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function index()
    {
        return view('settings.index', [
            'barangays' => Barangay::query()->withCount('devices')->orderBy('name')->get(),
            'map' => [
                'latitude' => Setting::read('map.latitude'),
                'longitude' => Setting::read('map.longitude'),
                'zoom' => Setting::read('map.zoom'),
            ],
            'defaultZoom' => DashboardController::DEFAULT_ZOOM,
        ]);
    }

    /**
     * Where the dashboard map opens. All empty: the map frames the devices
     * automatically. Latitude and longitude go together; zoom needs a center.
     */
    public function updateMap(Request $request)
    {
        $data = $request->validateWithBag('map', [
            'latitude' => ['nullable', 'required_with:longitude,zoom', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'required_with:latitude,zoom', 'numeric', 'between:-180,180'],
            'zoom' => ['nullable', 'integer', 'between:3,19'],
        ], [
            'latitude.required_with' => 'Enter the latitude too, or leave all three empty.',
            'longitude.required_with' => 'Enter the longitude too, or leave all three empty.',
            'zoom.between' => 'Zoom goes from 3 (a whole region) to 19 (a single building).',
        ]);

        Setting::write([
            'map.latitude' => $data['latitude'] ?? null,
            'map.longitude' => $data['longitude'] ?? null,
            'map.zoom' => $data['zoom'] ?? null,
        ]);

        return redirect()->to(route('settings').'#map')->with('status', isset($data['latitude'])
            ? 'The dashboard map now opens on that spot.'
            : 'The dashboard map now frames your devices automatically.');
    }

    public function storeBarangay(Request $request)
    {
        $name = $this->validName($request, 'addBarangay');
        Barangay::create(['name' => $name]);

        return $this->back("{$name} added.");
    }

    public function updateBarangay(Request $request, Barangay $barangay)
    {
        $old = $barangay->name;
        $name = $this->validName($request, 'barangay'.$barangay->id, $barangay->id);
        $barangay->update(['name' => $name]);

        return $this->back($old === $name ? 'No change.' : "{$old} renamed to {$name}. Its devices moved with it.");
    }

    public function destroyBarangay(Barangay $barangay)
    {
        $inUse = $barangay->devices()->count();
        if ($inUse > 0) {
            return $this->back("{$barangay->name} still has {$inUse} ".str('device')->plural($inUse)
                .'. Move them to another barangay first.', 'error');
        }

        $barangay->delete();

        return $this->back("{$barangay->name} deleted.");
    }

    private function validName(Request $request, string $bag, ?int $ignoreId = null): string
    {
        $request->merge(['name' => Barangay::cleanName($request->input('name'))]);

        $request->validateWithBag($bag, [
            'name' => ['required', 'string', 'max:80', function ($attr, $value, $fail) use ($ignoreId) {
                if (Barangay::nameTaken($value, $ignoreId)) {
                    $fail("{$value} is already on the list.");
                }
            }],
        ], ['name.required' => 'Enter the barangay name.']);

        return $request->input('name');
    }

    private function back(string $message, string $key = 'status')
    {
        return redirect()->to(route('settings').'#barangays')->with($key, $message);
    }
}
