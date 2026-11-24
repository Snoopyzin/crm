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
        Schema::create('fiscal_documents', function (Blueprint $table) {
            $table->increments('id');

            $table->integer('quote_id')->unsigned()->nullable();
            $table->foreign('quote_id')->references('id')->on('quotes')->onDelete('set null');

            $table->string('type');
            $table->string('focus_ref')->unique();
            $table->string('status')->default('processando');
            $table->string('chave_nfe')->nullable();
            $table->string('numero')->nullable();
            $table->text('xml_url')->nullable();
            $table->text('pdf_url')->nullable();
            $table->text('last_error')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fiscal_documents');
    }
};
