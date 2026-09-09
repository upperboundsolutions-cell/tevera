@extends('layouts.app')
@section('title', 'Subscription plans')
@section('content')
<div class="page-heading"><div><div class="eyebrow accent">PLATFORM BILLING</div><h1>Subscription plans</h1><p class="muted">Set your prices before publishing. Currency must match the Paynow integration currency. Monthly and yearly plans use calendar periods.</p></div></div>
@foreach($plans as $plan)<section class="panel fleet-section"><form class="fleet-form" method="POST" action="{{ route('plans.update', $plan) }}">@csrf @method('PUT') @include('billing.plan-fields')<button class="primary">Save plan</button></form></section>@endforeach
<section class="panel"><h2>Create plan</h2><form class="fleet-form" method="POST" action="{{ route('plans.store') }}">@csrf @include('billing.plan-fields', ['plan' => null])<button class="primary">Create plan</button></form></section>
@endsection
