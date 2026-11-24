<?php

namespace Webkul\Admin\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\User\Models\UserProxy;

class ApiKey extends Model
{
    const TIPO_FOCUSNFE = 'focusnfe';

    const TIPO_ASAAS = 'asaas';

    protected $table = 'api_keys';

    protected $fillable = [
        'nome',
        'tipo',
        'valor',
        'observacoes',
        'user_id',
    ];

    /**
     * "valor" holds arbitrary third-party API credentials, so it's kept
     * encrypted at rest -- Laravel transparently encrypts on write and
     * decrypts on read using APP_KEY.
     */
    protected $casts = [
        'valor' => 'encrypted',
    ];

    public function user()
    {
        return $this->belongsTo(UserProxy::modelClass());
    }

    /**
     * The current tenant's Focus NFe token, or null if none is registered yet.
     */
    public static function focusNfeToken(): ?string
    {
        return static::where('tipo', self::TIPO_FOCUSNFE)->value('valor');
    }

    /**
     * The current tenant's Asaas API key, or null if none is registered yet.
     */
    public static function asaasToken(): ?string
    {
        return static::where('tipo', self::TIPO_ASAAS)->value('valor');
    }
}
