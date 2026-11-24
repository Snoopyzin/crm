<?php

namespace Webkul\Fiscal\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\Fiscal\Contracts\FiscalDocumentLog as FiscalDocumentLogContract;

class FiscalDocumentLog extends Model implements FiscalDocumentLogContract
{
    protected $table = 'fiscal_document_logs';

    protected $fillable = [
        'fiscal_document_id',
        'event',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function fiscalDocument()
    {
        return $this->belongsTo(FiscalDocumentProxy::modelClass());
    }
}
