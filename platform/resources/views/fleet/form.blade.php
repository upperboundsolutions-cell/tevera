@extends('layouts.app')
@section('title', $vehicle->exists ? 'Manage vehicle' : 'Add vehicle')
@section('content')
<div class="page-heading"><div><div class="eyebrow accent">FLEET MANAGEMENT</div><h1>{{ $vehicle->exists ? $vehicle->name : 'Add vehicle & GPS tracker' }}</h1><p class="muted">Register any device supported by Traccar using its exact tracker identifier.</p></div><a class="secondary" href="{{ route('vehicles.index') }}">Back to vehicles</a></div>
@if(!$vehicle->exists)
<section class="panel fleet-section"><h2>1. Choose the customer workspace</h2>
@if($customers->isEmpty())<p class="muted">Create a customer before adding their fleet.</p>@can('manage-platform')<a class="primary" href="{{ route('customers.index') }}">Create customer</a>@endcan
@else
<form action="{{ route('vehicles.create') }}" method="GET" class="fleet-filter"><label for="workspace-customer">Customer<select id="workspace-customer" name="customer" required><option value="">Choose a customer</option>@foreach($customers as $customer)<option value="{{ $customer->id }}" @selected($customerId == $customer->id)>{{ $customer->name }}</option>@endforeach</select></label><button class="secondary">Load workspace</button>@can('manage-platform')<a href="{{ route('customers.index') }}">+ New customer</a>@endcan</form>
@endif</section>
@endif
@if($customerId)
<form method="POST" action="{{ $vehicle->exists ? route('vehicles.update', $vehicle) : route('vehicles.store') }}" class="fleet-form">
@csrf @if($vehicle->exists) @method('PUT') @endif
<input type="hidden" name="customer_id" value="{{ $customerId }}">
<section class="panel fleet-section"><h2>Vehicle details</h2><p class="muted">Customer: {{ $customers->firstWhere('id', $customerId)?->name }}. Customer ownership stays fixed after registration.</p><div class="fleet-grid">
@foreach(['name' => 'Vehicle name', 'registration' => 'Registration number', 'make' => 'Make', 'model' => 'Model', 'year' => 'Year', 'vin' => 'VIN', 'vehicle_type' => 'Vehicle type', 'fuel_type' => 'Fuel type', 'tank_capacity' => 'Tank capacity (litres)', 'odometer' => 'Business odometer (km)'] as $field => $label)
<label for="vehicle-{{ $field }}">{{ $label }}{{ in_array($field, ['name','registration']) ? ' *' : '' }}<input id="vehicle-{{ $field }}" name="{{ $field }}" value="{{ old($field, $vehicle->$field) }}" type="{{ in_array($field, ['year','tank_capacity','odometer']) ? 'number' : 'text' }}" @if(in_array($field, ['tank_capacity','odometer'])) step="0.01" min="0" @endif @required(in_array($field, ['name','registration'])) maxlength="255"></label>
@endforeach
</div></section>
<section class="panel fleet-section"><h2>GPS tracker</h2><p class="muted">The tracker identifier is fixed after registration to prevent linking the wrong device. No protocol-specific restriction is imposed on device registration.</p><div class="fleet-grid">
<label for="unique-id">Device identifier / IMEI *<input id="unique-id" name="unique_id" value="{{ old('unique_id', $vehicle->unique_id) }}" required maxlength="128" @readonly($vehicle->exists) autocomplete="off"><small>Must exactly match the ID transmitted by the tracker.</small></label>
<label for="tracker-model">Tracker make / model<input id="tracker-model" name="tracker_model" value="{{ old('tracker_model', $vehicle->tracker_model) }}" maxlength="255" placeholder="For example Teltonika FMC920"></label>
<label for="tracker-protocol">Traccar protocol<input id="tracker-protocol" name="tracker_protocol" value="{{ old('tracker_protocol', $vehicle->tracker_protocol) }}" maxlength="80" placeholder="Optional, for example teltonika"><small><a href="{{ route('protocols.index') }}" target="_blank" rel="noopener">Find a protocol and port</a></small></label>
<label for="sim-number">SIM phone number<input id="sim-number" name="sim_number" value="{{ old('sim_number', $vehicle->sim_number) }}" maxlength="40"></label>
<label for="installation-date">Installation date<input id="installation-date" type="date" name="installation_date" value="{{ old('installation_date', $vehicle->installation_date?->format('Y-m-d')) }}"></label>
</div><p class="muted fleet-help">Selecting a protocol here records installation information. The device itself must send data to the matching receiver port on the Traccar server.</p></section>
<section class="panel fleet-section"><h2>Driver & user access</h2><div class="fleet-grid">
<label for="driver">Assigned driver<select id="driver" name="driver_id"><option value="">Unassigned</option>@foreach($drivers as $driver)<option value="{{ $driver->id }}" @selected(old('driver_id', $vehicle->driver_id) == $driver->id)>{{ $driver->name }}</option>@endforeach</select><small><a href="{{ route('drivers.index') }}" target="_blank" rel="noopener">Add a driver</a>, then reload this form.</small></label>
<label for="assigned-users">Users allowed to track this vehicle<select id="assigned-users" name="user_ids[]" multiple size="5">@foreach($users as $user)<option value="{{ $user->id }}" @selected(in_array($user->id, old('user_ids', $assignedUsers)))>{{ $user->name }} — {{ $user->email }}</option>@endforeach</select><small>Ctrl/Cmd-click to select multiple users. Customer administrators and fleet managers already see their customer's fleet.</small></label>
</div><label for="notes">Notes<textarea id="notes" name="notes" rows="4" maxlength="10000">{{ old('notes', $vehicle->notes) }}</textarea></label></section>
<div class="fleet-save"><button class="primary">{{ $vehicle->exists ? 'Save & sync with Traccar' : 'Register vehicle & GPS device' }}</button><a href="{{ route('vehicles.index') }}">Cancel</a></div>
</form>
@endif
@if($vehicle->exists)
<section class="panel fleet-section"><h2>Device connection & status</h2><dl class="gps-details"><div><dt>Traccar device ID</dt><dd>{{ $vehicle->traccar_device_id ?? 'Not yet linked' }}</dd></div><div><dt>Synchronization</dt><dd>{{ ucfirst($vehicle->sync_status ?? 'pending') }}</dd></div><div><dt>Vehicle status</dt><dd>{{ $vehicle->is_active ? 'Active' : 'Deactivated' }}</dd></div></dl>
@if($vehicle->sync_error)<p class="notice error">{{ $vehicle->sync_error }}</p>@endif
<div class="tracking-actions"><form method="POST" action="{{ route('vehicles.sync', $vehicle) }}">@csrf<button class="secondary">Retry sync</button></form><form method="POST" action="{{ route('vehicles.status', $vehicle) }}">@csrf<input type="hidden" name="is_active" value="{{ $vehicle->is_active ? '0' : '1' }}"><button class="secondary">{{ $vehicle->is_active ? 'Deactivate vehicle & tracker' : 'Reactivate vehicle & tracker' }}</button></form><a class="secondary" href="{{ route('tracking') }}">Open vehicle map</a></div><p class="muted fleet-help">Deactivation preserves tracking history. If synchronization fails, the tracker remains unchanged in Traccar until the retry succeeds.</p></section>
@endif
@endsection
