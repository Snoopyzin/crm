<?php

namespace Webkul\Fiscal\Models;

use Illuminate\Database\Eloquent\Model;

class FiscalSettings extends Model
{
    protected $table = 'fiscal_settings';

    protected $fillable = [
        'razao_social',
        'nome_fantasia',
        'cnpj',
        'inscricao_municipal',
        'inscricao_estadual',
        'cfop_padrao',
        'regime_tributario',
        'codigo_municipio',
        'uf',
        'optante_simples_nacional',
        'item_lista_servico',
        'aliquota_iss',
    ];

    protected $casts = [
        'optante_simples_nacional' => 'boolean',
    ];

    /**
     * One row per tenant. Creates it with defaults on first access so the
     * settings screen always has something to edit.
     */
    public static function current(): self
    {
        return static::first() ?? static::create([]);
    }

    /**
     * Whether enough data exists here to attempt an NFS-e (serviço) emission.
     */
    public function isReadyForEmission(): bool
    {
        return filled($this->cnpj)
            && filled($this->inscricao_municipal)
            && filled($this->codigo_municipio)
            && filled($this->item_lista_servico);
    }

    /**
     * Whether enough data exists here to attempt a NF-e (produto) emission.
     */
    public function isReadyForNfeEmission(): bool
    {
        return filled($this->cnpj)
            && filled($this->inscricao_estadual)
            && filled($this->uf)
            && filled($this->cfop_padrao)
            && filled($this->regime_tributario);
    }
}
