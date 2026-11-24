<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * NFS-e's Focus NFe response identifies the document with
     * "codigo_verificacao" instead of the NF-e/NFC-e "chave_nfe" -- keeping
     * both columns rather than overloading chave_nfe's meaning per type.
     */
    public function up(): void
    {
        Schema::table('fiscal_documents', function (Blueprint $table) {
            $table->string('codigo_verificacao')->nullable()->after('chave_nfe');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fiscal_documents', function (Blueprint $table) {
            $table->dropColumn('codigo_verificacao');
        });
    }
};
