<?php

namespace Webkul\ServiceOrder\Services;

use Illuminate\Support\Facades\Schema;
use Webkul\PartInventory\Models\Service;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Services\Alcada\AlcadaService;
use Webkul\ServiceOrder\Services\Financeiro\DreService;

/**
 * Valor da hora e tempo padrão do serviço lançado na OS (B5 do roteiro): o valor vem da Ficha,
 * as horas vêm do catálogo, valor muito fora da faixa mostra aviso e abaixo do mínimo exige alçada.
 *
 * Só vale com o piloto ligado: desligado, a tela de itens funciona como sempre.
 * A regra mora no servidor; o JavaScript só pré-preenche e avisa.
 */
class ValorHoraService
{
    /**
     * Quantas vezes o valor da Ficha é "caro demais" (aviso). Configurável pela oficina.
     */
    public const MULTIPLO_PADRAO = 3;

    public function ativo(): bool
    {
        return StageGate::active();
    }

    public function valorDaFicha(): ?float
    {
        $valor = (float) WorkshopSettings::get('valor_hora_mao_obra', 0);

        return $valor > 0 ? $valor : null;
    }

    public function multiplo(): float
    {
        $valor = (float) WorkshopSettings::get('valor_hora_limite_multiplo', self::MULTIPLO_PADRAO);

        return $valor > 1 ? $valor : (float) self::MULTIPLO_PADRAO;
    }

    public function limiteSuperior(): ?float
    {
        $ficha = $this->valorDaFicha();

        return $ficha ? round($ficha * $this->multiplo(), 2) : null;
    }

    /**
     * Preço mínimo da hora pela DRE (null quando a oficina ainda não tem dados para calculá-lo).
     */
    public function precoMinimoDre(): ?float
    {
        try {
            $minimo = app(DreService::class)->precoMinimoHora(now()->format('Y-m'))['preco_minimo'] ?? null;
        } catch (\Throwable) {
            return null;
        }

        return $minimo ? (float) $minimo : null;
    }

    /**
     * Piso para exigir alçada: o MENOR entre o mínimo da DRE e o valor da Ficha. Assim, lançar o valor
     * da Ficha (ou mais) nunca pede alçada, mesmo que a Ficha esteja abaixo do mínimo calculado.
     */
    public function piso(): ?float
    {
        $minimo = $this->precoMinimoDre();
        $ficha = $this->valorDaFicha();

        return $minimo && $ficha ? min($minimo, $ficha) : $minimo;
    }

    /**
     * O horas padrão do serviço do catálogo (null se não tiver ou a coluna ainda não existir).
     */
    public function tempoPadrao(?int $serviceId): ?float
    {
        if (! $serviceId || ! Schema::hasColumn('services', 'tempo_padrao_horas')) {
            return null;
        }

        $horas = (float) Service::whereKey($serviceId)->value('tempo_padrao_horas');

        return $horas > 0 ? $horas : null;
    }

    /**
     * Preenche o que veio em branco num serviço: valor da hora da Ficha e horas do tempo padrão.
     *
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    public function completar(array $dados): array
    {
        if (! $this->ativo() || ($dados['tipo'] ?? null) !== 'Serviço') {
            return $dados;
        }

        if (! filled($dados['preco_unitario'] ?? null) && ($ficha = $this->valorDaFicha())) {
            $dados['preco_unitario'] = $ficha;
        }

        if (! filled($dados['hours'] ?? null) && ($horas = $this->tempoPadrao((int) ($dados['service_id'] ?? 0)))) {
            $dados['hours'] = $horas;
        }

        return $dados;
    }

    /**
     * Situação do valor lançado: ok, abaixo do mínimo ou acima do limite.
     *
     * @return array{situacao: string, mensagem: ?string}
     */
    public function avaliar(float $valor): array
    {
        $piso = $this->piso();
        $limite = $this->limiteSuperior();

        if ($piso !== null && $valor < $piso) {
            return ['situacao' => 'abaixo', 'mensagem' => 'Valor da hora abaixo do preço mínimo da hora. Esse valor precisa da aprovação do gerente.'];
        }

        if ($limite !== null && $valor > $limite) {
            return ['situacao' => 'acima', 'mensagem' => 'Valor da hora acima de '.rtrim(rtrim(number_format($this->multiplo(), 2, ',', ''), '0'), ',').' vezes o valor da Ficha. Confira se não foi erro de digitação.'];
        }

        return ['situacao' => 'ok', 'mensagem' => null];
    }

    /**
     * Confere um serviço que vai entrar na OS: abaixo do mínimo pede alçada (bloqueia criando o pedido);
     * acima do limite deixa lançar e devolve o aviso. Só vale para serviço, com o piloto ligado e quando o
     * serviço tem horas (valor da hora × horas).
     *
     * @param  array<string, mixed>  $dados
     * @return ?string aviso para mostrar na tela
     */
    public function conferir(ServiceOrder $os, array $dados): ?string
    {
        if (! $this->ativo() || ($dados['tipo'] ?? null) !== 'Serviço' || ! filled($dados['preco_unitario'] ?? null)) {
            return null;
        }

        $valor = (float) $dados['preco_unitario'];
        $avaliacao = $this->avaliar($valor);

        if ($avaliacao['situacao'] === 'abaixo') {
            app(AlcadaService::class)->authorize(
                'hora_abaixo_minimo',
                $os,
                null,
                'Serviço "'.($dados['descricao'] ?? '').'" lançado com a hora abaixo do preço mínimo da hora'
            );
        }

        return $avaliacao['situacao'] === 'ok' ? null : $avaliacao['mensagem'];
    }
}
