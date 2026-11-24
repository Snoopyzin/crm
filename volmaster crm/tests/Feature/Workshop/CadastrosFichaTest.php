<?php

use Illuminate\Support\Facades\DB;
use Webkul\ServiceOrder\Models\Colaborador;
use Webkul\ServiceOrder\Models\IndicatorDefinition;
use Webkul\ServiceOrder\Models\MentoriaRegistro;
use Webkul\ServiceOrder\Models\WorkshopAlert;
use Webkul\ServiceOrder\Services\FichaEnquadramento;
use Webkul\ServiceOrder\Services\Indicators\IndicatorService;
use Webkul\ServiceOrder\Services\PeriodicAlerts;
use Webkul\ServiceOrder\Services\WorkshopSettings;
use Webkul\User\Models\User;

function rh(array $extra = []): User
{
    return test()->makeUser('gerente', array_merge(['settings', 'settings.ficha', 'settings.ficha.editar', 'colaboradores'], $extra));
}

function colaborador(array $dados = []): Colaborador
{
    return Colaborador::create(array_merge(['nome' => 'Fulano', 'cargos' => 'tecnico', 'classificacao' => 'oficina', 'ativo' => true], $dados));
}

beforeEach(function () {
    IndicatorDefinition::forgetSeeded();
});

it('cadastra colaborador com cargos acumulados, vencimentos e CNH; a CNH vai para o técnico vinculado', function () {
    $this->actingAs(rh(), 'user');
    $mecanico = $this->makeMechanic('Zé');

    $this->post(route('admin.colaboradores.store'), [
        'nome' => 'José da Silva',
        'cpf' => '123.456.789-00',
        'mechanic_id' => $mecanico->id,
        'cargos' => ['tecnico', 'chefe', 'cargo_inventado'],
        'classificacao' => 'oficina',
        'salario_base' => '4.500,00',
        'admissao' => '2026-11-03',
        'registro_antes_inicio' => '1',
        'ferias_vencimento' => '2027-03-01',
        'exame_periodico_vencimento' => '2027-01-10',
        'epi_vencimento' => '2027-02-01',
        'cnh_categoria' => 'e',
        'competencias' => ['Motor' => 3, 'Elétrica' => 1],
    ])->assertSessionHasErrors('cargos.2');

    $this->post(route('admin.colaboradores.store'), [
        'nome' => 'José da Silva',
        'mechanic_id' => $mecanico->id,
        'cargos' => ['tecnico', 'chefe'],
        'classificacao' => 'oficina',
        'salario_base' => '4.500,00',
        'admissao' => '2026-11-03',
        'registro_antes_inicio' => '1',
        'cnh_categoria' => 'e',
        'competencias' => ['Motor' => 3, 'Elétrica' => 1],
    ])->assertRedirect(route('admin.colaboradores.index'));

    $colaborador = Colaborador::firstOrFail();

    expect($colaborador->cargosLista())->toBe(['chefe', 'tecnico'])
        ->and($colaborador->salario_base)->toBe(4500.0)
        ->and($colaborador->cnh_categoria)->toBe('E')
        ->and($colaborador->competencias)->toBe(['Motor' => 3, 'Elétrica' => 1])
        ->and(DB::table('mechanics')->where('id', $mecanico->id)->value('cnh_categoria'))->toBe('E')
        ->and(MentoriaRegistro::where('tipo', 'admissao')->count())->toBe(1);

    $this->get(route('admin.colaboradores.index'))->assertOk()->assertSee('José da Silva')->assertSee('Chefe de oficina + Técnico');
    $this->get(route('admin.colaboradores.edit', $colaborador->id))->assertOk()->assertSee('Editar José da Silva');

    $this->put(route('admin.colaboradores.update', $colaborador->id), ['nome' => 'José S.', 'cargos' => ['tecnico'], 'classificacao' => 'administracao'])->assertRedirect();

    expect($colaborador->refresh()->nome)->toBe('José S.')
        ->and($colaborador->classificacao)->toBe('administracao');
});

it('desligar mantém o cadastro, tira do quadro e registra o desligamento', function () {
    $this->actingAs(rh(), 'user');
    $colaborador = colaborador();

    $this->post(route('admin.colaboradores.desligar', $colaborador->id), ['desligamento_em' => '2026-11-15'])->assertRedirect();

    expect($colaborador->refresh()->ativo)->toBeFalse()
        ->and($colaborador->desligamento_em->toDateString())->toBe('2026-11-15')
        ->and(MentoriaRegistro::where('tipo', 'desligamento')->count())->toBe(1)
        ->and(Colaborador::ativos()->count())->toBe(0);
});

it('indicadores de RH leem o cadastro de colaboradores', function () {
    $this->at('2026-11-20 10:00:00');

    colaborador(['nome' => 'A', 'ferias_vencimento' => '2026-10-01', 'exame_periodico_vencimento' => '2027-01-01', 'epi_vencimento' => '2027-01-01', 'admissao' => '2026-11-02', 'registro_antes_inicio' => true]);
    colaborador(['nome' => 'B', 'ferias_vencimento' => '2027-02-01', 'exame_periodico_vencimento' => '2026-09-01', 'epi_vencimento' => '2027-01-01', 'admissao' => '2026-11-09', 'registro_antes_inicio' => false]);
    colaborador(['nome' => 'C', 'cargos' => 'gerente', 'ferias_vencimento' => '2027-02-01', 'exame_periodico_vencimento' => '2027-01-01', 'epi_vencimento' => '2027-01-01']);
    colaborador(['nome' => 'D', 'ativo' => false, 'desligamento_em' => '2026-11-10']);

    $servico = app(IndicatorService::class);
    $valor = fn ($codigo) => $servico->evaluate($codigo, '2026-11')['resultado']->realizado;

    expect($valor('GER-15'))->toBe(1.0)
        // Exame/EPI em dia: C e A (B tem exame vencido) => 2 de 3.
        ->and($valor('GER-16'))->toBe(66.7)
        // Admitidos no mês: A (registro antes) e B (não) => 50%.
        ->and($valor('GER-13'))->toBe(50.0)
        // 1 desligado para um quadro médio de 3 + 0,5.
        ->and($valor('GER-20'))->toBe(round(1 / 3.5 * 100, 1));
});

it('ficha de enquadramento: salva valores, garantia, bônus por cargo e fase da mentoria', function () {
    $this->actingAs(rh(), 'user');

    $this->get(route('admin.ficha.edit'))->assertOk()->assertSee('Ficha de Enquadramento')->assertSee('sem responsável');

    $this->put(route('admin.ficha.update'), [
        'ficha_razao_social' => 'Oficina Diesel Norte Ltda',
        'ficha_cnpj' => '12.345.678/0001-90',
        'ficha_cidade' => 'Goiânia',
        'ficha_porte' => '7_a_15',
        'ficha_boxes' => 4,
        'ficha_elevadores' => 2,
        'ficha_veiculo_externo' => '1',
        'valor_hora_mao_obra' => '180,00',
        'diaria_patio_valor' => '120,00',
        'diaria_patio_horas_pronto' => 24,
        'diaria_patio_horas_nao_autorizado' => 48,
        'garantia_servico_dias' => 90,
        'garantia_pecas_dias' => 120,
        'horario_cafe' => '09:30',
        'horario_lanche' => '15:30',
        'dono_horas_prospeccao' => 6,
        'gatilho_resultado' => '250.000,00',
        'meta_faturamento_mensal' => '300.000,00',
        'inicio_mentoria' => '2026-10-05',
        'bonus_cheio' => ['tecnico' => '15', 'gerente' => '20,5', 'cargo_inventado' => '99'],
    ])->assertRedirect(route('admin.ficha.edit'));

    WorkshopSettings::flush();

    expect(WorkshopSettings::get('ficha_cidade'))->toBe('Goiânia')
        ->and(WorkshopSettings::get('valor_hora_mao_obra'))->toEqual(180)
        ->and(WorkshopSettings::get('gatilho_resultado'))->toEqual(250000)
        ->and(WorkshopSettings::get('garantia_pecas_dias'))->toBe(120)
        ->and(WorkshopSettings::get('bonus_cheio_percentual'))->toEqual(['tecnico' => 15, 'gerente' => 20.5])
        ->and(WorkshopSettings::get('dono_horas_prospeccao'))->toEqual(6);

    // Mês 1 e 2 = fase 1; do mês 3 em diante = fase 2.
    expect(FichaEnquadramento::mesDaMentoria('2026-10'))->toBe(1)
        ->and(FichaEnquadramento::fase('2026-10'))->toBe(1)
        ->and(FichaEnquadramento::fase('2026-11'))->toBe(1)
        ->and(FichaEnquadramento::mesDaMentoria('2026-12'))->toBe(3)
        ->and(FichaEnquadramento::fase('2026-12'))->toBe(2)
        ->and(FichaEnquadramento::fase('2027-03'))->toBe(2)
        ->and(FichaEnquadramento::mesDaMentoria('2026-09'))->toBeNull();

    // A meta de horas do dono (DON-01) vem da Ficha.
    expect(app(IndicatorService::class)->evaluate('DON-01', '2026-11')['meta'])->toBe(6.0);
});

it('sem data de início a mentoria fica na fase 1', function () {
    expect(FichaEnquadramento::fase('2027-06'))->toBe(1);
});

it('cargos sem responsável viram pendência com ação e prazo, e o gerente é avisado quando o prazo passa', function () {
    $this->enablePilot();
    $gerente = rh();
    $this->actingAs($gerente, 'user');
    colaborador(['nome' => 'Gerente', 'cargos' => 'gerente,proprietario']);

    $ficha = app(FichaEnquadramento::class);

    expect($ficha->cargosSemResponsavel())->toBe(['chefe', 'consultor', 'tecnico', 'pecas', 'financeiro'])
        ->and($ficha->cargosOcupados())->toBe(['gerente' => ['Gerente'], 'proprietario' => ['Gerente']]);

    $this->post(route('admin.ficha.pendencia'), ['cargo' => 'chefe', 'acao' => 'contratar', 'gatilho' => 'Quando passar de 6 OS/dia', 'prazo' => '2026-11-10'])->assertRedirect();
    $this->post(route('admin.ficha.pendencia'), ['cargo' => 'pecas', 'acao' => 'qualquer'])->assertSessionHasErrors('acao');

    expect($ficha->pendencias())->toHaveCount(1);

    $periodicos = app(PeriodicAlerts::class);

    // Antes do prazo: nada.
    expect($periodicos->cargosSemResponsavel($this->at('2026-11-09 10:00:00')))->toBe(0);

    // Prazo vencido: o gerente recebe, o dono não.
    expect($periodicos->cargosSemResponsavel($this->at('2026-11-12 10:00:00')))->toBe(1)
        ->and(WorkshopAlert::where('barrier_code', 'cargo_sem_responsavel')->pluck('user_id')->all())->toBe([$gerente->id]);

    $this->post(route('admin.ficha.pendencia.resolver', DB::table('cargos_pendentes')->value('id')))->assertRedirect();

    expect($ficha->pendencias())->toHaveCount(0);
});

it('RH a vencer em 30 dias avisa o gerente com o nome do colaborador e sem valores', function () {
    $this->enablePilot();
    $gerente = $this->makeUser('gerente');
    colaborador(['nome' => 'Maria Zeladora', 'cargos' => 'zeladoria', 'exame_periodico_vencimento' => '2026-12-01', 'ferias_vencimento' => '2027-06-01', 'epi_vencimento' => '2027-06-01', 'salario_base' => 2000]);

    $criados = app(PeriodicAlerts::class)->rhAVencer($this->at('2026-11-18 10:00:00'));

    $alerta = WorkshopAlert::where('barrier_code', 'rh_a_vencer')->first();

    expect($criados)->toBe(1)
        ->and($alerta->user_id)->toBe($gerente->id)
        ->and($alerta->mensagem)->toContain('Exame periódico de Maria Zeladora vence em 01/12/2026')
        ->and($alerta->mensagem)->not->toContain('2000')->not->toContain('R$');
});

it('tabela de margem de peças: salva faixas e ignora linhas vazias', function () {
    $this->actingAs(rh(), 'user');

    $this->post(route('admin.ficha.margens'), ['faixa' => [
        ['custo_ate' => '100,00', 'margem_minima' => '40', 'margem_alvo' => '60'],
        ['custo_ate' => '1.000,00', 'margem_minima' => '25', 'margem_alvo' => '40'],
        ['custo_ate' => '', 'margem_minima' => '15,5', 'margem_alvo' => '25'],
        ['custo_ate' => '', 'margem_minima' => '', 'margem_alvo' => ''],
    ]])->assertRedirect();

    $faixas = DB::table('margem_pecas_faixas')->orderByRaw('custo_ate is null, custo_ate')->get();

    expect($faixas)->toHaveCount(3)
        ->and((float) $faixas[0]->custo_ate)->toBe(100.0)
        ->and((float) $faixas[1]->custo_ate)->toBe(1000.0)
        ->and($faixas[2]->custo_ate)->toBeNull()
        ->and((float) $faixas[2]->margem_minima)->toBe(15.5);
});

it('só quem tem permissão acessa colaboradores e a ficha', function () {
    $tecnico = $this->makeUser('tecnico', ['service_orders', 'tecnico']);
    $this->actingAs($tecnico, 'user');

    $this->get(route('admin.colaboradores.index'))->assertForbidden();
    $this->get(route('admin.ficha.edit'))->assertForbidden();
    $this->post(route('admin.colaboradores.store'), ['nome' => 'X', 'classificacao' => 'oficina'])->assertForbidden();
});
