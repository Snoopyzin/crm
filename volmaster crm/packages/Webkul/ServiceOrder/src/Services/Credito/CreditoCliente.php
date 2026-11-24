<?php

namespace Webkul\ServiceOrder\Services\Credito;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Webkul\ServiceOrder\Models\ClienteCreditoRegistro;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Services\ValoresFinanceiros;
use Webkul\ServiceOrder\Services\WorkshopSettings;
use Webkul\User\Models\User;

/**
 * Cor de crédito do cliente (B1 do roteiro): histórico no cadastro do cliente, sugestão
 * automática pelos títulos (boletos) e a cor que vale na hora de liberar prazo.
 *
 * Verde = paga em dia, Amarelo = atrasa às vezes, Vermelho = inadimplente. Dado restrito:
 * só Consultor, Financeiro, Gerente e Proprietário veem; nunca vai para o link do cliente,
 * a impressão, o Técnico nem mensagens de alerta.
 */
class CreditoCliente
{
    public const CORES = ['verde' => 'Verde (paga em dia)', 'amarelo' => 'Amarelo (atrasa às vezes)', 'vermelho' => 'Vermelho (inadimplente)'];

    protected const PESO = ['verde' => 0, 'amarelo' => 1, 'vermelho' => 2];

    protected static ?bool $disponivel = null;

    /**
     * A tabela de histórico existe (antes da migration o resto do sistema segue como sempre)?
     */
    public static function disponivel(): bool
    {
        return static::$disponivel ??= Schema::hasTable('cliente_credito_registros');
    }

    public static function esquecer(): void
    {
        static::$disponivel = null;
    }

    /**
     * Quem enxerga o crédito: precisa da permissão "credito_cliente" E de poder ver valores
     * (assim, quem não vê dinheiro nunca vê crédito, mesmo que a função receba a chave por engano).
     */
    public function podeVer(?User $user = null): bool
    {
        $user ??= auth()->guard('user')->user();

        return $user !== null && bouncer()->hasPermission('credito_cliente') && ValoresFinanceiros::can();
    }

    public function podeRegistrar(?User $user = null): bool
    {
        return $this->podeVer($user) && bouncer()->hasPermission('credito_cliente.registrar');
    }

    /**
     * @return Collection<int, ClienteCreditoRegistro>
     */
    public function historico(int $personId, int $limite = 10): Collection
    {
        if (! static::disponivel()) {
            return new Collection;
        }

        return ClienteCreditoRegistro::with('user:id,name')->where('person_id', $personId)->latest('id')->limit($limite)->get();
    }

    public function vigente(int $personId): ?ClienteCreditoRegistro
    {
        return static::disponivel() ? ClienteCreditoRegistro::where('person_id', $personId)->latest('id')->first() : null;
    }

    /**
     * Validade do cadastro do cliente (reunião de 19/11/2026): a última análise de crédito vale por
     * N dias (Ficha: "Validade do cadastro"). Sem análise nenhuma, ou com análise mais velha que
     * isso, o cadastro está vencido e o sistema avisa para pedir nova consulta ao Financeiro.
     *
     * @return array{validade_dias: int, ultima_em: ?Carbon, dias: ?int, vencida: bool, nunca: bool}
     */
    public function validade(int $personId): array
    {
        $validadeDias = (int) WorkshopSettings::get('cadastro_validade_dias', 90);
        $validadeDias = $validadeDias >= 1 ? $validadeDias : 90;
        $ultima = $this->vigente($personId)?->created_at;
        $dias = $ultima ? max(0, (int) $ultima->copy()->startOfDay()->diffInDays(Carbon::today(), false)) : null;

        return [
            'validade_dias' => $validadeDias,
            'ultima_em' => $ultima,
            'dias' => $dias,
            'vencida' => $ultima === null || $dias > $validadeDias,
            'nunca' => $ultima === null,
        ];
    }

    /**
     * Sugestão automática pelos títulos do cliente: com título vencido há mais de 15 dias fica
     * Vermelho; com atraso nos últimos 90 dias, Amarelo. Os limites são da oficina (Ficha).
     *
     * @return array{cor: string, motivo: string}
     */
    public function sugestao(int $personId): array
    {
        $diasVermelho = max(1, (int) WorkshopSettings::get('credito_dias_vermelho', 15));
        $diasAmarelo = max($diasVermelho, (int) WorkshopSettings::get('credito_dias_amarelo', 90));
        $hoje = Carbon::today();

        $titulos = DB::table('service_order_boletos')
            ->join('quotes', 'quotes.id', '=', 'service_order_boletos.quote_id')
            ->where('quotes.person_id', $personId)
            ->whereNotNull('service_order_boletos.vencimento')
            ->where('service_order_boletos.status', '!=', 'Cancelado')
            ->get(['service_order_boletos.status', 'service_order_boletos.vencimento', 'service_order_boletos.updated_at']);

        if ($titulos->isEmpty()) {
            return ['cor' => 'verde', 'motivo' => 'Sem histórico de títulos'];
        }

        $maiorAtrasoAberto = 0;
        $atrasoRecente = false;

        foreach ($titulos as $titulo) {
            $vencimento = Carbon::parse($titulo->vencimento)->startOfDay();

            if ($titulo->status === 'Pendente') {
                $dias = (int) $vencimento->diffInDays($hoje, false);

                if ($dias > 0) {
                    $maiorAtrasoAberto = max($maiorAtrasoAberto, $dias);
                    $atrasoRecente = true;
                }

                continue;
            }

            // Pago: atraso se foi baixado depois do vencimento, dentro da janela recente.
            if ($titulo->updated_at && Carbon::parse($titulo->updated_at)->startOfDay()->gt($vencimento) && $vencimento->gte($hoje->copy()->subDays($diasAmarelo))) {
                $atrasoRecente = true;
            }
        }

        if ($maiorAtrasoAberto > $diasVermelho) {
            return ['cor' => 'vermelho', 'motivo' => 'Título vencido há '.$maiorAtrasoAberto.' dias (limite: '.$diasVermelho.')'];
        }

        if ($atrasoRecente) {
            return ['cor' => 'amarelo', 'motivo' => 'Atraso em título nos últimos '.$diasAmarelo.' dias'];
        }

        return ['cor' => 'verde', 'motivo' => 'Títulos em dia'];
    }

    /**
     * A cor que vale agora para liberar prazo: a última análise do Financeiro, a menos que a
     * sugestão do sistema tenha PIORADO desde a decisão (o cliente "fica Vermelho" sozinho quando
     * um título passa de 15 dias). Decisão justificada contra a sugestão vale enquanto a sugestão
     * não mudar. Sem nenhuma análise, vale a sugestão se for Amarelo ou Vermelho.
     */
    public function corEfetiva(?int $personId): ?string
    {
        if (! $personId || ! static::disponivel()) {
            return null;
        }

        $sugestao = $this->sugestao($personId);
        $vigente = $this->vigente($personId);

        if (! $vigente) {
            return $sugestao['cor'] === 'verde' ? null : $sugestao['cor'];
        }

        if ($vigente->sugestao_cor === $sugestao['cor']) {
            return $vigente->cor;
        }

        return static::PESO[$sugestao['cor']] > static::PESO[$vigente->cor] ? $sugestao['cor'] : $vigente->cor;
    }

    /**
     * Registra uma análise. Registrar uma cor MAIS BRANDA que a sugestão do sistema exige justificativa.
     *
     * @throws ValidationException
     */
    public function registrar(int $personId, string $cor, ?string $observacao = null, ?string $justificativa = null, ?ServiceOrder $os = null, ?User $user = null): ClienteCreditoRegistro
    {
        if (! array_key_exists($cor, static::CORES)) {
            throw ValidationException::withMessages(['cor_credito' => ['Cor de crédito inválida.']]);
        }

        $user ??= auth()->guard('user')->user();
        $sugestao = $this->sugestao($personId);

        if (static::PESO[$cor] < static::PESO[$sugestao['cor']] && ! filled($justificativa)) {
            throw ValidationException::withMessages(['justificativa' => [
                'O sistema sugere '.mb_strtolower(explode(' ', static::CORES[$sugestao['cor']])[0]).' ('.$sugestao['motivo'].'). Para registrar uma cor mais branda, informe a justificativa.',
            ]]);
        }

        return ClienteCreditoRegistro::create([
            'person_id' => $personId,
            'cor' => $cor,
            'cor_anterior' => $this->vigente($personId)?->cor,
            'sugestao_cor' => $sugestao['cor'],
            'sugestao_motivo' => $sugestao['motivo'],
            'observacao' => $observacao,
            'justificativa' => $justificativa,
            'quote_id' => $os?->id,
            'user_id' => $user?->id,
        ]);
    }
}
