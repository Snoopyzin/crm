<?php

namespace Webkul\ServiceOrder\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Foto/vídeo/arquivo anexado à OS (fotos e vídeos do checklist/termo de
 * entrada e fotos do laudo).
 */
class ServiceOrderAttachment extends Model
{
    public const TIPO_TERMO_FOTO = 'termo_foto';

    public const TIPO_TERMO_VIDEO = 'termo_video';

    public const TIPO_LAUDO_FOTO = 'laudo_foto';

    public const TIPO_DIAGNOSTICO_FOTO = 'diagnostico_foto';

    public const TIPO_DIAGNOSTICO_VIDEO = 'diagnostico_video';

    public const UPDATED_AT = null;

    protected $table = 'service_order_attachments';

    protected $guarded = [];

    public function url(): string
    {
        return Storage::disk('public')->url($this->path);
    }
}
