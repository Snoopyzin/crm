<?php

namespace Webkul\ServiceOrder\Services;

use Illuminate\Support\Facades\Log;
use Webkul\User\Models\User;

/**
 * Permissão "ver_valores_financeiros": valores em reais, descontos, crédito,
 * forma de pagamento, comissões. O perfil Técnico não a tem. A checagem é
 * feita no servidor -- as telas, a impressão e a API consultam esta classe
 * antes de montar qualquer campo de valor.
 *
 * Enquanto o piloto estiver desligado, ninguém perde acesso nenhum.
 */
class ValoresFinanceiros
{
    public const PERMISSION = 'ver_valores_financeiros';

    public static function can(?User $user = null): bool
    {
        if (! StageGate::active()) {
            return true;
        }

        $user ??= auth()->guard('user')->user();

        if (! $user || ! $user->role) {
            return false;
        }

        // Administrador da oficina (permissão total).
        if ($user->role->permission_type === 'all') {
            return true;
        }

        return (bool) $user->role->permissions && $user->hasPermission(self::PERMISSION);
    }

    /**
     * Interrompe a requisição com 403 (e registra a tentativa) se o usuário
     * não pode ver valores.
     */
    public static function authorize(string $acao = 'acesso a valores'): void
    {
        if (static::can()) {
            return;
        }

        Log::warning('Acesso negado a valores financeiros', [
            'usuario_id' => auth()->guard('user')->id(),
            'acao' => $acao,
            'url' => request()->fullUrl(),
        ]);

        abort(403, 'Seu perfil não tem acesso a valores financeiros.');
    }
}
