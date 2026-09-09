<?php

namespace App\Http\Controllers;

use App\Exceptions\TraccarException;
use App\Models\Vehicle;
use App\Services\FleetAssistant;
use App\Services\MovementEvidence;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class MovementAnalysisController extends Controller
{
    public function index(Request $request, FleetAssistant $assistant)
    {
        return $this->screen($request, $assistant);
    }

    private function screen(Request $request, FleetAssistant $assistant, ?array $evidence = null, ?string $answer = null)
    {
        return view('fleet.movement-analysis', ['vehicles' => Vehicle::visibleTo($request->user())->orderBy('name')->get(['id', 'name', 'registration']),
            'ready' => $assistant->ready(), 'evidence' => $evidence, 'answer' => $answer]);
    }

    public function analyze(Request $request, MovementEvidence $reports, FleetAssistant $assistant)
    {
        $data = $request->validate(['vehicle_id' => 'required|integer', 'from' => 'required|date_format:Y-m-d\TH:i',
            'to' => 'required|date_format:Y-m-d\TH:i|after:from', 'action' => 'required|in:preview,ai',
            'question' => 'nullable|required_if:action,ai|string|max:1200', 'share_movement' => 'exclude_unless:action,ai|required|accepted']);
        $vehicle = Vehicle::visibleTo($request->user())->findOrFail($data['vehicle_id']);
        if ($data['action'] === 'ai' && ! $assistant->ready()) {
            return back()->withErrors(['question' => 'Connect the AI provider first, or use Preview reports.']);
        }
        if ($data['action'] === 'ai') {
            $bucket = 'fleet-ai:'.($request->user()->customer_id ?? 'platform').':'.now()->format('Y-m-d');
            if (RateLimiter::tooManyAttempts($bucket, 100)) {
                return back()->withErrors(['question' => 'Daily workspace assistant limit reached.']);
            }
            RateLimiter::hit($bucket, 86400);
        }
        try {
            $evidence = $reports->collect($request->user(), $vehicle, CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $data['from'], 'UTC'), CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $data['to'], 'UTC'));
        } catch (TraccarException $error) {
            return back()->withErrors(['vehicle_id' => 'Traccar reports could not be retrieved. Check the tracking connection and retry. No analysis was generated.']);
        }
        $answer = null;
        if ($data['action'] === 'ai') {
            try {
                $answer = $assistant->answer($data['question'], [], $evidence);
            } catch (\RuntimeException $error) {
                return $this->screen($request, $assistant, $evidence)->with('analysisError', 'AI analysis is unavailable. The retrieved evidence is shown below.');
            }
        }

        return $this->screen($request, $assistant, $evidence, $answer);
    }
}
