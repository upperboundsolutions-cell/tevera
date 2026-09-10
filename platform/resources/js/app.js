if (document.querySelector('[data-tracking-url]')) import('./tracking');
if (document.querySelector('[data-history-url], [data-geofence-picker]')) import('./history');
import './operations';
import '../css/operations.css';
import '../css/fleet.css';
import '../css/premium.css';
import '../css/motion.css';
import '../css/executive.css';
import '../css/futuristic.css';
import '../css/controls.css';
import '../css/orbital.css';

const progress = document.querySelector('[data-navigation-progress]');
const actionLoader = document.querySelector('[data-action-loader]');
let recoveryTimer;
function resetLoading() {
    clearTimeout(recoveryTimer);
    progress.hidden = true;
    actionLoader.hidden = true;
    document.querySelectorAll('form[aria-busy="true"]').forEach(form => {
        form.removeAttribute('aria-busy');
        form.querySelectorAll('.is-submitting').forEach(button => button.classList.remove('is-submitting'));
    });
}
function showLoading(label) {
    progress.hidden = false;
    actionLoader.querySelector('[data-action-label]').textContent = label;
    actionLoader.hidden = false;
    clearTimeout(recoveryTimer);
    recoveryTimer = setTimeout(resetLoading, 15000);
}
document.addEventListener('click', event => {
    const link = event.target.closest('a[href]');
    if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || link.hasAttribute('download') || (link.target && link.target !== '_self')) return;
    const url = new URL(link.href, location.href);
    if (url.origin !== location.origin || !['http:', 'https:'].includes(url.protocol) || (url.pathname === location.pathname && url.search === location.search)) return;
    showLoading('Opening your workspace...');
});
document.addEventListener('submit', event => {
    const form = event.target;
    if (event.defaultPrevented || form.target === '_blank' || form.method === 'dialog') return;
    if (form.getAttribute('aria-busy') === 'true') { event.preventDefault(); return; }
    form.setAttribute('aria-busy', 'true');
    event.submitter?.classList.add('is-submitting');
    showLoading(form.method.toLowerCase() === 'get' ? 'Finding your results...' : 'Processing your request...');
});
window.addEventListener('pageshow', resetLoading);

const applyTheme = (theme) => {
    document.documentElement.dataset.theme = theme;
    document.querySelectorAll('[data-theme-toggle]').forEach(button => {
        const label = theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode';
        button.setAttribute('aria-label', label);
        button.setAttribute('title', label);
        button.setAttribute('aria-pressed', String(theme === 'dark'));
    });
};
let savedTheme = 'dark';
try { savedTheme = localStorage.getItem('tevera-theme') === 'light' ? 'light' : 'dark'; } catch { /* Storage can be disabled. */ }
applyTheme(savedTheme);
document.querySelectorAll('[data-theme-toggle]').forEach(button => button.addEventListener('click', () => {
    const next = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
    applyTheme(next);
    try { localStorage.setItem('tevera-theme', next); } catch { /* Theme still works for this page. */ }
}));

document.querySelectorAll('.nav-item').forEach(link => {
    const path = new URL(link.href).pathname;
    link.classList.toggle('selected', (window.location.pathname === path && (!new URL(link.href).search || new URL(link.href).search === window.location.search)) || window.location.pathname.startsWith(path + '/'));
});

document.querySelector('.mobile-toggle')?.addEventListener('click', (event) => {
    const open = document.querySelector('#navigation').classList.toggle('is-open');
    event.currentTarget.setAttribute('aria-expanded', String(open));
});
