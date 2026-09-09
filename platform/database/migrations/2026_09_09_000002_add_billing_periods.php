<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Zero preserves the original 30-day terms of historical plans/payments.
        foreach (['plans', 'payments'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->unsignedTinyInteger('billing_months')->default(0));
        }
    }

    public function down(): void
    {
        foreach (['plans', 'payments'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('billing_months'));
        }
    }
};
