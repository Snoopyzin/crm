<?php

namespace Webkul\ServiceOrder\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\Mechanic\Models\Mechanic;
use Webkul\User\Models\User;

/**
 * Colaborador da oficina (capítulo 3.2). Os cargos são os mesmos papéis dos
 * alertas e do bônus (proprietario, gerente, chefe, consultor, tecnico, pecas,
 * financeiro, zeladoria); um colaborador pode acumular vários.
 */
class Colaborador extends Model
{
    protected $table = 'colaboradores';

    protected $guarded = [];

    protected $casts = [
        'registro_antes_inicio' => 'boolean',
        'ativo' => 'boolean',
        'competencias' => 'array',
        'salario_base' => 'float',
        'admissao' => 'date',
        'ferias_vencimento' => 'date',
        'exame_periodico_vencimento' => 'date',
        'epi_vencimento' => 'date',
        'desligamento_em' => 'date',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function mechanic()
    {
        return $this->belongsTo(Mechanic::class);
    }

    /**
     * @return string[]
     */
    public function cargosLista(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->cargos))));
    }

    public function temCargo(string $cargo): bool
    {
        return in_array($cargo, $this->cargosLista(), true);
    }

    public function scopeAtivos($query)
    {
        return $query->where('ativo', true);
    }

    public function scopeComCargo($query, string $cargo)
    {
        return $query->whereRaw('FIND_IN_SET(?, cargos)', [$cargo]);
    }

    /**
     * Férias vencidas: o vencimento já passou.
     */
    public function feriasVencidas(): bool
    {
        return $this->ferias_vencimento && $this->ferias_vencimento->lt(today());
    }

    /**
     * Exame periódico e ficha de EPI em dia (sem data cadastrada = pendente).
     */
    public function exameEEpiEmDia(): bool
    {
        return $this->exame_periodico_vencimento && $this->exame_periodico_vencimento->gte(today())
            && $this->epi_vencimento && $this->epi_vencimento->gte(today());
    }
}
