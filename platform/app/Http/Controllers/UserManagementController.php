<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Customer;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserManagementController extends Controller
{
    public function index(Request $request)
    {
        $customers = Customer::where('is_active', true)->when($request->user()->role !== Role::SuperAdmin, fn ($q) => $q->whereKey($request->user()->customer_id))->orderBy('name')->get();
        $users = User::with('customer')->when($request->user()->role !== Role::SuperAdmin, fn ($q) => $q->where('customer_id', $request->user()->customer_id))->orderBy('name')->paginate(25);

        return view('fleet.users', compact('customers', 'users'));
    }

    public function store(Request $request, AuditLogger $audit)
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $customerRule = Rule::exists('customers', 'id')->where('is_active', true);
        if ($request->user()->role !== Role::SuperAdmin) {
            $customerRule->where('id', $request->user()->customer_id);
        }
        $data = $request->validate([
            'customer_id' => ['required', 'integer', $customerRule], 'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users',
            'role' => ['required', Rule::in(['admin', 'fleet_manager', 'operator', 'customer'])],
            'password' => ['required', 'confirmed', 'max:1024', Password::min(12)->mixedCase()->numbers()->symbols()],
        ]);
        DB::transaction(function () use ($data, $request, $audit) {
            $user = new User($data);
            $user->customer_id = $data['customer_id'];
            $user->role = Role::from($data['role']);
            $user->save();
            $audit->record('user.created', $request->user(), $user);
        });

        return back()->with('status', 'User created. Assign customer/operator accounts to vehicles from the vehicle edit screen.');
    }

    public function status(Request $request, User $user, AuditLogger $audit)
    {
        abort_if($user->id === $request->user()->id || $user->role === Role::SuperAdmin, 403);
        abort_unless($request->user()->role === Role::SuperAdmin || $request->user()->customer_id === $user->customer_id, 403);
        $request->validate(['is_active' => 'required|boolean']);
        DB::transaction(function () use ($request, $user, $audit) {
            $user->is_active = $request->boolean('is_active');
            $user->remember_token = Str::random(60);
            $user->save();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $audit->record($user->is_active ? 'user.activated' : 'user.deactivated', $request->user(), $user);
        });

        return back()->with('status', 'User account status updated.');
    }
}
