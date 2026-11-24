<?php

namespace Webkul\ServiceOrder\Services;

use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Webkul\Mechanic\Models\Mechanic;
use Webkul\PartInventory\Models\PartInventory;
use Webkul\ServiceOrder\Models\DiagnosticoQueixaTipo;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Models\ServiceOrderAttachment;
use Webkul\ServiceOrder\Models\ServiceOrderEvent;
use Webkul\ServiceOrder\Models\ServiceOrderItem;
use Webkul\ServiceOrder\Models\ServiceOrderLaudoItem;
use Webkul\ServiceOrder\Services\Credito\CreditoCliente;

/**
 * Ações de cada passo do fluxo da OS. Cada uma atualiza o estado atual da OS
 * e grava o evento correspondente (data, hora e usuário) no log.
 */
class WorkflowService
{
    public function __construct(protected StageGate $gate) {}

    public function designateTechnician(ServiceOrder $os, int $mechanicId): void
    {
        $mechanic = Mechanic::findOrFail($mechanicId);

        $os->forceFill(array_filter([
            'diagnostico_mechanic_id' => $mechanic->id,
            'mechanic_id' => $os->mechanic_id ?: $mechanic->id,
        ]))->save();

        ServiceOrderEvent::record($os, 'tecnico_designado', ['mechanic_id' => $mechanic->id, 'tecnico' => $mechanic->nome]);
    }

    /**
     * Termo de entrada: km, combustível, itens, autorização de rodagem,
     * fotos e assinatura.
     *
     * @param  UploadedFile[]  $photos
     */
    public function saveTermo(ServiceOrder $os, array $data, array $photos = []): void
    {
        $os->forceFill([
            'km_entrada' => $data['km_entrada'] ?? $os->km_entrada,
            'combustivel_entrada' => $data['combustivel_entrada'] ?? $os->combustivel_entrada,
            'itens_entrada' => $data['itens_entrada'] ?? $os->itens_entrada,
            'autorizacao_rodagem' => (bool) ($data['autorizacao_rodagem'] ?? false),
        ])->save();

        foreach ($photos as $photo) {
            $this->storeAttachment($os, ServiceOrderAttachment::TIPO_TERMO_FOTO, $photo);
        }

        if (! empty($data['assinado']) && ! $os->termo_assinado_em) {
            $os->forceFill(['termo_assinado_em' => now()])->save();

            ServiceOrderEvent::record($os, 'termo_assinado', [
                'km' => $os->km_entrada,
                'combustivel' => $os->combustivel_entrada,
                'autorizacao_rodagem' => $os->autorizacao_rodagem,
                'fotos' => $os->attachments()->where('tipo', ServiceOrderAttachment::TIPO_TERMO_FOTO)->count(),
            ]);
        }
    }

    /**
     * Checklist de entrada feito no Novo Cadastro: descrição do veículo, km,
     * combustível, itens/avarias, fotos e vídeos. Grava nos mesmos campos do
     * termo de entrada (a OS já nasce com km e fotos), sem assinar o termo
     * nem autorizar a rodagem: isso continua sendo passo da OS.
     *
     * @param  array<string, mixed>  $data
     * @param  UploadedFile[]  $photos
     * @param  UploadedFile[]  $videos
     */
    public function saveChecklistEntrada(ServiceOrder $os, array $data, array $photos = [], array $videos = []): void
    {
        $campos = array_filter([
            'km_entrada' => $data['km_entrada'] ?? null,
            'combustivel_entrada' => $data['combustivel_entrada'] ?? null,
            'itens_entrada' => $data['itens_entrada'] ?? null,
            // Bancos ainda sem a migration da descrição seguem funcionando.
            'descricao_entrada' => Schema::hasColumn('quotes', 'descricao_entrada') ? ($data['descricao_entrada'] ?? null) : null,
            'relato_motorista' => Schema::hasColumn('quotes', 'relato_motorista') ? ($data['relato_motorista'] ?? null) : null,
            'defeito_relatado' => Schema::hasColumn('quotes', 'defeito_relatado') ? ($data['defeito_relatado'] ?? null) : null,
        ], fn ($valor) => $valor !== null && $valor !== '');

        if ($campos) {
            $os->forceFill($campos)->save();
        }

        foreach ($photos as $photo) {
            $this->storeAttachment($os, ServiceOrderAttachment::TIPO_TERMO_FOTO, $photo);
        }

        foreach ($videos as $video) {
            $this->storeAttachment($os, ServiceOrderAttachment::TIPO_TERMO_VIDEO, $video);
        }

        if ($campos || $photos || $videos) {
            ServiceOrderEvent::record($os, 'checklist_entrada', [
                'km' => $os->km_entrada,
                'combustivel' => $os->combustivel_entrada,
                'fotos' => count($photos),
                'videos' => count($videos),
            ]);
        }
    }

    public function setCorCredito(ServiceOrder $os, string $cor, ?string $observacao = null, ?string $justificativa = null): void
    {
        if (! array_key_exists($cor, ServiceOrder::CORES_CREDITO)) {
            throw ValidationException::withMessages(['cor_credito' => ['Cor de crédito inválida.']]);
        }

        $mudou = $os->cor_credito !== $cor;

        // A hora em que a OS recebeu cor pela PRIMEIRA vez é a que o CON-03 mede ("antes da liberação").
        $os->forceFill(['cor_credito' => $cor, 'cor_credito_em' => $os->cor_credito_em ?? now()])->save();

        if ($mudou) {
            ServiceOrderEvent::record($os, 'cor_credito_registrada', ['cor' => $cor]);
        }

        // B1: a análise também entra no histórico de crédito do CLIENTE (com usuário e observação).
        if ($os->person_id && CreditoCliente::disponivel()) {
            app(CreditoCliente::class)->registrar((int) $os->person_id, $cor, $observacao, $justificativa, $os);
        }
    }

    /**
     * @param  string|null  $oQueFazer  A decisão do diagnóstico ("o que precisa ser feito").
     *                                  null = o formulário não mandou o campo: nada muda.
     */
    public function saveLaudo(ServiceOrder $os, ?string $laudo, ?string $oQueFazer = null): void
    {
        $campos = ['diagnostico' => $laudo];

        if ($oQueFazer !== null && Schema::hasColumn('quotes', 'diagnostico_o_que_fazer')) {
            $campos['diagnostico_o_que_fazer'] = $oQueFazer !== '' ? $oQueFazer : null;
        }

        $os->forceFill($campos)->save();
    }

    /**
     * O chefe de oficina informa (ou corrige) os horários de início e conclusão do diagnóstico
     * (reunião de 19/11/2026). Horário que ainda não existia vira o evento normal de início/fim, marcado
     * como "informado pelo chefe"; mudar um horário já registrado exige justificativa e fica como correção.
     *
     * @throws ValidationException
     */
    public function informarHorariosDiagnostico(ServiceOrder $os, ?string $inicio, ?string $fim, ?string $justificativa = null): void
    {
        $novoInicio = $inicio ? Carbon::parse($inicio)->second(0) : null;
        $novoFim = $fim ? Carbon::parse($fim)->second(0) : null;

        if (! $novoInicio && ! $novoFim) {
            throw ValidationException::withMessages(['diagnostico_inicio_em' => ['Informe o horário de início, o de conclusão ou os dois.']]);
        }

        $limite = now()->addMinutes(5);

        foreach (['diagnostico_inicio_em' => $novoInicio, 'diagnostico_fim_em' => $novoFim] as $campo => $horario) {
            if ($horario && $horario->gt($limite)) {
                throw ValidationException::withMessages([$campo => ['O horário não pode ser no futuro.']]);
            }
        }

        $inicioFinal = $novoInicio ?? $os->diagnostico_inicio_em;
        $fimFinal = $novoFim ?? $os->diagnostico_concluido_em;

        if ($fimFinal && ! $inicioFinal) {
            throw ValidationException::withMessages(['diagnostico_inicio_em' => ['Informe também o horário de início do diagnóstico.']]);
        }

        if ($inicioFinal && $fimFinal && $fimFinal->lt($inicioFinal)) {
            throw ValidationException::withMessages(['diagnostico_fim_em' => ['A conclusão não pode ser antes do início.']]);
        }

        if ($novoFim && ! trim((string) $os->diagnostico)) {
            throw ValidationException::withMessages(['diagnostico_fim_em' => ['Escreva a descrição técnica antes de informar a conclusão.']]);
        }

        $minuto = fn (?Carbon $horario) => $horario?->format('Y-m-d H:i');
        $muda = ($novoInicio && $os->diagnostico_inicio_em && $minuto($novoInicio) !== $minuto($os->diagnostico_inicio_em))
            || ($novoFim && $os->diagnostico_concluido_em && $minuto($novoFim) !== $minuto($os->diagnostico_concluido_em));

        if ($muda && mb_strlen(trim((string) $justificativa)) < 5) {
            throw ValidationException::withMessages(['justificativa' => ['Para mudar um horário já registrado, explique o motivo (mínimo de 5 letras).']]);
        }

        DB::transaction(function () use ($os, $novoInicio, $novoFim, $justificativa) {
            $campos = [];

            foreach ([
                'diagnostico_inicio_em' => [$novoInicio, 'diagnostico_inicio'],
                'diagnostico_concluido_em' => [$novoFim, 'diagnostico_fim'],
            ] as $coluna => [$novo, $tipoEvento]) {
                if (! $novo) {
                    continue;
                }

                $antes = $os->{$coluna};

                if ($antes && $antes->format('Y-m-d H:i') === $novo->format('Y-m-d H:i')) {
                    continue;
                }

                $campos[$coluna] = $novo;

                if ($antes) {
                    ServiceOrderEvent::record($os, 'correcao', [
                        'campo' => $coluna,
                        'antes' => $antes->toDateTimeString(),
                        'depois' => $novo->toDateTimeString(),
                        'justificativa' => trim((string) $justificativa),
                    ]);
                } else {
                    ServiceOrderEvent::record($os, $tipoEvento, ['origem' => 'informado_chefe'], null, null, $novo);
                }
            }

            if ($campos) {
                $os->forceFill($campos)->save();
            }
        });
    }

    /**
     * Fotos e vídeos do diagnóstico (a evidência do que o técnico encontrou).
     *
     * @param  UploadedFile[]  $fotos
     * @param  UploadedFile[]  $videos
     */
    public function addDiagnosticoMidia(ServiceOrder $os, array $fotos = [], array $videos = []): int
    {
        foreach ($fotos as $foto) {
            $this->storeAttachment($os, ServiceOrderAttachment::TIPO_DIAGNOSTICO_FOTO, $foto);
        }

        foreach ($videos as $video) {
            $this->storeAttachment($os, ServiceOrderAttachment::TIPO_DIAGNOSTICO_VIDEO, $video);
        }

        $total = count($fotos) + count($videos);

        if ($total > 0) {
            ServiceOrderEvent::record($os, 'diagnostico_midia', ['fotos' => count($fotos), 'videos' => count($videos)]);
        }

        return $total;
    }

    public function startDiagnostico(ServiceOrder $os, ?int $queixaTipoId = null): void
    {
        if ($os->diagnostico_inicio_em) {
            throw ValidationException::withMessages(['diagnostico' => ['O diagnóstico desta OS já foi iniciado.']]);
        }

        $tipo = $queixaTipoId ? DiagnosticoQueixaTipo::find($queixaTipoId) : null;

        $os->forceFill([
            'diagnostico_inicio_em' => now(),
            'queixa_tipo_id' => $tipo?->id,
            // O técnico que inicia é o responsável pelo laudo.
            'diagnostico_mechanic_id' => $os->diagnostico_mechanic_id ?: $os->mechanic_id,
        ])->save();

        ServiceOrderEvent::record($os, 'diagnostico_inicio', [
            'queixa_tipo' => $tipo?->nome,
            'tempo_alvo_min' => $tipo?->tempo_alvo_min,
        ]);
    }

    /**
     * Finaliza o diagnóstico. O laudo precisa de texto e de pelo menos uma
     * evidência; se passou do tempo alvo, a causa do atraso é obrigatória.
     */
    public function finishDiagnostico(ServiceOrder $os, ?string $causaAtraso = null, ?string $observacao = null): void
    {
        if (! $os->diagnostico_inicio_em) {
            throw ValidationException::withMessages(['diagnostico' => ['Inicie o diagnóstico antes de finalizar.']]);
        }

        if ($os->diagnostico_concluido_em) {
            throw ValidationException::withMessages(['diagnostico' => ['O diagnóstico já foi finalizado.']]);
        }

        if (! trim((string) $os->diagnostico)) {
            throw ValidationException::withMessages(['diagnostico' => ['Escreva o laudo antes de finalizar o diagnóstico.']]);
        }

        if (! $os->temEvidenciaDoDiagnostico()) {
            throw ValidationException::withMessages(['diagnostico' => ['O laudo não fecha sem evidência: adicione uma foto ou um vídeo, um código de falha ou uma medição.']]);
        }

        $agora = now();
        $minutos = max(0, $agora->getTimestamp() - $os->diagnostico_inicio_em->getTimestamp()) / 60;
        $alvo = $os->queixaTipo?->tempo_alvo_min; // real: vai para o evento, como sempre
        $alvoEfetivo = $os->queixaTipo?->tempoAlvoEfetivoMin(); // com o divisor do modo de teste
        $passou = $alvoEfetivo && $minutos > $alvoEfetivo;

        if ($passou) {
            $causas = (array) WorkshopSettings::get('causas_atraso_diagnostico', []);

            if (! $causaAtraso || ! in_array($causaAtraso, $causas, true)) {
                throw ValidationException::withMessages([
                    'causa_atraso' => ['O diagnóstico passou do tempo alvo ('.ModoTeste::numero($alvoEfetivo).' min): informe a causa do atraso.'],
                ]);
            }
        }

        $os->forceFill([
            'diagnostico_concluido_em' => $agora,
            'diagnostico_causa_atraso' => $passou ? $causaAtraso : null,
            // Voltou do chefe? Fica aguardando novo visto.
            'diagnostico_visto_status' => null,
        ])->save();

        ServiceOrderEvent::record($os, 'diagnostico_fim', [
            'mechanic_id' => $os->tecnicoDesignadoId(),
            'minutos' => round($minutos, 1),
            'tempo_alvo_min' => $alvo,
            'passou_do_alvo' => (bool) $passou,
            'causa_atraso' => $passou ? $causaAtraso : null,
        ]);

        if ($passou) {
            ServiceOrderEvent::record($os, 'causa_atraso_registrada', ['causa' => $causaAtraso, 'observacao' => $observacao]);
        }
    }

    public function addEvidence(ServiceOrder $os, string $tipo, ?string $descricao, ?string $valor = null, ?UploadedFile $foto = null): ServiceOrderLaudoItem
    {
        if (! array_key_exists($tipo, ServiceOrderLaudoItem::TIPOS)) {
            throw ValidationException::withMessages(['tipo' => ['Tipo de evidência inválido.']]);
        }

        $attachment = null;

        if ($tipo === ServiceOrderLaudoItem::TIPO_FOTO) {
            if (! $foto) {
                throw ValidationException::withMessages(['foto' => ['Envie a foto da evidência.']]);
            }

            $attachment = $this->storeAttachment($os, ServiceOrderAttachment::TIPO_LAUDO_FOTO, $foto);
        } elseif (! trim((string) $descricao)) {
            throw ValidationException::withMessages(['descricao' => ['Descreva a evidência (código ou medição).']]);
        }

        $item = ServiceOrderLaudoItem::create([
            'quote_id' => $os->id,
            'tipo' => $tipo,
            'descricao' => trim((string) $descricao) ?: 'Foto do diagnóstico',
            'valor' => $valor,
            'attachment_id' => $attachment?->id,
            'user_id' => auth()->guard('user')->id(),
        ]);

        ServiceOrderEvent::record($os, 'laudo_evidencia', ['tipo' => $tipo, 'descricao' => $item->descricao]);

        return $item;
    }

    /**
     * Visto do chefe: aprovado ou devolvido. Quem fez o diagnóstico não
     * audita o próprio laudo.
     */
    public function auditDiagnostico(ServiceOrder $os, string $status, ?string $observacao = null): void
    {
        if (! in_array($status, ['aprovado', 'devolvido'], true)) {
            throw ValidationException::withMessages(['status' => ['Escolha aprovar ou devolver.']]);
        }

        if (! $os->diagnostico_concluido_em) {
            throw ValidationException::withMessages(['status' => ['O técnico ainda não finalizou o diagnóstico.']]);
        }

        $user = auth()->guard('user')->user();

        if ($user?->mechanic_id && $user->mechanic_id === $os->tecnicoDesignadoId()) {
            throw ValidationException::withMessages(['status' => ['Quem fez o diagnóstico não pode dar o visto no próprio laudo.']]);
        }

        if ($status === 'devolvido' && ! trim((string) $observacao)) {
            throw ValidationException::withMessages(['observacao' => ['Informe o que o técnico precisa corrigir.']]);
        }

        $os->forceFill([
            'diagnostico_visto_status' => $status,
            'diagnostico_visto_por' => $user?->id,
            'diagnostico_visto_em' => now(),
            // Devolvido: o técnico corrige e finaliza de novo.
            'diagnostico_concluido_em' => $status === 'devolvido' ? null : $os->diagnostico_concluido_em,
        ])->save();

        ServiceOrderEvent::record($os, 'diagnostico_auditado', ['status' => $status, 'observacao' => $observacao]);
    }

    public function deliverParts(ServiceOrder $os, bool $semPecas = false): void
    {
        $pecas = $os->items()->where('tipo', ServiceOrderItem::TIPO_PECA)->count();

        if (! $semPecas && $pecas === 0) {
            throw ValidationException::withMessages([
                'relacao_pecas' => ['Lance as peças na OS ou marque "Este serviço não precisa de peças".'],
            ]);
        }

        $os->forceFill(['relacao_pecas_em' => now()])->save();

        // Cada peça sem saldo em estoque vira compra no fornecedor, e isso
        // define o prazo de precificação: 20 min (tudo em estoque) ou 40 min.
        $todasEmEstoque = true;

        foreach ($os->items()->where('tipo', ServiceOrderItem::TIPO_PECA)->get() as $item) {
            $emEstoque = $this->hasStock($item);

            $todasEmEstoque = $todasEmEstoque && $emEstoque;

            if (! $item->precificada_em) {
                $item->forceFill(['origem_preco' => $emEstoque ? 'estoque' : 'fornecedor'])->save();
            }
        }

        ServiceOrderEvent::record($os, 'relacao_pecas_entregue', [
            'itens' => $pecas,
            'sem_pecas' => $semPecas,
            'prazo_precificacao_min' => $todasEmEstoque ? 20 : 40,
        ]);
    }

    /**
     * A peça tem saldo livre no estoque para a quantidade da OS?
     */
    protected function hasStock(ServiceOrderItem $item): bool
    {
        if (! $item->part_id) {
            return false;
        }

        $livre = PartInventory::where('part_id', $item->part_id)
            ->selectRaw('COALESCE(SUM(in_stock - allocated), 0) as livre')
            ->value('livre');

        // A reserva desta OS já está em "allocated": saldo livre negativo
        // significa que o estoque não cobre a quantidade pedida.
        return (int) $livre >= 0;
    }

    public function registerBudgetSent(ServiceOrder $os, string $canal): void
    {
        $os->forceFill(['orcamento_enviado_em' => now()])->save();

        ServiceOrderEvent::record($os, 'orcamento_enviado', ['canal' => $canal]);
    }

    public function registerApproval(ServiceOrder $os, string $tipo, string $meio, ?string $motivoRecusa = null): void
    {
        if (! in_array($tipo, ['total', 'parcial', 'recusa'], true)) {
            throw ValidationException::withMessages(['tipo' => ['Escolha aprovação total, parcial ou recusa.']]);
        }

        if (! in_array($meio, (array) WorkshopSettings::get('meios_aprovacao', []), true)) {
            throw ValidationException::withMessages(['meio' => ['Informe por qual meio o cliente respondeu.']]);
        }

        if ($tipo === 'recusa' && ! trim((string) $motivoRecusa)) {
            throw ValidationException::withMessages(['motivo_recusa' => ['Informe o motivo da recusa.']]);
        }

        $os->forceFill([
            'aprovacao_tipo' => $tipo,
            'aprovacao_meio' => $meio,
            'aprovacao_em' => now(),
            'motivo_recusa' => $tipo === 'recusa' ? $motivoRecusa : null,
        ])->save();

        if ($tipo === 'recusa') {
            ServiceOrderEvent::record($os, 'cliente_recusou', ['meio' => $meio, 'motivo' => $motivoRecusa]);
        } else {
            ServiceOrderEvent::record($os, $tipo === 'parcial' ? 'cliente_aprovou_parcial' : 'cliente_aprovou', ['tipo' => $tipo, 'meio' => $meio]);
        }
    }

    public function registerPaymentTerms(ServiceOrder $os): void
    {
        if (trim((string) $os->condicao_pagamento)) {
            ServiceOrderEvent::record($os, 'forma_pagamento_registrada', ['forma' => $os->condicao_pagamento]);
        }
    }

    public function registerNf(ServiceOrder $os, string $numero): void
    {
        $os->forceFill(['nf_manual_numero' => $numero, 'nf_manual_em' => now()])->save();

        ServiceOrderEvent::record($os, 'nf_emitida', ['origem' => 'manual', 'numero' => $numero]);
    }

    public function confirmPayment(ServiceOrder $os, string $origem = 'manual'): void
    {
        if ($os->pagamento_confirmado_em) {
            return;
        }

        $os->forceFill(['pagamento_confirmado_em' => now()])->save();

        ServiceOrderEvent::record($os, 'pagamento_confirmado', ['origem' => $origem]);
    }

    public function signAgreement(ServiceOrder $os): void
    {
        $os->forceFill(['acordo_assinado_em' => now()])->save();

        ServiceOrderEvent::record($os, 'acordo_assinado');
    }

    protected function storeAttachment(ServiceOrder $os, string $tipo, UploadedFile $file): ServiceOrderAttachment
    {
        $path = Storage::disk('public')->putFile("service-orders/{$os->id}/{$tipo}", $file);

        return ServiceOrderAttachment::create([
            'quote_id' => $os->id,
            'tipo' => $tipo,
            'path' => $path,
            'nome_original' => $file->getClientOriginalName(),
            'user_id' => auth()->guard('user')->id(),
        ]);
    }
}
