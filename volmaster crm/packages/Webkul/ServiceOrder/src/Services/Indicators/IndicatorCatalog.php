<?php

namespace Webkul\ServiceOrder\Services\Indicators;

/**
 * Catálogo dos indicadores da mentoria (capítulo 5 e 10.4): código, nome,
 * cargo, fórmula, tipo de meta, meta padrão, unidade e quem valida.
 *
 * Tipos de meta: min (quanto maior, melhor), teto (quanto menor, melhor),
 * zero (contagem de ocorrências) e nota (média de auditoria 0 a 10).
 * Meta null com $empresa = true: "meta da empresa", em branco até o dono
 * preencher (aparece sem cor no painel).
 *
 * Este arquivo é só dado. A conta de cada indicador está em IndicatorCalculator.
 */
class IndicatorCatalog
{
    public const CARGOS = [
        'consultor' => 'Consultor técnico',
        'chefe' => 'Chefe de oficina',
        'tecnico' => 'Técnico',
        'pecas' => 'Peças',
        'financeiro' => 'Financeiro',
        'gerente' => 'Gerente',
        'zeladoria' => 'Zeladoria',
        'dono' => 'Dono',
        'dre' => 'DRE e precificação',
    ];

    /**
     * @return list<array{codigo: string, nome: string, cargo: string, formula: string, tipo: string, meta: ?float, unidade: string, valida: string, empresa: bool}>
     */
    public static function all(): array
    {
        $rows = [];

        $add = function (string $codigo, string $nome, string $cargo, string $formula, string $tipo, ?float $meta, string $unidade, string $valida, bool $empresa = false) use (&$rows) {
            $rows[] = compact('codigo', 'nome', 'cargo', 'formula', 'tipo', 'meta', 'unidade', 'valida', 'empresa');
        };

        // 5.1 Consultor técnico
        $add('CON-01', 'Recepção em até 10 min', 'consultor', 'OS com (recepção − chegada) ≤ 10 min ÷ OS', 'min', 90, 'percent', 'Gerente');
        $add('CON-02', 'Ficha aceita sem devolução, com termo', 'consultor', 'Fichas aceitas na 1ª vez ÷ fichas enviadas', 'min', 95, 'percent', 'Chefe');
        $add('CON-03', 'Cor de crédito antes da liberação', 'consultor', 'OS com cor registrada antes da liberação ÷ OS', 'min', 100, 'percent', 'Financeiro');
        $add('CON-04', 'Orçamento em até 2 h', 'consultor', 'OS com (envio do orçamento − fim do diagnóstico) ≤ 2 h ÷ OS', 'min', 90, 'percent', 'Gerente');
        $add('CON-05', 'Aprovação registrada antes da execução', 'consultor', 'OS com aprovação antes da liberação ÷ OS executadas', 'min', 100, 'percent', 'Chefe e gerente');
        $add('CON-06', 'Atualização diária ao cliente', 'consultor', 'OS sem intervalo > 24 h entre atualizações ÷ OS em aberto', 'min', 90, 'percent', 'Gerente');
        $add('CON-07', 'OS fechada e enviada ao financeiro no dia', 'consultor', 'OS enviadas no mesmo dia do checklist de saída ÷ OS prontas', 'min', 95, 'percent', 'Financeiro');
        $add('CON-08', 'Pós-venda em 7 dias', 'consultor', 'Clientes contatados até 7 dias após a saída ÷ entregas', 'min', 90, 'percent', 'Gerente');
        $add('CON-09', 'Taxa de aprovação de orçamentos', 'consultor', 'Valor aprovado ÷ valor orçado', 'min', null, 'percent', 'Gerente', true);
        $add('CON-10', 'Serviços adicionais vendidos', 'consultor', 'OS com item adicional aprovado ÷ OS', 'min', null, 'percent', 'Gerente', true);
        $add('CON-11', 'Adimplência dos serviços liberados', 'consultor', 'Valor recebido em dia ÷ valor faturado a prazo pelo consultor', 'min', null, 'percent', 'Financeiro', true);
        $add('CON-12', 'Recusas com retorno em 7 dias', 'consultor', 'Orçamentos recusados com recontato em 7 dias ÷ recusados', 'min', 100, 'percent', 'Gerente');
        $add('CON-13', 'Termo com autorização de rodagem', 'consultor', 'Termos com autorização assinada ÷ entradas', 'min', 100, 'percent', 'Chefe');
        $add('CON-14', 'Próxima revisão registrada', 'consultor', 'Entregas com próxima revisão ÷ entregas', 'min', 100, 'percent', 'Gerente');
        $add('CON-15', 'Nota de recomendação', 'consultor', 'Média das notas 0 a 10', 'min', null, 'nota', 'Gerente', true);
        $add('CON-16', 'Retorno para preventiva', 'consultor', 'Clientes que voltaram na data da próxima revisão ÷ revisões vencidas', 'min', null, 'percent', 'Gerente', true);
        $add('CON-17', 'Chamado externo autorizado antes da saída', 'consultor', 'Chamados com autorização prévia ÷ chamados', 'min', 100, 'percent', 'Gerente');

        // 5.2 Chefe de oficina
        $add('CHE-01', 'Técnico designado em até 30 min', 'chefe', 'OS com (designação − recebimento da ficha) ≤ 30 min ÷ OS', 'min', 95, 'percent', 'Consultor');
        $add('CHE-02', 'Diagnóstico auditado antes do orçamento', 'chefe', 'OS com visto antes do orçamento ÷ OS', 'min', 100, 'percent', 'Gerente');
        $add('CHE-03', 'Orçamento técnico sem correção', 'chefe', 'OS sem correção por peças ou consultor ÷ OS', 'min', 95, 'percent', 'Peças e consultor');
        $add('CHE-04', 'OS completa ao consultor em até 1 h', 'chefe', 'OS com (OS completa − fim do diagnóstico) ≤ 1 h ÷ OS', 'min', 90, 'percent', 'Consultor');
        $add('CHE-05', 'Liberação só com autorização', 'chefe', 'Liberações com aprovação registrada ÷ liberações', 'min', 100, 'percent', 'Consultor e gerente');
        $add('CHE-06', 'Aderência ao tempo', 'chefe', 'Operações com horas reais ≤ 1,2 × padrão ÷ operações', 'min', 85, 'percent', 'Gerente');
        $add('CHE-07', 'Entrega no prazo prometido', 'chefe', 'Saídas até o prazo prometido ÷ entregas', 'min', 90, 'percent', 'Consultor');
        $add('CHE-08', 'Test drive registrado', 'chefe', 'Serviços com test drive completo ÷ serviços', 'min', 100, 'percent', 'Consultor');
        $add('CHE-09', 'Checklist de saída com horas reais', 'chefe', 'Fichas completas ÷ entregas', 'min', 100, 'percent', 'Consultor');
        $add('CHE-10', 'Retrabalho', 'chefe', 'Retornos por falha de execução ÷ OS entregues', 'teto', 2, 'percent', 'Consultor');
        $add('CHE-11', 'Itens adicionais identificados', 'chefe', 'OS com item adicional ÷ OS', 'min', null, 'percent', 'Gerente', true);
        $add('CHE-12', 'Produtividade da oficina', 'chefe', 'Horas padrão faturadas ÷ horas disponíveis dos técnicos', 'min', null, 'percent', 'Gerente', true);
        $add('CHE-13', 'Diagnóstico de retorno em até 2 h', 'chefe', 'OS de retorno diagnosticadas em 2 h ÷ OS de retorno', 'min', 100, 'percent', 'Gerente');
        $add('CHE-14', 'Retornos classificados com causa', 'chefe', 'Retornos com causa ÷ retornos', 'min', 100, 'percent', 'Gerente');
        $add('CHE-15', 'Pré-diagnóstico externo em 30 min', 'chefe', 'Chamados com pré-diagnóstico em 30 min ÷ chamados', 'min', 90, 'percent', 'Gerente');
        $add('CHE-16', 'Tempário revisado no trimestre', 'chefe', 'Revisão registrada no trimestre (sim/não)', 'min', 100, 'percent', 'Gerente');

        // 5.3 Técnico
        $add('TEC-01', 'Tempo de diagnóstico', 'tecnico', 'Diagnósticos ≤ tempo alvo do tipo de queixa ÷ diagnósticos', 'min', 85, 'percent', 'Chefe');
        $add('TEC-02', 'Assertividade', 'tecnico', 'Diagnósticos sem peça trocada a mais por erro e sem retorno pelo mesmo defeito em 30 dias ÷ diagnósticos', 'min', 95, 'percent', 'Chefe e consultor');
        $add('TEC-03', 'Aprovado na 1ª auditoria', 'tecnico', 'Laudos aprovados sem devolução ÷ laudos', 'min', 90, 'percent', 'Chefe');
        $add('TEC-04', 'Laudo com evidências', 'tecnico', 'Laudos com códigos, medições e fotos ÷ laudos', 'min', 95, 'percent', 'Chefe');
        $add('TEC-05', 'Relação de peças em 30 min', 'tecnico', 'Relações entregues ≤ 30 min após o diagnóstico ÷ relações', 'min', 90, 'percent', 'Chefe');
        $add('TEC-06', 'Relação correta na 1ª vez', 'tecnico', 'Relações sem peça faltando ou errada ÷ relações', 'min', 95, 'percent', 'Chefe e peças');
        $add('TEC-07', 'Eficiência', 'tecnico', 'Σ horas padrão ÷ Σ horas reais apontadas', 'min', 90, 'percent', 'Chefe');
        $add('TEC-08', 'Aderência ao tempo', 'tecnico', 'Operações ≤ 1,2 × padrão ÷ operações', 'min', 85, 'percent', 'Chefe');
        $add('TEC-09', 'Aprovado na 1ª conferência', 'tecnico', 'Serviços aprovados sem correção ÷ serviços', 'min', 90, 'percent', 'Chefe');
        $add('TEC-10', 'Retorno de serviço', 'tecnico', 'Retornos por falha de execução em 30 dias ÷ OS executadas', 'teto', 2, 'percent', 'Consultor');
        $add('TEC-11', 'Peças velhas com termo', 'tecnico', 'OS com peças separadas, identificadas e termo assinado ÷ OS com troca', 'min', 100, 'percent', 'Consultor');
        $add('TEC-12', 'Apontamento de início e fim', 'tecnico', 'Operações com início e fim ÷ operações', 'min', 100, 'percent', 'Chefe');
        $add('TEC-13', 'Auditoria de box e postura', 'tecnico', 'Média das notas semanais', 'nota', 8, 'nota', 'Chefe');
        $add('TEC-14', 'Celular durante a execução', 'tecnico', 'Ocorrências registradas pelo chefe', 'zero', 0, 'count', 'Chefe');
        $add('TEC-15', 'Conferência do chefe antes de pronto', 'tecnico', 'Serviços conferidos antes de marcar pronto ÷ serviços', 'min', 100, 'percent', 'Chefe');

        // 5.4 Peças
        $add('PEC-01', 'Precificação no prazo', 'pecas', 'Itens precificados em ≤ 20 min (estoque) ou ≤ 40 min (fornecedor) ÷ itens', 'min', 90, 'percent', 'Chefe');
        $add('PEC-02', 'Peça certa na 1ª vez', 'pecas', 'Itens sem troca por erro de código, cotação ou envio ÷ itens', 'min', 98, 'percent', 'Chefe');
        $add('PEC-03', 'Preço e prazo mantidos', 'pecas', 'Itens sem alteração após a aprovação ÷ itens aprovados', 'min', 95, 'percent', 'Consultor');
        $add('PEC-04', 'Compra no mesmo dia', 'pecas', 'Pedidos no dia da autorização ÷ pedidos', 'min', 95, 'percent', 'Consultor');
        $add('PEC-05', 'Peça entregue no prazo informado', 'pecas', 'Entregas ao técnico até o prazo ÷ entregas', 'min', 90, 'percent', 'Chefe');
        $add('PEC-06', 'Conferência antes do faturamento', 'pecas', 'OS sem divergência aplicado × lançado ÷ OS faturadas', 'min', 100, 'percent', 'Financeiro');
        $add('PEC-07', 'NF de entrada em 24 h', 'pecas', 'Notas lançadas em ≤ 24 h ÷ notas recebidas', 'min', 100, 'percent', 'Financeiro');
        $add('PEC-08', 'Garantia/devolução em 5 dias', 'pecas', 'Processos abertos em ≤ 5 dias ÷ processos', 'min', 100, 'percent', 'Financeiro');
        $add('PEC-09', 'Acuracidade do estoque', 'pecas', 'Itens do inventário rotativo com saldo correto ÷ itens contados', 'min', 98, 'percent', 'Dono ou financeiro');
        $add('PEC-10', 'Inventário rotativo semanal', 'pecas', 'Semanas com inventário ÷ semanas do mês', 'min', 100, 'percent', 'Financeiro');
        $add('PEC-11', 'Peças paradas > 180 dias', 'pecas', 'Valor parado > 180 dias ÷ valor do estoque', 'teto', 10, 'percent', 'Dono');
        $add('PEC-12', 'Margem bruta de peças', 'pecas', '(Venda − custo) ÷ venda', 'min', null, 'percent', 'Financeiro', true);
        $add('PEC-13', 'Auditoria de organização do estoque', 'pecas', 'Nota média das auditorias', 'nota', 8, 'nota', 'Gerente');

        // 5.5 Financeiro
        $add('FIN-01', 'Análise de crédito em 30 min', 'financeiro', 'Análises com cor devolvida em ≤ 30 min ÷ análises', 'min', 95, 'percent', 'Consultor');
        $add('FIN-02', 'NF antes da saída', 'financeiro', 'Saídas com NF (fora a exceção de frota) ÷ saídas faturadas', 'min', 100, 'percent', 'Consultor');
        $add('FIN-03', 'Boleto no dia', 'financeiro', 'Boletos emitidos e enviados no dia do faturamento ÷ boletos', 'min', 100, 'percent', 'Dono');
        $add('FIN-04', 'Conciliação em dia', 'financeiro', 'Dias conciliados até o dia útil seguinte ÷ dias úteis', 'min', 100, 'percent', 'Dono');
        $add('FIN-05', 'Caixa sem diferença', 'financeiro', 'Dias sem diferença não justificada ÷ dias', 'min', 100, 'percent', 'Dono');
        $add('FIN-06', 'Contas pagas em dia', 'financeiro', 'Contas pagas sem juros ou multa ÷ contas pagas', 'min', 100, 'percent', 'Dono');
        $add('FIN-07', 'NF de entrada conferida antes de pagar', 'financeiro', 'Pagamentos de peças com conferência ÷ pagamentos de peças', 'min', 100, 'percent', 'Dono');
        $add('FIN-08', 'Relatório diário entregue', 'financeiro', 'Dias com relatório completo no horário ÷ dias úteis', 'min', 100, 'percent', 'Dono');
        $add('FIN-09', 'Notas canceladas ou corrigidas', 'financeiro', 'Notas com erro ÷ notas emitidas', 'teto', 2, 'percent', 'Dono');
        $add('FIN-10', 'Inadimplência', 'financeiro', 'Valor vencido ÷ valor faturado a prazo', 'teto', 5, 'percent', 'Dono');
        $add('FIN-11', 'Régua de cobrança cumprida', 'financeiro', 'Contatos feitos nos dias 1, 7 e 15 ÷ contatos previstos', 'min', 100, 'percent', 'Dono');
        $add('FIN-12', 'Contabilidade no prazo', 'financeiro', 'Envios até a data combinada ÷ envios', 'min', 100, 'percent', 'Contabilidade');
        $add('FIN-13', 'Relatório mensal até o 5º dia útil', 'financeiro', 'Entregue no prazo (sim/não)', 'min', 100, 'percent', 'Dono');

        // 5.6 Gerente
        $add('GER-01', 'Indicadores do Painel do Dono no alvo', 'gerente', 'Indicadores "No alvo" ÷ indicadores com meta', 'min', 85, 'percent', 'Dono');
        $add('GER-02', 'Faturamento contra a meta', 'gerente', 'Faturamento do mês ÷ meta', 'min', 100, 'percent', 'Dono');
        $add('GER-03', 'Resultado ou margem contra a meta', 'gerente', 'Resultado do mês ÷ meta', 'min', null, 'percent', 'Dono', true);
        $add('GER-04', 'Permanência média do caminhão', 'gerente', 'Média (saída − chegada) em dias', 'teto', null, 'days', 'Dono', true);
        $add('GER-05', 'Retrabalho da oficina', 'gerente', 'Igual a CHE-10, toda a oficina', 'teto', 2, 'percent', 'Dono');
        $add('GER-06', 'Reuniões realizadas', 'gerente', 'Diárias, semanais e mensal registradas ÷ previstas', 'min', 100, 'percent', 'Dono');
        $add('GER-07', 'Planos de ação para todos os vermelhos', 'gerente', 'Indicadores vermelhos com plano ÷ indicadores vermelhos', 'min', 100, 'percent', 'Dono');
        $add('GER-08', 'Planos de ação concluídos no prazo', 'gerente', 'Planos concluídos até o prazo ÷ planos vencidos no mês', 'min', 90, 'percent', 'Dono');
        $add('GER-09', 'Reclamações resolvidas em 48 h', 'gerente', 'Reclamações fechadas em ≤ 48 h ÷ reclamações', 'min', 100, 'percent', 'Dono');
        $add('GER-10', 'Auditorias por amostragem feitas', 'gerente', 'Auditorias registradas ÷ combinadas', 'min', 100, 'percent', 'Dono');
        $add('GER-11', 'Painel do Dono consolidado no prazo', 'gerente', 'Fechamento até o 5º dia útil (sim/não)', 'min', 100, 'percent', 'Dono');
        $add('GER-12', 'Relatório diário de 5 linhas', 'gerente', 'Dias com relatório ÷ dias úteis', 'min', 100, 'percent', 'Dono');
        $add('GER-13', 'Admissões com registro antes do início', 'gerente', 'Admissões registradas antes ÷ admissões', 'min', 100, 'percent', 'Dono');
        $add('GER-14', 'Variáveis da folha no prazo e folha até o 5º dia útil', 'gerente', 'Sim/não, os dois', 'min', 100, 'percent', 'Dono');
        $add('GER-15', 'Férias vencidas', 'gerente', 'Colaboradores com férias vencidas', 'zero', 0, 'count', 'Dono');
        $add('GER-16', 'Exames e EPI em dia', 'gerente', 'Colaboradores em dia ÷ colaboradores', 'min', 100, 'percent', 'Dono');
        $add('GER-17', 'Horas extras sem autorização prévia', 'gerente', 'Ocorrências', 'zero', 0, 'count', 'Dono');
        $add('GER-18', 'Rescisões pagas no prazo', 'gerente', 'Rescisões no prazo ÷ rescisões', 'min', 100, 'percent', 'Dono');
        $add('GER-19', 'Absenteísmo', 'gerente', 'Horas de faltas e atrasos ÷ horas previstas', 'teto', 3, 'percent', 'Dono');
        $add('GER-20', 'Rotatividade', 'gerente', 'Desligamentos ÷ quadro médio', 'teto', null, 'percent', 'Dono', true);

        // 5.7 Auxiliar de copa e zeladoria
        $add('ZEL-01', 'Checklist diário de limpeza', 'zeladoria', 'Dias com checklist completo ÷ dias trabalhados', 'min', 100, 'percent', 'Gerente');
        $add('ZEL-02', 'Banheiros nos horários', 'zeladoria', 'Registros feitos ÷ previstos', 'min', 100, 'percent', 'Gerente');
        $add('ZEL-03', 'Auditoria semanal do ambiente', 'zeladoria', 'Média das notas', 'nota', 8, 'nota', 'Gerente');
        $add('ZEL-04', 'Café e lanche no horário', 'zeladoria', 'Dias no horário ÷ dias trabalhados', 'min', 100, 'percent', 'Gerente');
        $add('ZEL-05', 'Falta de itens por falta de aviso', 'zeladoria', 'Ocorrências', 'zero', 0, 'count', 'Gerente');
        $add('ZEL-06', 'Reclamações de limpeza', 'zeladoria', 'Ocorrências', 'zero', 0, 'count', 'Gerente');

        // 5.8 Dono
        $add('DON-01', 'Horas semanais com clientes e prospecção', 'dono', 'Horas registradas na agenda ÷ semanas', 'min', 8, 'hours', 'Mentor');
        $add('DON-02', 'Visitas e prospecções no mês', 'dono', 'Contagem', 'min', null, 'count', 'Mentor', true);
        $add('DON-03', 'Novos clientes ou frotas', 'dono', 'Clientes com 1ª OS no mês', 'min', null, 'count', 'Mentor', true);
        $add('DON-04', 'Reuniões com o gerente realizadas', 'dono', 'Semanais e mensal registradas ÷ previstas', 'min', 100, 'percent', 'Mentor');
        $add('DON-05', 'Acionado fora da alçada', 'dono', 'Registros "ação do dono fora do fluxo" + pedidos que chegaram ao dono sem passar pelo gerente', 'teto', null, 'count', 'Mentor');
        $add('DON-06', 'Alçadas respondidas em 24 h', 'dono', 'Decisões do dono em ≤ 24 h ÷ decisões pedidas', 'min', 100, 'percent', 'Mentor');

        // 10.4 DRE e precificação
        $add('DRE-01', 'Margem de contribuição de serviço', 'dre', 'Margem de contribuição de serviço ÷ receita de serviço', 'min', null, 'percent', 'Dono', true);
        $add('DRE-02', 'Margem de contribuição de peças', 'dre', 'Margem de contribuição de peças ÷ receita de peças', 'min', null, 'percent', 'Dono', true);
        $add('DRE-03', 'Resultado operacional ÷ faturamento', 'dre', 'Resultado operacional ÷ faturamento', 'min', null, 'percent', 'Dono', true);
        $add('DRE-04', 'Folha ÷ faturamento', 'dre', 'Pessoal ÷ faturamento', 'teto', null, 'percent', 'Dono', true);
        $add('DRE-05', 'Dia em que o ponto de equilíbrio foi atingido', 'dre', 'Dia do mês em que o faturamento cobriu o ponto de equilíbrio', 'teto', null, 'days', 'Dono', true);
        $add('DRE-06', 'Hora cobrada ÷ preço mínimo', 'dre', 'Valor da hora cobrada ÷ preço mínimo da hora', 'min', 100, 'percent', 'Dono');
        $add('DRE-07', 'Peças abaixo do preço mínimo sem alçada', 'dre', 'Ocorrências', 'zero', 0, 'count', 'Dono');
        $add('DRE-08', 'Lançamentos sem categoria no fechamento', 'dre', 'Ocorrências', 'zero', 0, 'count', 'Dono');
        $add('DRE-09', 'OS com margem negativa', 'dre', 'OS com margem negativa ÷ OS', 'teto', null, 'percent', 'Dono', true);
        $add('DRE-10', 'Caixa projetado em 30 dias ÷ despesas fixas', 'dre', 'Caixa projetado em 30 dias ÷ despesas fixas', 'min', null, 'percent', 'Dono', true);

        return $rows;
    }

    /**
     * @return array<string, array>
     */
    public static function byCode(): array
    {
        return collect(static::all())->keyBy('codigo')->all();
    }
}
