<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Um alerta por destinatário e por nível disparado.
        Schema::create('workshop_alerts', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Usado pelo service worker para confirmar "entregue" e "aberto"
            // sem precisar de sessão.
            $table->string('token', 40)->unique();

            $table->unsignedInteger('user_id');
            $table->unsignedInteger('service_order_id')->nullable();
            $table->unsignedBigInteger('barrier_instance_id')->nullable();
            $table->string('barrier_code', 40)->nullable();

            // 0 = alerta crítico fora de barreira; 1..3 = nível da barreira.
            $table->unsignedTinyInteger('nivel')->default(1);
            $table->boolean('critico')->default(false);

            $table->string('titulo');
            $table->text('mensagem');
            $table->text('mensagem_resolvido')->nullable();

            // Quem responde pelo prazo (nome) e quando o prazo venceu.
            $table->string('responsavel', 120)->nullable();
            $table->dateTime('vencido_em')->nullable();

            // aberto | resolvido
            $table->string('status', 12)->default('aberto');

            $table->dateTime('disparado_em');
            $table->dateTime('lido_em')->nullable();
            $table->dateTime('resolvido_em')->nullable();

            // Agrupamento: alertas pendentes de push são enviados juntos.
            $table->dateTime('push_enviado_em')->nullable();
            $table->dateTime('push_aberto_em')->nullable();
            // pendente | enviado | entregue | aberto | falhou | sem_dispositivo
            $table->string('push_status', 20)->nullable();
            $table->string('sms_status', 12)->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['barrier_instance_id', 'nivel']);
        });

        // Status por canal: enviado, entregue, aberto, falhou.
        Schema::create('alert_deliveries', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('alert_id');
            $table->index('alert_id');

            // in_app | push | sms
            $table->string('canal', 10);
            // alerta | resolvido
            $table->string('tipo', 10)->default('alerta');
            // enviado | entregue | aberto | falhou
            $table->string('status', 10);
            $table->text('detalhe')->nullable();

            $table->timestamps();
        });

        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedInteger('user_id');
            $table->index('user_id');

            $table->text('endpoint');
            $table->char('endpoint_hash', 64)->unique();
            $table->string('p256dh');
            $table->string('auth');
            $table->string('user_agent')->nullable();

            $table->timestamps();
        });

        Schema::create('sms_logs', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('alert_id')->nullable();
            $table->unsignedInteger('user_id')->nullable();
            $table->string('telefone', 30);
            $table->string('mensagem', 200);
            $table->string('provedor', 20);
            // enviado | falhou | bloqueado
            $table->string('status', 12);
            $table->decimal('custo', 8, 4)->default(0);
            $table->text('detalhe')->nullable();

            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_logs');
        Schema::dropIfExists('push_subscriptions');
        Schema::dropIfExists('alert_deliveries');
        Schema::dropIfExists('workshop_alerts');
    }
};
