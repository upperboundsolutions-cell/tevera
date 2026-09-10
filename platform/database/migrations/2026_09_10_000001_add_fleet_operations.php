<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fuel_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $t->date('filled_on');
            $t->decimal('litres', 10, 2);
            $t->decimal('odometer', 12, 1);
            $t->unsignedBigInteger('cost_cents');
            $t->string('currency', 3);
            $t->boolean('full_tank')->default(false);
            $t->string('notes', 500)->nullable();
            $t->timestamps();
        });
        Schema::create('maintenance_tasks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $t->string('title', 150);
            $t->date('due_on')->nullable();
            $t->decimal('due_odometer', 12, 1)->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->unsignedBigInteger('cost_cents')->nullable();
            $t->string('currency', 3)->default('USD');
            $t->text('notes')->nullable();
            $t->string('document_path')->nullable();
            $t->timestamps();
        });
        Schema::create('tracking_shares', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('token_hash', 64)->unique();
            $t->string('label', 100);
            $t->timestamp('expires_at');
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
        });
        Schema::create('report_schedules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('frequency', 10);
            $t->timestamp('next_run_at');
            $t->string('last_status')->nullable();
            $t->timestamps();
        });
        Schema::create('maintenance_deliveries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('task_id')->constrained('maintenance_tasks')->cascadeOnDelete();
            $t->date('sent_on');
            $t->string('status')->default('sending');
            $t->string('whatsapp_status')->nullable();
            $t->unique(['user_id', 'task_id', 'sent_on']);
        });
        Schema::table('notification_preferences', function (Blueprint $t) {
            $t->boolean('whatsapp_enabled')->default(false);
            $t->string('whatsapp_number', 20)->nullable();
        });
        Schema::table('notification_deliveries', fn (Blueprint $t) => $t->string('whatsapp_status')->nullable());
    }

    public function down(): void
    {
        Schema::table('notification_deliveries', fn (Blueprint $t) => $t->dropColumn('whatsapp_status'));
        Schema::table('notification_preferences', fn (Blueprint $t) => $t->dropColumn(['whatsapp_enabled', 'whatsapp_number']));
        foreach (['maintenance_deliveries', 'report_schedules', 'tracking_shares', 'maintenance_tasks', 'fuel_entries'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
