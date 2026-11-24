<?php

namespace Webkul\Portal\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Webkul\Contact\Models\PersonProxy;

class ClientUser extends Authenticatable
{
    const STATUS_CONVIDADO = 'Convidado';

    const STATUS_ATIVO = 'Ativo';

    const STATUS_DESATIVADO = 'Desativado';

    protected $table = 'client_users';

    protected $fillable = [
        'person_id',
        'email',
        'password',
        'status',
        'invited_at',
        'activated_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'invited_at' => 'datetime',
        'activated_at' => 'datetime',
    ];

    public function person()
    {
        return $this->belongsTo(PersonProxy::modelClass());
    }

    /**
     * ClientUser has no name of its own -- it's a thin credential record
     * over a Person, so borrow theirs for greetings in emails/views.
     */
    public function getNameAttribute(): ?string
    {
        return $this->person?->name;
    }
}
