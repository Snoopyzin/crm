<?php

namespace Webkul\ServiceOrder\Services;

use Carbon\Carbon;
use Throwable;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Models\ServiceOrderAttachment;
use Webkul\ServiceOrder\Models\ServiceOrderExtra;

/**
 * Tudo o que o cliente vê no link de acompanhamento: as 9 etapas da jornada do
 * caminhão, o histórico e as anotações e anexos. Só leitura e só o que é seguro
 * mostrar: sem valores, sem crédito, sem anotações internas.
 */
class JornadaCliente
{
    /** Etapas na ordem em que o caminhão passa por elas. */
    public const ETAPAS = [
        ['chave' => 'os', 'titulo' => 'Ordem de serviço', 'texto' => 'Seu veículo chegou e a ordem de serviço foi aberta.'],
        ['chave' => 'diagnostico', 'titulo' => 'Diagnóstico', 'texto' => 'Nosso mecânico está avaliando o problema.'],
        ['chave' => 'orcamento', 'titulo' => 'Orçamento em andamento', 'texto' => 'Estamos montando o orçamento do serviço.'],
        ['chave' => 'negociacao', 'titulo' => 'Negociação', 'texto' => 'Aguardando a sua aprovação para seguir.'],
        ['chave' => 'execucao', 'titulo' => 'Em execução', 'texto' => 'O serviço está em andamento agora.'],
        ['chave' => 'teste', 'titulo' => 'Veículo em teste', 'texto' => 'Seu veículo saiu para um teste de rodagem na rua.'],
        ['chave' => 'pronto', 'titulo' => 'Pronto para retirada', 'texto' => 'Pode vir buscar quando quiser!'],
        ['chave' => 'faturamento', 'titulo' => 'Faturamento', 'texto' => 'Fechando a parte financeira da sua ordem de serviço.'],
        ['chave' => 'posvenda', 'titulo' => 'Pós-venda', 'texto' => 'Serviço entregue. Vamos acompanhar se está tudo certo.'],
    ];

    /** Eventos do histórico que o cliente pode ver, com o texto para ele. */
    private const EVENTOS = [
        'chegada_veiculo' => 'Veículo recebido na oficina',
        'termo_assinado' => 'Termo de entrada assinado',
        'diagnostico_inicio' => 'Diagnóstico iniciado',
        'diagnostico_fim' => 'Diagnóstico finalizado',
        'orcamento_enviado' => 'Orçamento enviado para você',
        'cliente_aprovou' => 'Orçamento aprovado',
        'cliente_aprovou_parcial' => 'Orçamento aprovado parcialmente',
        'cliente_recusou' => 'Orçamento recusado',
        'execucao_liberada' => 'Serviço liberado para execução',
        'operacao_inicio' => 'Serviço iniciado',
        'teste_inicio' => 'Teste de rodagem iniciado',
        'teste_fim' => 'Teste de rodagem finalizado',
        'nf_emitida' => 'Nota fiscal emitida',
        'pagamento_confirmado' => 'Pagamento confirmado',
        'saida_veiculo' => 'Veículo entregue',
        'posvenda_contato' => 'Contato de pós-venda',
    ];

    /** Nome da etapa interna da oficina para o cliente. */
    private const NOME_ETAPA = [
        ServiceOrder::STAGE_CADASTRO => 'Ordem de serviço',
        ServiceOrder::STAGE_ORDEM_SERVICO => 'Ordem de serviço',
        ServiceOrder::STAGE_DIAGNOSTICO => 'Diagnóstico',
        ServiceOrder::STAGE_ORCAMENTO => 'Orçamento em andamento',
        ServiceOrder::STAGE_NEGOCIACAO => 'Negociação',
        ServiceOrder::STAGE_EM_EXECUCAO => 'Em execução',
        ServiceOrder::STAGE_VEICULO_TESTE => 'Veículo em teste',
        ServiceOrder::STAGE_FATURAMENTO => 'Pronto para retirada',
        ServiceOrder::STAGE_POS_VENDA => 'Pós-venda',
        ServiceOrder::STAGE_CONCLUIDA => 'Ordem de serviço concluída',
    ];

    /** Qual das 9 etapas corresponde à etapa interna (e se já foi faturada). */
    public static function indice(?string $etapa, bool $faturada = false): int
    {
        return match ($etapa) {
            ServiceOrder::STAGE_DIAGNOSTICO => 1,
            ServiceOrder::STAGE_ORCAMENTO => 2,
            ServiceOrder::STAGE_NEGOCIACAO => 3,
            ServiceOrder::STAGE_EM_EXECUCAO => 4,
            ServiceOrder::STAGE_VEICULO_TESTE => 5,
            ServiceOrder::STAGE_FATURAMENTO => $faturada ? 7 : 6,
            ServiceOrder::STAGE_POS_VENDA, ServiceOrder::STAGE_CONCLUIDA => 8,
            default => 0,
        };
    }

    /**
     * @return array{passos: array<int, array<string, mixed>>, atual: int, concluida: bool}
     */
    public function jornada(ServiceOrder $os): array
    {
        $concluida = $os->workshop_status === ServiceOrder::STAGE_CONCLUIDA;
        $atual = self::indice($os->workshop_status, $os->faturado_em !== null);
        $datas = $this->datasDasEtapas($os);
        $passos = [];

        foreach (self::ETAPAS as $i => $etapa) {
            $estado = $concluida || $i < $atual ? 'feito' : ($i === $atual ? 'atual' : 'futuro');
            $passos[] = $etapa + ['estado' => $estado, 'quando' => $datas[$i] ?? null];
        }

        return ['passos' => $passos, 'atual' => $atual, 'concluida' => $concluida];
    }

    /**
     * Quando cada etapa foi alcançada (primeira vez), pelo registro de eventos.
     *
     * @return array<int, Carbon>
     */
    private function datasDasEtapas(ServiceOrder $os): array
    {
        $datas = [];

        foreach ($this->eventos($os) as $evento) {
            $quando = $evento->ocorrido_em ?? $evento->criado_em;
            $i = null;

            if ($evento->tipo_evento === 'mudanca_etapa' && $evento->etapa_destino) {
                $i = self::indice($evento->etapa_destino, false);
            } elseif ($evento->tipo_evento === 'nf_emitida') {
                $i = 7;
            } elseif ($evento->tipo_evento === 'chegada_veiculo') {
                $i = 0;
            }

            if ($i !== null && $quando && (! isset($datas[$i]) || $quando->lt($datas[$i]))) {
                $datas[$i] = $quando;
            }
        }

        return $datas;
    }

    /** @return \Illuminate\Support\Collection<int, \Webkul\ServiceOrder\Models\ServiceOrderEvent> */
    private function eventos(ServiceOrder $os)
    {
        try {
            return $os->events()->orderBy('ocorrido_em')->orderBy('id')->get();
        } catch (Throwable) {
            return collect();   // servidor ainda sem a tabela de eventos
        }
    }

    /**
     * Linha do tempo para o cliente, do mais recente para o mais antigo.
     *
     * @return array<int, array{quando: ?Carbon, titulo: string}>
     */
    public function historico(ServiceOrder $os): array
    {
        $linhas = [];
        $ultimo = null;

        foreach ($this->eventos($os) as $evento) {
            if ($evento->tipo_evento === 'mudanca_etapa') {
                $nome = self::NOME_ETAPA[$evento->etapa_destino] ?? null;
                $titulo = $nome ? "Passou para: {$nome}" : null;
            } else {
                $titulo = self::EVENTOS[$evento->tipo_evento] ?? null;
            }

            if ($titulo === null || $titulo === $ultimo) {
                continue;
            }

            $ultimo = $titulo;
            $linhas[] = ['quando' => $evento->ocorrido_em ?? $evento->criado_em, 'titulo' => $titulo];
        }

        return array_slice(array_reverse($linhas), 0, 40);
    }

    /**
     * Anotações e anexos marcados como "Mostrar ao cliente" + fotos do diagnóstico.
     *
     * @return array<int, array<string, mixed>>
     */
    public function anexos(ServiceOrder $os): array
    {
        $itens = [];

        try {
            $extras = ServiceOrderExtra::where('quote_id', $os->id)
                ->where('visivel_cliente', true)
                ->orderByDesc('id')
                ->get();

            foreach ($extras as $extra) {
                $itens[] = [
                    'titulo' => $extra->titulo,
                    'texto' => $extra->texto,
                    'quando' => $extra->created_at,
                    'arquivos' => $extra->arquivosComUrl(),
                ];
            }
        } catch (Throwable) {
            // coluna ainda não existe (migration pendente): nada é mostrado
        }

        try {
            $fotos = $os->attachments()
                ->whereIn('tipo', [ServiceOrderAttachment::TIPO_DIAGNOSTICO_FOTO, ServiceOrderAttachment::TIPO_LAUDO_FOTO])
                ->orderBy('id')
                ->get();

            if ($fotos->isNotEmpty()) {
                array_unshift($itens, [
                    'titulo' => 'Fotos do diagnóstico',
                    'texto' => 'O que o nosso mecânico encontrou no seu veículo.',
                    'quando' => $fotos->first()->created_at ?? null,
                    'arquivos' => $fotos->map(fn ($f) => ['url' => $f->url(), 'nome' => 'Foto do diagnóstico', 'tipo' => 'foto', 'tamanho' => 0])->all(),
                ]);
            }
        } catch (Throwable) {
        }

        return $itens;
    }

    /** Caminhão pré-renderizado (3D) mais parecido com a cor do veículo. */
    public const CAMINHOES = [
        'branco' => '#F1F3F5', 'prata' => '#C2C9D0', 'cinza' => '#808B96', 'grafite' => '#3B434C', 'preto' => '#1F2329',
        'vermelho' => '#D1262C', 'vinho' => '#6E1B2B', 'laranja' => '#F27A1A', 'amarelo' => '#F2BE1A', 'verde' => '#2F9D4F',
        'azul' => '#1F6FD6', 'azul-escuro' => '#16367F', 'celeste' => '#6DB8EE', 'marrom' => '#7A4A2B', 'bege' => '#D8C6A0',
    ];

    public static function caminhao(?string $cor): array
    {
        $hex = TruckSvg::resolveColor($cor)['hex'];
        [$r, $g, $b] = [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
        $melhor = 'azul';
        $menor = PHP_INT_MAX;

        foreach (self::CAMINHOES as $slug => $ref) {
            $d = ($r - hexdec(substr($ref, 1, 2))) ** 2 + ($g - hexdec(substr($ref, 3, 2))) ** 2 + ($b - hexdec(substr($ref, 5, 2))) ** 2;

            if ($d < $menor) {
                $menor = $d;
                $melhor = $slug;
            }
        }

        return ['slug' => $melhor, 'hex' => $hex, 'arquivo' => "img/caminhoes/caminhao-{$melhor}.png"];
    }
}
