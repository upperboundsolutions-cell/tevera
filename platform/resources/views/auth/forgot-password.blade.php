@extends('layouts.app')
@section('title', 'Reset password')
@section('content')
<div class="eyebrow accent">ACCOUNT RECOVERY</div>
<h2>Forgot your password?</h2>
<p class="muted intro">Enter your account email and we'll send a reset link.</p>
<form method="POST" action="{{ route('password.email') }}" class="form-stack">
    @csrf
    <label for="email">Email address</label><input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus maxlength="255">
    <button class="primary">Send reset link <span>→</span></button>
</form>
<a class="back-link" href="{{ route('login') }}">← Back to sign in</a>
@endsection

