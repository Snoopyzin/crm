<?php

namespace Webkul\ServiceOrder\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Models\ServiceOrderEvent;
use Webkul\ServiceOrder\Models\ServiceOrderTermo;
use Webkul\User\Models\User;

/**
 * Aceite do termo de entrada pelo link enviado ao WhatsApp do cliente (B2 do roteiro): o
 * cliente abre o link no celular ou no tablet, confere os dados, assina na tela e aceita; o
 * sistema guarda data, hora, IP e aparelho e libera a OS para sair do Cadastro.
 */
class TermoEntrada
{
    public const VALIDADE_DIAS = 7;

    public const TEXTO_PADRAO = 'Declaro que conferi os dados do veículo acima (quilometragem, combustível, itens deixados e avarias) e '
        .'autorizo a oficina a realizar o diagnóstico e os serviços que eu aprovar. Estou ciente de que o veículo só será liberado '
        .'mediante a quitação do serviço, e de que o teste de rodagem só será feito se eu o autorizar abaixo.';

    protected static ?bool $disponivel = null;

    public static function disponivel(): bool
    {
        return static::$disponivel ??= Schema::hasTable('service_order_termos');
    }

    public static function esquecer(): void
    {
        static::$disponivel = null;
    }

    public function texto(): string
    {
        return (string) (WorkshopSettings::get('termo_texto') ?: self::TEXTO_PADRAO);
    }

    /**
     * Gera o link do termo. O token só existe no link: no banco fica o hash.
     *
     * @return array{url: string, whatsapp: string, expira_em: string}
     */
    public function gerarLink(ServiceOrder $os, ?User $usuario = null): array
    {
        $token = Str::random(48);
        $expira = now()->addDays(self::VALIDADE_DIAS);

        ServiceOrderTermo::create([
            'quote_id' => $os->id,
            'token_hash' => hash('sha256', $token),
            'expira_em' => $expira,
            'criado_por' => $usuario?->id,
        ]);

        $url = route('service_orders.termo.show', $token);
        $placa = $os->vehicle?->placa;
        $mensagem = 'Olá! Para seguir com o atendimento'.($placa ? ' do seu veículo '.$placa : '').', confira e aceite o termo de entrada: '.$url;

        $telefone = preg_replace('/\D/', '', (string) collect($os->person?->contact_numbers ?? [])->pluck('value')->filter()->first());

        if ($telefone !== '' && ! str_starts_with($telefone, '55') && in_array(strlen($telefone), [10, 11], true)) {
            $telefone = '55'.$telefone;
        }

        return [
            'url' => $url,
            'whatsapp' => 'https://wa.me/'.$telefone.'?text='.rawurlencode($mensagem),
            'expira_em' => $expira->format('d/m/Y H:i'),
        ];
    }

    /**
     * O link vale? (existe e não venceu). Link já usado continua abrindo, mostrando que o aceite foi feito.
     */
    public function encontrar(string $token): ?ServiceOrderTermo
    {
        if (! static::disponivel() || strlen($token) < 40) {
            return null;
        }

        $termo = ServiceOrderTermo::where('token_hash', hash('sha256', $token))->first();

        if (! $termo || (! $termo->aceito_em && $termo->expira_em->isPast())) {
            return null;
        }

        return $termo;
    }

    /**
     * Registra o aceite. Idempotente: um link já aceito não muda (o aceite é prova).
     *
     * @param  array{nome?: ?string, autoriza_rodagem?: mixed, assinatura?: ?string}  $dados
     *
     * @throws ValidationException
     */
    public function aceitar(ServiceOrderTermo $termo, array $dados, string $ip, ?string $userAgent): ServiceOrderTermo
    {
        $nome = trim((string) ($dados['nome'] ?? ''));
        $assinatura = (string) ($dados['assinatura'] ?? '');
        $rodagem = $dados['autoriza_rodagem'] ?? null;

        if ($nome === '') {
            throw ValidationException::withMessages(['nome' => ['Informe o seu nome.']]);
        }

        if (! in_array((string) $rodagem, ['0', '1'], true)) {
            throw ValidationException::withMessages(['autoriza_rodagem' => ['Responda se autoriza o teste de rodagem (sim ou não).']]);
        }

        if ($assinatura !== '' && (! preg_match('#^data:image/png;base64,[A-Za-z0-9+/=]+$#', $assinatura) || strlen($assinatura) > 400000)) {
            throw ValidationException::withMessages(['assinatura' => ['A assinatura enviada é inválida. Assine de novo.']]);
        }

        DB::transaction(function () use ($termo, $nome, $assinatura, $rodagem, $ip, $userAgent) {
            $atual = ServiceOrderTermo::whereKey($termo->id)->lockForUpdate()->first();

            if ($atual->aceito_em) {
                return;
            }

            $atual->forceFill([
                'aceito_em' => now(),
                'aceite_nome' => mb_substr($nome, 0, 150),
                'autoriza_rodagem' => $rodagem === '1',
                'aceite_ip' => mb_substr($ip, 0, 45),
                'aceite_user_agent' => mb_substr((string) $userAgent, 0, 250),
                'assinatura' => $assinatura !== '' ? $assinatura : null,
            ])->save();

            $os = ServiceOrder::findOrFail($atual->quote_id);

            $os->forceFill([
                'termo_assinado_em' => $os->termo_assinado_em ?? now(),
                'autorizacao_rodagem' => $rodagem === '1',
            ])->save();

            ServiceOrderEvent::record($os, 'termo_assinado', [
                'origem' => 'link',
                'nome' => $nome,
                'ip' => $ip,
                'autorizacao_rodagem' => $rodagem === '1',
                'assinatura_na_tela' => $assinatura !== '',
                'fotos' => $os->attachments()->where('tipo', 'termo_foto')->count(),
            ]);
        });

        return $termo->refresh();
    }

    /**
     * Último aceite da OS (para mostrar data, hora e IP na tela).
     */
    public function ultimoAceite(ServiceOrder $os): ?ServiceOrderTermo
    {
        return static::disponivel()
            ? ServiceOrderTermo::where('quote_id', $os->id)->whereNotNull('aceito_em')->latest('aceito_em')->first()
            : null;
    }
}
