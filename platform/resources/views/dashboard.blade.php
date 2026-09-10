@extends('layouts.app')
@section('title', 'Overview')
@section('content')
<section class="executive-banner dashboard-banner"><div><span class="hero-kicker">TEVERA / FLEET COMMAND</span><h2>Your fleet.<br>Within reach.</h2><p>Track movement, review journeys and manage your fleet from one workspace.</p><div class="heading-actions"><a class="primary" href="{{ route('tracking') }}">Open vehicle map â†’</a>@can('manage-fleet')<a class="hero-link" href="{{ route('assistant.index') }}">Explore fleet assistant âœ¦</a>@endcan</div></div><div class="hero-emblem" aria-hidden="true">@include('partials.icon', ['name' => 'signal'])<span>TEVERA</span><small>ALWAYS AHEAD</small></div></section>
<nav class="command-launcher" aria-label="Fleet shortcuts">
    <a href="{{ route('tracking') }}"><span class="command-number">01</span>@include('partials.icon', ['name' => 'map'])<span><strong>Tracking map</strong><small>Locate your vehicles</small></span><span aria-hidden="true">&#8599;</span></a>
    <a href="{{ route('history.index') }}"><span class="command-number">02</span>@include('partials.icon', ['name' => 'clock'])<span><strong>Journey replay</strong><small>Explore recorded movement</small></span><span aria-hidden="true">&#8599;</span></a>
    @can('manage-fleet')<a href="{{ route('geofences.index') }}"><span class="command-number">03</span>@include('partials.icon', ['name' => 'shield'])<span><strong>Geofence zones</strong><small>Manage fleet boundaries</small></span><span aria-hidden="true">&#8599;</span></a>@endcan
</nav>
<div class="page-heading dashboard-heading">
    <div><div class="eyebrow accent">FLEET INTELLIGENCE</div><h1>Fleet overview<span class="heading-dot">.</span></h1><p class="muted">Welcome back, {{ auth()->user()->name }}. Here's your fleet at a glance.</p></div>
    <div class="heading-actions"><span class="date-chip">@include('partials.icon', ['name' => 'clock']) {{ now()->format('d M Y') }}</span>@can('create', \App\Models\Vehicle::class)<a class="primary" href="{{ route('vehicles.create') }}">@include('partials.icon', ['name' => 'plus']) Add vehicle</a>@endcan</div>
</div>
<div class="stats-grid premium-stats">
    <a href="{{ route('vehicles.index') }}" class="stat-card"><div class="metric-top"><span>Total vehicles</span><span class="stat-icon blue">@include('partials.icon', ['name' => 'vehicle'])</span></div><strong>{{ number_format($vehicleCount) }}</strong><div class="metric-bottom"><span>In your workspace</span>@include('partials.icon', ['name' => 'arrow'])</div></a>
    <a href="{{ route('vehicles.index', ['status' => 'active']) }}" class="stat-card"><div class="metric-top"><span>Active vehicles</span><span class="stat-icon green">@include('partials.icon', ['name' => 'check'])</span></div><strong>{{ number_format($activeCount) }}</strong><div class="metric-bottom"><span>Enabled for fleet access</span>@include('partials.icon', ['name' => 'arrow'])</div></a>
    <a href="{{ route('devices.index') }}" class="stat-card"><div class="metric-top"><span>GPS devices linked</span><span class="stat-icon violet">@include('partials.icon', ['name' => 'signal'])</span></div><strong>{{ number_format($linkedCount) }}</strong><div class="metric-bottom"><span>Registered with Traccar</span>@include('partials.icon', ['name' => 'arrow'])</div></a>
    <a href="{{ route('vehicles.index') }}" class="stat-card"><div class="metric-top"><span>Sync needs attention</span><span class="stat-icon amber">@include('partials.icon', ['name' => 'warning'])</span></div><strong>{{ number_format($attentionCount) }}</strong><div class="metric-bottom"><span>Pending or failed updates</span>@include('partials.icon', ['name' => 'arrow'])</div></a>
</div>
<section class="panel ops-panel" data-insights-url="{{ route('operations.snapshot') }}"><div class="panel-heading"><h2>Attention needed</h2><button type="button" class="secondary" data-insights-refresh>Refresh</button></div><p class="muted" data-insights-status role="status">Loading fleet status?</p><div class="insight-counts" data-insights-counts></div><ul class="attention-list" data-insights-list></ul></section>
@include('partials.tracking-map')
<div class="overview-grid premium-overview">
    <section class="panel fleet-summary">
        <div class="panel-heading"><div><h2>Your fleet</h2><p class="section-caption">Recently added vehicles</p></div><a class="subtle-link" href="{{ route('vehicles.index') }}">View all @include('partials.icon', ['name' => 'arrow'])</a></div>
        @if($recentVehicles->isEmpty())
        <div class="setup-empty"><div class="setup-icon">@include('partials.icon', ['name' => 'vehicle'])</div><h3>Your next journey starts here</h3><p>Add your first vehicle and connect its GPS tracker to bring your fleet into view.</p>@can('create', \App\Models\Vehicle::class)<a class="primary" href="{{ route('vehicles.create') }}">@include('partials.icon', ['name' => 'plus']) Add your first vehicle</a>@else<p class="empty-access-note">Your administrator can assign vehicles to your account.</p>@endcan</div>
        @else
        <div class="recent-vehicles">@foreach($recentVehicles as $vehicle)<div class="recent-vehicle"><span class="recent-vehicle-icon">@include('partials.icon', ['name' => 'vehicle'])</span><div><strong>{{ $vehicle->name }}</strong><small>{{ $vehicle->registration }} Â· {{ $vehicle->customer->name }}</small></div><span class="badge {{ $vehicle->sync_status === 'synced' ? '' : 'neutral' }}">{{ $vehicle->sync_status === 'synced' ? 'Device linked' : ($vehicle->sync_status === 'failed' ? 'Sync failed' : 'Sync pending') }}</span>@can('update', $vehicle)<a aria-label="Manage {{ $vehicle->name }}" href="{{ route('vehicles.edit', $vehicle) }}">@include('partials.icon', ['name' => 'arrow'])</a>@endcan</div>@endforeach</div>
        @endif
    </section>
    <section class="panel workspace-summary">
        <div class="panel-heading"><div><h2>Workspace overview</h2><p class="section-caption">Your fleet connection & access</p></div><span class="summary-shield">@include('partials.icon', ['name' => 'shield'])</span></div>
        <div class="connection-progress"><div><span>Vehicle device links</span><strong>{{ $linkedCount }} / {{ $vehicleCount }}</strong></div><progress value="{{ $linkedCount }}" max="{{ max(1, $vehicleCount) }}">{{ $linkedCount }} linked</progress><small>{{ $vehicleCount ? round($linkedCount / $vehicleCount * 100) : 0 }}% of vehicles linked to a GPS device</small></div>
        <dl class="workspace-details"><div><dt>Workspace</dt><dd>{{ auth()->user()->customer?->name ?? 'Platform administration' }}</dd></div><div><dt>Your role</dt><dd>{{ ucwords(str_replace('_', ' ', auth()->user()->role->value)) }}</dd></div><div><dt>Account</dt><dd><span class="tiny-status"></span> Active</dd></div></dl>
        @can('manage-platform')<form class="connection-check" method="POST" action="{{ route('traccar.check') }}">@csrf<div>@include('partials.icon', ['name' => 'signal'])<span>Tracking engine</span></div><button class="secondary">Test connection @include('partials.icon', ['name' => 'arrow'])</button></form>@endcan
    </section>
</div>
@can('manage-fleet')
<div class="quick-links"><a href="{{ route('drivers.index') }}">@include('partials.icon', ['name' => 'driver'])<span><strong>Manage your drivers</strong><small>People behind every journey</small></span>@include('partials.icon', ['name' => 'arrow'])</a><a href="{{ route('protocols.index') }}">@include('partials.icon', ['name' => 'signal'])<span><strong>Connect a GPS tracker</strong><small>Find your protocol & receiver port</small></span>@include('partials.icon', ['name' => 'arrow'])</a></div>
@endcan
@endsection
