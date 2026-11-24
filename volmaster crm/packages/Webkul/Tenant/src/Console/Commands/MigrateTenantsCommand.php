<?php

namespace Webkul\Tenant\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use RuntimeException;
use Webkul\Tenant\Services\TenantMigrator;

class MigrateTenantsCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'tenants:migrate
        {--oficina= : Endereço (subdomínio) de uma única oficina}
        {--simular : Só lista o que está pendente; não altera nada}
        {--tempo= : Limite em segundos; passado o limite, as oficinas restantes ficam para a próxima rodada}
        {--force : Não pergunta antes de aplicar (necessário em produção)}';

    protected $description = 'Aplica as migrations pendentes no banco de cada oficina (só adiciona; nunca apaga dados)';

    public function handle(TenantMigrator $migrator): int
    {
        $simular = (bool) $this->option('simular');
        $subdominio = $this->option('oficina') ?: null;

        if (! $simular && ! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $oficinas = $migrator->oficinas($subdominio);

        if ($oficinas->isEmpty()) {
            if ($subdominio) {
                $this->error('Nenhuma oficina ativa ou suspensa com o endereço "'.$subdominio.'".');

                return self::FAILURE;
            }

            $this->info('Nenhuma oficina cadastrada para migrar.');

            return self::SUCCESS;
        }

        try {
            $resultado = $migrator->migrarTodas(
                oficinas: $oficinas,
                simular: $simular,
                tempoMaximo: $this->option('tempo') !== null ? (int) $this->option('tempo') : null,
                progresso: fn (array $linha) => $this->imprimir($linha, $simular),
            );
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $r = $resultado['resumo'];

        $this->line(sprintf(
            '%d oficina(s): %d migrada(s), %d em dia, %d falhou/falharam, %d ignorada(s), %d adiada(s).',
            $r['total'], $r[TenantMigrator::MIGRADA] + $r[TenantMigrator::SIMULADA], $r[TenantMigrator::EM_DIA], $r[TenantMigrator::FALHOU], $r[TenantMigrator::IGNORADA], $r[TenantMigrator::ADIADA],
        ));

        return $resultado['falhas'] > 0 || $resultado['incompleto'] ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $linha
     */
    protected function imprimir(array $linha, bool $simular): void
    {
        $texto = sprintf('%-26s %-10s %s', $linha['subdominio'], TenantMigrator::ROTULOS[$linha['situacao']], $linha['mensagem']);

        $linha['situacao'] === TenantMigrator::FALHOU ? $this->error($texto) : $this->line($texto);

        if ($simular || $linha['situacao'] === TenantMigrator::FALHOU) {
            foreach (array_slice($linha['pendentes'], 0, 8) as $nome) {
                $this->line('    - '.$nome);
            }
        }
    }
}
