import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import '../css/tracking.css';

const root = document.querySelector('[data-tracking-url]');
if (root) {
    const find = selector => root.querySelector(selector);
    const map = L.map('fleet-map').setView([-17.8252, 31.0335], 11);
    const tiles = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
    }).addTo(map);
    let vehicles = [], page = 1, lastPage = 1, fitted = false, selected = null;
    const markers = new Map();
    const message = find('[data-map-message]');
    let loading = false;
    const loader = document.createElement('div');
    loader.className = 'map-loading'; loader.hidden = true;
    loader.setAttribute('role', 'status');
    const spinner = document.createElement('span'); spinner.className = 'tevera-spinner'; spinner.setAttribute('aria-hidden', 'true');
    const loaderLabel = document.createElement('span'); loaderLabel.textContent = 'Updating vehicle locations';
    loader.append(spinner, loaderLabel); find('.map-stage').append(loader);
    const colors = {moving: '#0d947f', stopped: '#5078bf', idling: '#d29b24', offline: '#8a949e', unknown: '#8a949e'};
    const text = (tag, value, className) => { const el = document.createElement(tag); el.textContent = value; if (className) el.className = className; return el; };
    const speed = v => Math.max(0, Number(v.position?.speed || 0) * 1.852);
    const state = v => v.status !== 'online' ? (v.status === 'offline' ? 'offline' : 'unknown') : speed(v) > 1 ? 'moving' : v.position?.attributes?.ignition ? 'idling' : 'stopped';
    const valid = v => v.position && v.position.valid !== false && Number.isFinite(v.position.latitude) && Number.isFinite(v.position.longitude) && Math.abs(v.position.latitude) <= 90 && Math.abs(v.position.longitude) <= 180;
    const date = value => value ? new Date(value).toLocaleString() : 'Not reported';
    const bool = value => value === undefined ? 'Not reported' : value ? 'On' : 'Off';
    function details(v) {
        selected = v.id;
        const panel = find('[data-map-details]'); panel.replaceChildren(text('strong', `${v.name} · ${v.registration}`));
        const p = v.position, a = p?.attributes || {};
        const fields = p ? {
            Status: state(v), Speed: `${speed(v).toFixed(1)} km/h`, 'GPS time': date(p.fixTime), 'Server time': date(p.serverTime),
            Latitude: p.latitude, Longitude: p.longitude, Altitude: `${p.altitude ?? '—'} m`, Direction: `${p.course ?? '—'}°`,
            Ignition: bool(a.ignition), Motion: bool(a.motion), Battery: a.batteryLevel !== undefined ? `${a.batteryLevel}%` : 'Not reported', Satellites: a.sat ?? 'Not reported',
        } : {Status: 'Awaiting first GPS position', 'Last update': date(v.lastUpdate)};
        const list = text('dl', '', 'gps-details');
        for (const [label, value] of Object.entries(fields)) { const item = text('div', ''); item.append(text('dt', label), text('dd', String(value))); list.append(item); }
        panel.append(list);
    }
    function fit() { const bounds = L.latLngBounds([...markers.values()].map(marker => marker.getLatLng())); if (bounds.isValid()) map.fitBounds(bounds, {padding: [35, 35], maxZoom: 16}); }
    function render() {
        const search = find('[data-map-search]').value.toLowerCase();
        const filter = find('[data-map-filter]').value;
        const shown = vehicles.filter(v => `${v.name} ${v.registration}`.toLowerCase().includes(search) && (filter === 'all' || filter === state(v)));
        const ids = new Set(shown.filter(valid).map(v => v.id));
        for (const [id, marker] of markers) if (!ids.has(id)) { map.removeLayer(marker); markers.delete(id); }
        const list = find('[data-map-vehicles]'); list.replaceChildren();
        for (const v of shown) {
            const status = state(v);
            const row = text('button', '', `vehicle-row status-${status}`); row.type = 'button';
            row.append(text('strong', v.name), text('span', v.registration), text('small', `${status} · ${speed(v).toFixed(1)} km/h`), text('small', valid(v) ? date(v.position.fixTime) : 'Awaiting GPS position'));
            row.addEventListener('click', () => { details(v); if (valid(v)) { map.setView([v.position.latitude, v.position.longitude], 16); markers.get(v.id)?.openPopup(); } });
            list.append(row);
            if (valid(v)) {
                let marker = markers.get(v.id);
                if (!marker) { marker = L.circleMarker([v.position.latitude, v.position.longitude], {radius: 9, weight: 3, fillOpacity: 1, color: '#ffffff'}).addTo(map); markers.set(v.id, marker); }
                marker.setLatLng([v.position.latitude, v.position.longitude]).setStyle({fillColor: colors[status]});
                const popup = text('div', ''); popup.append(text('strong', v.name), text('p', `${v.registration} · ${speed(v).toFixed(1)} km/h`));
                marker.bindPopup(popup); marker.off('click'); marker.on('click', () => details(v));
            }
        }
        if (!vehicles.length) { message.textContent = 'No vehicles are assigned yet. The map is ready; markers appear after a linked tracker sends GPS data.'; }
        else if (!shown.length) { message.textContent = 'No vehicles match these filters.'; }
        else { message.textContent = `${shown.length} vehicles on this page · ${markers.size} with GPS locations`; }
        if (selected !== null) { const vehicle = vehicles.find(v => v.id === selected); if (vehicle) details(vehicle); }
        if (!fitted && markers.size) { fit(); fitted = true; }
    }
    async function refresh() {
        if (loading) return;
        loading = true; loader.hidden = false;
        find('[data-map-vehicles]').setAttribute('aria-busy', 'true');
        find('[data-map-prev]').disabled = true; find('[data-map-next]').disabled = true;
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 20000);
        const button = find('[data-map-refresh]'); button.disabled = true; message.textContent = 'Loading positions...';
        try {
            const response = await fetch(`${root.dataset.trackingUrl}?page=${page}`, {headers: {Accept: 'application/json'}, credentials: 'same-origin', signal: controller.signal});
            if ([401, 402, 403].includes(response.status) || response.redirected) { vehicles = []; render(); window.location.assign(response.status === 402 ? '/billing' : '/login'); return; }
            const result = await response.json();
            if (!response.ok) throw new Error(result.message || 'Unable to load vehicle positions.');
            vehicles = result.vehicles; lastPage = result.lastPage;
            find('[data-map-prev]').disabled = page <= 1; find('[data-map-next]').disabled = page >= lastPage;
            find('[data-map-page]').textContent = `${page} / ${lastPage}`;
            find('[data-map-updated]').textContent = `Last fetched ${date(result.fetchedAt)} · Auto-update checks every 15 seconds while enabled`;
            render();
        } catch (error) { message.textContent = `${error.message} Existing markers may be out of date. Try Refresh positions.`; }
        finally {
            clearTimeout(timeout); loading = false; loader.hidden = true; button.disabled = false;
            find('[data-map-vehicles]').removeAttribute('aria-busy');
            find('[data-map-prev]').disabled = page <= 1; find('[data-map-next]').disabled = page >= lastPage;
        }
    }
    tiles.on('tileerror', () => { find('[data-map-updated]').textContent = 'Map tiles could not load. Check your internet connection. Vehicle details remain available.'; });
    find('[data-map-search]').addEventListener('input', render);
    find('[data-map-filter]').addEventListener('change', render);
    find('[data-map-refresh]').addEventListener('click', refresh);
    find('[data-map-fit]').addEventListener('click', fit);
    find('[data-map-prev]').addEventListener('click', () => { if (page > 1) { page--; fitted = false; refresh(); } });
    find('[data-map-next]').addEventListener('click', () => { if (page < lastPage) { page++; fitted = false; refresh(); } });
    find('[data-map-fullscreen]').addEventListener('click', async () => { try { if (document.fullscreenElement) await document.exitFullscreen(); else await root.requestFullscreen(); } catch { message.textContent = 'Full screen is unavailable in this browser.'; } });
    document.addEventListener('fullscreenchange', () => map.invalidateSize());
    new ResizeObserver(() => map.invalidateSize()).observe(find('.map-canvas'));
    refresh();
    let autoTimer;
    const startAuto = () => {
        clearInterval(autoTimer);
        autoTimer = setInterval(() => {
            if (!document.hidden && find('[data-map-auto]').checked && !loading) refresh();
        }, 15000);
    };
    startAuto();
    window.addEventListener('pagehide', () => clearInterval(autoTimer));
    window.addEventListener('pageshow', startAuto);
}
