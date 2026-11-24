<?php

use Illuminate\Validation\ValidationException;
use Webkul\ServiceOrder\Models\BarrierInstance;
use Webkul\ServiceOrder\Models\ServiceOrderEvent;
use Webkul\ServiceOrder\Models\WorkshopAlert;
use Webkul\ServiceOrder\Services\BarrierEngine;
use Webkul\ServiceOrder\Services\OperationService;

function operationScenario(float $hours = 1.0): array
{
    $mechanic = test()->makeMechanic('Zé Mecânico');
    $tecnico = test()->makeUser('tecnico', null, ['mechanic_id' => $mechanic->id]);
    $chefe = test()->makeUser('chefe');
    $gerente = test()->makeUser('gerente');
    $dono = test()->makeUser('proprietario');

    $os = test()->makeOs(['mechanic_id' => $mechanic->id]);
    $item = test()->makeServiceItem($os, $hours, $mechanic->id);

    test()->enablePilot();

    return compact('mechanic', 'tecnico', 'chefe', 'gerente', 'dono', 'os', 'item');
}

it('critério 3: serviço de 1h alerta o chefe ao passar de 1h12 de tempo real e as pausas não contam', function () {
    ['chefe' => $chefe, 'gerente' => $gerente, 'os' => $os, 'item' => $item] = operationScenario(1.0);
    $operations = app(OperationService::class);
    $engine = app(BarrierEngine::class);

    // 08:00 inicia, 08:30 pausa (almoço), 09:30 retoma: 30 min trabalhados + 1h parado.
    $this->at('2026-11-18 08:00:00');
    $operations->start($item);

    $this->at('2026-11-18 08:30:00');
    $operations->pause($item, 'Almoço');

    $this->at('2026-11-18 09:15:00');
    expect($operations->workedSeconds($item))->toBe(30 * 60)
        ->and($engine->tick())->toBe(0);

    $this->at('2026-11-18 09:30:00');
    $operations->resume($item);

    // 10:12 = 30 + 42 = 72 min = exatamente 120%: ainda não passou.
    expect($engine->tick($this->at('2026-11-18 10:12:00')))->toBe(0)
        ->and($operations->percentual($item))->toBe(120.0);

    // 10:13 = 73 min > 1h12.
    $engine->tick($this->at('2026-11-18 10:13:00'));
    expect(WorkshopAlert::pluck('user_id')->all())->toBe([$chefe->id])
        ->and(WorkshopAlert::first()->mensagem)->toContain("OS #{$os->id}")
        ->and(WorkshopAlert::first()->responsavel)->toBe('Zé Mecânico');

    // Não repete o nível 1 e o gerente só recebe acima de 150% (90 min = 10:31).
    $engine->tick($this->at('2026-11-18 10:20:00'));
    expect(WorkshopAlert::count())->toBe(1);

    $engine->tick($this->at('2026-11-18 10:31:30'));
    expect(WorkshopAlert::where('user_id', $gerente->id)->count())->toBe(1)
        ->and(WorkshopAlert::count())->toBe(2);
});

it('exige o motivo ao concluir acima de 120% e o registra no evento de fim', function () {
    ['os' => $os, 'item' => $item] = operationScenario(1.0);
    $operations = app(OperationService::class);

    $this->at('2026-11-18 08:00:00');
    $operations->start($item);

    $this->at('2026-11-18 09:30:00');

    expect(fn () => $operations->finish($item))->toThrow(ValidationException::class)
        ->and(fn () => $operations->finish($item, 'Motivo que não está na lista'))->toThrow(ValidationException::class);

    $operations->finish($item, 'Falta de ferramenta', 'Chave especial emprestada');

    $fim = ServiceOrderEvent::where('service_order_id', $os->id)->where('tipo_evento', 'operacao_fim')->first();

    expect($fim->dados_json['acima_da_margem'])->toBeTrue()
        ->and($fim->dados_json['motivo_atraso'])->toBe('Falta de ferramenta')
        ->and($fim->dados_json['percentual'])->toEqual(150)
        ->and($operations->status($item))->toBe(OperationService::CONCLUIDA);
});

it('concluir dentro do tempo não pede motivo e fecha a barreira', function () {
    ['os' => $os, 'item' => $item] = operationScenario(1.0);
    $operations = app(OperationService::class);

    $this->at('2026-11-18 08:00:00');
    $operations->start($item);

    $this->at('2026-11-18 08:50:00');
    $operations->finish($item);

    $instance = BarrierInstance::where('service_order_id', $os->id)->first();

    expect($instance->status)->toBe('cumprida')
        ->and($instance->cumprida_no_prazo)->toBeTrue();
});

it('o técnico só pode ter uma operação em andamento por vez', function () {
    ['mechanic' => $mechanic, 'os' => $os, 'item' => $item] = operationScenario(1.0);
    $operations = app(OperationService::class);
    $segunda = $this->makeServiceItem($os, 2.0, $mechanic->id, 'Troca de óleo');

    $this->at('2026-11-18 08:00:00');
    $operations->start($item);

    expect(fn () => $operations->start($segunda))->toThrow(ValidationException::class);

    // Pausando a primeira, pode iniciar a outra; mas não retomar a primeira com a outra rodando.
    $operations->pause($item, 'Outro serviço');
    $operations->start($segunda);

    expect(fn () => $operations->resume($item))->toThrow(ValidationException::class);
});

it('o cronômetro fica verde até 100%, amarelo até 120% e vermelho acima', function () {
    ['item' => $item] = operationScenario(1.0);
    $operations = app(OperationService::class);

    $this->at('2026-11-18 08:00:00');
    $operations->start($item);

    expect($operations->cor($item, $this->at('2026-11-18 08:59:00')))->toBe('verde')
        ->and($operations->cor($item, $this->at('2026-11-18 09:00:00')))->toBe('verde')
        ->and($operations->cor($item, $this->at('2026-11-18 09:05:00')))->toBe('amarelo')
        ->and($operations->cor($item, $this->at('2026-11-18 09:12:00')))->toBe('amarelo')
        ->and($operations->cor($item, $this->at('2026-11-18 09:13:00')))->toBe('vermelho');
});

it('pausa exige motivo e só vale para operação em andamento', function () {
    ['item' => $item] = operationScenario(1.0);
    $operations = app(OperationService::class);

    expect(fn () => $operations->pause($item, 'Almoço'))->toThrow(ValidationException::class);

    $this->at('2026-11-18 08:00:00');
    $operations->start($item);

    expect(fn () => $operations->pause($item, '  '))->toThrow(ValidationException::class);
});
