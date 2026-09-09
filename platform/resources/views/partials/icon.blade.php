@php
    $paths = [
        'overview' => 'M3 3h7v7H3z M14 3h7v7h-7z M3 14h7v7H3z M14 14h7v7h-7z',
        'map' => 'm3 6 6-3 6 3 6-3v15l-6 3-6-3-6 3z M9 3v15 M15 6v15',
        'vehicle' => 'm5 8 2-5h10l2 5 M4 8h16v10H4z M7 18v3 M17 18v3 M7 12h2 M15 12h2',
        'device' => 'M8 3h8v18H8z M11 17h2 M11 7h2',
        'users' => 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2 M9 3a4 4 0 1 0 0 8 4 4 0 0 0 0-8 M17 4a4 4 0 0 1 0 7 M22 21v-2a4 4 0 0 0-3-3.87',
        'driver' => 'M12 3a4 4 0 1 0 0 8 4 4 0 0 0 0-8 M4 21v-2a6 6 0 0 1 6-6h4a6 6 0 0 1 6 6v2',
        'signal' => 'M4 18v3 M9 13v8 M14 8v13 M19 3v18',
        'building' => 'M4 21V3h12v18 M16 9h4v12 M2 21h20 M8 7h4 M8 11h4 M8 15h4 M9 21v-3h2v3',
        'arrow' => 'M5 12h14 M13 6l6 6-6 6',
        'plus' => 'M12 5v14 M5 12h14',
        'check' => 'm5 12 4 4L19 6',
        'shield' => 'm12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6z M8 12l3 3 5-6',
        'clock' => 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18 M12 7v5l3 2',
        'warning' => 'M12 3 2 21h20z M12 9v5 M12 17v1',
        'moon' => 'M20 15A9 9 0 0 1 9 4a9 9 0 1 0 11 11',
        'menu' => 'M4 6h16 M4 12h16 M4 18h16',
        'logout' => 'M9 4H4v16h5 M9 12h12 M17 8l4 4-4 4',
        'refresh' => 'M20 8A9 9 0 0 0 4 7 M4 3v4h4 M4 16a9 9 0 0 0 16 1 M20 21v-4h-4',
        'focus' => 'M8 3H3v5 M16 3h5v5 M3 16v5h5 M21 16v5h-5 M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8',
        'expand' => 'M8 3H3v5 M16 3h5v5 M3 16v5h5 M21 16v5h-5',
    ];
@endphp
<svg class="ui-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $paths[$name] ?? $paths['overview'] }}"/></svg>
