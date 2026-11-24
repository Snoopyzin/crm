<?php

namespace Webkul\ServiceOrder\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Evidência do laudo de diagnóstico: código de falha, medição ou foto.
 */
class ServiceOrderLaudoItem extends Model
{
    public const TIPO_CODIGO = 'codigo';

    public const TIPO_MEDICAO = 'medicao';

    public const TIPO_FOTO = 'foto';

    public const TIPOS = [
        self::TIPO_CODIGO => 'Código de falha',
        self::TIPO_MEDICAO => 'Medição',
        self::TIPO_FOTO => 'Foto',
    ];

    public const UPDATED_AT = null;

    protected $table = 'service_order_laudo_items';

    protected $guarded = [];

    public function attachment()
    {
        return $this->belongsTo(ServiceOrderAttachment::class, 'attachment_id');
    }
}
