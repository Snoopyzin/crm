<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Webkul\ServiceOrder\Models\BonusApuracao;
use Webkul\ServiceOrder\Models\BonusMarcador;
use Webkul\ServiceOrder\Models\Colaborador;
use Webkul\ServiceOrder\Models\IndicatorDefinition;
use Webkul\ServiceOrder\Models\MentoriaRegistro;
use Webkul\ServiceOrder\Models\ServiceOrderEvent;
use Webkul\ServiceOrder\Services\Bonus\BonusService;
use Webkul\ServiceOrder\Services\HistoricoImportService;
use Webkul\ServiceOrder\Services\Indicators\IndicatorService;
use Webkul\ServiceOrder\Services\Indicators\PainelDonoService;
use Webkul\ServiceOrder\Services\MentorService;
use Webkul\Tenant\Models\Tenant;

beforeEach(function () {
    IndicatorDefinition::forgetSeeded();
    BonusMarcador::forgetSeeded();
});

function planilha(string $conteudo): string
{
    $caminho = tempnam(sys_get_temp_dir(), 'imp').'.csv';
    file_put_contents($caminho, $conteudo);

    return $caminho;
}

function importar(string $tipo, string $csv, bool $simular = false): array
{
    $caminho = planilha($csv);
    $resultado = app(HistoricoImportService::class)->importar($tipo, $caminho, $simular);
    @unlink($caminho);

    return $resultado;
}

it('importa colaboradores e cargos da planilha, reconhecendo cargos acumulados, datas e valores brasileiros', function () {
    $csv = "nome;cpf;cargos;salario_base;admissao;registro_antes_inicio;ferias_vencimento;exame_vencimento;epi_vencimento;cnh\n"
        ."José da Silva;123.456.789-00;Técnico + Chefe;4.500,00;03/11/2026;sim;01/03/2027;10/01/2027;01/02/2027;e\n"
        ."Maria Souza;;Auxiliar de copa e zeladoria;2000;;nao;;;;\n"
        ."Fulano Estranho;;Astronauta;1000;;;;;;\n";

    $resultado = importar('colaboradores', $csv);

    expect($resultado['criados'])->toBe(3)
        ->and($resultado['total'])->toBe(3);

    $jose = Colaborador::where('nome', 'José da Silva')->first();

    expect($jose->cargosLista())->toBe(['tecnico', 'chefe'])
        ->and($jose->salario_base)->toBe(4500.0)
        ->and($jose->admissao->format('Y-m-d'))->toBe('2026-11-03')
        ->and($jose->registro_antes_inicio)->toBeTrue()
        ->and($jose->ferias_vencimento->format('Y-m-d'))->toBe('2027-03-01')
        ->and($jose->cnh_categoria)->toBe('E')
        ->and(Colaborador::where('nome', 'Maria Souza')->first()->cargosLista())->toBe(['zeladoria'])
        ->and(Colaborador::where('nome', 'Maria Souza')->first()->registro_antes_inicio)->toBeFalse()
        // Cargo desconhecido: cadastra a pessoa sem cargo, para o gerente completar.
        ->and(Colaborador::where('nome', 'Fulano Estranho')->first()->cargos)->toBeNull();

    // Importar de novo atualiza, não duplica.
    $segunda = importar('colaboradores', $csv);

    expect($segunda['criados'])->toBe(0)
        ->and($segunda['ignorados'])->toBe(3)
        ->and(Colaborador::count())->toBe(3);
});

it('planilha sem a coluna obrigatória é recusada com a explicação', function () {
    expect(fn () => importar('colaboradores', "cpf;cargos\n123;tecnico\n"))->toThrow(InvalidArgumentException::class, 'precisa ter a coluna "nome"');
});

it('importa metas preenchidas e avisa do indicador inexistente', function () {
    $resultado = importar('metas', "codigo;meta\nCON-04;92\ncon-15;8,5\nXXX-99;10\n");

    expect($resultado['criados'])->toBe(2)
        ->and($resultado['erros'])->toHaveCount(1)
        ->and($resultado['erros'][0])->toContain('Linha 4')->toContain('XXX-99');

    expect(IndicatorDefinition::find('CON-04')->meta())->toBe(92.0)
        ->and(IndicatorDefinition::find('CON-15')->meta())->toBe(8.5);
});

it('importa registros de medição por dia (pela chave ou pelo nome), marca a origem e não duplica', function () {
    Colaborador::create(['nome' => 'Maria', 'cargos' => 'zeladoria', 'ativo' => true, 'user_id' => 1]);

    $csv = "tipo;data;valor;ok;colaborador;observacao\n"
        ."zel_limpeza;05/10/2026;;sim;Maria;\n"
        ."Checklist diário de limpeza;06/10/2026;;não;Maria;faltou banheiro\n"
        ."auditoria_estoque;12/10/2026;8,5;;;\n"
        ."tipo_inventado;07/10/2026;;sim;;\n"
        ."zel_cafe;31/02/2026;;sim;;\n";

    $resultado = importar('medicao', $csv);

    expect($resultado['criados'])->toBe(3)
        ->and($resultado['erros'])->toHaveCount(2);

    $registros = MentoriaRegistro::where('origem', 'planilha')->orderBy('ocorrido_em')->get();

    expect($registros)->toHaveCount(3)
        ->and($registros[0]->tipo)->toBe('zel_limpeza')
        ->and($registros[0]->ok)->toBeTrue()
        ->and($registros[0]->user_id)->toBe(1)
        ->and($registros[1]->ok)->toBeFalse()
        ->and($registros[1]->observacao)->toBe('faltou banheiro')
        ->and($registros[2]->valor)->toBe(8.5);

    expect(importar('medicao', $csv)['criados'])->toBe(0);
    expect(MentoriaRegistro::count())->toBe(3);
});

it('histórico mensal dos indicadores entra com a marca "origem: planilha" quando o sistema não tem dado do mês', function () {
    importar('indicadores', "periodo;codigo;realizado\n2026-09;CON-01;88,5\n2026-10;CON-01;91\n2026-13;CON-01;90\n");

    $servico = app(IndicatorService::class);
    $setembro = $servico->evaluate('CON-01', '2026-09');

    expect($setembro['resultado']->realizado)->toBe(88.5)
        ->and($setembro['origem'])->toBe('planilha')
        // Meta de 90%: 88,5% é "atenção".
        ->and($setembro['situacao'])->toBe('amarelo');

    $painel = app(PainelDonoService::class)->linha('CON-01', '2026-11');

    // A tendência dos 3 meses anteriores mostra a origem.
    expect(collect($painel['tendencia'])->pluck('origem')->all())->toBe(['sistema', 'planilha', 'planilha'])
        ->and(collect($painel['tendencia'])->pluck('fmt')->implode(' | '))->toContain('88,5% (origem: planilha)');

    $outubro = app(PainelDonoService::class)->linha('CON-01', '2026-10');
    expect($outubro['realizado_fmt'])->toBe('91,0% (origem: planilha)');

    // Mês que o sistema mede não é substituído pela planilha.
    expect($servico->evaluate('CON-01', '2026-11')['origem'])->toBe('sistema');
});

it('notas de bônus já fechadas entram como aprovadas, saem na folha e não duplicam', function () {
    importar('colaboradores', "nome;cargos;salario_base\nJosé;tecnico;4000\n");

    $csv = "periodo;colaborador;cargo;nota;faixa;valor\n2026-10;José;Técnico;92,5;100;400,00\n2026-10;Pedro;tecnico;80;70;100\n2026-10;José;astronauta;80;70;100\n";
    $resultado = importar('bonus', $csv);

    expect($resultado['criados'])->toBe(1)
        ->and($resultado['erros'])->toHaveCount(2)
        ->and($resultado['erros'][0])->toContain('Pedro')->toContain('importe os colaboradores primeiro');

    $apuracao = BonusApuracao::firstOrFail();

    expect($apuracao->status)->toBe('aprovado')
        ->and($apuracao->origem)->toBe('planilha')
        ->and($apuracao->nota)->toBe(92.5)
        ->and($apuracao->valor)->toBe(400.0)
        ->and(app(BonusService::class)->linhasDaFolha('2026-10'))->toHaveCount(1)
        ->and(importar('bonus', $csv)['criados'])->toBe(0);
});

it('simulação mostra o que aconteceria sem gravar nada', function () {
    $resultado = importar('colaboradores', "nome;cargos\nAna;gerente\nBeto;consultor\n", true);

    expect($resultado['criados'])->toBe(2)
        ->and(Colaborador::count())->toBe(0);
});

it('telas de importação: modelo para baixar, envio de arquivo e resumo; sem permissão, barrado', function () {
    $admin = $this->makeUser('gerente', ['settings', 'settings.historico']);
    $this->actingAs($admin, 'user');

    $this->get(route('admin.historico.index'))->assertOk()->assertSee('Importar o histórico')->assertSee('Notas de bônus já fechadas');

    $modelo = $this->get(route('admin.historico.modelo', 'metas'));
    $modelo->assertOk();
    expect($modelo->getContent())->toStartWith("\xEF\xBB\xBFcodigo;meta");

    $this->get(route('admin.historico.modelo', 'inexistente'))->assertNotFound();

    $arquivo = UploadedFile::fake()->createWithContent('colaboradores.csv', "nome;cargos\nCarla;financeiro\n");

    $this->post(route('admin.historico.importar'), ['tipo' => 'colaboradores', 'arquivo' => $arquivo, 'simular' => '0'])->assertRedirect(route('admin.historico.index'));

    expect(Colaborador::where('nome', 'Carla')->exists())->toBeTrue();

    $this->get(route('admin.historico.index'))->assertOk();

    $semAcesso = $this->makeUser('consultor', ['service_orders']);
    $this->actingAs($semAcesso, 'user')->get(route('admin.historico.index'))->assertForbidden();
});

// ---- Visão do mentor ------------------------------------------------------------------

function oficinaFalsa(string $nome): Tenant
{
    $banco = config('database.connections.mysql');

    return (new Tenant)->setRawAttributes([
        'name' => $nome, 'subdomain' => strtolower($nome), 'db_host' => $banco['host'], 'db_name' => $banco['database'],
        'db_username' => $banco['username'], 'db_password' => encrypt((string) $banco['password']), 'status' => 'active',
    ]);
}

it('comparativo do mentor: indicadores em % de cada oficina lado a lado, e a conexão volta ao normal', function () {
    $this->enablePilot([], ['recepcao']);
    $os = $this->makeOs();
    ServiceOrderEvent::record($os, 'chegada_veiculo', [], null, null, $this->at('2026-11-18 08:00:00'));
    ServiceOrderEvent::record($os, 'recepcao', [], null, null, $this->at('2026-11-18 08:05:00'));
    $this->at('2026-11-30 12:00:00');

    $antes = Config::get('database.connections.mysql');
    $mentor = app(MentorService::class);

    $comparativo = $mentor->comparativo('2026-11', collect([null, oficinaFalsa('Norte')]));

    expect(collect($comparativo['oficinas'])->pluck('nome')->all())->toBe(['Matriz', 'Norte'])
        ->and(Config::get('database.connections.mysql'))->toBe($antes);

    $con01 = collect($comparativo['linhas'])->firstWhere('codigo', 'CON-01');

    expect($con01['oficinas']['Matriz']['realizado'])->toBe(100.0)
        ->and($con01['oficinas']['Norte']['realizado'])->toBe(100.0)
        ->and($con01['oficinas']['Matriz']['situacao'])->toBe('verde');

    // Só indicadores em porcentagem (nada de R$, dias, horas ou contagens).
    $unidades = IndicatorDefinition::whereIn('codigo', collect($comparativo['linhas'])->pluck('codigo'))->pluck('unidade')->unique()->all();
    expect($unidades)->toBe(['percent']);
});

/**
 * Mentor cuja leitura da oficina "quebrada" falha como falharia uma oficina sem as migrations
 * (sem trocar de banco de verdade: isso derrubaria a transação do teste).
 */
function mentorComOficinaQuebrada(): MentorService
{
    return new class extends MentorService
    {
        public function naOficina(?Tenant $tenant, Closure $fn): mixed
        {
            if ($tenant && $tenant->subdomain === 'quebrada') {
                throw new RuntimeException("SQLSTATE[42S02]: Base table or view not found: 1146 Table 'indicator_definitions' doesn't exist");
            }

            return parent::naOficina($tenant, $fn);
        }
    };
}

it('oficina ilegível não derruba o comparativo: aparece com traços e o erro vai para o log', function () {
    Exceptions::fake();

    $quebrada = (new Tenant)->setRawAttributes(['name' => 'Quebrada', 'subdomain' => 'quebrada', 'db_host' => '127.0.0.1', 'db_name' => 'banco_que_nao_existe', 'db_username' => 'root', 'db_password' => encrypt(''), 'status' => 'active']);
    $antes = Config::get('database.connections.mysql.database');

    $comparativo = mentorComOficinaQuebrada()->comparativo('2026-11', collect([null, $quebrada]));

    expect($comparativo['oficinas'][0]['nome'])->toBe('Matriz')
        ->and($comparativo['oficinas'][1])->toBe(['nome' => 'Quebrada', 'sem_dados' => true])
        // O texto de erro nunca chega à tela: só traços; o erro real fica no log.
        ->and(json_encode($comparativo))->not->toContain('Não foi possível ler')
        ->and(Config::get('database.connections.mysql.database'))->toBe($antes);

    Exceptions::assertReportedCount(1);

    // Cada indicador da oficina quebrada aparece como traço (null), nunca como erro.
    $linha = collect($comparativo['linhas'])->first();
    expect($linha['oficinas'])->not->toHaveKey('Quebrada');
});

it('evolução e replicação com oficina ilegível: traços e aviso, sem erro 500', function () {
    Exceptions::fake();

    $quebrada = (new Tenant)->setRawAttributes(['name' => 'Quebrada', 'subdomain' => 'quebrada', 'db_host' => '127.0.0.1', 'db_name' => 'banco_que_nao_existe', 'db_username' => 'root', 'db_password' => encrypt(''), 'status' => 'active']);
    $mentor = mentorComOficinaQuebrada();

    $evolucao = $mentor->evolucao('2026-11', collect([null, $quebrada]));

    expect($evolucao[1])->toBe(['oficina' => 'Quebrada', 'marco_zero' => null, 'sem_dados' => true])
        ->and(json_encode($evolucao))->not->toContain('Não foi possível ler');

    BonusMarcador::ensureDefaults();

    expect($mentor->replicarMetasPadrao(collect([$quebrada])))->toBe(0)
        ->and($mentor->falhas)->toBe(['Quebrada'])
        ->and($mentor->replicarPesos(collect([$quebrada])))->toBe(0)
        ->and($mentor->falhas)->toBe(['Quebrada']);
});

it('replicação de pesos copia os marcadores da origem sem perder nenhum', function () {
    BonusMarcador::ensureDefaults();
    $antes = BonusMarcador::count();

    expect($antes)->toBeGreaterThan(0)
        ->and(app(MentorService::class)->replicarPesos(collect([oficinaFalsa('Norte')])))->toBe(1)
        ->and(BonusMarcador::count())->toBe($antes);
});

it('evolução contra o Marco Zero em porcentagem', function () {
    DB::table('marco_zero')->insert(['data' => '2026-10-01', 'hora_cobrada' => 100, 'preco_minimo_hora' => 125, 'produtividade' => 55, 'retrabalho' => 6, 'created_at' => now(), 'updated_at' => now()]);

    $linhas = app(MentorService::class)->evolucao('2026-11', collect([null]));

    expect($linhas[0]['oficina'])->toBe('Matriz')
        ->and($linhas[0]['marco_zero']['hora_sobre_minimo'])->toBe(80.0)
        ->and($linhas[0]['marco_zero']['produtividade'])->toBe(55.0)
        ->and($linhas[0]['marco_zero']['retrabalho'])->toBe(6.0)
        ->and($linhas[0]['hoje'])->toHaveKeys(['hora_sobre_minimo', 'produtividade', 'retrabalho']);
});

it('tela do mentor e replicação do padrão da mentoria para as oficinas', function () {
    $mentor = $this->makeUser('proprietario', ['mentor_mentoria']);
    $this->actingAs($mentor, 'user');

    $this->get(route('admin.mentor.index', ['periodo' => '2026-11']))->assertOk()->assertSee('Visão do mentor')->assertSee('Matriz')->assertSee('Evolução contra o Marco Zero')->assertDontSee('Não foi possível ler esta oficina');
    $this->get(route('admin.mentor.index', ['periodo' => '2026-11', 'cargo' => 'consultor']))->assertOk()->assertSee('CON-01')->assertDontSee('TEC-01');

    // Metas padrão e pesos replicados (a oficina de teste usa o mesmo banco, então só conferimos a contagem e a preservação da meta própria).
    IndicatorDefinition::forgetSeeded();
    app(IndicatorService::class)->definitions();
    IndicatorDefinition::whereKey('CON-04')->update(['meta_padrao' => 95, 'meta_oficina' => 70]);

    $oficinas = collect([oficinaFalsa('Norte')]);

    expect(app(MentorService::class)->replicarMetasPadrao($oficinas))->toBe(1)
        ->and(IndicatorDefinition::find('CON-04')->meta_padrao)->toBe(95.0)
        // A meta que a oficina preencheu para si não é mexida.
        ->and(IndicatorDefinition::find('CON-04')->meta_oficina)->toBe(70.0);

    BonusMarcador::ensureDefaults();
    expect(app(MentorService::class)->replicarPesos($oficinas))->toBe(1)
        ->and(BonusMarcador::where('cargo', 'consultor')->where('fase', 1)->count())->toBe(6);

    $this->post(route('admin.mentor.replicar_metas'))->assertRedirect(route('admin.mentor.index'));

    $semAcesso = $this->makeUser('consultor', ['service_orders']);
    $this->actingAs($semAcesso, 'user')->get(route('admin.mentor.index'))->assertForbidden();
});
