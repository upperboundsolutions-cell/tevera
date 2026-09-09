@extends('layouts.app')
@section('title', 'Sign in')
@section('content')
<div class="eyebrow accent">WELCOME BACK</div>
<h2>Sign in to your workspace</h2>
<p class="muted intro">Your fleet operations start here.</p>
<form method="POST" action="{{ route('login') }}" class="form-stack">
    @csrf
    <label for="email">Email address</label>
    <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus maxlength="255" placeholder="you@company.com">
    <label for="password">Password</label>
    <input id="password" name="password" type="password" autocomplete="current-password" required maxlength="1024" placeholder="Enter your password">
    <div class="form-row"><label class="checkbox"><input type="checkbox" name="remember" value="1" @checked(old('remember'))> Remember me</label><a href="{{ route('password.request') }}">Forgot password?</a></div>
    <button class="primary" type="submit">Sign in <span>→</span></button>
</form>
@endsection

