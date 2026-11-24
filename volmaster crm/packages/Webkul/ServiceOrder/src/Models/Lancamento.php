<?php

namespace Webkul\ServiceOrder\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Conta a pagar. O financeiro escolhe a conta do plano de contas de cada
 * pagamento; sem conta, o lançamento não entra na conciliação e conta como
 * "lançamento sem categoria" no fechamento (DRE-08).
 */
class Lancamento extends Model
{
    public const FORMAS = ['dinheiro' => 'Dinheiro', 'pix' => 'PIX', 'cartao' => 'Cartão', 'boleto' => 'Boleto', 'transferencia' => 'Transferência'];

    protected $table = 'financeiro_lancamentos';

    protected $guarded = [];

    protected $casts = [
        'valor' => 'float',
        'vencimento' => 'date',
        'competencia' => 'date',
        'pago_em' => 'date',
        'conciliado_em' => 'datetime',
        'juros_multa' => 'boolean',
        'pagamento_pecas' => 'boolean',
        'nf_entrada_conferida' => 'boolean',
    ];

    public function conta()
    {
        return $this->belongsTo(PlanoConta::class, 'conta_id');
    }

    public function scopeEmAberto($query)
    {
        return $query->whereNull('pago_em');
    }

    public function scopeSemConta($query)
    {
        return $query->whereNull('conta_id');
    }
}
