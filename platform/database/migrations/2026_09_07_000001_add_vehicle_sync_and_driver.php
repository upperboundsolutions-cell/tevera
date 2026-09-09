<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->foreignId('driver_id')->nullable()->constrained()->restrictOnDelete();
            $table->uuid('sync_key')->nullable()->unique();
            $table->string('sync_status', 20)->default('pending')->index();
            $table->string('sync_error')->nullable();
            $table->string('tracker_protocol', 80)->nullable();
        });
        DB::table('vehicles')->orderBy('id')->each(function ($vehicle) {
            DB::table('vehicles')->where('id', $vehicle->id)->update([
                'sync_key' => (string) Str::uuid(),
                'sync_status' => $vehicle->traccar_device_id ? 'synced' : 'pending',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('driver_id');
            $table->dropUnique(['sync_key']);
            $table->dropIndex(['sync_status']);
            $table->dropColumn(['sync_key', 'sync_status', 'sync_error', 'tracker_protocol']);
        });
    }
};
