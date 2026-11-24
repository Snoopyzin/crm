<?php

namespace Webkul\ServiceOrder\Services;

use Webkul\ServiceOrder\Models\Colaborador;

/**
 * O que a oficina precisa ter preenchido para ligar o piloto (A5 do roteiro):
 * valor da hora, impostos, data de início da mentoria e pelo menos um
 * colaborador ativo com cargo de Gerente ou Proprietário. Sem isso, os
 * bloqueios parariam a oficina em vez de organizá-la.
 */
class PilotoRequisitos
{
    /**
     * @return list<array{chave: string, texto: string, link: string}> o que falta (vazio = pode ligar)
     */
    public static function faltando(): array
    {
        $faltas = [];

        if ((float) WorkshopSettings::get('valor_hora_mao_obra', 0) <= 0) {
            $faltas[] = [
                'chave' => 'valor_hora_mao_obra',
                'texto' => 'Valor da hora de mão de obra, na Ficha de Enquadramento',
                'link' => route('admin.ficha.edit'),
            ];
        }

        // Impostos 0% é um valor válido (oficina no Simples sem repasse); só "em branco" não vale.
        $impostos = WorkshopSettings::get('pct_impostos');

        if ($impostos === null || $impostos === '') {
            $faltas[] = [
                'chave' => 'pct_impostos',
                'texto' => 'Impostos (%), na Ficha de Enquadramento',
                'link' => route('admin.ficha.edit'),
            ];
        }

        if (! filled(WorkshopSettings::get('inicio_mentoria'))) {
            $faltas[] = [
                'chave' => 'inicio_mentoria',
                'texto' => 'Data de início da mentoria, na Ficha de Enquadramento',
                'link' => route('admin.ficha.edit'),
            ];
        }

        $temDirecao = Colaborador::ativos()
            ->where(fn ($consulta) => $consulta->comCargo('gerente')->orWhere(fn ($q) => $q->comCargo('proprietario')))
            ->exists();

        if (! $temDirecao) {
            $faltas[] = [
                'chave' => 'colaborador_direcao',
                'texto' => 'Pelo menos um colaborador ativo com cargo de Gerente ou Proprietário, em Colaboradores',
                'link' => route('admin.colaboradores.index'),
            ];
        }

        return $faltas;
    }

    public static function completo(): bool
    {
        return self::faltando() === [];
    }
}
