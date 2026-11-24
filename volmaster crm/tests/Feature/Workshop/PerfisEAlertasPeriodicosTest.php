<?php

use Illuminate\Support\Facades\DB;
use Webkul\ServiceOrder\Models\MentoriaRegistro;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Models\ServiceOrderEvent;
use Webkul\ServiceOrder\Models\WorkshopAlert;
use Webkul\ServiceOrder\Services\Alcada\AlcadaService;
use Webkul\ServiceOrder\Services\BarrierEngine;
use Webkul\ServiceOrder\Services\PeriodicAlerts;
use Webkul\ServiceOrder\Services\RecipientResolver;

it('acúmulo de cargos: uma função com chefe e técnico recebe os alertas dos dois papéis', function () {
    $this->enablePilot([], ['relacao_pecas']);
    $mecanico = $this->makeMechanic('Mestre');

    $acumulado = $this->makeUser('chefe', null, ['mechanic_id' => $mecanico->id]);
    DB::table('roles')->where('id', $acumulado->role_id)->update(['perfil_oficina' => 'chefe,tecnico']);
    $gerente = $this->makeUser('gerente');

    $resolver = app(RecipientResolver::class);

    expect($resolver->byPerfil('chefe')->pluck('id')->all())->toBe([$acumulado->id])
        ->and($resolver->byPerfil('tecnico')->pluck('id')->all())->toBe([$acumulado->id])
        ->and($resolver->byPerfil('gerente')->pluck('id')->all())->toBe([$gerente->id])
        // "chefe" não casa com um papel que só contém o texto parecido.
        ->and($resolver->byPerfil('chef')->count())->toBe(0);

    // Barreira de relação de peças alerta técnico e chefe: a mesma pessoa, uma vez só.
    $os = $this->makeOs(['mechanic_id' => $mecanico->id]);
    ServiceOrderEvent::record($os, 'diagnostico_fim', [], null, null, $this->at('2026-11-18 09:00:00'));
    app(BarrierEngine::class)->tick($this->at('2026-11-18 09:30:00'));

    expect(WorkshopAlert::where('barrier_code', 'relacao_pecas')->where('user_id', $acumulado->id)->count())->toBe(1);
});

it('acúmulo de cargos: quem acumula gerente e proprietário decide no nível mais alto', function () {
    $dupla = $this->makeUser('gerente');
    DB::table('roles')->where('id', $dupla->role_id)->update(['perfil_oficina' => 'gerente,proprietario']);

    expect(app(AlcadaService::class)->levelOf($dupla->fresh()))->toBe(2);
});

it('tela de papéis: marcar vários papéis na mesma função e desmarcar todos', function () {
    $this->enablePilot();
    $admin = $this->makeUser('proprietario', ['settings', 'settings.workshop']);
    $funcao = $this->makeRole('chefe');
    $this->actingAs($admin, 'user');

    $this->put(route('admin.workshop.settings.roles'), ['papel' => [$funcao->id => ['', 'tecnico', 'chefe', 'papel_inventado']]])->assertRedirect();

    // Ordem fixa da lista, sem papel inventado.
    expect(DB::table('roles')->where('id', $funcao->id)->value('perfil_oficina'))->toBe('chefe,tecnico');

    $this->put(route('admin.workshop.settings.roles'), ['papel' => [$funcao->id => ['']]])->assertRedirect();

    expect(DB::table('roles')->where('id', $funcao->id)->value('perfil_oficina'))->toBeNull();
});

it('OS parada há mais de 3 dias avisa o gerente, uma vez por dia, e nunca o dono', function () {
    $this->enablePilot([], []);
    $gerente = $this->makeUser('gerente');
    $dono = $this->makeUser('proprietario');
    $periodicos = app(PeriodicAlerts::class);

    $parada = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_ORCAMENTO]);
    $recente = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_ORCAMENTO]);

    DB::table('service_order_events')->where('service_order_id', $parada->id)->where('tipo_evento', 'mudanca_etapa')
        ->update(['ocorrido_em' => now()->subDays(4)]);

    expect($periodicos->osParadas(now()))->toBe(1);

    $alerta = WorkshopAlert::where('barrier_code', 'os_parada')->first();

    expect($alerta->user_id)->toBe($gerente->id)
        ->and($alerta->service_order_id)->toBe($parada->id)
        ->and($alerta->mensagem)->toContain('OS #'.$parada->id)->not->toContain('R$')
        ->and(WorkshopAlert::where('user_id', $dono->id)->count())->toBe(0);

    // Rodar de novo no mesmo dia não repete.
    expect($periodicos->osParadas(now()))->toBe(0);
});

it('indicador vermelho há mais de 7 dias sem plano de ação avisa o gerente, e com plano não avisa', function () {
    $this->enablePilot();
    $gerente = $this->makeUser('gerente');
    $periodicos = app(PeriodicAlerts::class);

    // Duas ocorrências de celular na execução deixam TEC-14 vermelho.
    $this->at('2026-11-10 09:00:00');
    MentoriaRegistro::record('celular_execucao', [], now());
    MentoriaRegistro::record('celular_execucao', [], now()->addMinute());

    // Primeira passada: apenas começa a contar.
    $periodicos->indicadoresVermelhosSemPlano($this->at('2026-11-12 10:00:00'));
    expect(WorkshopAlert::where('barrier_code', 'indicador_sem_plano')->count())->toBe(0);

    // Oito dias depois ainda vermelho e sem plano.
    $criados = $periodicos->indicadoresVermelhosSemPlano($this->at('2026-11-20 10:00:00'));

    expect($criados)->toBeGreaterThanOrEqual(1)
        ->and(WorkshopAlert::where('barrier_code', 'indicador_sem_plano')->where('mensagem', 'like', '%TEC-14%')->where('user_id', $gerente->id)->count())->toBe(1);

    // Um lembrete por semana: logo em seguida não repete.
    $periodicos->indicadoresVermelhosSemPlano($this->at('2026-11-21 10:00:00'));
    expect(WorkshopAlert::where('barrier_code', 'indicador_sem_plano')->where('mensagem', 'like', '%TEC-14%')->count())->toBe(1);

    // Com plano de ação, depois de uma semana, não avisa de novo.
    MentoriaRegistro::record('plano_acao', ['dados_json' => ['indicador' => 'TEC-14', 'prazo' => '2026-12-10']], now());
    $periodicos->indicadoresVermelhosSemPlano($this->at('2026-11-29 10:00:00'));

    expect(WorkshopAlert::where('barrier_code', 'indicador_sem_plano')->where('mensagem', 'like', '%TEC-14%')->count())->toBe(1);
});

it('os alertas periódicos rodam no máximo uma vez por hora', function () {
    $this->enablePilot();
    $this->makeUser('gerente');
    $parada = $this->makeOs(['workshop_status' => ServiceOrder::STAGE_ORCAMENTO]);

    // Os 5 dias de parada contam a partir da data fixa do teste, e não do dia em que ele roda
    // (antes usava now() e falhava assim que a data real passava de 20/11/2026).
    $manha = $this->at('2026-11-18 10:00:00');
    DB::table('service_order_events')->where('service_order_id', $parada->id)->where('tipo_evento', 'mudanca_etapa')->update(['ocorrido_em' => $manha->copy()->subDays(5)]);

    $periodicos = app(PeriodicAlerts::class);

    expect($periodicos->runIfDue($manha))->toBeGreaterThanOrEqual(1)
        ->and($periodicos->runIfDue($this->at('2026-11-18 10:30:00')))->toBe(0)
        ->and($periodicos->runIfDue($this->at('2026-11-18 11:01:00')))->toBeGreaterThanOrEqual(0);
});
