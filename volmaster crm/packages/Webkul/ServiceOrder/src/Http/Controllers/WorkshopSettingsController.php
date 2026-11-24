<?php

namespace Webkul\ServiceOrder\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\ServiceOrder\Models\BarrierRule;
use Webkul\ServiceOrder\Models\DiagnosticoQueixaTipo;
use Webkul\ServiceOrder\Models\SmsLog;
use Webkul\ServiceOrder\Services\PilotoRequisitos;
use Webkul\ServiceOrder\Services\Sms\SmsManager;
use Webkul\ServiceOrder\Services\WebPush;
use Webkul\ServiceOrder\Services\WorkshopSettings;
use Webkul\User\Models\User;

/**
 * Configuração do piloto: chave geral, horário comercial, silêncio, prazos
 * e destinos das barreiras, papéis das funções, tipos de queixa, push e SMS.
 * Tudo vem de tabelas de configuração -- nada fica fixo no código.
 */
class WorkshopSettingsController extends Controller
{
    public const PAPEIS = [
        'proprietario' => 'Proprietário',
        'gerente' => 'Gerente',
        'chefe' => 'Chefe de oficina',
        'consultor' => 'Consultor técnico',
        'tecnico' => 'Técnico',
        'pecas' => 'Peças',
        'financeiro' => 'Financeiro',
        'zeladoria' => 'Auxiliar de copa e zeladoria',
    ];

    public const DIAS = [1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta', 5 => 'Sexta', 6 => 'Sábado', 7 => 'Domingo'];

    public function edit(): View
    {
        BarrierRule::query()->exists() || BarrierRule::ensureDefaults();
        DiagnosticoQueixaTipo::ensureDefaults();

        return view('service_order::settings.workshop', [
            'settings' => WorkshopSettings::all(),
            'rules' => BarrierRule::orderBy('critico')->orderBy('id')->get(),
            'roles' => DB::table('roles')->orderBy('name')->get(['id', 'name', 'perfil_oficina']),
            'users' => User::with('role')->orderBy('name')->get(),
            'queixas' => DiagnosticoQueixaTipo::orderBy('nome')->get(),
            'papeis' => self::PAPEIS,
            'dias' => self::DIAS,
            'destinos' => BarrierRule::DESTINOS,
            'smsMes' => app(SmsManager::class)->monthlyCount(),
            'pilotoFaltando' => PilotoRequisitos::faltando(),
        ]);
    }

    public function updateGeneral(): RedirectResponse
    {
        $this->validate(request(), [
            'horario' => 'nullable|array',
            'horario.*.inicio' => 'nullable|date_format:H:i',
            'horario.*.fim' => 'nullable|date_format:H:i',
            'silencio_inicio' => 'required|date_format:H:i',
            'silencio_fim' => 'required|date_format:H:i',
            'agrupar_alertas_minutos' => 'required|integer|min:1|max:60',
            'sms_provedor' => 'required|in:log,twilio',
            'sms_limite_mensal' => 'required|integer|min:0|max:100000',
            'sms_custo_unitario' => 'required|numeric|min:0|max:10',
            'sms_nao_aberto_minutos' => 'required|integer|min:1|max:120',
            'tecnico_margem_percentual' => 'required|integer|min:100|max:300',
            'tecnico_meta_eficiencia' => 'required|integer|min:1|max:200',
            'push_vapid_subject' => 'nullable|string|max:255',
            'sms_twilio_sid' => 'nullable|string|max:80',
            'sms_twilio_from' => 'nullable|string|max:30',
            'sms_twilio_token' => 'nullable|string|max:120',
        ]);

        // Horário comercial: só entram os dias marcados como abertos.
        $horario = [];

        foreach (self::DIAS as $dia => $nome) {
            $linha = request("horario.{$dia}", []);

            if (! empty($linha['aberto']) && ! empty($linha['inicio']) && ! empty($linha['fim']) && $linha['fim'] > $linha['inicio']) {
                $horario[(string) $dia] = [$linha['inicio'], $linha['fim']];
            }
        }

        // O contato das notificações precisa ser um e-mail (ou https): a Apple recusa o envio se vier
        // texto solto. Um e-mail sem "mailto:" ganha o prefixo.
        $contato = trim((string) request('push_vapid_subject', ''));

        if ($contato !== '' && ! WebPush::normalizarAssunto($contato)) {
            throw ValidationException::withMessages([
                'push_vapid_subject' => ['Informe um e-mail real (ex.: oficina@seudominio.com.br) ou um endereço https. E-mails de exemplo não valem.'],
            ]);
        }

        request()->merge(['push_vapid_subject' => $contato === '' ? '' : WebPush::normalizarAssunto($contato)]);

        // Ligar o piloto exige a Ficha e a equipe mínimas (A5): sem elas os bloqueios parariam a oficina
        // em vez de organizá-la. A trava só vale na passagem de desligado para ligado: um piloto que já
        // está ligado nunca é desligado por isso.
        $piloto = request()->boolean('piloto_ativo');
        $faltando = [];

        if ($piloto && ! WorkshopSettings::get('piloto_ativo')) {
            $faltando = PilotoRequisitos::faltando();

            if ($faltando !== []) {
                $piloto = false;
            }
        }

        WorkshopSettings::set('piloto_ativo', $piloto);
        WorkshopSettings::set('modo_teste_barreiras', request()->boolean('modo_teste_barreiras'));
        WorkshopSettings::set('modo_teste_tempo_padrao', request()->boolean('modo_teste_tempo_padrao'));
        WorkshopSettings::set('horario_comercial', $horario);
        WorkshopSettings::set('silencio_ativo', request()->boolean('silencio_ativo'));
        WorkshopSettings::set('dono_resumo_barreiras', request()->boolean('dono_resumo_barreiras'));

        foreach ([
            'silencio_inicio', 'silencio_fim', 'sms_provedor', 'push_vapid_subject', 'sms_twilio_sid', 'sms_twilio_from',
        ] as $key) {
            WorkshopSettings::set($key, (string) request($key, ''));
        }

        foreach ([
            'agrupar_alertas_minutos', 'sms_limite_mensal', 'sms_nao_aberto_minutos', 'tecnico_margem_percentual', 'tecnico_meta_eficiencia',
        ] as $key) {
            WorkshopSettings::set($key, (int) request($key));
        }

        WorkshopSettings::set('sms_custo_unitario', (float) request('sms_custo_unitario'));

        // O token só é regravado quando um novo é digitado.
        if (filled(request('sms_twilio_token'))) {
            WorkshopSettings::set('sms_twilio_token', request('sms_twilio_token'));
        }

        session()->flash('success', 'Configurações salvas.');

        if ($faltando !== []) {
            session()->flash('warning', 'O piloto NÃO foi ligado porque falta: '.implode('; ', array_column($faltando, 'texto')).'.');
        }

        return back();
    }

    public function updateRule(int $ruleId): RedirectResponse
    {
        $rule = BarrierRule::findOrFail($ruleId);

        $this->validate(request(), [
            'prazo_minutos' => 'nullable|integer|min:0|max:100000',
            'nivel1_percentual' => 'nullable|integer|min:1|max:1000',
            'nivel2_percentual' => 'nullable|integer|min:1|max:1000',
            'nivel2_apos_minutos' => 'nullable|integer|min:0|max:100000',
            'nivel3_apos_minutos' => 'nullable|integer|min:0|max:100000',
            'destinos1' => 'nullable|array',
            'destinos2' => 'nullable|array',
            'destinos3' => 'nullable|array',
            'destinos1.*' => 'in:'.implode(',', array_keys(BarrierRule::DESTINOS)),
            'destinos2.*' => 'in:'.implode(',', array_keys(BarrierRule::DESTINOS)),
            'destinos3.*' => 'in:'.implode(',', array_keys(BarrierRule::DESTINOS)),
        ]);

        $rule->fill([
            'prazo_minutos' => request('prazo_minutos') !== null && request('prazo_minutos') !== '' ? (int) request('prazo_minutos') : $rule->prazo_minutos,
            'nivel1_percentual' => request('nivel1_percentual') ?: $rule->nivel1_percentual,
            'nivel2_percentual' => request('nivel2_percentual') ?: $rule->nivel2_percentual,
            'nivel1_destino' => implode(',', request('destinos1', [])) ?: $rule->nivel1_destino,
            'nivel2_destino' => implode(',', request('destinos2', [])) ?: null,
            'nivel3_destino' => implode(',', request('destinos3', [])) ?: null,
            'nivel2_apos_minutos' => request('nivel2_apos_minutos') !== null && request('nivel2_apos_minutos') !== '' ? (int) request('nivel2_apos_minutos') : null,
            'nivel3_apos_minutos' => request('nivel3_apos_minutos') !== null && request('nivel3_apos_minutos') !== '' ? (int) request('nivel3_apos_minutos') : null,
            'ativo' => request()->boolean('ativo'),
        ])->save();

        session()->flash('success', "Barreira \"{$rule->nome}\" atualizada. Vale para as próximas OS.");

        return back();
    }

    public function restoreRules(): RedirectResponse
    {
        BarrierRule::ensureDefaults();

        session()->flash('success', 'Barreiras padrão da mentoria restauradas (as que faltavam).');

        return back();
    }

    /**
     * Papel da oficina de cada função (role) -- é daqui que as barreiras
     * sabem quem é o chefe, o gerente, o proprietário etc.
     */
    public function updateRoles(): RedirectResponse
    {
        $this->validate(request(), ['papel' => 'nullable|array', 'papel.*' => 'nullable']);

        // Uma função pode acumular papéis (chefe e técnico...); guardados na ordem fixa da lista.
        foreach ((array) request('papel', []) as $roleId => $papeis) {
            $escolhidos = array_values(array_intersect(array_keys(self::PAPEIS), array_filter((array) $papeis, 'is_string')));

            DB::table('roles')->where('id', (int) $roleId)->update(['perfil_oficina' => $escolhidos ? implode(',', $escolhidos) : null]);
        }

        // Celular e aceite de SMS de cada colaborador.
        foreach ((array) request('celular', []) as $userId => $celular) {
            $user = User::find((int) $userId);

            if (! $user) {
                continue;
            }

            $celular = preg_replace('/[^\d+() -]/', '', (string) $celular);
            $aceita = (bool) request("sms_aceite.{$userId}") && $celular !== '';

            $user->forceFill([
                'celular' => $celular ?: null,
                'sms_aceite_em' => $aceita ? ($user->sms_aceite_em ?? now()) : null,
            ])->save();
        }

        session()->flash('success', 'Papéis e celulares salvos.');

        return back();
    }

    public function updateComplaintTypes(): RedirectResponse
    {
        $this->validate(request(), [
            'queixa' => 'nullable|array',
            'queixa.*.nome' => 'required|string|max:255',
            'queixa.*.tempo_alvo_min' => 'required|integer|min:1|max:1440',
            'nova_nome' => 'nullable|string|max:255',
            'nova_tempo' => 'nullable|integer|min:1|max:1440',
        ]);

        foreach ((array) request('queixa', []) as $id => $dados) {
            DiagnosticoQueixaTipo::where('id', (int) $id)->update([
                'nome' => $dados['nome'],
                'tempo_alvo_min' => (int) $dados['tempo_alvo_min'],
                'ativo' => ! empty($dados['ativo']),
            ]);
        }

        if (filled(request('nova_nome')) && filled(request('nova_tempo'))) {
            DiagnosticoQueixaTipo::create(['nome' => request('nova_nome'), 'tempo_alvo_min' => (int) request('nova_tempo')]);
        }

        session()->flash('success', 'Tipos de queixa salvos.');

        return back();
    }

    /**
     * Gera o par de chaves VAPID (identifica o servidor nas notificações).
     * Trocar as chaves invalida os aparelhos já inscritos.
     */
    public function generateVapid(): RedirectResponse
    {
        try {
            $keys = WebPush::generateVapidKeys();
        } catch (\Throwable $exception) {
            session()->flash('error', 'Não foi possível gerar as chaves: '.$exception->getMessage());

            return back();
        }

        WorkshopSettings::set('push_vapid_public', $keys['public']);
        WorkshopSettings::set('push_vapid_private', $keys['private']);

        DB::table('push_subscriptions')->delete();

        session()->flash('success', 'Chaves de notificação geradas. Cada pessoa precisa ativar as notificações de novo no celular.');

        return back();
    }

    public function smsReport(): View
    {
        $logs = SmsLog::latest('id')->paginate(50);

        return view('service_order::settings.sms-report', [
            'logs' => $logs,
            'mes' => app(SmsManager::class)->monthlyCount(),
            'limite' => (int) WorkshopSettings::get('sms_limite_mensal', 0),
            'simulado' => WorkshopSettings::get('sms_provedor', 'log') !== 'twilio',
            'custoMes' => (float) SmsLog::where('status', 'enviado')->where('created_at', '>=', now()->startOfMonth())->sum('custo'),
        ]);
    }
}
