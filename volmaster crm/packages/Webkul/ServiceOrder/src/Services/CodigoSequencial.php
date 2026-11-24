<?php

namespace Webkul\ServiceOrder\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Webkul\Contact\Models\Person;
use Webkul\Mechanic\Models\Mechanic;

/**
 * Código sequencial de cada cliente e de cada mecânico: 1, 2, 3... na ordem
 * em que foram cadastrados, sem repetir. O código nasce junto com o cadastro
 * (de qualquer tela ou importação) e não é editável; se alguém for excluído,
 * o número dele não é reaproveitado.
 */
class CodigoSequencial
{
    public const CLIENTES = 'clientes';

    public const MECANICOS = 'mecanicos';

    /** @var array<string, bool> */
    protected static array $disponivel = [];

    /**
     * Liga o código ao cadastro de clientes e de mecânicos.
     */
    public static function registrar(): void
    {
        Person::creating(fn (Model $pessoa) => static::atribuir($pessoa, self::CLIENTES));
        Mechanic::creating(fn (Model $mecanico) => static::atribuir($mecanico, self::MECANICOS));
    }

    /**
     * Dá o próximo código ao registro novo (se a tabela já tem a coluna).
     */
    public static function atribuir(Model $modelo, string $sequencia): void
    {
        if (! empty($modelo->getAttribute('codigo')) || ! static::disponivel($modelo->getTable())) {
            return;
        }

        $modelo->setAttribute('codigo', static::proximo($sequencia, $modelo->getTable()));
    }

    /**
     * Próximo número da sequência. Dois cadastros ao mesmo tempo nunca recebem
     * o mesmo número (a linha do contador fica travada durante a conta).
     */
    public static function proximo(string $sequencia, string $tabela): int
    {
        return DB::transaction(function () use ($sequencia, $tabela) {
            DB::table('codigo_sequencias')->insertOrIgnore(['nome' => $sequencia, 'ultimo' => 0]);

            $ultimo = (int) DB::table('codigo_sequencias')->where('nome', $sequencia)->lockForUpdate()->value('ultimo');

            // Nunca volta atrás de um código que já existe (ex.: banco restaurado ou importado).
            $proximo = max($ultimo, (int) DB::table($tabela)->max('codigo')) + 1;

            DB::table('codigo_sequencias')->where('nome', $sequencia)->update(['ultimo' => $proximo]);

            return $proximo;
        });
    }

    /**
     * Quantos já foram cadastrados e qual o maior código (a "carteira").
     *
     * @return array{total: int, ultimo: int}
     */
    public static function carteira(string $tabela): array
    {
        if (! static::disponivel($tabela)) {
            return ['total' => (int) DB::table($tabela)->count(), 'ultimo' => 0];
        }

        return ['total' => (int) DB::table($tabela)->count(), 'ultimo' => (int) DB::table($tabela)->max('codigo')];
    }

    /**
     * "Nº 25", ou um traço quando o registro ainda não tem código.
     */
    public static function rotulo(?int $codigo): string
    {
        return $codigo ? 'Nº '.$codigo : '—';
    }

    /**
     * A tabela já recebeu a coluna "codigo"? (bancos ainda não atualizados continuam funcionando)
     */
    public static function disponivel(string $tabela): bool
    {
        return static::$disponivel[$tabela] ??= Schema::hasTable('codigo_sequencias') && Schema::hasColumn($tabela, 'codigo');
    }
}
