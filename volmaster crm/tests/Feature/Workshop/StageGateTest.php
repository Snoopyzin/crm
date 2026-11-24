<?php

use Illuminate\Validation\ValidationException;
use Webkul\ServiceOrder\Models\DiagnosticoQueixaTipo;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Models\ServiceOrderAttachment;
use Webkul\ServiceOrder\Models\ServiceOrderEvent;
use Webkul\ServiceOrder\Models\ServiceOrderLaudoItem;
use Webkul\ServiceOrder\Models\WorkshopAlert;
use Webkul\ServiceOrder\Services\OperationService;
use Webkul\ServiceOrder\Services\StageAdvanceService;
use Webkul\ServiceOrder\Services\StageGate;

function advancer(): StageAdvanceService
{
    return app(StageAdvanceService::class);
}

it('critério 4: a OS não sai do Diagnóstico sem laudo com evidência e visto do chefe, e mostra o que falta', function () {
    $this->enablePilot();
    $os = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_DIAGNOSTICO]);

    $resultado = advancer()->advance($os, ServiceOrder::STAGE_ORCAMENTO);

    expect($resultado['avancou'])->toBeFalse()
        ->and($resultado['faltando'])->toContain('Laudo do diagnóstico')
        ->and($resultado['faltando'])->toContain('Laudo com pelo menos 1 evidência (código de falha, medição ou foto)')
        ->and($resultado['faltando'])->toContain('Visto do chefe de oficina no diagnóstico')
        ->and($resultado['faltando'])->toContain('Relação de peças entregue')
        ->and($os->refresh()->workshop_status)->toBe(ServiceOrder::STAGE_DIAGNOSTICO);

    // Cada pendência resolvida some da lista.
    $os->forceFill(['diagnostico' => 'Bico injetor 3 travado', 'diagnostico_concluido_em' => now()])->save();
    ServiceOrderLaudoItem::create(['quote_id' => $os->id, 'tipo' => 'codigo', 'descricao' => 'P0203', 'valor' => 'Injetor cilindro 3']);

    $faltando = advancer()->advance($os->refresh(), ServiceOrder::STAGE_ORCAMENTO)['faltando'];
    expect($faltando)->toBe(['Visto do chefe de oficina no diagnóstico', 'Relação de peças entregue']);

    $os->forceFill(['diagnostico_visto_status' => 'aprovado', 'relacao_pecas_em' => now()])->save();

    $resultado = advancer()->advance($os->refresh(), ServiceOrder::STAGE_ORCAMENTO);

    expect($resultado['avancou'])->toBeTrue()
        ->and($os->refresh()->workshop_status)->toBe(ServiceOrder::STAGE_ORCAMENTO);
});

it('diagnóstico devolvido pelo chefe continua bloqueando', function () {
    $this->enablePilot();
    $os = $this->makeOs([
        'workshop_status' => ServiceOrder::STAGE_DIAGNOSTICO,
        'diagnostico' => 'x',
    ]);
    $os->forceFill(['diagnostico_concluido_em' => now(), 'diagnostico_visto_status' => 'devolvido', 'relacao_pecas_em' => now()])->save();
    ServiceOrderLaudoItem::create(['quote_id' => $os->id, 'tipo' => 'codigo', 'descricao' => 'P0001']);

    expect(advancer()->advance($os->refresh(), ServiceOrder::STAGE_ORCAMENTO)['faltando'])
        ->toBe(['Diagnóstico devolvido pelo chefe: corrigir e pedir novo visto']);
});

it('pede a causa do atraso quando o diagnóstico passou do tempo alvo do tipo de queixa', function () {
    $this->enablePilot();
    $tipo = DiagnosticoQueixaTipo::create(['nome' => 'Freios', 'tempo_alvo_min' => 60]);

    $os = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_DIAGNOSTICO, 'diagnostico' => 'Pastilha gasta']);
    $os->forceFill([
        'queixa_tipo_id' => $tipo->id,
        'diagnostico_inicio_em' => now()->subMinutes(100),
        'diagnostico_concluido_em' => now(),
        'diagnostico_visto_status' => 'aprovado',
        'relacao_pecas_em' => now(),
    ])->save();
    ServiceOrderLaudoItem::create(['quote_id' => $os->id, 'tipo' => 'medicao', 'descricao' => 'Espessura da pastilha', 'valor' => '2 mm']);

    expect(advancer()->advance($os->refresh(), ServiceOrder::STAGE_ORCAMENTO)['faltando'])
        ->toBe(['Causa do atraso no diagnóstico (passou do tempo alvo)']);

    $os->forceFill(['diagnostico_causa_atraso' => 'Falta de ferramenta'])->save();

    expect(advancer()->advance($os->refresh(), ServiceOrder::STAGE_ORCAMENTO)['avancou'])->toBeTrue();
});

it('Cadastro exige CPF/CNPJ válido, telefone, placa, km, combustível, itens, 4 fotos, termo assinado e cor de crédito', function () {
    $this->enablePilot();
    $os = $this->makeOs();

    $faltando = advancer()->advance($os, ServiceOrder::STAGE_ORDEM_SERVICO)['faltando'];

    expect($faltando)->toBe([
        'Km de entrada',
        'Nível de combustível',
        'Itens do veículo (descreva, ou escreva "sem itens nem avarias")',
        'Termo de entrada: fotos do veículo (mínimo 4, há 0)',
        'Termo de entrada assinado',
        'Cor de crédito (verde, amarelo ou vermelho)',
    ]);

    $os->forceFill(['km_entrada' => 350000, 'combustivel_entrada' => '1/2', 'itens_entrada' => 'Estepe e macaco', 'termo_assinado_em' => now(), 'autorizacao_rodagem' => true, 'cor_credito' => 'verde'])->save();

    foreach (['frente', 'traseira', 'lateral-esq', 'lateral-dir'] as $lado) {
        ServiceOrderAttachment::create(['quote_id' => $os->id, 'tipo' => 'termo_foto', 'path' => "x/{$lado}.jpg"]);
    }

    expect(advancer()->advance($os->refresh(), ServiceOrder::STAGE_ORDEM_SERVICO)['avancou'])->toBeTrue();
});

it('OS sem veículo (serviço só de software) não exige placa, km nem termo', function () {
    $this->enablePilot();
    $os = $this->makeOs(['vehicle_id' => null]);

    expect(advancer()->advance($os, ServiceOrder::STAGE_ORDEM_SERVICO)['faltando'])
        ->toBe(['Cor de crédito (verde, amarelo ou vermelho)']);
});

it('OS → Diagnóstico exige técnico designado', function () {
    $this->enablePilot();
    $os = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_ORDEM_SERVICO]);

    expect(advancer()->advance($os, ServiceOrder::STAGE_DIAGNOSTICO)['faltando'])->toBe(['Técnico designado']);

    $os->forceFill(['diagnostico_mechanic_id' => $this->makeMechanic()->id])->save();

    expect(advancer()->advance($os->refresh(), ServiceOrder::STAGE_DIAGNOSTICO)['avancou'])->toBeTrue();
});

it('Negociação → Execução exige aprovação do cliente (meio e hora) e forma de pagamento', function () {
    $this->enablePilot();
    $os = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_NEGOCIACAO]);

    expect(advancer()->advance($os, ServiceOrder::STAGE_EM_EXECUCAO)['faltando'])
        ->toBe(['Aprovação do cliente registrada (meio e hora)', 'Forma de pagamento']);

    $os->forceFill(['aprovacao_tipo' => 'recusa', 'aprovacao_em' => now(), 'aprovacao_meio' => 'WhatsApp', 'condicao_pagamento' => 'À vista'])->save();

    expect(advancer()->advance($os->refresh(), ServiceOrder::STAGE_EM_EXECUCAO)['faltando'])->toBe(['Cliente recusou o orçamento']);

    $os->forceFill(['aprovacao_tipo' => 'total'])->save();

    $resultado = advancer()->advance($os->refresh(), ServiceOrder::STAGE_EM_EXECUCAO);

    expect($resultado['avancou'])->toBeTrue()
        ->and($os->refresh()->execucao_liberada_em)->not->toBeNull()
        ->and(ServiceOrderEvent::where('service_order_id', $os->id)->where('tipo_evento', 'execucao_liberada')->count())->toBe(1);
});

it('Execução → Teste exige todas as operações com início e fim', function () {
    $this->enablePilot();
    $mechanic = $this->makeMechanic();
    $os = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_EM_EXECUCAO, 'mechanic_id' => $mechanic->id]);
    $item = $this->makeServiceItem($os, 1.0, $mechanic->id, 'Troca de embreagem');

    expect(advancer()->advance($os, ServiceOrder::STAGE_VEICULO_TESTE)['faltando'])
        ->toBe(['Operação sem início/fim apontado: Troca de embreagem']);

    $operations = app(OperationService::class);
    $this->at('2026-11-18 08:00:00');
    $operations->start($item);
    $this->at('2026-11-18 08:50:00');
    $operations->finish($item);

    expect(advancer()->advance($os->refresh(), ServiceOrder::STAGE_VEICULO_TESTE)['avancou'])->toBeTrue();
});

it('critério 5: o veículo não recebe saída sem NF e sem pagamento ou acordo', function () {
    $this->enablePilot();
    $os = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_FATURAMENTO]);

    // Sem o checklist de saída (horas reais e próxima revisão) também não sai.
    expect(advancer()->advance($os, ServiceOrder::STAGE_POS_VENDA)['faltando'])
        ->toBe(['Checklist de saída com horas reais e próxima revisão', 'Nota fiscal emitida', 'Pagamento confirmado ou acordo assinado']);

    $os->forceFill(['checklist_saida_em' => now()])->save();

    expect(advancer()->advance($os->refresh(), ServiceOrder::STAGE_POS_VENDA)['faltando'])
        ->toBe(['Nota fiscal emitida', 'Pagamento confirmado ou acordo assinado']);

    $os->forceFill(['nf_manual_em' => now(), 'nf_manual_numero' => '123'])->save();

    expect(advancer()->advance($os->refresh(), ServiceOrder::STAGE_POS_VENDA)['faltando'])->toBe(['Pagamento confirmado ou acordo assinado']);

    $os->forceFill(['acordo_assinado_em' => now()])->save();

    $resultado = advancer()->advance($os->refresh(), ServiceOrder::STAGE_POS_VENDA);

    expect($resultado['avancou'])->toBeTrue()
        ->and($os->refresh()->saida_em)->not->toBeNull()
        ->and(ServiceOrderEvent::where('service_order_id', $os->id)->where('tipo_evento', 'saida_veiculo')->first()->dados_json['sem_nf'])->toBeFalse();
});

it('frota com faturamento mensal é a exceção: sai sem NF e sem pagamento', function () {
    $this->enablePilot();
    $person = $this->makePerson();
    $vehicle = $this->makeVehicle($person->id, ['frota' => true]);
    $os = $this->makeOs(['person_id' => $person->id, 'vehicle_id' => $vehicle->id, 'workshop_status' => ServiceOrder::STAGE_FATURAMENTO]);
    $os->forceFill(['checklist_saida_em' => now()])->save();

    expect(advancer()->advance($os->refresh(), ServiceOrder::STAGE_POS_VENDA)['avancou'])->toBeTrue()
        ->and(WorkshopAlert::count())->toBe(0);
});

it('forçar a saída sem NF exige justificativa e gera alerta crítico ao gerente e ao proprietário', function () {
    $this->enablePilot();
    $gerente = $this->makeUser('gerente');
    $dono = $this->makeUser('proprietario');
    $consultor = $this->makeUser('consultor');
    $os = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_FATURAMENTO]);

    expect(fn () => advancer()->advance($os, ServiceOrder::STAGE_POS_VENDA, true, ''))->toThrow(ValidationException::class)
        ->and($os->refresh()->workshop_status)->toBe(ServiceOrder::STAGE_FATURAMENTO);

    $resultado = advancer()->advance($os, ServiceOrder::STAGE_POS_VENDA, true, 'Cliente com urgência, NF sai amanhã');

    expect($resultado['avancou'])->toBeTrue()
        ->and($resultado['forcado'])->toBeTrue()
        ->and($os->refresh()->workshop_status)->toBe(ServiceOrder::STAGE_POS_VENDA);

    $forcado = ServiceOrderEvent::where('service_order_id', $os->id)->where('tipo_evento', 'bloqueio_forcado')->first();

    expect($forcado->dados_json['justificativa'])->toBe('Cliente com urgência, NF sai amanhã')
        ->and($forcado->dados_json['escala_dono'])->toBeTrue();

    $criticos = WorkshopAlert::where('critico', true)->get();

    // Gerente e proprietário recebem; o consultor não.
    expect($criticos->where('user_id', $dono->id)->count())->toBeGreaterThanOrEqual(1)
        ->and($criticos->where('user_id', $gerente->id)->count())->toBeGreaterThanOrEqual(1)
        ->and($criticos->where('user_id', $consultor->id)->count())->toBe(0)
        ->and($criticos->pluck('barrier_code')->unique()->sort()->values()->all())->toBe(['critico_bloqueio_forcado', 'critico_saida_sem_nota']);
});

it('avanço forçado de etapa que não é pagamento nem saída avisa só o gerente', function () {
    $this->enablePilot();
    $gerente = $this->makeUser('gerente');
    $dono = $this->makeUser('proprietario');
    $os = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_ORDEM_SERVICO]);

    advancer()->advance($os, ServiceOrder::STAGE_DIAGNOSTICO, true, 'Técnico será definido no box');

    expect(WorkshopAlert::where('user_id', $gerente->id)->count())->toBe(1)
        ->and(WorkshopAlert::where('user_id', $dono->id)->count())->toBe(0);
});

it('com o piloto ligado, mudar a etapa por fora do botão Avançar é recusado', function () {
    $this->enablePilot();
    $os = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_DIAGNOSTICO]);
    $admin = getDefaultAdmin();

    $this->actingAs($admin, 'user');

    expect(fn () => $os->update(['workshop_status' => ServiceOrder::STAGE_FATURAMENTO]))->toThrow(ValidationException::class)
        ->and($os->refresh()->workshop_status)->toBe(ServiceOrder::STAGE_DIAGNOSTICO);
});

it('com o piloto desligado o fluxo antigo segue sem nenhum bloqueio', function () {
    $os = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_DIAGNOSTICO]);

    expect(StageGate::active())->toBeFalse()
        ->and(advancer()->advance($os, ServiceOrder::STAGE_ORCAMENTO)['avancou'])->toBeTrue();
});
