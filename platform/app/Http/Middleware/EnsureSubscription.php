<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use Closure;
use Illuminate\Http\Request;

class EnsureSubscription
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->user()->role !== Role::SuperAdmin && ! $request->user()->customer->subscriptionAllowsAccess()
            && ! $request->routeIs('billing.*', 'logout')) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Your company subscription has expired. Contact your company administrator.'], 402);
            }

            return redirect()->route('billing.index')->with('status', 'Your company subscription needs renewal.');
        }

        return $next($request);
    }
}
