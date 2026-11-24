<?php

namespace Webkul\WhatsApp\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppSettings extends Model
{
    protected $table = 'whatsapp_settings';

    protected $fillable = [
        'access_token',
        'phone_number_id',
        'business_account_id',
        'webhook_verify_token',
        'template_orcamento',
        'template_diagnostico',
        'template_pos_venda',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
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
     * Whether enough is configured here to actually call the Graph API --
     * false until the tenant pastes real Meta credentials.
     */
    public function isConfigured(): bool
    {
        return filled($this->access_token) && filled($this->phone_number_id);
    }
}
