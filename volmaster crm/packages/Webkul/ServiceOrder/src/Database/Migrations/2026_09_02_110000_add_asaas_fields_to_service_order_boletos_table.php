<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('service_order_boletos', function (Blueprint $table) {
            $table->string('asaas_payment_id')->nullable()->after('gateway');
            $table->string('billing_type')->nullable()->after('asaas_payment_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_order_boletos', function (Blueprint $table) {
            $table->dropColumn(['asaas_payment_id', 'billing_type']);
        });
    }
};
