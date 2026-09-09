<?php

namespace App\Http\Controllers;

use App\Exceptions\TraccarException;
use App\Services\AuditLogger;
use App\Services\TraccarService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TraccarConnectionController extends Controller
{
    public function __invoke(Request $request, TraccarService $traccar, AuditLogger $audit): RedirectResponse
    {
        try {
            $traccar->checkConnection();
            $audit->record('traccar.connection_verified', $request->user());

            return back()->with('status', 'Traccar connection verified. Service authentication succeeded.');
        } catch (TraccarException $e) {
            $audit->record('traccar.connection_failed', $request->user());

            return back()->withErrors(['traccar' => $e->getMessage()]);
        }
    }
}
