<?php

namespace Webkul\ServiceOrder\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Webkul\ServiceOrder\Services\StageGate;

/**
 * Cada cargo entra direto na própria tela (capítulo 11, passo 8): com o piloto
 * ligado, quem abre o dashboard antigo do CRM ("negócios ganhos", "cotações")
 * é levado ao Painel do Dono (dono), à tela do técnico (técnico) ou ao painel
 * de rotina do cargo. Só vale se o usuário tem permissão para a tela de destino.
 */
class EntradaPorCargo
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->isMethod('GET') || ! $request->routeIs('admin.dashboard.index') || $request->ajax() || ! StageGate::active()) {
            return $next($request);
        }

        $user = auth()->guard('user')->user();

        if (! $user) {
            return $next($request);
        }

        $perfis = array_map('trim', explode(',', (string) $user->role?->perfil_oficina));

        $destino = match (true) {
            in_array('proprietario', $perfis, true) => ['painel_dono', 'admin.mentoria.painel'],
            in_array('tecnico', $perfis, true) && ! array_intersect(['chefe', 'gerente'], $perfis) => ['tecnico', 'admin.tecnico.index'],
            default => ['rotina_painel', 'admin.rotina.painel'],
        };

        // Sem papel da oficina ou sem permissão para a tela do cargo: segue para o dashboard de sempre.
        if (! array_filter($perfis) || ! bouncer()->hasPermission($destino[0])) {
            return $next($request);
        }

        return redirect()->route($destino[1]);
    }
}
