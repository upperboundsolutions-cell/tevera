@extends('layouts.app')
@section('title', 'Company workspace')
@section('content')
<div class="page-heading"><div><div class="eyebrow accent">COMPANY WORKSPACE</div><h1>{{ $customer->name }}</h1><p class="muted">{{ $customer->is_active ? 'Active' : 'Suspended' }} · {{ $customer->vehicles_count }} vehicles · {{ $customer->users_count }} users</p></div><a class="secondary" href="{{ route('customers.index') }}">All companies</a></div>
<section class="panel fleet-section"><h2>Workspace settings</h2><form class="fleet-form" method="POST" action="{{ route('customers.update', $customer) }}">@csrf @method('PUT')
<div class="fleet-grid">
<label>Company name<input name="name" required maxlength="255" value="{{ old('name', $customer->name) }}"></label>
<label>Contact email<input name="email" type="email" maxlength="255" value="{{ old('email', $customer->email) }}"></label>
<label>Phone<input name="phone" maxlength="40" value="{{ old('phone', $customer->phone) }}"></label>
<label>Vehicle limit<input name="vehicle_limit" type="number" min="1" max="1000000" value="{{ old('vehicle_limit', $customer->vehicle_limit) }}" placeholder="Unlimited"></label>
<label>Subscription access<select name="billing_required"><option value="0" @selected(!$customer->billing_required)>Managed / no payment required</option><option value="1" @selected($customer->billing_required)>Require active subscription or trial</option></select></label>
<label>Trial ends<input name="trial_ends_at" type="date" value="{{ old('trial_ends_at', $customer->trial_ends_at?->format('Y-m-d')) }}"></label>
<label>Payment review<select name="billing_review_required"><option value="0" @selected(!$customer->billing_review_required)>Clear — no payment hold</option><option value="1" @selected($customer->billing_review_required)>Hold — merchant review required</option></select></label>
</div><p class="muted">The limit counts all vehicles, including inactive vehicles. Leave blank for unlimited. Lowering it does not remove existing vehicles.</p><button class="primary">Save settings</button></form></section>
<section class="panel fleet-section"><h2>Get this company started</h2><p>1. Create the company administrator in Users & access if you have not already done so.</p><p>2. Add vehicles and register their GPS identifiers.</p><p>3. Share the <a href="{{ route('login') }}">TEVERA login address</a> with the administrator. Their account opens only this company's workspace.</p><div class="heading-actions"><a class="secondary" href="{{ route('users.index') }}">Manage users</a>@if($customer->is_active)<a class="primary" href="{{ route('vehicles.create', ['customer' => $customer->id]) }}">Add vehicle</a>@endif</div></section>
<section class="panel"><h2>Workspace access</h2><p class="muted">Suspending a company signs out its users and blocks access to TEVERA. Vehicle records remain intact and the tracking server continues receiving GPS reports.</p><form method="POST" action="{{ route('customers.status', $customer) }}">@csrf<input type="hidden" name="is_active" value="{{ $customer->is_active ? 0 : 1 }}"><button class="secondary">{{ $customer->is_active ? 'Suspend company access' : 'Restore company access' }}</button></form></section>
@endsection
