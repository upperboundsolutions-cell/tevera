@extends('layouts.app')
@section('title', 'Movement analysis')
@section('content')
@isset($analysisError)<p class="notice">{{ $analysisError }}</p>@endisset
<section class="executive-banner"><div><span class="hero-kicker">TEVERA INTELLIGENCE</span><h1>Understand every journey.</h1><p>Review trips, stops, events and recorded driver assignments. Ask AI to explain the evidence.</p></div><span class="ai-orbit" aria-hidden="true">✦</span></section>
<section class="panel fleet-section"><form class="fleet-form" action="{{ route('movement.analyze') }}" method="POST">@csrf
<div class="fleet-grid"><label for="analysis-vehicle">Vehicle<select id="analysis-vehicle" name="vehicle_id" required><option value="">Choose a vehicle</option>@foreach($vehicles as $vehicle)<option value="{{ $vehicle->id }}" @selected((string) request('vehicle_id') === (string) $vehicle->id)>{{ $vehicle->name }} · {{ $vehicle->registration }}</option>@endforeach</select></label>
<label>From (UTC)<input name="from" type="datetime-local" value="{{ request('from', now()->utc()->subDay()->format('Y-m-d\TH:i')) }}" required></label><label>To (UTC)<input name="to" type="datetime-local" value="{{ request('to', now()->utc()->format('Y-m-d\TH:i')) }}" required></label></div>
<p class="muted">Select up to 24 hours in the past. All times are UTC; Zimbabwe time is UTC +2. Preview retrieves real records without sending them to AI.</p>
<label for="movement-question">What would you like to know?<textarea id="movement-question" name="question" rows="3" maxlength="1200" placeholder="Summarize this vehicle's journeys, long stops and driver assignments.">{{ request('question') }}</textarea></label>
<label class="checkbox"><input name="share_movement" type="checkbox" value="1"> Send this vehicle's report evidence, locations and recorded driver names to the configured AI provider ({{ config('ai.provider') }}) for analysis</label>
<div class="heading-actions"><button class="secondary" name="action" value="preview">Preview reports</button><button class="primary" name="action" value="ai" @disabled(!$ready)>Analyze with AI ✦</button></div>@if(!$ready)<p class="notice">AI provider not configured. Report previews work without an AI key.</p>@endif</form></section>
@if($evidence)
<section class="panel fleet-section"><h2>{{ $evidence['vehicle']['name'] }} · {{ $evidence['vehicle']['registration'] }}</h2><p class="muted">{{ $evidence['from_utc'] }} → {{ $evidence['to_utc'] }} · Retrieved {{ $evidence['retrieved_at_utc'] }}</p><p><strong>{{ count($evidence['trips']) }}</strong> displayed trips · <strong>{{ $evidence['displayed_distance_km'] }} km</strong> reported distance across displayed trips · <strong>{{ count($evidence['stops']) }}</strong> stops · <strong>{{ count($evidence['events']) }}</strong> events</p>
@foreach($evidence['truncated'] as $section => $truncated)@if($truncated)<p class="notice">{{ ucfirst(str_replace('_', ' ', $section)) }} exceeded the 50-record limit. Shorten the time range for a complete view.</p>@endif
@endforeach
@if($answer)<div class="assistant-answer"><span class="hero-kicker">AI INTERPRETATION</span><p>{{ $answer }}</p></div>@endif
<details><summary>Coverage and interpretation limits</summary><ul>@foreach($evidence['limitations'] as $limitation)<li>{{ $limitation }}</li>@endforeach</ul></details></section>
@foreach(['trips' => 'Trips', 'stops' => 'Stops', 'events' => 'Events', 'driver_assignments' => 'Recorded driver assignments'] as $key => $label)
<section class="panel fleet-section"><h2>{{ $label }}</h2>@forelse($evidence[$key] as $record)<details class="assistant-question"><summary><strong>[{{ $record['reference'] }}]</strong> {{ $record['start_utc'] ?? $record['assigned_at_utc'] }} @if(isset($record['driver_name'])) · {{ $record['driver_name'] }}@endif @if(isset($record['distance_km'])) · {{ $record['distance_km'] }} km @endif @if(isset($record['type'])) · {{ $record['type'] }}@endif</summary><dl class="workspace-details">@foreach($record as $field => $value)<div><dt>{{ ucwords(str_replace('_', ' ', $field)) }}</dt><dd>{{ $value ?? 'Not reported / open-ended' }}</dd></div>@endforeach</dl></details>@empty<p class="muted">No {{ strtolower($label) }} returned in this window. This does not establish that no movement occurred.</p>@endforelse</section>
@endforeach
@endif
@endsection
