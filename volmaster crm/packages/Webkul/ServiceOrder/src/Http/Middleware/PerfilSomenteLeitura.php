<?php

namespace Webkul\ServiceOrder\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Webkul\ServiceOrder\Services\Perfis\PerfilCatalog;
use Webkul\ServiceOrder\Services\StageGate;

/**
 * Mentor é somente leitura (B8 do roteiro): com o piloto ligado, quem só tem perfis de
 * leitura (o Mentor) não consegue gravar nada. Só passam as notificações do celular.
 */
class PerfilSomenteLeitura
{
    /**
     * Rotas de escrita que o Mentor pode usar (notificações do próprio aparelho).
     */
    protected const PERMITIDAS = ['admin.workshop.push.subscribe', 'admin.workshop.push.unsubscribe', 'admin.workshop.push.test'];

    public function handle(Request $request, Closure $next)
    {
        if ($request->isMethodSafe() || ! StageGate::active() || $request->routeIs(...self::PERMITIDAS)) {
            return $next($request);
        }

        $usuario = auth()->guard('user')->user();
        $perfis = array_values(array_filter(array_map('trim', explode(',', (string) $usuario?->role?->getAttribute('perfil_oficina')))));

        if ($perfis !== [] && ! array_filter($perfis, fn ($perfil) => ! PerfilCatalog::somenteLeitura($perfil))) {
            abort(403, 'O Mentor só consulta: esta ação não é permitida.');
        }

        return $next($request);
    }
}
