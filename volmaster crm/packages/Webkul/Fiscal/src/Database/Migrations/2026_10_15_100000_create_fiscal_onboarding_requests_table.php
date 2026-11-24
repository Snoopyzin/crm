<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A client shop's request to be registered as an "empresa" on Volmaster's
 * Focus NFe account. Read/written through the 'landlord' connection only
 * (see FiscalOnboardingRequest) -- same pattern as the `tenants` table: the
 * migration runs on the default connection, so the table also exists (empty,
 * unused) in each tenant database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_onboarding_requests', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('tenant_id')->unsigned()->index();
            $table->string('status')->default('pending');

            // Encrypted (APP_KEY) -- company data, address, A1 certificate.
            $table->longText('payload');
            $table->longText('certificado')->nullable();
            $table->text('senha_certificado')->nullable();

            $table->string('focus_empresa_id')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_onboarding_requests');
    }
};
