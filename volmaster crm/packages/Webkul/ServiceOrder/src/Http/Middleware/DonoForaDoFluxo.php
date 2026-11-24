<?php

namespace Webkul\ServiceOrder\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Webkul\ServiceOrder\Models\ServiceOrderEvent;
use Webkul\ServiceOrder\Services\StageGate;

/**
 * Regra do dono blindado (capítulo 2): o dono pode abrir qualquer OS, mas
 * qualquer ação dele dentro do fluxo gera o registro "ação do dono fora do
 * fluxo", que alimenta o indicador DON-05 (vezes que o dono foi acionado fora
 * da alçada). Decidir a fila de alçadas não conta: essa é a função dele.
 */
class DonoForaDoFluxo
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true) || $response->getStatusCode() >= 400) {
            return $response;
        }

        $route = $request->route();
        $name = (string) $route?->getName();

        if (! str_starts_with($name, 'admin.service_orders.') || ! StageGate::active()) {
            return $response;
        }

        // Ação que falhou na regra de negócio (mensagem de erro na sessão) não aconteceu.
        if ($request->hasSession() && ($request->session()->has('error') || $request->session()->has('errors'))) {
            return $response;
        }

        $user = auth()->guard('user')->user();
        $perfis = array_map('trim', explode(',', (string) $user?->role?->perfil_oficina));

        if (! $user || ! in_array('proprietario', $perfis, true)) {
            return $response;
        }

        $osId = $route->parameter('id');

        if (is_numeric($osId)) {
            ServiceOrderEvent::record((int) $osId, 'acao_dono_fora_fluxo', [
                'rota' => $name,
                'metodo' => $request->method(),
                'usuario' => $user->name,
            ]);
        }

        return $response;
    }
}
