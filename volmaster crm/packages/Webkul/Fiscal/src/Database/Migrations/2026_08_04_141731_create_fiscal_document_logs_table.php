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
        Schema::create('fiscal_document_logs', function (Blueprint $table) {
            $table->increments('id');

            $table->integer('fiscal_document_id')->unsigned();
            $table->foreign('fiscal_document_id')->references('id')->on('fiscal_documents')->onDelete('cascade');

            $table->string('event');
            $table->json('payload')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fiscal_document_logs');
    }
};
