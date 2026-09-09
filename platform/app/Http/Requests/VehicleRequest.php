<?php

namespace App\Http\Requests;

use App\Enums\Role;
use App\Models\Vehicle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class VehicleRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['user_ids' => $this->input('user_ids', [])]);
    }

    public function authorize(): bool
    {
        $vehicle = $this->route('vehicle');

        return $vehicle ? Gate::allows('update', $vehicle) : Gate::allows('create', Vehicle::class);
    }

    public function rules(): array
    {
        $vehicle = $this->route('vehicle');
        $customerId = $vehicle?->customer_id ?? ($this->user()->role === Role::SuperAdmin ? $this->input('customer_id') : $this->user()->customer_id);

        return [
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->where('is_active', true), Rule::in([$customerId])],
            'name' => 'required|string|max:255',
            'registration' => ['required', 'string', 'max:80', Rule::unique('vehicles')->where('customer_id', $customerId)->ignore($vehicle?->id)],
            'unique_id' => ['required', 'string', 'max:128', 'not_regex:/[\x00-\x1F\x7F]/', Rule::unique('vehicles')->ignore($vehicle?->id), ...($vehicle ? [Rule::in([$vehicle->unique_id])] : [])],
            'make' => 'nullable|string|max:255', 'model' => 'nullable|string|max:255',
            'year' => 'nullable|integer|min:1900|max:2100', 'vin' => 'nullable|string|max:80',
            'vehicle_type' => 'nullable|string|max:80', 'fuel_type' => 'nullable|string|max:80',
            'tank_capacity' => 'nullable|numeric|min:0|max:99999999', 'odometer' => 'nullable|numeric|min:0|max:999999999999',
            'sim_number' => 'nullable|string|max:40', 'tracker_model' => 'nullable|string|max:255',
            'tracker_protocol' => 'nullable|string|max:80', 'installation_date' => 'nullable|date', 'notes' => 'nullable|string|max:10000',
            'driver_id' => ['nullable', 'integer', Rule::exists('drivers', 'id')->where('customer_id', $customerId)->where('is_active', true)],
            'user_ids' => 'sometimes|array|max:500',
            'user_ids.*' => ['integer', 'distinct', Rule::exists('users', 'id')->where('customer_id', $customerId)->where('is_active', true)],
        ];
    }
}
