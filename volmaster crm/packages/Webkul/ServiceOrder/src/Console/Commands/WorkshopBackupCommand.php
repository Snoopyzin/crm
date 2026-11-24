<?php

namespace Webkul\ServiceOrder\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Webkul\ServiceOrder\Services\WorkshopSettings;
use Webkul\Tenant\Models\Tenant;

/**
 * Backup diário do banco (LGPD, capítulo 11 passo 7): gera um dump comprimido
 * de cada oficina em storage/app/backups e apaga os mais antigos que o prazo
 * de retenção. Precisa do mysqldump no servidor (informe o caminho em
 * MYSQLDUMP_PATH no .env quando ele não estiver no PATH). O backup do
 * servidor (cPanel) continua valendo: este é uma camada a mais.
 */
class WorkshopBackupCommand extends Command
{
    protected $signature = 'workshop:backup {--tenant= : Subdomínio de uma única oficina} {--dias=14 : Quantos dias de backup manter}';

    protected $description = 'Faz o backup diário do banco de cada oficina e apaga os antigos';

    public function handle(): int
    {
        $destinos = $this->bancos();
        $falhas = 0;

        File::ensureDirectoryExists(storage_path('app/backups'));

        foreach ($destinos as $nome => $banco) {
            try {
                $arquivo = $this->dump($nome, $banco);
                $this->info("{$nome}: {$arquivo}");
            } catch (\Throwable $exception) {
                $falhas++;
                $this->error("{$nome}: {$exception->getMessage()}");
            }
        }

        $apagados = $this->limpar((int) $this->option('dias'));

        if ($apagados) {
            $this->line("{$apagados} backup(s) antigo(s) apagado(s).");
        }

        return $falhas ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array<string, array{host: string, port: mixed, database: string, username: string, password: string}>
     */
    protected function bancos(): array
    {
        $conexao = config('database.connections.mysql');
        $bancos = ['principal' => ['host' => $conexao['host'], 'port' => $conexao['port'] ?? 3306, 'database' => $conexao['database'], 'username' => $conexao['username'], 'password' => (string) $conexao['password']]];

        if ($apenas = $this->option('tenant')) {
            $bancos = [];
        }

        try {
            $query = Tenant::where('status', Tenant::STATUS_ACTIVE);

            if ($apenas) {
                $query->where('subdomain', $apenas);
            }

            foreach ($query->get() as $tenant) {
                $bancos[$tenant->subdomain] = [
                    'host' => $tenant->db_host ?: config('database.connections.landlord.host'),
                    'port' => $conexao['port'] ?? 3306,
                    'database' => $tenant->db_name,
                    'username' => $tenant->db_username,
                    'password' => (string) $tenant->db_password,
                ];
            }
        } catch (\Throwable) {
            // Instalação de uma oficina só.
        }

        return $bancos;
    }

    protected function dump(string $nome, array $banco): string
    {
        $binario = env('MYSQLDUMP_PATH') ?: (getenv('MYSQLDUMP_PATH') ?: 'mysqldump');
        $sql = storage_path('app/backups/'.$nome.'-'.now()->format('Ymd-His').'.sql');

        $processo = new Process([
            $binario, '--host='.$banco['host'], '--port='.$banco['port'], '--user='.$banco['username'],
            '--single-transaction', '--quick', '--routines', '--result-file='.$sql, $banco['database'],
        ], null, ['MYSQL_PWD' => $banco['password']]);

        $processo->setTimeout(600);
        $processo->run();

        if (! $processo->isSuccessful() || ! is_file($sql) || filesize($sql) === 0) {
            @unlink($sql);

            throw new \RuntimeException('mysqldump falhou ou não foi encontrado ('.trim($processo->getErrorOutput() ?: 'sem mensagem').'). Informe MYSQLDUMP_PATH no .env.');
        }

        // Comprime sem carregar o arquivo inteiro na memória.
        $origem = fopen($sql, 'rb');
        $destino = gzopen($sql.'.gz', 'wb9');

        while (! feof($origem)) {
            gzwrite($destino, fread($origem, 1024 * 512));
        }

        fclose($origem);
        gzclose($destino);
        unlink($sql);

        WorkshopSettings::set('ultimo_backup', now()->toIso8601String());

        return $sql.'.gz';
    }

    /**
     * Apaga os backups mais antigos que $dias.
     */
    public function limpar(int $dias): int
    {
        $limite = now()->subDays(max(1, $dias))->getTimestamp();
        $apagados = 0;

        foreach (File::glob(storage_path('app/backups/*.sql.gz')) as $arquivo) {
            if (filemtime($arquivo) < $limite) {
                File::delete($arquivo);
                $apagados++;
            }
        }

        return $apagados;
    }
}
