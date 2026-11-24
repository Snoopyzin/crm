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
        Schema::create('tenants', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('subdomain')->unique();
            $table->string('admin_name');
            $table->string('admin_email');
            $table->string('db_host')->nullable();
            $table->string('db_name');
            $table->string('db_username');
            $table->text('db_password');
            $table->string('status')->default('pending');
            $table->string('provisioning_step')->nullable();
            $table->text('provisioning_error')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
