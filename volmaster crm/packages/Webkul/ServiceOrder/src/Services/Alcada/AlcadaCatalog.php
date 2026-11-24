<?php

namespace Webkul\ServiceOrder\Services\Alcada;

/**
 * Matriz de alçadas padrão da mentoria (capítulo 7.1): tema, quem decide
 * sozinho, o que o gerente aprova, o que vai ao dono e o gatilho no sistema.
 *
 * Tipos:
 * - limites: valor comparado com o limite de "decide sozinho" e com o do gerente;
 * - cor:     cor de crédito (0 verde, 1 amarelo, 2 vermelho) × forma de pagamento;
 * - gerente: sempre o gerente decide;
 * - dono:    sempre o dono decide (o gerente propõe e encaminha).
 */
class AlcadaCatalog
{
    public const NIVEL_NENHUM = 0;

    public const NIVEL_GERENTE = 1;

    public const NIVEL_DONO = 2;

    /**
     * @return array<string, array{nome: string, tipo: string, unidade: string, sozinho: string, gerente: string, dono: string, gatilho: string, automatico: bool, sozinho_aplica: bool, gerente_aprova: bool}>
     */
    public static function temas(): array
    {
        $t = fn (string $nome, string $tipo, string $unidade, string $sozinho, string $gerente, string $dono, string $gatilho, bool $automatico = false, bool $sozinhoAplica = true, bool $gerenteAprova = true) => compact('nome', 'tipo', 'unidade', 'sozinho', 'gerente', 'dono', 'gatilho', 'automatico') + ['sozinho_aplica' => $sozinhoAplica, 'gerente_aprova' => $gerenteAprova];

        return [
            'desconto' => $t('Desconto em orçamento', 'limites', 'percent', 'Consultor até __%', 'Até __%', 'Acima', 'Campo desconto na etapa 03/04', true),
            'condicao_pagamento' => $t('Condição de pagamento', 'cor', 'cor', 'Financeiro: cliente verde, pela política', 'Exceção para amarelo', 'Faturado para vermelho', 'Forma de pagamento × cor de crédito', true),
            'limite_credito' => $t('Limite de crédito PJ', 'limites', 'brl', 'Financeiro até R$ __', '—', 'Acima', 'Cadastro de cliente', false, true, false),
            'compra_pecas' => $t('Compra de peças', 'limites', 'brl', 'Peças, com OS autorizada ou estoque mínimo', 'Acima de R$ __', 'Acima de R$ __', 'Pedido de compra', true),
            'despesas' => $t('Despesas e pagamentos', 'limites', 'brl', 'Financeiro: contas programadas', 'Até R$ __', 'Acima', 'Contas a pagar'),
            'ajuste_estoque' => $t('Ajuste de estoque', 'limites', 'brl', '—', 'Até R$ __', 'Acima', 'Movimento de ajuste', false, false),
            'retorno_garantia' => $t('Retorno e garantia', 'limites', 'brl', 'Chefe: decisão técnica', 'Custo até R$ __', 'Acima', 'OS de retorno'),
            'reclamacao' => $t('Reclamação de cliente', 'gerente', 'nenhuma', 'Consultor (1ª instância)', 'Escalada, 48 h', 'Cliente estratégico, risco jurídico ou valor alto', 'Pós-venda'),
            'horas_extras' => $t('Horas extras', 'gerente', 'nenhuma', '—', 'Autorização prévia', '—', 'Apontamento fora do horário'),
            'advertencia' => $t('Advertência disciplinar', 'gerente', 'nenhuma', '—', 'Com orientação da contabilidade', 'Informado', 'RH'),
            'contratacao_desligamento' => $t('Contratação e desligamento', 'dono', 'nenhuma', '—', 'Propõe', 'Decide', 'RH'),
            'salarios_bonus' => $t('Salários, reajustes e bônus', 'dono', 'nenhuma', '—', 'Apura e propõe', 'Aprova', 'Motor de bônus'),
            'valor_hora_margem' => $t('Valor da hora e tabela de margem', 'dono', 'nenhuma', '—', 'Propõe com o financeiro', 'Aprova', 'Configuração'),
            'contratos_frota' => $t('Contratos de frota', 'dono', 'nenhuma', '—', 'Negocia', 'Aprova', 'Cadastro de cliente'),
            'investimento' => $t('Investimento', 'dono', 'nenhuma', '—', 'Propõe', 'Decide', 'Plano de ação'),
            'mudanca_processo' => $t('Mudança de processo ou padrão', 'dono', 'nenhuma', '—', 'Propõe', 'Aprova com o mentor', 'Configuração'),
            'protesto_negativacao' => $t('Protesto ou negativação', 'dono', 'nenhuma', '—', 'Propõe', 'Decide', 'Cobrança'),
            // Capítulo 10.4: peça abaixo do preço mínimo vai para a fila de alçadas; a DRE congelada só reabre com o dono.
            // B5: serviço lançado com o valor da hora abaixo do preço mínimo da hora (DRE).
            'hora_abaixo_minimo' => $t('Valor da hora abaixo do mínimo', 'gerente', 'nenhuma', '—', 'Aprova a exceção', '—', 'Lançamento de serviço na OS', true),
            'preco_abaixo_minimo' => $t('Preço de peça abaixo do mínimo', 'gerente', 'nenhuma', '—', 'Aprova a exceção', '—', 'Precificação de peça', true),
            'reabrir_dre' => $t('Reabertura da DRE do mês', 'dono', 'nenhuma', '—', 'Propõe com o financeiro', 'Aprova', 'Fechamento mensal da DRE', true),
        ];
    }

    public static function tema(string $key): ?array
    {
        return static::temas()[$key] ?? null;
    }
}
