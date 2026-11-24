<?php

namespace Webkul\ServiceOrder\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Services\Rotina\RotinaService;

/**
 * Telas de rotina do dia a dia: quadro de pátio, painel de cada cargo
 * (chefe, peças, financeiro, consultor, gerente, zeladoria), diária de pátio
 * e agenda (etapa 00 do fluxo).
 */
class RotinaController extends Controller
{
    public function __construct(protected RotinaService $rotina) {}

    public function patio(): View
    {
        return view('service_order::rotina.patio', ['etapas' => $this->rotina->quadroDePatio()]);
    }

    public function painel(): View
    {
        $user = auth()->guard('user')->user();

        return view('service_order::rotina.painel', [
            'secoes' => $this->rotina->painelDoUsuario($user),
            'perfis' => array_filter(array_map('trim', explode(',', (string) $user->role?->perfil_oficina))),
        ]);
    }

    public function checklist(Request $request): RedirectResponse
    {
        try {
            $this->rotina->registrarChecklist(auth()->guard('user')->user(), (string) $request->input('tipo'));
            session()->flash('success', 'Registrado.');
        } catch (ValidationException $exception) {
            session()->flash('error', collect($exception->errors())->flatten()->first());
        }

        return redirect()->route('admin.rotina.painel');
    }

    public function diaria(int $id): RedirectResponse
    {
        try {
            $this->rotina->lancarDiaria(ServiceOrder::findOrFail($id));
            session()->flash('success', 'Diária de pátio lançada na OS.');
        } catch (ValidationException $exception) {
            session()->flash('error', collect($exception->errors())->flatten()->first());
        }

        return redirect()->route('admin.service_orders.view', $id);
    }

    public function agenda(Request $request): View
    {
        try {
            $dia = Carbon::parse($request->query('dia', today()->toDateString()))->startOfDay();
        } catch (\Throwable) {
            $dia = today();
        }

        $agendamentos = DB::table('agendamentos')->whereDate('agendado_para', $dia->toDateString())->orderBy('agendado_para')->get();
        $capacidade = $this->rotina->capacidadeDoDia();
        $ocupadas = $agendamentos->whereIn('status', ['agendado', 'chegou'])->count();

        return view('service_order::rotina.agenda', [
            'dia' => $dia,
            'agendamentos' => $agendamentos,
            'capacidade' => $capacidade,
            'ocupacao' => round($ocupadas / max(1, $capacidade) * 100, 1),
        ]);
    }

    public function agendar(Request $request): RedirectResponse
    {
        $dados = $this->validate($request, [
            'agendado_para' => 'required|date|after_or_equal:today',
            'cliente' => 'required|string|max:120',
            'telefone' => 'nullable|string|max:30',
            'placa' => 'nullable|string|max:12',
            'queixa' => 'nullable|string|max:500',
            'encaixe' => 'nullable|in:0,1',
        ]);

        try {
            $resultado = $this->rotina->agendar($dados, auth()->guard('user')->user());

            session()->flash('success', 'Atendimento agendado.');

            if ($resultado['aviso']) {
                session()->flash('warning', $resultado['aviso']);
            }
        } catch (ValidationException $exception) {
            session()->flash('error', collect($exception->errors())->flatten()->first());
        }

        return redirect()->route('admin.rotina.agenda', ['dia' => Carbon::parse($dados['agendado_para'])->toDateString()]);
    }

    public function statusAgendamento(Request $request, int $id): RedirectResponse
    {
        $dados = $this->validate($request, ['status' => 'required|in:chegou,faltou,cancelado']);

        $agendamento = DB::table('agendamentos')->where('id', $id)->first();
        abort_unless($agendamento, 404);

        DB::table('agendamentos')->where('id', $id)->update(['status' => $dados['status'], 'updated_at' => now()]);

        // Chegou: segue para o cadastro do atendimento.
        if ($dados['status'] === 'chegou') {
            return redirect()->route('admin.service_orders.cadastro.create', array_filter(['placa' => $agendamento->placa, 'name' => $agendamento->cliente]));
        }

        return redirect()->route('admin.rotina.agenda', ['dia' => Carbon::parse($agendamento->agendado_para)->toDateString()]);
    }
}
