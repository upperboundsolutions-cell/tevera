<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ProtocolController extends Controller
{
    public function __invoke(Request $request)
    {
        $request->validate(['search' => 'nullable|string|max:80']);
        $protocols = collect(json_decode(file_get_contents(resource_path('data/traccar-protocols.json')), true, flags: JSON_THROW_ON_ERROR));
        $protocols = $protocols->map(fn ($protocol) => $protocol + ['configuredPort' => config('traccar.port_overrides.'.$protocol['name'], $protocol['port'])]);
        $search = strtolower((string) $request->input('search'));

        return view('fleet.protocols', ['protocols' => $protocols->filter(fn ($protocol) => str_contains($protocol['name'], $search) || str_contains((string) $protocol['port'], $search) || str_contains((string) $protocol['configuredPort'], $search)), 'total' => $protocols->count()]);
    }
}
