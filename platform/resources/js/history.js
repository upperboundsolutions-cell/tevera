import 'leaflet/dist/leaflet.css';
import '../css/tracking.css';
import L from 'leaflet';
function mapTiles(map) { const tiles=L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {maxZoom:19, attribution:'&copy; OpenStreetMap contributors'}); let low=false; try { low=localStorage.getItem('tevera-low-data')==='1'; } catch {} if(!low) tiles.addTo(map); window.addEventListener('tevera-data-mode',e=>{if(e.detail) map.removeLayer(tiles); else tiles.addTo(map);}); }
const picker = document.querySelector('[data-geofence-picker]');
if (picker) {
    const map = L.map(picker).setView([-17.8252, 31.0335], 11);
    mapTiles(map);
    const latitude = document.querySelector('input[name="latitude"]'), longitude = document.querySelector('input[name="longitude"]'), radius = document.querySelector('input[name="radius"]');
    let circle;
    const preview = () => {
        if (!latitude.value || !longitude.value || !latitude.validity.valid || !longitude.validity.valid || !radius.validity.valid) return;
        const centre = [Number(latitude.value), Number(longitude.value)];
        if (!circle) circle = L.circle(centre, {radius: Number(radius.value), color: '#1767e8'}).addTo(map);
        circle.setLatLng(centre).setRadius(Number(radius.value));
    };
    map.on('click', event => { latitude.value = event.latlng.lat.toFixed(7); longitude.value = event.latlng.lng.toFixed(7); preview(); });
    [latitude, longitude, radius].forEach(input => input.addEventListener('input', preview));
    new ResizeObserver(() => map.invalidateSize()).observe(picker);
}
const root = document.querySelector('[data-history-url]');
if (root) {
    const find = selector => root.querySelector(selector);
    const map = L.map('history-map').setView([-17.8252, 31.0335], 10);
    mapTiles(map);
    let points = [], route, marker, timer, controller;
    const slider = find('[data-history-slider]'), play = find('[data-history-play]'), status = find('[data-history-status]');
    const pause = () => { clearInterval(timer); timer = null; play.textContent = 'Play'; };
    function show() {
        const point = points[Number(slider.value)]; if (!point) return;
        const latlng = [point.latitude, point.longitude];
        if (!marker) marker = L.circleMarker(latlng, {radius: 9, color: '#fff', fillColor: '#1767e8', fillOpacity: 1}).addTo(map);
        marker.setLatLng(latlng);
        find('[data-history-position]').textContent = `${Number(slider.value) + 1} / ${points.length} Â· ${point.time} Â· ${point.speed_kmh ?? 'Unknown'} km/h Â· ${point.latitude}, ${point.longitude}`;
    }
    function start() {
        pause(); if (!points.length) return;
        if (Number(slider.value) >= points.length - 1) slider.value = 0;
        play.textContent = 'Pause'; show();
        timer = setInterval(() => { slider.value = Number(slider.value) + 1; show(); if (Number(slider.value) >= points.length - 1) pause(); }, Number(find('[data-history-speed]').value));
    }
    play.addEventListener('click', () => timer ? pause() : start());
    slider.addEventListener('input', () => { pause(); show(); });
    find('[data-history-reset]').addEventListener('click', () => { pause(); slider.value = 0; show(); });
    find('[data-history-speed]').addEventListener('change', () => { if (timer) start(); });
    document.addEventListener('visibilitychange', () => { if (document.hidden) pause(); });
    window.addEventListener('pagehide', () => { pause(); controller?.abort(); });
    find('[data-history-form]').addEventListener('submit', async event => {
        event.preventDefault(); pause(); controller?.abort(); controller = new AbortController();
        const current = controller; const timeout = setTimeout(() => current.abort(), 45000);
        points = []; slider.disabled = play.disabled = find('[data-history-reset]').disabled = true;
        if (route) { map.removeLayer(route); route = null; } if (marker) { map.removeLayer(marker); marker = null; }
        find('[data-history-events]').replaceChildren(); find('[data-history-position]').textContent = '';
        status.textContent = 'Loading recorded journeyâ€¦'; find('[data-history-load]').disabled = true;
        try {
            const query = new URLSearchParams(new FormData(event.target));
            const response = await fetch(`${root.dataset.historyUrl}?${query}`, {headers: {Accept: 'application/json'}, signal: current.signal});
            if (response.redirected || response.status === 401) { window.location.assign('/login'); return; }
            const result = await response.json(); if (!response.ok) throw new Error(result.message || 'History unavailable.');
            points = result.points; slider.max = Math.max(0, points.length - 1); slider.value = 0;
            slider.disabled = play.disabled = find('[data-history-reset]').disabled = !points.length;
            if (points.length) { route = L.polyline(points.map(p => [p.latitude, p.longitude]), {color: '#1767e8', weight: 4}).addTo(map); map.fitBounds(route.getBounds(), {padding: [30, 30], maxZoom: 16}); show(); }
            status.textContent = `${points.length} recorded positions Â· ${result.events.length} events.${result.points_truncated || result.events_truncated ? ' Results limited. Shorten the time range.' : ''}${!points.length ? ' No valid GPS positions returned; this does not prove no movement occurred.' : ''}`;
            for (const item of result.events) { const row = document.createElement('tr'); for (const value of [item.time, item.type]) { const cell = document.createElement('td'); cell.textContent = value; row.append(cell); } find('[data-history-events]').append(row); }
        } catch (error) { if (controller === current) status.textContent = current.signal.aborted ? 'Request timed out or cancelled. Try a shorter range.' : error.message; }
        finally { clearTimeout(timeout); if (controller === current) find('[data-history-load]').disabled = false; }
    });
    new ResizeObserver(() => map.invalidateSize()).observe(find('#history-map'));
}
