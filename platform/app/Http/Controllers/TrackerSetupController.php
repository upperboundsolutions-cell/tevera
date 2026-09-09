<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TrackerSetupController extends Controller
{
    public function __invoke(Request $request)
    {
        $protocols = collect(json_decode(file_get_contents(resource_path('data/traccar-protocols.json')), true, flags: JSON_THROW_ON_ERROR));
        $request->validate(['protocol' => ['nullable', 'string', Rule::in($protocols->pluck('name')->all())]]);
        $selected = $protocols->firstWhere('name', $request->input('protocol', 'gt06'));

        return view('fleet.tracker-setup', [
            'protocols' => $protocols, 'selected' => $selected,
            'port' => $selected ? config('traccar.port_overrides.'.$selected['name'], $selected['port']) : null,
            'trackerHost' => config('traccar.device_host'),
            'clientUrl' => config('traccar.client_url'),
            'phonePort' => config('traccar.port_overrides.osmand', 5055),
        ]);
    }
}
