<?php

namespace App\Http\Controllers;

use App\Services\FleetAssistant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class AssistantController extends Controller
{
    public function index(Request $request, FleetAssistant $assistant)
    {
        return view('fleet.assistant', ['summary' => $assistant->summary($request->user()), 'ready' => $assistant->ready()]);
    }

    public function ask(Request $request, FleetAssistant $assistant)
    {
        $data = $request->validate(['question' => 'required|string|max:1200', 'include_summary' => 'sometimes|boolean']);
        if (! $assistant->ready()) {
            return back()->withErrors(['question' => 'Your platform administrator needs to connect the AI provider first.']);
        }
        $bucket = 'fleet-ai:'.($request->user()->customer_id ?? 'platform').':'.now()->format('Y-m-d');
        $answer = null;
        try {
            $allowed = RateLimiter::attempt($bucket, 100, function () use ($assistant, $request, $data, &$answer) {
                $answer = $assistant->answer($data['question'], $request->boolean('include_summary') ? $assistant->summary($request->user()) : []);
            }, 86400);
            if ($allowed === false) {
                return back()->withErrors(['question' => 'This workspace has reached its daily assistant limit.']);
            }
        } catch (\RuntimeException $error) {
            return back()->withErrors(['question' => $error->getMessage()]);
        }

        return redirect()->route('assistant.index')->with('assistant_answer', $answer)->with('assistant_question', $data['question']);
    }
}
