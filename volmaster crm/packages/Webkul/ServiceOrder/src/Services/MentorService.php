<?php

namespace Webkul\ServiceOrder\Services;

use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Webkul\ServiceOrder\Models\BonusMarcador;
use Webkul\ServiceOrder\Models\IndicatorDefinition;
use Webkul\ServiceOrder\Services\Financeiro\DreService;
use Webkul\ServiceOrder\Services\Indicators\IndicatorFormatter;
use Webkul\ServiceOrder\Services\Indicators\IndicatorService;
use Webkul\ServiceOrder\Services\Indicators\IndicatorStatus;
use Webkul\Tenant\Models\Tenant;

/**
 * Visão do Mentor (capítulos 2 e 8): o mesmo painel de cada oficina e um
 * comparativo entre elas, só com indicadores em porcentagem, e a manutenção
 * do padrão da mentoria (metas e pesos replicados para todas as oficinas).
 *
 * O mentor só lê os dados operacionais: cada oficina é consultada no próprio
 * banco, sem gravar nada nele (exceto na replicação do padrão).
 */
class MentorService
{
    /**
     * Oficinas que ficaram de fora da última replicação de metas/pesos.
     *
     * @var list<string>
     */
    public array $falhas = [];

    /**
     * Oficinas que o mentor acompanha: a instalação principal ("Matriz") e
     * as oficinas ativas. Dentro de uma oficina (subdomínio) só ela aparece.
     *
     * @return Collection<int, Tenant|null> null = instalação principal
     */
    public function oficinas(): Collection
    {
        if (app()->bound('currentTenant')) {
            return collect([app('currentTenant')]);
        }

        $oficinas = collect([null]);

        try {
            // Instalação de uma oficina só: não existe diretório de oficinas.
            if (! Schema::connection('landlord')->hasTable('tenants')) {
                return $oficinas;
            }

            return $oficinas->merge(Tenant::where('status', Tenant::STATUS_ACTIVE)->orderBy('subdomain')->get());
        } catch (\Throwable $exception) {
            report($exception); // a Matriz continua na tela

            return $oficinas;
        }
    }

    public function nome(?Tenant $tenant): string
    {
        return $tenant ? ($tenant->name ?? $tenant->subdomain ?? 'Oficina') : 'Matriz';
    }

    /**
     * Indicadores em % de cada oficina no mês, lado a lado.
     *
     * @param  Collection<int, Tenant|null>|null  $oficinas
     * @return array{oficinas: list<array<string, mixed>>, linhas: list<array<string, mixed>>}
     */
    public function comparativo(string $periodo, ?Collection $oficinas = null): array
    {
        $oficinas ??= $this->oficinas();

        $colunas = [];
        $dados = [];

        foreach ($oficinas as $oficina) {
            $nome = $this->nome($oficina);
            $coluna = ['nome' => $nome, 'sem_dados' => true];

            try {
                $linhas = $this->naOficina($oficina, fn () => $this->indicadoresPercentuais($periodo));

                $dados[$nome] = $linhas;
                $coluna['sem_dados'] = collect($linhas)->every(fn ($linha) => $linha['realizado'] === null);
            } catch (\Throwable $exception) {
                // Oficina sem as migrations, banco fora do ar etc.: a tela mostra traços; o erro real fica no log.
                report($exception);
            }

            $colunas[] = $coluna;
        }

        $codigos = collect($dados)->flatMap(fn ($linhas) => array_keys($linhas))->unique()->values();

        $linhas = $codigos->map(function ($codigo) use ($dados) {
            $referencia = collect($dados)->map(fn ($l) => $l[$codigo] ?? null)->filter()->first();

            return [
                'codigo' => $codigo,
                'nome' => $referencia['nome'],
                'cargo' => $referencia['cargo'],
                'meta' => $referencia['meta'],
                'oficinas' => collect($dados)->map(fn ($l) => $l[$codigo] ?? null)->all(),
            ];
        })->all();

        return ['oficinas' => $colunas, 'linhas' => $linhas];
    }

    /**
     * Evolução contra o Marco Zero de cada oficina, só em porcentagem.
     *
     * @return list<array<string, mixed>>
     */
    public function evolucao(string $periodo, ?Collection $oficinas = null): array
    {
        $oficinas ??= $this->oficinas();

        return $oficinas->map(function ($oficina) use ($periodo) {
            try {
                return $this->naOficina($oficina, function () use ($oficina, $periodo) {
                    if (! Schema::hasTable('marco_zero')) {
                        return ['oficina' => $this->nome($oficina), 'marco_zero' => null];
                    }

                    $marco = DB::table('marco_zero')->orderByDesc('id')->first();
                    $servico = app(IndicatorService::class);
                    $hora = app(DreService::class)->precoMinimoHora($periodo);

                    return [
                        'oficina' => $this->nome($oficina),
                        'marco_zero' => $marco ? [
                            'data' => $marco->data,
                            'hora_sobre_minimo' => $marco->hora_cobrada && $marco->preco_minimo_hora ? round($marco->hora_cobrada / $marco->preco_minimo_hora * 100, 1) : null,
                            'produtividade' => $marco->produtividade !== null ? (float) $marco->produtividade : null,
                            'retrabalho' => $marco->retrabalho !== null ? (float) $marco->retrabalho : null,
                        ] : null,
                        'hoje' => [
                            'hora_sobre_minimo' => $hora['hora_cobrada_sobre_minimo'],
                            'produtividade' => $servico->evaluate('CHE-12', $periodo)['resultado']->realizado,
                            'retrabalho' => $servico->evaluate('CHE-10', $periodo)['resultado']->realizado,
                        ],
                    ];
                });
            } catch (\Throwable $exception) {
                report($exception);

                return ['oficina' => $this->nome($oficina), 'marco_zero' => null, 'sem_dados' => true];
            }
        })->all();
    }

    /**
     * Replica as metas padrão da mentoria (da instalação em uso) para todas as
     * oficinas. A meta que a oficina preencheu (meta_oficina) não é mexida.
     *
     * @return int Quantidade de oficinas atualizadas
     */
    public function replicarMetasPadrao(?Collection $oficinas = null): int
    {
        $this->falhas = [];

        // Garante o padrão semeado na origem antes de copiar.
        app(IndicatorService::class)->definitions(apenasAtivos: false);

        $padrao = IndicatorDefinition::query()->get(['codigo', 'meta_padrao', 'tipo_meta', 'meta_empresa']);
        $oficinas ??= $this->oficinas()->filter();
        $atualizadas = 0;

        foreach ($oficinas as $oficina) {
            if (! $oficina) {
                continue;
            }

            try {
                $this->naOficina($oficina, function () use ($padrao) {
                    app(IndicatorService::class)->definitions(apenasAtivos: false);

                    foreach ($padrao as $linha) {
                        IndicatorDefinition::whereKey($linha->codigo)->update(['meta_padrao' => $linha->meta_padrao, 'tipo_meta' => $linha->tipo_meta, 'meta_empresa' => $linha->meta_empresa]);
                    }
                });

                $atualizadas++;
            } catch (\Throwable $exception) {
                // Uma oficina desatualizada não derruba a ação das outras.
                report($exception);
                $this->falhas[] = $this->nome($oficina);
            }
        }

        return $atualizadas;
    }

    /**
     * Replica os pesos do bônus (marcadores por cargo e fase) para as oficinas.
     */
    public function replicarPesos(?Collection $oficinas = null): int
    {
        $this->falhas = [];

        BonusMarcador::ensureDefaultsOnce();

        $marcadores = DB::table('bonus_marcadores')->get(['cargo', 'fase', 'nome', 'codigos', 'peso', 'resultado', 'ativo']);

        // Sem pesos na origem: nunca esvaziar os pesos das oficinas.
        if ($marcadores->isEmpty()) {
            return 0;
        }

        $oficinas ??= $this->oficinas()->filter();
        $atualizadas = 0;

        foreach ($oficinas as $oficina) {
            if (! $oficina) {
                continue;
            }

            try {
                $this->naOficina($oficina, function () use ($marcadores) {
                    // Falha no meio não deixa a oficina sem pesos.
                    DB::transaction(function () use ($marcadores) {
                        DB::table('bonus_marcadores')->delete();

                        foreach ($marcadores as $m) {
                            DB::table('bonus_marcadores')->insert(array_merge((array) $m, ['created_at' => now(), 'updated_at' => now()]));
                        }
                    });
                });

                $atualizadas++;
            } catch (\Throwable $exception) {
                report($exception);
                $this->falhas[] = $this->nome($oficina);
            }
        }

        return $atualizadas;
    }

    /**
     * Executa $fn no banco da oficina e devolve a conexão ao que era antes.
     */
    public function naOficina(?Tenant $tenant, Closure $fn): mixed
    {
        $original = Config::get('database.connections.mysql');
        $tenantAnterior = app()->bound('currentTenant') ? app('currentTenant') : null;

        // Já estamos no banco dessa oficina (instalação principal ou a própria
        // oficina): não há o que trocar, e fechar a conexão derrubaria a transação em curso.
        if (! $tenant || $this->mesmoBanco($tenant, $original)) {
            return $fn();
        }

        try {
            if ($tenant) {
                Config::set('database.connections.mysql.host', $tenant->db_host ?: config('database.connections.landlord.host'));
                Config::set('database.connections.mysql.database', $tenant->db_name);
                Config::set('database.connections.mysql.username', $tenant->db_username);
                Config::set('database.connections.mysql.password', $tenant->db_password);

                app()->instance('currentTenant', $tenant);
            }

            DB::purge('mysql');
            WorkshopSettings::flush();

            return $fn();
        } finally {
            Config::set('database.connections.mysql', $original);
            DB::purge('mysql');
            WorkshopSettings::flush();

            $tenantAnterior ? app()->instance('currentTenant', $tenantAnterior) : app()->forgetInstance('currentTenant');
        }
    }

    protected function mesmoBanco(Tenant $tenant, array $conexao): bool
    {
        return ($tenant->db_name ?? null) === ($conexao['database'] ?? null)
            && ($tenant->db_host ?: config('database.connections.landlord.host')) === ($conexao['host'] ?? null)
            && ($tenant->db_username ?? null) === ($conexao['username'] ?? null);
    }

    /**
     * @return array<string, array<string, mixed>> código => linha (só indicadores em %)
     */
    protected function indicadoresPercentuais(string $periodo): array
    {
        $servico = app(IndicatorService::class);
        $linhas = [];
        $falhas = 0;
        $primeira = null;

        // Se faltar a tabela do catálogo, o erro sobe para comparativo() e a oficina inteira vira traços.
        foreach ($servico->definitions() as $definicao) {
            if ($definicao->unidade !== 'percent') {
                continue;
            }

            try {
                $avaliacao = $servico->evaluate($definicao, $periodo);

                $linhas[$definicao->codigo] = [
                    'nome' => $definicao->nome,
                    'cargo' => $definicao->cargo,
                    'meta' => IndicatorFormatter::meta($avaliacao['meta'], $definicao->tipo_meta, $definicao->unidade),
                    'realizado' => $avaliacao['resultado']->realizado,
                    'realizado_fmt' => IndicatorFormatter::value($avaliacao['resultado']->realizado, 'percent'),
                    'situacao' => $avaliacao['situacao'],
                    'situacao_rotulo' => IndicatorStatus::ROTULOS[$avaliacao['situacao']],
                    'cores' => IndicatorFormatter::cores($avaliacao['situacao']),
                ];
            } catch (\Throwable $exception) {
                $falhas++;
                $primeira ??= $exception;
                $linhas[$definicao->codigo] = $this->linhaSemDado($definicao);
            }
        }

        // Um só aviso por oficina no log (evita dezenas de linhas por visita).
        if ($primeira) {
            report(new \RuntimeException("Visão do mentor: {$falhas} indicador(es) sem leitura nesta oficina; primeiro erro: ".$primeira->getMessage(), 0, $primeira));
        }

        return $linhas;
    }

    /**
     * @return array<string, mixed>
     */
    protected function linhaSemDado(IndicatorDefinition $definicao): array
    {
        return [
            'nome' => $definicao->nome,
            'cargo' => $definicao->cargo,
            'meta' => IndicatorFormatter::meta($definicao->meta(), $definicao->tipo_meta, $definicao->unidade),
            'realizado' => null,
            'realizado_fmt' => IndicatorFormatter::value(null, 'percent'),
            'situacao' => IndicatorStatus::SEM_DADO,
            'situacao_rotulo' => IndicatorStatus::ROTULOS[IndicatorStatus::SEM_DADO],
            'cores' => IndicatorFormatter::cores(IndicatorStatus::SEM_DADO),
        ];
    }
}
