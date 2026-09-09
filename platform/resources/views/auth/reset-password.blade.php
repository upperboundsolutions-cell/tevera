@extends('layouts.app')
@section('title', 'Choose a password')
@section('content')
<div class="eyebrow accent">ACCOUNT RECOVERY</div>
<h2>Set your new password</h2>
<p class="muted intro">Use at least 12 characters, with upper and lowercase letters, a number and a symbol.</p>
<form method="POST" action="{{ route('password.update') }}" class="form-stack">
    @csrf
    <input type="hidden" name="token" value="{{ $token }}">
    <label for="email">Email address</label><input id="email" name="email" type="email" value="{{ old('email', request('email')) }}" autocomplete="username" required maxlength="255">
    <label for="password">New password</label><input id="password" name="password" type="password" autocomplete="new-password" required minlength="12" maxlength="1024">
    <label for="password_confirmation">Confirm new password</label><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required minlength="12" maxlength="1024">
    <button class="primary">Reset password <span>→</span></button>
</form>
@endsection

