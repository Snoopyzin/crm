<?php

namespace Webkul\ServiceOrder\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de acesso (LGPD): grava quem abriu ou mexeu nas telas financeiras,
 * de pessoal e do painel do dono. Falha de gravação nunca derruba a tela.
 */
class RegistraAcessoSensivel
{
    /** Prefixos de rota que levam dados financeiros, de pessoal ou de crédito. */
    protected const PREFIXOS = [
        'admin.mentoria.', 'admin.financeiro.', 'admin.colaboradores.', 'admin.ficha.', 'admin.historico.', 'admin.mentor.', 'admin.commissions.', 'admin.marketing.export',
    ];

    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        try {
            $nome = (string) $request->route()?->getName();

            foreach (self::PREFIXOS as $prefixo) {
                if (str_starts_with($nome, $prefixo) && Schema::hasTable('acessos_sensiveis')) {
                    DB::table('acessos_sensiveis')->insert([
                        'user_id' => auth()->guard('user')->id(),
                        'rota' => $nome,
                        'metodo' => $request->method(),
                        'ip' => $request->ip(),
                        'acessado_em' => now(),
                    ]);

                    break;
                }
            }
        } catch (\Throwable $exception) {
            report($exception);
        }

        return $response;
    }
}
