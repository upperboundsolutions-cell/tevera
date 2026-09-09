@php($logoPath = public_path('brand/tevera-logo.png'))
<a class="brand {{ is_file($logoPath) ? 'brand-with-logo' : '' }}" href="{{ $brandUrl }}" aria-label="{{ config('app.name') }} home">
    @if(is_file($logoPath))
        <img class="tevera-logo" src="{{ asset('brand/tevera-logo.png') }}?v={{ filemtime($logoPath) }}" alt="TEVERA Vehicle Tracking — Always Ahead" width="256" height="256">
    @else
        <span class="brand-icon" aria-hidden="true">T</span> {{ config('app.name') }}
    @endif
</a>
<p class="brand-tagline">{{ config('app.tagline') }}</p>
