<?php

namespace Webkul\Fiscal\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\Fiscal\Contracts\FiscalDocument as FiscalDocumentContract;
use Webkul\ServiceOrder\Models\ServiceOrder;

class FiscalDocument extends Model implements FiscalDocumentContract
{
    const STATUS_PROCESSANDO = 'processando';

    const STATUS_AUTORIZADO = 'autorizado';

    const STATUS_ERRO = 'erro_autorizacao';

    const STATUS_CANCELADO = 'cancelado';

    protected $table = 'fiscal_documents';

    protected $fillable = [
        'quote_id',
        'type',
        'focus_ref',
        'status',
        'chave_nfe',
        'codigo_verificacao',
        'numero',
        'xml_url',
        'pdf_url',
        'last_error',
    ];

    public function serviceOrder()
    {
        return $this->belongsTo(ServiceOrder::class, 'quote_id')->withoutGlobalScopes();
    }

    public function logs()
    {
        return $this->hasMany(FiscalDocumentLogProxy::modelClass());
    }
}
