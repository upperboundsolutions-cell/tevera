import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import '../css/operations.css';
const root=document.querySelector('#shared-map');
if(root){const map=L.map(root).setView([Number(root.dataset.lat),Number(root.dataset.lng)],14);L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png',{attribution:'&copy; OpenStreetMap contributors'}).addTo(map);L.circleMarker(map.getCenter(),{radius:10}).addTo(map);}
document.querySelector('[data-shared-refresh]')?.addEventListener('click',()=>location.reload());
