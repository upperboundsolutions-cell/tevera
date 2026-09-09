<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request, AuditLogger $audit): RedirectResponse
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $data = $request->validate(['email' => 'required|email|max:255', 'password' => 'required|string|max:1024', 'remember' => 'sometimes|boolean']);
        $key = 'login:'.hash('sha256', $data['email'].'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['email' => 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.']);
        }
        if (! Auth::attempt(['email' => $data['email'], 'password' => $data['password'], 'is_active' => true], $request->boolean('remember'))) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['email' => 'These credentials do not match an available account.']);
        }
        if (! $request->user()->canAccessPlatform()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['email' => 'These credentials do not match an available account.']);
        }
        RateLimiter::clear($key);
        $request->session()->regenerate();
        $audit->record('auth.login', $request->user());

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request, AuditLogger $audit): RedirectResponse
    {
        $audit->record('auth.logout', $request->user());
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    public function forgotPassword(Request $request): RedirectResponse
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $data = $request->validate(['email' => 'required|email|max:255']);
        $user = User::where('email', $data['email'])->first();
        if ($user?->canAccessPlatform()) {
            Password::sendResetLink($data);
        }

        return back()->with('status', 'If that account is available, a password reset link has been sent.');
    }

    public function resetPassword(Request $request, AuditLogger $audit): RedirectResponse
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'token' => 'required|string', 'email' => 'required|email|max:255',
            'password' => ['required', 'confirmed', 'max:1024', PasswordRule::min(12)->mixedCase()->numbers()->symbols()],
        ]);
        $user = User::where('email', $data['email'])->first();
        if (! $user?->canAccessPlatform()) {
            throw ValidationException::withMessages(['email' => 'This password reset link is invalid or expired.']);
        }
        $status = Password::reset($data, function (User $user, string $password) use ($audit) {
            DB::transaction(function () use ($user, $password, $audit) {
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                DB::table('sessions')->where('user_id', $user->id)->delete();
                $audit->record('auth.password_reset', $user);
            });
            event(new PasswordReset($user));
        });
        if ($status !== Password::PasswordReset) {
            throw ValidationException::withMessages(['email' => 'This password reset link is invalid or expired.']);
        }

        return redirect()->route('login')->with('status', 'Password reset. Sign in with your new password.');
    }
}
