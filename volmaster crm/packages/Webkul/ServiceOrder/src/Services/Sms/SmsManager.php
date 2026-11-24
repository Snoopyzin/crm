<?php

namespace Webkul\ServiceOrder\Services\Sms;

use Illuminate\Support\Str;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Models\SmsLog;
use Webkul\ServiceOrder\Models\WorkshopAlert;
use Webkul\ServiceOrder\Services\WorkshopSettings;

/**
 * Envio de SMS de reserva: aplica o aceite do colaborador e o limite mensal
 * da oficina, e registra cada tentativa (com custo) em sms_logs.
 */
class SmsManager
{
    /** Gateway usado nos testes; null = escolhe pelo provedor configurado. */
    protected static ?SmsGateway $fake = null;

    public static function fake(?SmsGateway $gateway): void
    {
        static::$fake = $gateway;
    }

    public function gateway(): SmsGateway
    {
        if (static::$fake) {
            return static::$fake;
        }

        return match (WorkshopSettings::get('sms_provedor', 'log')) {
            'twilio' => new TwilioSmsGateway,
            default => new LogSmsGateway,
        };
    }

    /**
     * Envia o SMS do alerta. Retorna o status gravado: enviado, simulado,
     * falhou ou bloqueado.
     */
    public function sendAlert(WorkshopAlert $alert): string
    {
        $user = $alert->user;

        $text = $this->textFor($alert);

        $base = ['alert_id' => $alert->id, 'user_id' => $alert->user_id, 'mensagem' => $text];

        if (! $user?->celular || ! $user->sms_aceite_em) {
            $this->log($base + ['telefone' => (string) $user?->celular, 'provedor' => '-', 'status' => 'bloqueado', 'detalhe' => 'Sem celular cadastrado ou sem aceite de SMS.']);

            return 'bloqueado';
        }

        if ($this->monthlyCount() >= (int) WorkshopSettings::get('sms_limite_mensal', 0)) {
            $this->log($base + ['telefone' => $user->celular, 'provedor' => '-', 'status' => 'bloqueado', 'detalhe' => 'Limite mensal de SMS atingido.']);

            return 'bloqueado';
        }

        $gateway = $this->gateway();
        $result = $gateway->send($this->e164($user->celular), $text);

        $status = ! $result['ok'] ? 'falhou' : (! empty($result['simulado']) ? 'simulado' : 'enviado');

        $this->log($base + [
            'telefone' => $user->celular,
            'provedor' => $gateway->name(),
            'status' => $status,
            'custo' => $status === 'enviado' ? (float) WorkshopSettings::get('sms_custo_unitario', 0) : 0,
            'detalhe' => $result['error'] ?? ($result['id'] ?? null),
        ]);

        return $status;
    }

    /**
     * SMS enviados (ou simulados) no mês corrente.
     */
    public function monthlyCount(): int
    {
        return SmsLog::whereIn('status', ['enviado', 'simulado'])
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();
    }

    /**
     * "ALERTA {barreira} OS {numero} {placa}. Venceu {hora}. Resp: {nome}. {link_curto}"
     * -- até 160 caracteres, sem acentos.
     */
    public function textFor(WorkshopAlert $alert): string
    {
        $serviceOrder = $alert->service_order_id ? ServiceOrder::with('vehicle')->find($alert->service_order_id) : null;

        $barreira = Str::ascii((string) $alert->titulo);
        $numero = $alert->service_order_id ?: '-';
        $placa = Str::ascii((string) ($serviceOrder?->vehicle?->placa ?? '-'));
        $hora = ($alert->vencido_em ?? $alert->disparado_em ?? now())->format('H:i');
        $nome = Str::ascii((string) ($alert->responsavel ?? ''));
        $link = $alert->service_order_id ? url('/os/'.$alert->service_order_id) : url('/');

        $montar = fn (string $b, string $n) => "ALERTA {$b} OS {$numero} {$placa}. Venceu {$hora}. Resp: {$n}. {$link}";

        $text = $montar($barreira, $nome);

        // Encurta primeiro o nome do responsável e depois o da barreira.
        while (strlen($text) > 160 && strlen($nome) > 6) {
            $nome = substr($nome, 0, -2);
            $text = $montar($barreira, $nome);
        }

        while (strlen($text) > 160 && strlen($barreira) > 8) {
            $barreira = substr($barreira, 0, -2);
            $text = $montar($barreira, $nome);
        }

        return substr($text, 0, 160);
    }

    protected function e164(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);

        // 12 ou 13 dígitos começando em 55 já trazem o código do país; com 10 ou 11 dígitos o 55 é o DDD (RS).
        return in_array(strlen($digits), [12, 13], true) && str_starts_with($digits, '55') ? '+'.$digits : '+55'.$digits;
    }

    protected function log(array $data): void
    {
        SmsLog::create($data + ['custo' => 0, 'detalhe' => null]);
    }
}
