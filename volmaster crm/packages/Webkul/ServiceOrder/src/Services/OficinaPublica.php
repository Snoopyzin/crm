<?php

namespace Webkul\ServiceOrder\Services;

use Illuminate\Support\Facades\Storage;

/**
 * Identidade da oficina no link de acompanhamento do cliente: nome, foto da
 * fachada (Configuração > Geral > Identidade da oficina) e, se a Volmaster tiver
 * gerado a cena 3D personalizada, os arquivos dessa oficina.
 */
class OficinaPublica
{
    /** Pasta da oficina em public/img/oficinas/ (id da oficina; "principal" numa instalação única). */
    public static function chave(): string
    {
        return app()->bound('currentTenant') && app('currentTenant') ? (string) app('currentTenant')->id : 'principal';
    }

    /**
     * @return array{nome: string, fachada: ?string, propria: bool, personalizada: bool, pasta: string}
     */
    public static function dados(string $corSlug): array
    {
        $nome = trim((string) core()->getConfigData('general.settings.oficina.nome'));
        $arquivo = core()->getConfigData('general.settings.oficina.fachada');
        $fachada = $arquivo ? Storage::url($arquivo) : null;

        $pasta = 'img/oficinas/'.self::chave().'/';
        $personalizada = is_file(public_path($pasta."rua-{$corSlug}.webp")) && is_file(public_path($pasta."chegou-{$corSlug}.jpg"));

        return [
            'nome' => $nome !== '' ? $nome : 'Volmaster Tech',
            'fachada' => $fachada,
            // oficina com identidade própria: não mostramos a cena padrão (que tem o nome da Volmaster no letreiro)
            'propria' => $nome !== '' || $fachada !== null,
            'personalizada' => $personalizada,
            'pasta' => $pasta,
        ];
    }
}
