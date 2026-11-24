<?php

use Carbon\Carbon;
use Webkul\ServiceOrder\Models\BonusApuracao;
use Webkul\ServiceOrder\Models\BonusMarcador;
use Webkul\ServiceOrder\Models\BonusTrava;
use Webkul\ServiceOrder\Models\Colaborador;
use Webkul\ServiceOrder\Models\IndicatorDefinition;
use Webkul\ServiceOrder\Models\MentoriaRegistro;
use Webkul\ServiceOrder\Services\BusinessHours;
use Webkul\ServiceOrder\Services\WorkshopSettings;
use Webkul\User\Models\User;

const MES_BONUS = '2026-10';

beforeEach(function () {
    IndicatorDefinition::forgetSeeded();
    BonusMarcador::forgetSeeded();
    WorkshopSettings::set('bonus_cheio_percentual', ['zeladoria' => 10]);
});

function bonusGerente(): User
{
    return test()->makeUser('gerente', ['bonus_mentoria', 'bonus_gerente', 'painel_dono', 'indicadores_mentoria']);
}

function bonusDono(): User
{
    return test()->makeUser('proprietario', ['bonus_mentoria', 'bonus_gerente', 'bonus_dono', 'painel_dono', 'ver_valores_financeiros', 'alcadas']);
}

function zeladoraDeOutubro(?User $usuario = null): Colaborador
{
    $zeladora = Colaborador::create(['nome' => 'Dona Maria', 'cpf' => '111.222.333-44', 'cargos' => 'zeladoria', 'user_id' => $usuario?->id, 'salario_base' => 2000, 'ativo' => true]);

    $dia = Carbon::parse('2026-10-01');

    while ($dia->month === 10) {
        if ((new BusinessHours)->isBusinessDay($dia)) {
            MentoriaRegistro::record('zel_limpeza', ['ok' => true], $dia->copy()->setTime(18, 0));
            MentoriaRegistro::record('zel_cafe', ['ok' => true], $dia->copy()->setTime(9, 0));
            foreach (range(1, 3) as $vez) {
                MentoriaRegistro::record('zel_banheiro', ['ok' => true], $dia->copy()->setTime(8 + $vez * 2, 0));
            }
        }
        $dia->addDay();
    }

    MentoriaRegistro::record('zel_auditoria', ['valor' => 9], Carbon::parse('2026-10-10 10:00'));

    return $zeladora;
}

it('o gerente calcula as notas, revisa com feedback e o dono aprova dentro da janela', function () {
    $this->enablePilot();
    $this->at('2026-11-03 09:00:00');
    zeladoraDeOutubro();
    $gerente = bonusGerente();
    $dono = bonusDono();

    $this->actingAs($gerente, 'user')->post(route('admin.mentoria.bonus.calcular'), ['periodo' => MES_BONUS])->assertRedirect()->assertSessionHas('success');

    $apuracao = BonusApuracao::firstOrFail();

    expect($apuracao->nota)->toBe(100.0);

    $this->get(route('admin.mentoria.bonus', ['periodo' => MES_BONUS]))->assertOk()->assertSee('Dona Maria')->assertSee('100,0')->assertSee('Calculado');
    $this->get(route('admin.mentoria.bonus.detalhe', $apuracao->id))->assertOk()->assertSee('ZEL-01')->assertSee('Marcadores');

    // O gerente não aprova (rota do dono).
    $this->post(route('admin.mentoria.bonus.aprovar', $apuracao->id))->assertForbidden();

    // Sem feedback não revisa.
    $this->post(route('admin.mentoria.bonus.revisar', $apuracao->id), ['feedback' => ' '])->assertSessionHas('error');
    $this->post(route('admin.mentoria.bonus.revisar', $apuracao->id), ['feedback' => 'Mês excelente.'])->assertSessionHas('success');

    expect($apuracao->refresh()->status)->toBe('revisado');

    // O dono aprova dentro da janela (3/11) e vê o valor.
    $this->actingAs($dono, 'user')->get(route('admin.mentoria.bonus', ['periodo' => MES_BONUS]))->assertOk()->assertSee('R$ 200,00')->assertSee('Exportar folha');
    $this->post(route('admin.mentoria.bonus.aprovar', $apuracao->id))->assertSessionHas('success');

    expect($apuracao->refresh()->status)->toBe('aprovado');

    // Exportação da folha: CSV com BOM, só o aprovado.
    $csv = $this->get(route('admin.mentoria.bonus.exportar', ['periodo' => MES_BONUS]));

    $csv->assertOk();
    expect($csv->headers->get('Content-Disposition'))->toContain('folha-bonus-2026-10.csv')
        ->and($csv->getContent())->toStartWith("\xEF\xBB\xBFcolaborador;cpf;cargo;nota;faixa_percentual;trava;valor")
        ->and($csv->getContent())->toContain('Dona Maria;111.222.333-44;zeladoria;100,0;100;nao;200,00');
});

it('aprovação fora da janela (depois do 5º dia útil) é recusada com mensagem', function () {
    $this->enablePilot();
    $this->at('2026-11-12 09:00:00');
    zeladoraDeOutubro();
    $dono = bonusDono();
    $gerente = bonusGerente();

    $this->actingAs($gerente, 'user')->post(route('admin.mentoria.bonus.calcular'), ['periodo' => MES_BONUS]);
    $apuracao = BonusApuracao::firstOrFail();
    $this->post(route('admin.mentoria.bonus.revisar', $apuracao->id), ['feedback' => 'ok']);

    $this->actingAs($dono, 'user')->post(route('admin.mentoria.bonus.aprovar', $apuracao->id))
        ->assertSessionHas('error', 'A aprovação do bônus vai do dia 1 ao 5º dia útil do mês seguinte.');

    expect($apuracao->refresh()->status)->toBe('revisado');
});

it('aprovar todos os revisados de uma vez e estornar com justificativa', function () {
    $this->enablePilot();
    $this->at('2026-11-04 09:00:00');
    zeladoraDeOutubro();
    $gerente = bonusGerente();
    $dono = bonusDono();

    $this->actingAs($gerente, 'user')->post(route('admin.mentoria.bonus.calcular'), ['periodo' => MES_BONUS]);
    $apuracao = BonusApuracao::firstOrFail();

    $this->actingAs($dono, 'user')->post(route('admin.mentoria.bonus.aprovar_todos'), ['periodo' => MES_BONUS])->assertSessionHas('error');

    $this->actingAs($gerente, 'user')->post(route('admin.mentoria.bonus.revisar', $apuracao->id), ['feedback' => 'Bom']);
    $this->actingAs($dono, 'user')->post(route('admin.mentoria.bonus.aprovar_todos'), ['periodo' => MES_BONUS])->assertSessionHas('success');

    expect($apuracao->refresh()->status)->toBe('aprovado');

    $this->post(route('admin.mentoria.bonus.estornar', $apuracao->id), ['justificativa' => ''])->assertSessionHas('error');
    $this->post(route('admin.mentoria.bonus.estornar', $apuracao->id), ['justificativa' => 'Pagamento em duplicidade'])->assertSessionHas('success');

    expect($apuracao->refresh()->status)->toBe('calculado')
        ->and(MentoriaRegistro::where('tipo', 'bonus_estorno')->count())->toBe(1);
});

it('travas pela tela: registrar com evidência, confirmar e o bônus zera', function () {
    $this->enablePilot();
    $this->at('2026-11-03 09:00:00');
    $zeladora = zeladoraDeOutubro();
    $gerente = bonusGerente();
    $this->actingAs($gerente, 'user');

    $this->post(route('admin.mentoria.bonus.calcular'), ['periodo' => MES_BONUS]);

    $this->get(route('admin.mentoria.bonus.travas', ['periodo' => MES_BONUS]))->assertOk()->assertSee('Registrar uma trava');

    $this->post(route('admin.mentoria.bonus.travas.store'), [
        'periodo' => MES_BONUS, 'colaborador_id' => $zeladora->id, 'cargo' => 'zeladoria', 'tipo' => 'falta_injustificada', 'descricao' => 'Faltou dia 20', 'evidencia' => '',
    ])->assertSessionHasErrors('evidencia');

    $this->post(route('admin.mentoria.bonus.travas.store'), [
        'periodo' => MES_BONUS, 'colaborador_id' => $zeladora->id, 'cargo' => 'zeladoria', 'tipo' => 'falta_injustificada', 'descricao' => 'Faltou dia 20', 'evidencia' => 'Livro de ponto, 20/10',
    ])->assertSessionHas('success');

    $trava = BonusTrava::firstOrFail();

    $this->post(route('admin.mentoria.bonus.travas.decidir', $trava->id), ['decisao' => 'rejeitar'])->assertSessionHas('error');
    $this->post(route('admin.mentoria.bonus.travas.decidir', $trava->id), ['decisao' => 'confirmar'])->assertSessionHas('success');

    $apuracao = BonusApuracao::firstOrFail();

    expect($trava->refresh()->status)->toBe('confirmada')
        ->and($apuracao->zerado_por_trava)->toBeTrue()
        ->and($apuracao->valor)->toBe(0.0);

    $this->get(route('admin.mentoria.bonus', ['periodo' => MES_BONUS]))->assertOk()->assertSee('sim');
});

it('meu bônus: o colaborador só vê a nota depois da revisão e registra a ciência', function () {
    $this->enablePilot();
    $this->at('2026-11-03 09:00:00');
    $usuario = $this->makeUser('tecnico', ['bonus_meu']);
    zeladoraDeOutubro($usuario);
    $gerente = bonusGerente();

    $this->actingAs($gerente, 'user')->post(route('admin.mentoria.bonus.calcular'), ['periodo' => MES_BONUS]);
    $apuracao = BonusApuracao::firstOrFail();

    $this->actingAs($usuario, 'user')->get(route('admin.mentoria.bonus.meu'))->assertOk()->assertDontSee('2026-10');

    $this->actingAs($gerente, 'user')->post(route('admin.mentoria.bonus.revisar', $apuracao->id), ['feedback' => 'Parabéns!']);

    $this->actingAs($usuario, 'user')->get(route('admin.mentoria.bonus.meu'))->assertOk()->assertSee('2026-10')->assertSee('Parabéns!')->assertSee('Estou ciente');

    // Outro usuário não registra a ciência da nota alheia.
    $outro = $this->makeUser('tecnico', ['bonus_meu']);
    $this->actingAs($outro, 'user')->post(route('admin.mentoria.bonus.ciencia', $apuracao->id))->assertSessionHas('error');

    $this->actingAs($usuario, 'user')->post(route('admin.mentoria.bonus.ciencia', $apuracao->id))->assertSessionHas('success');

    expect($apuracao->refresh()->ciencia_colaborador_em)->not->toBeNull();

    // O técnico (sem a permissão de valores) não vê o valor em reais.
    $this->get(route('admin.mentoria.bonus.meu'))->assertDontSee('valor R$');
});

it('Painel do Dono (bloco 6) mostra a tabela de bônus e o botão de aprovar; sem permissão, a tela de bônus é barrada', function () {
    $this->enablePilot();
    $this->at('2026-11-03 09:00:00');
    zeladoraDeOutubro();
    $gerente = bonusGerente();
    $dono = bonusDono();

    $this->actingAs($gerente, 'user')->post(route('admin.mentoria.bonus.calcular'), ['periodo' => MES_BONUS]);
    $this->post(route('admin.mentoria.bonus.revisar', BonusApuracao::firstOrFail()->id), ['feedback' => 'ok']);

    $this->actingAs($dono, 'user')->get(route('admin.mentoria.painel', ['periodo' => MES_BONUS]))
        ->assertOk()
        ->assertSee('Equipe e bônus')
        ->assertSee('Dona Maria')
        ->assertSee('Aprovar bônus')
        ->assertSee('Fila de alçadas do dono');

    $semAcesso = $this->makeUser('consultor', ['service_orders', 'service_orders.view']);
    $this->actingAs($semAcesso, 'user')->get(route('admin.mentoria.bonus'))->assertForbidden();
    $this->get(route('admin.mentoria.bonus.travas'))->assertForbidden();
    $this->get(route('admin.mentoria.bonus.exportar'))->assertForbidden();
});
