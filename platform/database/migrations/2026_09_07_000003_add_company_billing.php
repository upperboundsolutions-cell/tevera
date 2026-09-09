<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', fn (Blueprint $table) => $table->string('subject_reference')->nullable());
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('amount_cents');
            $table->string('currency', 3);
            $table->unsignedInteger('vehicle_limit');
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('plan_id')->nullable()->constrained();
            $table->boolean('billing_required')->default(false);
            $table->boolean('billing_review_required')->default(false);
            $table->timestamp('subscription_expires_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
        });
        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('customer_id')->constrained();
            $table->foreignId('plan_id')->constrained();
            $table->string('plan_name');
            $table->unsignedInteger('vehicle_limit');
            $table->unsignedInteger('amount_cents');
            $table->string('currency', 3);
            $table->string('status')->default('initiating');
            $table->text('poll_url')->nullable();
            $table->text('checkout_url')->nullable();
            $table->string('gateway_reference')->nullable()->unique();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->timestamps();
            $table->index(['customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', fn (Blueprint $table) => $table->dropColumn('subject_reference'));
        Schema::dropIfExists('payments');
        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('plan_id');
            $table->dropColumn(['billing_required', 'billing_review_required', 'subscription_expires_at', 'trial_ends_at']);
        });
        Schema::dropIfExists('plans');
    }
};
