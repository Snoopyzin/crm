<?php

namespace Webkul\ServiceOrder\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\ServiceOrder\Models\AlcadaPedido;
use Webkul\ServiceOrder\Models\AlcadaRegra;
use Webkul\ServiceOrder\Services\Alcada\AlcadaCatalog;
use Webkul\ServiceOrder\Services\Alcada\AlcadaService;
use Webkul\ServiceOrder\Services\Indicators\IndicatorFormatter;
use Webkul\ServiceOrder\Support\BrNumber;

/**
 * Fila de alçadas (aprovar, recusar, encaminhar), pedidos manuais de decisão
 * e a matriz de alçadas da oficina.
 */
class AlcadaController extends Controller
{
    public function __construct(protected AlcadaService $alcadas) {}

    public function fila(): View
    {
        $user = auth()->guard('user')->user();

        return view('service_order::alcadas.fila', [
            'nivel' => $this->alcadas->levelOf($user),
            'pedidos' => $this->alcadas->queueFor($user),
            'temas' => AlcadaCatalog::temas(),
            'proprios' => AlcadaPedido::where('solicitante_id', $user->id)->orderByDesc('id')->limit(15)->get(),
            'recentes' => AlcadaPedido::whereIn('status', [AlcadaPedido::APROVADO, AlcadaPedido::RECUSADO, AlcadaPedido::CONSUMIDO])->orderByDesc('decidido_em')->limit(15)->get(),
        ]);
    }

    /**
     * Fila em JSON (celular do dono): só o que foi encaminhado a ele.
     */
    public function dados(): JsonResponse
    {
        $user = auth()->guard('user')->user();
        $temas = AlcadaCatalog::temas();

        $pedidos = $this->alcadas->queueFor($user)->map(fn (AlcadaPedido $pedido) => [
            'id' => $pedido->id,
            'tema' => $pedido->tema_nome,
            'descricao' => $pedido->descricao,
            'os' => $pedido->service_order_id,
            'solicitante' => $pedido->solicitante?->name,
            'valor_fmt' => $this->valorFmt($pedido, $temas),
            'status' => $pedido->status,
            'nivel_necessario' => $pedido->nivel_necessario,
            'prazo_em' => $pedido->prazo_dono_em?->format('d/m H:i'),
            'atrasado' => $pedido->prazo_dono_em && $pedido->prazo_dono_em->isPast(),
        ])->values();

        return response()->json(['pedidos' => $pedidos, 'nivel' => $this->alcadas->levelOf($user)]);
    }

    public function solicitar(Request $request): RedirectResponse
    {
        $dados = $this->validate($request, [
            'tema' => 'required|in:'.implode(',', array_keys(AlcadaCatalog::temas())),
            'descricao' => 'required|string|max:500',
            'valor' => 'nullable|numeric|min:0',
            'service_order_id' => 'nullable|integer',
        ]);

        $this->alcadas->request($dados['tema'], $dados['descricao'], isset($dados['valor']) ? (float) $dados['valor'] : null, null);

        session()->flash('success', 'Pedido enviado.');

        return redirect()->route('admin.mentoria.alcadas');
    }

    /**
     * @return RedirectResponse|JsonResponse
     */
    public function decidir(Request $request, int $id)
    {
        $pedido = AlcadaPedido::findOrFail($id);
        $user = auth()->guard('user')->user();

        $dados = $this->validate($request, [
            'decisao' => 'required|in:aprovar,recusar,encaminhar',
            'comentario' => 'nullable|string|max:500',
        ]);

        try {
            match ($dados['decisao']) {
                'aprovar' => $this->alcadas->decide($pedido, $user, true, $dados['comentario'] ?? null),
                'recusar' => $this->alcadas->decide($pedido, $user, false, $dados['comentario'] ?? null),
                'encaminhar' => $this->alcadas->forward($pedido, $user, $dados['comentario'] ?? null),
            };
        } catch (ValidationException $exception) {
            $mensagem = collect($exception->errors())->flatten()->first();

            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'mensagem' => $mensagem], 422);
            }

            session()->flash('error', $mensagem);

            return redirect()->route('admin.mentoria.alcadas');
        }

        if ($request->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        session()->flash('success', 'Decisão registrada.');

        return redirect()->route('admin.mentoria.alcadas');
    }

    public function matriz(): View
    {
        AlcadaRegra::ensureDefaultsOnce();

        return view('service_order::alcadas.matriz', [
            'temas' => AlcadaCatalog::temas(),
            'regras' => AlcadaRegra::all()->keyBy('tema'),
        ]);
    }

    public function updateMatriz(Request $request): RedirectResponse
    {
        $this->validate($request, [
            'sozinho' => 'nullable|array',
            'sozinho.*' => 'nullable|string|max:20',
            'gerente' => 'nullable|array',
            'gerente.*' => 'nullable|string|max:20',
        ]);

        AlcadaRegra::ensureDefaultsOnce();

        foreach (array_keys(AlcadaCatalog::temas()) as $tema) {
            AlcadaRegra::whereKey($tema)->update([
                'limite_sozinho' => $this->numero($request->input("sozinho.{$tema}")),
                'limite_gerente' => $this->numero($request->input("gerente.{$tema}")),
                'ativo' => array_key_exists($tema, (array) $request->input('ativo', [])),
            ]);
        }

        session()->flash('success', 'Matriz de alçadas salva.');

        return redirect()->route('admin.mentoria.alcadas.matriz');
    }

    /**
     * "10.000,50", "R$ 500" ou "12,5" -> número; vazio -> null (limite em branco).
     */
    protected function numero(?string $bruto): ?float
    {
        return BrNumber::parse($bruto);
    }

    protected function valorFmt(AlcadaPedido $pedido, array $temas): ?string
    {
        if ($pedido->valor === null) {
            return null;
        }

        return match ($temas[$pedido->tema]['unidade'] ?? 'none') {
            'percent' => IndicatorFormatter::value($pedido->valor, 'percent'),
            'brl' => IndicatorFormatter::value($pedido->valor, 'brl'),
            default => null,
        };
    }
}
