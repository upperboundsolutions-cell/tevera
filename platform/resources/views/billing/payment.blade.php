@extends('layouts.app')
@section('title', 'Payment details')
@section('content')
<div class="page-heading"><div><div class="eyebrow accent">{{ $payment->applied_at ? 'PAYMENT RECEIPT' : 'PAYMENT REQUEST' }}</div><h1>{{ $payment->plan_name }}</h1><p class="muted">Reference: {{ $payment->id }}</p></div><a class="secondary" href="{{ route('billing.index') }}">Back to billing</a></div>
<section class="panel"><h2>{{ $payment->currency }} {{ number_format($payment->amount_cents / 100, 2) }}</h2><p>Merchant: {{ config('paynow.merchant') }}</p><p>Status: <strong>{{ ucfirst($payment->status) }}</strong></p><p>Allowance: {{ $payment->vehicle_limit }} vehicles for {{ $payment->billing_months == 12 ? 'one year' : ($payment->billing_months == 1 ? 'one month' : '30 days') }}</p>@if($payment->gateway_reference)<p>Paynow reference: {{ $payment->gateway_reference }}</p>@endif
@if($payment->applied_at)<p>Paid period: {{ $payment->period_start->format('d M Y H:i') }} to {{ $payment->period_end->format('d M Y H:i') }}</p>@endif
@if(in_array($payment->status, ['unknown', 'initiating']))<p class="notice">Checkout could not be confirmed. Contact support with this reference before trying another payment. This prevents accidental duplicate charges.</p>@endif
@if(in_array($payment->status, ['disputed', 'refunded']))<p class="notice">This payment needs merchant review. Contact support with the reference above.</p>@endif
<div class="heading-actions">@if($payment->status === 'pending' && $payment->checkout_url)<a class="primary" href="{{ $payment->checkout_url }}" rel="noreferrer">Continue to Paynow</a>@endif
@if($payment->poll_url)<form method="POST" action="{{ route('billing.check', $payment) }}">@csrf<button class="secondary">Check payment status</button></form>@endif</div><p class="muted">Returning from Paynow does not mark a payment as paid. Access updates only after server verification.</p></section>
@endsection
