<section class="tracking-shell" data-tracking-url="{{ route('tracking.positions') }}">
    <div class="map-footer"><label class="checkbox"><input type="checkbox" data-map-auto checked> Auto-update every 15 seconds</label><a href="{{ route('history.index') }}">Journey playback & events →</a></div>
    <div class="tracking-toolbar"><div class="map-section-title"><span class="map-title-icon">@include('partials.icon', ['name' => 'map'])</span><div><h2>Vehicle locations</h2><p>Latest reported GPS positions</p></div></div><div class="tracking-actions"><button class="secondary" type="button" data-map-fit>@include('partials.icon', ['name' => 'focus']) Fit vehicles</button><button class="secondary map-fullscreen-button" type="button" data-map-fullscreen>@include('partials.icon', ['name' => 'expand']) <span>Full screen</span></button><button class="primary" type="button" data-map-refresh>@include('partials.icon', ['name' => 'refresh']) Refresh positions</button></div></div>
    <div class="tracking-body">
        <aside class="vehicle-sidebar">
            <div class="vehicle-sidebar-title"><h3>Vehicle list</h3><span>YOUR FLEET</span></div>
            <label class="sr-only" for="vehicle-search">Find a vehicle</label><input id="vehicle-search" type="search" placeholder="Search name or registration..." data-map-search>
            <label class="sr-only" for="vehicle-status">Status</label><select id="vehicle-status" data-map-filter><option value="all">All vehicle statuses</option><option value="moving">Moving</option><option value="stopped">Stopped</option><option value="idling">Idling</option><option value="offline">Offline</option><option value="unknown">Unknown</option></select>
            <div class="map-list-message"><span class="list-message-icon">@include('partials.icon', ['name' => 'vehicle'])</span><p class="map-message" data-map-message role="status">Loading your vehicles...</p></div>
            <div data-map-vehicles></div>
            <div class="map-pagination"><button class="secondary" type="button" data-map-prev disabled>Previous</button><span data-map-page></span><button class="secondary" type="button" data-map-next disabled>Next</button></div>
        </aside>
        <div class="map-stage"><div class="map-canvas" id="fleet-map" aria-label="Vehicle location map"></div><div class="map-legend" aria-label="Vehicle status legend"><span><i class="legend-dot moving"></i>Moving</span><span><i class="legend-dot stopped"></i>Stopped</span><span><i class="legend-dot idling"></i>Idling</span><span><i class="legend-dot offline"></i>Offline</span></div></div>
    </div>
    <div class="map-details" data-map-details>Select a vehicle to view its latest reported GPS details.</div>
    <div class="map-footer"><span data-map-updated>Positions update every 15 seconds while auto-update is enabled.</span><span>Speed in km/h</span></div>
</section>
