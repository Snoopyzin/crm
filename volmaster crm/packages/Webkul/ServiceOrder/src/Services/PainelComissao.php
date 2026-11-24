<?php

namespace Webkul\ServiceOrder\Services;

use Illuminate\Support\Carbon;
use Webkul\User\Models\User;

/**
 * Início de quem ganha comissão (mecânico, consultor...): vê só a comissão
 * dele, nunca o faturamento geral da oficina.
 *
 * Quem é "só comissão": perfil que não é administrador, não recebeu a
 * permissão "Faturamento geral" (dashboard.faturamento_geral) e tem % de
 * comissão na Função -- ou, com o piloto ligado, não pode ver valores.
 * Perfis sem comissão (dono, gerente...) continuam vendo tudo, como antes.
 *
 * A regra vale no servidor: a API do Início recusa os blocos de faturamento
 * para esse perfil, não é só a tela que os esconde.
 */
class PainelComissao
{
    public const PERMISSAO_FATURAMENTO_GERAL = 'dashboard.faturamento_geral';

    /**
     * Blocos do Início que esse perfil ainda pode pedir (nenhum traz valor
     * de faturamento).
     */
    public const BLOCOS_PERMITIDOS = ['pending-service-orders', 'urgent-remote-services', 'minhas-comissoes'];

    /**
     * Campos da OS que o card "OS em andamento" mostra a esse perfil.
     * Lista branca: nenhum total, desconto, crédito ou forma de pagamento sai.
     */
    public const CAMPOS_OS = ['id', 'subject', 'workshop_status', 'created_at', 'data_previsao', 'hora_previsao', 'prazo'];

    public static function somenteComissao(?User $user = null): bool
    {
        $user ??= auth()->guard('user')->user();

        if (! $user || ! $user->role) {
            return false;
        }

        // Administrador da oficina e quem recebeu a permissão veem tudo.
        if ($user->role->permission_type === 'all' || $user->hasPermission(self::PERMISSAO_FATURAMENTO_GERAL)) {
            return false;
        }

        return static::temComissao($user) || ! ValoresFinanceiros::can($user);
    }

    /**
     * A Função do usuário tem % de comissão: o Início mostra "Minhas comissões".
     */
    public static function temComissao(?User $user = null): bool
    {
        $user ??= auth()->guard('user')->user();

        return $user && $user->role && (float) $user->role->comissao_percentual > 0;
    }

    /**
     * A comissão do próprio usuário no período, por OS faturada. Mostra o
     * cliente, o serviço e quanto ele ganhou; nunca a base, o valor cheio, o
     * desconto, o percentual nem o total da OS.
     *
     * @return array{periodo: string, total: array{valor: float, formatado: string}, os: int, linhas: array<int, array<string, mixed>>}
     */
    public static function minhasComissoes(User $user, Carbon $de, Carbon $ate): array
    {
        $relatorio = app(CommissionReportService::class)->build($de, $ate);

        $linhas = [];
        $total = 0.0;

        foreach ($relatorio['people'] as $pessoa) {
            if (($pessoa['user_id'] ?? null) !== $user->id || $pessoa['percent'] === null) {
                continue;
            }

            foreach ($pessoa['lines'] as $linha) {
                $comissao = round($linha['base'] * $pessoa['percent'] / 100, 2);
                $total += $comissao;

                $linhas[] = [
                    'os' => $linha['os'],
                    'data' => $linha['date']?->format('d/m/Y'),
                    'cliente' => $linha['cliente'],
                    'servico' => $pessoa['kind'] === CommissionReportService::KIND_VENDA ? 'Venda da OS' : $linha['descricao'],
                    'comissao' => $comissao,
                    'comissao_formatada' => core()->formatBasePrice($comissao),
                    '_ordem' => $linha['date']?->getTimestamp() ?? 0,
                ];
            }
        }

        usort($linhas, fn ($a, $b) => $b['_ordem'] <=> $a['_ordem'] ?: $b['os'] <=> $a['os']);

        $linhas = array_map(function ($linha) {
            unset($linha['_ordem']);

            return $linha;
        }, $linhas);

        $total = round($total, 2);

        return [
            'periodo' => $de->format('d/m/Y').' a '.$ate->format('d/m/Y'),
            'total' => ['valor' => $total, 'formatado' => core()->formatBasePrice($total)],
            'os' => count(array_unique(array_column($linhas, 'os'))),
            'linhas' => $linhas,
        ];
    }
}
