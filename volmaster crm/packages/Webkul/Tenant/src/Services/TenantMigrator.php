<?php

namespace Webkul\Tenant\Services;

use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;
use Webkul\Tenant\Models\Tenant;

/**
 * Aplica as migrations pendentes no banco de CADA oficina (o `migrate` normal
 * só enxerga o banco do .env). Só adiciona: nunca apaga dados, nunca cria
 * banco, e uma oficina com problema não impede as outras.
 */
class TenantMigrator
{
    public const CONEXAO = 'tenant_migrate';

    public const EM_DIA = 'em_dia';

    public const MIGRADA = 'migrada';

    public const SIMULADA = 'simulada';

    public const FALHOU = 'falhou';

    public const IGNORADA = 'ignorada';

    public const ADIADA = 'adiada';

    public const ROTULOS = [
        self::EM_DIA => 'Em dia',
        self::MIGRADA => 'Migrada',
        self::SIMULADA => 'Simulação',
        self::FALHOU => 'FALHOU',
        self::IGNORADA => 'Ignorada',
        self::ADIADA => 'Adiada',
    ];

    /**
     * Ativas e suspensas (as suspensas voltam um dia e precisam estar em dia).
     * pending/provisioning/failed ainda não têm banco pronto.
     *
     * @return Collection<int, Tenant>
     */
    public function oficinas(?string $subdominio = null): Collection
    {
        if (! Schema::connection('landlord')->hasTable('tenants')) {
            return new Collection;
        }

        return Tenant::query()
            ->whereIn('status', [Tenant::STATUS_ACTIVE, Tenant::STATUS_SUSPENDED])
            ->when($subdominio, fn ($query) => $query->where('subdomain', $subdominio))
            ->orderBy('id')
            ->get();
    }

    /**
     * Pendentes da instalação principal (só leitura; usado pela simulação do migrate-safe).
     *
     * @return list<string>
     */
    public function pendentesDaPrincipal(): array
    {
        return $this->pendentes(null);
    }

    /**
     * @param  ?Collection<int, Tenant>  $oficinas  null = todas de oficinas()
     * @return array{oficinas: list<array<string, mixed>>, resumo: array<string, int>, falhas: int, incompleto: bool}
     *
     * @throws RuntimeException já existe outra execução em andamento
     */
    public function migrarTodas(?Collection $oficinas = null, bool $simular = false, ?string $subdominio = null, ?int $tempoMaximo = null, ?Closure $progresso = null): array
    {
        $executar = function () use ($oficinas, $simular, $subdominio, $tempoMaximo, $progresso) {
            $oficinas ??= $this->oficinas($subdominio);
            $inicio = microtime(true);
            $linhas = [];

            foreach ($oficinas as $oficina) {
                // Passou do orçamento de tempo: as demais ficam para a próxima rodada (rodar de novo é seguro).
                $linha = $tempoMaximo !== null && (microtime(true) - $inicio) >= $tempoMaximo
                    ? array_merge($this->base($oficina), ['situacao' => self::ADIADA, 'mensagem' => 'Adiada por falta de tempo. Rode de novo para continuar.'])
                    : $this->migrar($oficina, $simular);

                $linhas[] = $linha;

                if ($progresso) {
                    $progresso($linha);
                }
            }

            $resumo = array_fill_keys(array_keys(self::ROTULOS), 0);

            foreach ($linhas as $linha) {
                $resumo[$linha['situacao']]++;
            }

            return [
                'oficinas' => $linhas,
                'resumo' => $resumo + ['total' => count($linhas)],
                'falhas' => $resumo[self::FALHOU],
                'incompleto' => $resumo[self::ADIADA] > 0,
            ];
        };

        return $simular ? $executar() : $this->comTrava($executar);
    }

    /**
     * Migra UMA oficina. Nunca lança: qualquer erro vira situação FALHOU (e vai para report()).
     *
     * @return array{oficina: string, subdominio: string, situacao: string, pendentes: list<string>, aplicadas: list<string>, mensagem: ?string, saida: string}
     */
    public function migrar(Tenant $tenant, bool $simular = false): array
    {
        $resultado = $this->base($tenant);

        if ($this->mesmoBancoDaPrincipal($tenant)) {
            return array_merge($resultado, ['situacao' => self::IGNORADA, 'mensagem' => 'Mesmo banco da instalação principal; já tratado no passo 1.']);
        }

        if (! preg_match('/^\w{1,64}$/', (string) $tenant->db_name) || (string) $tenant->db_username === '') {
            return array_merge($resultado, ['situacao' => self::IGNORADA, 'mensagem' => 'Credenciais do banco incompletas no cadastro da oficina. Nada foi feito.']);
        }

        $senha = null;
        $registrada = false;

        try {
            $senha = (string) $tenant->db_password; // pode lançar DecryptException (APP_KEY diferente)

            $this->registrarConexao($tenant, $senha);
            $registrada = true;

            // Conectar ANTES de qualquer coisa: com --force o `migrate` CRIARIA um banco inexistente.
            DB::connection(self::CONEXAO)->getPdo();

            $migrator = app('migrator');

            if (! $migrator->usingConnection(self::CONEXAO, fn () => $migrator->hasRunAnyMigrations())) {
                return array_merge($resultado, ['situacao' => self::IGNORADA, 'mensagem' => 'Banco sem histórico de migrations: não parece uma oficina instalada. Nada foi feito.']);
            }

            $pendentes = $resultado['pendentes'] = $this->pendentes(self::CONEXAO);

            if ($pendentes === []) {
                return array_merge($resultado, ['situacao' => self::EM_DIA, 'mensagem' => 'Nada a migrar.']);
            }

            if ($simular) {
                return array_merge($resultado, ['situacao' => self::SIMULADA, 'mensagem' => count($pendentes).' migration(s) pendente(s). Nada foi alterado (simulação).']);
            }

            $saida = new BufferedOutput;
            Artisan::call('migrate', ['--database' => self::CONEXAO, '--force' => true], $saida);

            $restantes = $this->pendentes(self::CONEXAO);
            $aplicadas = array_values(array_diff($pendentes, $restantes));

            return array_merge($resultado, [
                'situacao' => $restantes === [] ? self::MIGRADA : self::FALHOU,
                'aplicadas' => $aplicadas,
                'saida' => $saida->fetch(),
                'mensagem' => $restantes === []
                    ? count($aplicadas).' migration(s) aplicada(s).'
                    : 'Restaram '.count($restantes).' migration(s) pendente(s) (a primeira é '.$restantes[0].'). Veja o log.',
            ]);
        } catch (Throwable $erro) {
            report($erro);

            $resultado['situacao'] = self::FALHOU;
            $resultado['mensagem'] = $senha ? str_replace($senha, '***', $erro->getMessage()) : $erro->getMessage();

            // O que foi aplicado antes da falha continua registrado: confere para o relatório.
            // Só se a conexão foi registrada para ESTA oficina (senão leria o banco da anterior).
            if ($registrada) {
                try {
                    $restantes = $this->pendentes(self::CONEXAO);
                    $resultado['aplicadas'] = array_values(array_diff($resultado['pendentes'], $restantes));

                    if ($restantes && $resultado['pendentes']) {
                        $resultado['mensagem'] = 'Parou em '.$restantes[0].': '.$resultado['mensagem'].' As migrations seguintes não foram aplicadas e as anteriores ficaram registradas; corrigir e rodar de novo é seguro.';
                    }
                } catch (Throwable) {
                    // O diagnóstico é opcional; o erro original já foi reportado.
                }
            }

            return $resultado;
        } finally {
            // Desfaz a conexão (e a configuração: um registro antigo nunca pode ser reaproveitado pela próxima oficina).
            DB::purge(self::CONEXAO);
            Config::set('database.connections.'.self::CONEXAO, null);
        }
    }

    /**
     * @return list<string> nomes das migrations que existem em disco e ainda não constam em `migrations` da conexão
     */
    protected function pendentes(?string $conexao): array
    {
        $migrator = app('migrator');

        // Mesmos caminhos que o `migrate` usa: os dos providers + database/migrations.
        $arquivos = $migrator->getMigrationFiles(array_merge($migrator->paths(), [database_path('migrations')]));

        $executadas = $migrator->usingConnection($conexao, function () use ($migrator) {
            $repositorio = $migrator->getRepository();

            return $repositorio->repositoryExists() ? $repositorio->getRan() : [];
        });

        return array_values(array_diff(array_keys($arquivos), $executadas));
    }

    protected function registrarConexao(Tenant $tenant, string $senha): void
    {
        Config::set('database.connections.'.self::CONEXAO, array_merge(config('database.connections.mysql'), [
            'url' => null, // DATABASE_URL, se existisse, sobrescreveria host/database e apontaria de volta para a principal
            'host' => $tenant->db_host ?: config('database.connections.landlord.host'),
            'database' => $tenant->db_name,
            'username' => $tenant->db_username,
            'password' => $senha,
        ]));

        DB::purge(self::CONEXAO);
    }

    protected function mesmoBancoDaPrincipal(Tenant $tenant): bool
    {
        $principal = config('database.connections.mysql');

        return $tenant->db_name === ($principal['database'] ?? null)
            && ($tenant->db_host ?: config('database.connections.landlord.host')) === ($principal['host'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    protected function base(Tenant $tenant): array
    {
        return [
            'oficina' => (string) ($tenant->name ?: $tenant->subdomain),
            'subdominio' => (string) $tenant->subdomain,
            'situacao' => self::EM_DIA,
            'pendentes' => [],
            'aplicadas' => [],
            'mensagem' => null,
            'saida' => '',
        ];
    }

    /**
     * Uma execução por vez (duas páginas abertas ou um duplo clique rodariam
     * migrations ao mesmo tempo no mesmo banco).
     */
    protected function comTrava(Closure $acao): mixed
    {
        $trava = fopen(storage_path('framework/tenants-migrate.lock'), 'c');

        if ($trava === false || ! flock($trava, LOCK_EX | LOCK_NB)) {
            if (is_resource($trava)) {
                fclose($trava);
            }

            throw new RuntimeException('Já existe uma migração das oficinas em andamento. Aguarde terminar e tente de novo.');
        }

        try {
            return $acao();
        } finally {
            flock($trava, LOCK_UN);
            fclose($trava);
        }
    }
}
