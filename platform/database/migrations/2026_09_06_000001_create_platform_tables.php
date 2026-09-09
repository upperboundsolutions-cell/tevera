<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->nullable();
            $t->string('phone', 40)->nullable();
            $t->boolean('is_active')->default(true)->index();
            $t->timestamps();
        });
        Schema::table('users', function (Blueprint $t) {
            $t->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('role', 30)->default('customer')->index();
            $t->boolean('is_active')->default(true)->index();
        });
        Schema::create('drivers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->string('name');
            $t->string('phone', 40)->nullable();
            $t->string('email')->nullable();
            $t->string('licence_number')->nullable();
            $t->date('licence_expiry')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('vehicles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('traccar_device_id')->nullable()->unique();
            $t->string('name');
            $t->string('registration', 80);
            foreach (['make', 'model', 'vin', 'unique_id', 'vehicle_type', 'fuel_type', 'sim_number', 'tracker_model'] as $field) {
                $t->string($field)->nullable();
            }
            $t->unique('unique_id');
            $t->unsignedSmallInteger('year')->nullable();
            $t->decimal('tank_capacity', 10, 2)->nullable();
            $t->decimal('odometer', 14, 2)->default(0);
            $t->date('installation_date')->nullable();
            $t->text('notes')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['customer_id', 'registration']);
            $t->index(['customer_id', 'is_active']);
        });
        Schema::create('vehicle_user', function (Blueprint $t) {
            $t->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->primary(['vehicle_id', 'user_id']);
        });
        Schema::create('vehicle_driver_history', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            $t->foreignId('driver_id')->constrained()->restrictOnDelete();
            $t->timestamp('assigned_at');
            $t->timestamp('ended_at')->nullable();
            $t->index(['vehicle_id', 'ended_at']);
        });
        Schema::create('alerts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('traccar_event_id')->unique();
            $t->string('type', 80);
            $t->timestamp('occurred_at');
            $t->timestamps();
            $t->index(['customer_id', 'occurred_at']);
        });
        Schema::create('alert_user', function (Blueprint $t) {
            $t->foreignId('alert_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->timestamp('read_at');
            $t->primary(['alert_id', 'user_id']);
        });
        Schema::create('settings', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique();
            $t->json('value')->nullable();
            $t->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $t->string('action', 100)->index();
            $t->string('subject_type')->nullable();
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->string('ip_address', 45)->nullable();
            $t->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        foreach (['audit_logs', 'settings', 'alert_user', 'alerts', 'vehicle_driver_history', 'vehicle_user', 'vehicles', 'drivers'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', function (Blueprint $t) {
            $t->dropConstrainedForeignId('customer_id');
            $t->dropIndex(['role']);
            $t->dropIndex(['is_active']);
            $t->dropColumn(['role', 'is_active']);
        });
        Schema::dropIfExists('customers');
    }
};
