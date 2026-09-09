@extends('layouts.app')
@section('title', 'System health')
@section('content')
<div class="page-heading"><div><div class="eyebrow accent">PLATFORM OPERATIONS</div><h1>System health</h1><p class="muted">Checked {{ now()->format('d M Y H:i:s') }}. Reload to check again.</p></div></div>
<section class="panel"><table class="fleet-table"><thead><tr><th>Service</th><th>Status</th></tr></thead><tbody>@foreach($checks as $name => $state)<tr><td>{{ $name }}</td><td>{{ $state }}</td></tr>@endforeach</tbody></table><p class="muted">Backups and HTTPS renewal must also be monitored on the host. This screen checks application services only.</p></section>
@endsection
