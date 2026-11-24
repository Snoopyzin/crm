<?php

namespace Webkul\ServiceOrder\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Webkul\User\Models\User;

/**
 * Uma anotação ou anexo da OS: título e texto livres, mais fotos, vídeos e outros arquivos.
 */
class ServiceOrderExtra extends Model
{
    public const TIPO_FOTO = 'foto';

    public const TIPO_VIDEO = 'video';

    public const TIPO_ARQUIVO = 'arquivo';

    protected $table = 'service_order_extras';

    protected $guarded = [];

    protected $casts = [
        'arquivos' => 'array',
    ];

    public function autor()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function serviceOrder()
    {
        return $this->belongsTo(ServiceOrder::class, 'quote_id');
    }

    /**
     * Arquivos com o endereço público, prontos para a tela.
     *
     * @return array<int, array{url: string, nome: string, tipo: string, tamanho: int}>
     */
    public function arquivosComUrl(): array
    {
        return collect($this->arquivos ?? [])->map(fn (array $arquivo) => [
            'url' => Storage::disk('public')->url($arquivo['path']),
            'nome' => (string) ($arquivo['nome'] ?? basename($arquivo['path'])),
            'tipo' => (string) ($arquivo['tipo'] ?? self::TIPO_ARQUIVO),
            'tamanho' => (int) ($arquivo['tamanho'] ?? 0),
        ])->all();
    }
}
