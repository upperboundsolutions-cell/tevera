<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="{{ config('app.name') }} — {{ config('app.tagline') }}">
    <meta name="theme-color" content="#0c1427">
    <title>@yield('title', 'Overview') · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
<div class="navigation-progress" data-navigation-progress hidden aria-hidden="true"></div>
<div class="action-loader" data-action-loader role="status" aria-live="polite" hidden><span class="tevera-spinner" aria-hidden="true"></span><span data-action-label>Loading your workspace...</span></div>
<a class="skip-link" href="#main-content">Skip to content</a>
@auth
<div class="workspace">
    <aside class="sidebar" id="navigation" aria-label="Main navigation">
        <div class="sidebar-brand">@include('partials.brand', ['brandUrl' => route('dashboard')])</div>
        <nav class="nav-groups">
            @can('manage-platform')<a class="nav-item" href="{{ route('system.health') }}">@include('partials.icon', ['name' => 'signal'])<span>System health</span></a>@endcan
            <a class="nav-item" href="{{ route('billing.index') }}">@include('partials.icon', ['name' => 'shield'])<span>Subscription & billing</span></a>
            @can('manage-users')<a class="nav-item" href="{{ route('audit.index') }}">@include('partials.icon', ['name' => 'clock'])<span>Audit log</span></a>@endcan
            <div class="nav-label">WORKSPACE</div>
            @can('manage-fleet')<a class="nav-item" href="{{ route('readiness.index') }}">@include('partials.icon', ['name' => 'check'])<span>Getting started</span></a>@endcan
            <a class="nav-item" href="{{ route('notifications.index') }}">@include('partials.icon', ['name' => 'signal'])<span>Notifications</span></a>
            <a class="nav-item" href="{{ route('history.index') }}">@include('partials.icon', ['name' => 'clock'])<span>Playback & events</span></a>
            @can('manage-fleet')<a class="nav-item" href="{{ route('geofences.index') }}">@include('partials.icon', ['name' => 'map'])<span>Geofences</span></a>@endcan
            @can('manage-fleet')<a class="nav-item" href="{{ route('movement.index') }}">@include('partials.icon', ['name' => 'map'])<span>Movement analysis</span></a>@endcan
            @can('manage-fleet')<a class="nav-item ai-nav" href="{{ route('assistant.index') }}"><span aria-hidden="true">✦</span><span>Fleet assistant</span><small>AI</small></a>@endcan
            <a class="nav-item" href="{{ route('dashboard') }}">@include('partials.icon', ['name' => 'overview'])<span>Overview</span></a>
            <a class="nav-item" href="{{ route('tracking') }}">@include('partials.icon', ['name' => 'map'])<span>Vehicle map</span></a>
            <div class="nav-label">FLEET MANAGEMENT</div>
            <a class="nav-item" href="{{ route('vehicles.index') }}">@include('partials.icon', ['name' => 'vehicle'])<span>Vehicles</span></a>
            <a class="nav-item" href="{{ route('devices.index') }}">@include('partials.icon', ['name' => 'device'])<span>GPS devices</span></a>
            @can('manage-fleet')
            <a class="nav-item" href="{{ route('drivers.index') }}">@include('partials.icon', ['name' => 'driver'])<span>Drivers</span></a>
            @endcan
            @can('manage-users')
            <a class="nav-item" href="{{ route('users.index') }}">@include('partials.icon', ['name' => 'users'])<span>Users & access</span></a>
            @endcan
            @can('manage-platform')
            <a class="nav-item" href="{{ route('customers.index') }}">@include('partials.icon', ['name' => 'building'])<span>Customers</span></a>
            @endcan
            @can('manage-fleet')
            <div class="nav-label">CONFIGURATION</div>
            <a class="nav-item" href="{{ route('protocols.index') }}">@include('partials.icon', ['name' => 'signal'])<span>Tracker protocols</span></a>
            <a class="nav-item" href="{{ route('tracker.setup') }}">@include('partials.icon', ['name' => 'device'])<span>Tracker setup guide</span></a>
            @endcan
        </nav>
        <div class="sidebar-bottom">
            <div class="workspace-emblem">@include('partials.icon', ['name' => 'shield'])</div>
            <div><strong>{{ auth()->user()->customer?->name ?? 'Platform workspace' }}</strong><small>{{ ucwords(str_replace('_', ' ', auth()->user()->role->value)) }}</small></div>
        </div>
        <p class="sidebar-signature">TEVERA <span>Always ahead.</span></p>
    </aside>
    <div class="main">
        <header class="topbar">
            <div class="topbar-location">
                <button class="mobile-toggle icon-button" type="button" aria-label="Toggle navigation" aria-controls="navigation" aria-expanded="false">@include('partials.icon', ['name' => 'menu'])</button>
                <span class="breadcrumb">Workspace <span>/</span> <strong>@yield('title', 'Overview')</strong></span>
            </div>
            <div class="topbar-tools">
                <form class="global-search" action="{{ route('vehicles.index') }}" method="GET" role="search">
                    <label class="sr-only" for="global-search">Search your fleet</label>
                    <input id="global-search" name="search" placeholder="Search your fleet..." maxlength="128">
                    <button type="submit" aria-label="Search fleet">@include('partials.icon', ['name' => 'arrow'])</button>
                </form>
                <button class="icon-button theme-toggle" type="button" aria-label="Switch to dark mode" title="Switch to dark mode" data-theme-toggle>@include('partials.icon', ['name' => 'moon'])</button>
                <details class="profile-menu">
                    <summary><span class="avatar">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span><span class="profile-name">{{ auth()->user()->name }}<small>{{ auth()->user()->role === \App\Enums\Role::SuperAdmin ? 'Administrator' : ucwords(str_replace('_', ' ', auth()->user()->role->value)) }}</small></span><span class="profile-chevron">⌄</span></summary>
                    <div class="profile-dropdown"><strong>{{ auth()->user()->name }}</strong><span>{{ auth()->user()->email }}</span><form method="POST" action="{{ route('logout') }}">@csrf<button type="submit">@include('partials.icon', ['name' => 'logout']) Sign out</button></form></div>
                </details>
            </div>
        </header>
        <main class="content" id="main-content">
            @include('partials.messages')
            @yield('content')
            <footer class="workspace-footer"><span>TEVERA <span class="footer-dot">·</span> Vehicle Tracking & Fleet Management</span><span>Always ahead.</span></footer>
        </main>
    </div>
</div>
@else
<div class="auth-shell">
    <section class="auth-story">
        <div class="auth-brand-row">@include('partials.brand', ['brandUrl' => route('login')])</div>
        <div class="story-content">
            <div class="story-kicker"><span></span> YOUR FLEET. YOUR PERSPECTIVE.</div>
            <h1>Every vehicle.<br>Every journey.<br><em>In focus.</em></h1>
            <p>A clearer view of your fleet. A simpler way to manage the people and vehicles that keep your business moving.</p>
            <div class="story-features"><span>@include('partials.icon', ['name' => 'map']) Vehicle tracking</span><span>@include('partials.icon', ['name' => 'vehicle']) Fleet management</span><span>@include('partials.icon', ['name' => 'shield']) Controlled access</span></div>
        </div>
        <div class="journey-illustration" aria-hidden="true"><div class="journey-grid"></div><div class="journey-road road-one"></div><div class="journey-road road-two"></div><span class="journey-node node-start"></span><span class="journey-node node-finish"></span><div class="journey-pin">@include('partials.icon', ['name' => 'vehicle'])</div><span class="journey-label">ALWAYS AHEAD</span></div>
        <div class="story-footer"><span>TEVERA</span> Vehicle Tracking & Fleet Management</div>
    </section>
    <main class="auth-form-panel" id="main-content">
        <div class="auth-topline"><span>Your fleet workspace</span><button class="icon-button theme-toggle" type="button" aria-label="Switch to dark mode" title="Switch to dark mode" data-theme-toggle>@include('partials.icon', ['name' => 'moon'])</button></div>
        <div class="auth-card">
            <div class="auth-welcome-icon">@include('partials.icon', ['name' => 'shield'])</div>
            @include('partials.messages')
            @yield('content')
            <div class="auth-trust">@include('partials.icon', ['name' => 'shield']) <span>Secure access to your fleet workspace</span></div>
            <p class="auth-footnote">Need access? Contact your fleet administrator.</p>
        </div>
        <div class="auth-bottomline">TEVERA © {{ date('Y') }} <span>Always ahead.</span></div>
    </main>
</div>
@endauth
</body>
</html>
