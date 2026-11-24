<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row per tenant -- the WhatsApp Business Cloud API credentials
     * (Meta) plus the approved template names used to send the Orçamento
     * and the Pós-Venda satisfaction survey. Nothing here works until the
     * tenant finishes Meta's business verification and gets the templates
     * approved -- same situation as fiscal_settings/Focus NFe.
     */
    public function up(): void
    {
        Schema::create('whatsapp_settings', function (Blueprint $table) {
            $table->increments('id');

            $table->text('access_token')->nullable();
            $table->string('phone_number_id')->nullable();
            $table->string('business_account_id')->nullable();
            $table->string('webhook_verify_token')->nullable();

            $table->string('template_orcamento')->default('orcamento_pronto');
            $table->string('template_pos_venda')->default('pesquisa_satisfacao');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_settings');
    }
};
