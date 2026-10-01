<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MapSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_without_a_center_the_map_fits_the_devices(): void
    {
        $this->get('/settings')->assertOk()->assertSee('Dashboard map')->assertSee('fits to devices automatically');
        $this->get('/dashboard')->assertOk()->assertSee('"fixed":false', false);
    }

    public function test_saved_center_and_zoom_are_where_the_map_opens(): void
    {
        $this->put('/settings/map', ['latitude' => '14.4081', 'longitude' => '121.0415', 'zoom' => '13'])
            ->assertRedirect(route('settings').'#map')->assertSessionHasNoErrors();

        $this->get('/dashboard')->assertOk()->assertSee('"lat":14.4081,"lng":121.0415,"zoom":13,"fixed":true', false);
        $this->get('/dashboard/map')->assertOk()->assertSee('"fixed":true', false);
        $this->get('/settings')->assertSee('opens on 14.4081, 121.0415 at zoom 13');
    }

    public function test_zoom_defaults_when_only_a_center_is_given(): void
    {
        $this->put('/settings/map', ['latitude' => '10.3157', 'longitude' => '123.8854', 'zoom' => '']);

        $this->get('/dashboard')->assertSee('"lat":10.3157,"lng":123.8854,"zoom":15,"fixed":true', false);
    }

    public function test_clearing_goes_back_to_fitting_the_devices(): void
    {
        Setting::write(['map.latitude' => '14.4', 'map.longitude' => '121.0', 'map.zoom' => '12']);

        $this->put('/settings/map', ['latitude' => '', 'longitude' => '', 'zoom' => ''])->assertSessionHasNoErrors();

        $this->assertNull(Setting::read('map.latitude'));
        $this->get('/dashboard')->assertSee('"fixed":false', false);
    }

    public function test_bad_values_are_refused(): void
    {
        $this->put('/settings/map', ['latitude' => '14.4'])->assertSessionHasErrorsIn('map', 'longitude');
        $this->put('/settings/map', ['zoom' => '12'])->assertSessionHasErrorsIn('map', ['latitude', 'longitude']);
        $this->put('/settings/map', ['latitude' => '95', 'longitude' => '121'])->assertSessionHasErrorsIn('map', 'latitude');
        $this->put('/settings/map', ['latitude' => '14', 'longitude' => '121', 'zoom' => '25'])->assertSessionHasErrorsIn('map', 'zoom');

        $this->assertNull(Setting::read('map.latitude'));
    }

    public function test_full_screen_map_opens_in_a_new_tab(): void
    {
        $this->get('/dashboard')->assertOk()
            ->assertSee('href="'.route('dashboard.map').'" target="_blank"', false);

        $this->get('/dashboard/map')->assertOk()
            ->assertSee('class="map-page"', false)
            ->assertSee('id="map-fs"', false)
            ->assertSee('id="map"', false)
            ->assertDontSee('Open the map full screen in a new tab');
    }
}
