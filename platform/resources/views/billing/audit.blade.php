@extends('layouts.app')
@section('title', 'Audit log')
@section('content')
<div class="page-heading"><div><div class="eyebrow accent">ACCOUNTABILITY</div><h1>Audit log</h1><p class="muted">Company administration and fleet activity.</p></div></div>
<section class="panel"><div class="table-scroll"><table class="fleet-table"><thead><tr><th>Time</th><th>Action</th><th>Actor ID</th><th>Subject ID</th></tr></thead><tbody>@forelse($events as $event)<tr><td>{{ $event->created_at }}</td><td>{{ $event->action }}</td><td>{{ $event->user_id ?? 'System' }}</td><td>{{ $event->subject_reference ?? $event->subject_id }}</td></tr>@empty<tr><td colspan="4">No recorded activity.</td></tr>@endforelse</tbody></table></div>{{ $events->links() }}</section>
@endsection
