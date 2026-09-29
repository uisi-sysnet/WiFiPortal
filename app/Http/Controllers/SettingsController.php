<?php

namespace App\Http\Controllers;

use App\Models\Barangay;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function index()
    {
        return view('settings.index', [
            'barangays' => Barangay::query()->withCount('devices')->orderBy('name')->get(),
        ]);
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
