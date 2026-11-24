<?php

namespace Webkul\ServiceOrder\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Plano de contas (capítulo 10.1). O padrão da mentoria tem cerca de 20
 * contas; a oficina só cria subcontas (ligadas ao pai) e não apaga as padrão.
 */
class PlanoConta extends Model
{
    public const GRUPOS = [
        'receitas' => 'Receitas',
        'deducoes' => 'Deduções',
        'custos_variaveis' => 'Custos variáveis',
        'pessoal' => 'Pessoal',
        'despesas_fixas' => 'Despesas fixas',
        'abaixo_resultado' => 'Abaixo do resultado',
    ];

    /** @var list<array{0: string, 1: string, 2: string, 3: ?string}> código, nome, grupo, papel */
    public const PADRAO = [
        ['1.01', 'Serviços de mão de obra', 'receitas', 'mao_obra'],
        ['1.02', 'Venda de peças', 'receitas', 'pecas'],
        ['1.03', 'Outras receitas', 'receitas', 'outras'],
        ['2.01', 'Impostos sobre a venda', 'deducoes', 'impostos'],
        ['2.02', 'Devoluções e cancelamentos', 'deducoes', 'devolucoes'],
        ['3.01', 'Custo das peças vendidas', 'custos_variaveis', 'custo_pecas'],
        ['3.02', 'Serviços de terceiros', 'custos_variaveis', 'terceiros'],
        ['3.03', 'Comissões', 'custos_variaveis', 'comissoes'],
        ['3.04', 'Despesas variáveis de peças (frete, embalagem)', 'custos_variaveis', 'desp_var_pecas'],
        ['4.01', 'Salários e encargos da oficina', 'pessoal', 'pessoal_oficina'],
        ['4.02', 'Salários e encargos da administração', 'pessoal', 'pessoal_adm'],
        ['4.03', 'Benefícios e treinamento', 'pessoal', 'pessoal_adm'],
        ['5.01', 'Aluguel e condomínio', 'despesas_fixas', null],
        ['5.02', 'Energia, água e internet', 'despesas_fixas', null],
        ['5.03', 'Contabilidade e jurídico', 'despesas_fixas', null],
        ['5.04', 'Manutenção e ferramentas', 'despesas_fixas', null],
        ['5.05', 'Marketing', 'despesas_fixas', null],
        ['5.06', 'Veículos e combustível', 'despesas_fixas', null],
        ['5.07', 'Outras despesas fixas', 'despesas_fixas', null],
        ['6.01', 'Pró-labore', 'abaixo_resultado', 'pro_labore'],
        ['6.02', 'Juros e tarifas bancárias', 'abaixo_resultado', 'juros'],
        ['6.03', 'Parcelas de empréstimos e financiamentos', 'abaixo_resultado', 'parcelas'],
    ];

    protected $table = 'plano_contas';

    protected $guarded = [];

    protected $casts = ['padrao' => 'boolean', 'ativo' => 'boolean'];

    /** @var array<string, bool> */
    protected static array $seeded = [];

    public static function ensureDefaults(): void
    {
        $existentes = static::pluck('codigo')->flip();

        foreach (self::PADRAO as [$codigo, $nome, $grupo, $papel]) {
            if (! $existentes->has($codigo)) {
                static::create(['codigo' => $codigo, 'nome' => $nome, 'grupo' => $grupo, 'papel' => $papel, 'padrao' => true]);
            }
        }

        static::$seeded[DB::connection()->getDatabaseName()] = true;
    }

    public static function ensureDefaultsOnce(): void
    {
        if (! (static::$seeded[DB::connection()->getDatabaseName()] ?? false)) {
            static::ensureDefaults();
        }
    }

    public static function forgetSeeded(): void
    {
        static::$seeded = [];
    }

    public function pai()
    {
        return $this->belongsTo(self::class, 'pai_id');
    }

    /**
     * Subconta herda o grupo e o papel da conta pai.
     */
    public static function criarSubconta(self $pai, string $nome): self
    {
        $filhas = static::where('pai_id', $pai->id)->count() + 1;

        return static::create([
            'codigo' => $pai->codigo.'.'.str_pad((string) $filhas, 2, '0', STR_PAD_LEFT),
            'nome' => $nome,
            'grupo' => $pai->grupo,
            'papel' => $pai->papel,
            'pai_id' => $pai->id,
            'padrao' => false,
        ]);
    }
}
