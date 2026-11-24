<?php

namespace Webkul\ServiceOrder\Services\Perfis;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Webkul\ServiceOrder\Models\Colaborador;
use Webkul\ServiceOrder\Models\MentoriaRegistro;
use Webkul\User\Models\Role;
use Webkul\User\Models\User;

/**
 * Perfis = cargos da mentoria (A4 do roteiro): cria as funções com as permissões certas,
 * migra os usuários das funções antigas (com prévia e registro do que mudou, sem apagar
 * nenhuma função) e mantém o perfil do usuário em sincronia com os cargos do colaborador.
 *
 * Nunca roda sozinho: tudo parte de um clique (ou de salvar o cargo do colaborador depois
 * que as funções da mentoria foram criadas).
 */
class PerfilService
{
    /**
     * Funções criadas e mantidas pelo sistema (identificadas pelo marcador na descrição).
     *
     * @return Collection<int, Role>
     */
    public function gerenciadas(): Collection
    {
        return Role::query()->where('description', 'like', PerfilCatalog::marcador().'%')->get();
    }

    /**
     * As funções da mentoria já foram criadas nesta oficina?
     */
    public function ativo(): bool
    {
        return $this->gerenciadas()->isNotEmpty();
    }

    /**
     * Perfis da função (a coluna perfil_oficina guarda a lista separada por vírgula).
     *
     * @return list<string>
     */
    public function perfisDaFuncao(?Role $role): array
    {
        return PerfilCatalog::ordenar(array_filter(array_map('trim', explode(',', (string) $role?->getAttribute('perfil_oficina')))));
    }

    public function funcaoVeValores(?Role $role): bool
    {
        return $role && ($role->permission_type === 'all' || in_array('ver_valores_financeiros', (array) $role->permissions, true));
    }

    /**
     * Cria ou atualiza as funções dos 10 perfis. Idempotente: o que já está certo fica como está.
     *
     * @return list<array{perfil: string, nome: string, role_id: int, acao: string}>
     */
    public function aplicar(): array
    {
        $feitos = [];

        DB::transaction(function () use (&$feitos) {
            foreach (array_keys(PerfilCatalog::todos()) as $perfil) {
                [$role, $acao] = $this->garantirFuncao([$perfil]);

                $feitos[] = ['perfil' => $perfil, 'nome' => PerfilCatalog::nome($perfil), 'role_id' => $role->id, 'acao' => $acao];
            }
        });

        return $feitos;
    }

    /**
     * A função do conjunto de perfis (acha ou cria a "função combinada" do acúmulo de cargos).
     *
     * @param  array<int, string>  $perfis
     */
    public function funcaoPara(array $perfis): Role
    {
        return $this->garantirFuncao($perfis)[0];
    }

    /**
     * @param  array<int, string>  $perfis
     * @return array{0: Role, 1: string} a função e o que foi feito (criada, atualizada, igual)
     */
    protected function garantirFuncao(array $perfis): array
    {
        $perfis = PerfilCatalog::ordenar($perfis);
        $csv = implode(',', $perfis);
        $nome = PerfilCatalog::nomeDaFuncao($perfis);
        $descricao = PerfilCatalog::marcador().(count($perfis) === 1
            ? (string) PerfilCatalog::todos()[$perfis[0]]['descricao']
            : 'Função combinada (acúmulo de cargos): soma as permissões dos cargos.');
        $permissoes = PerfilCatalog::permissoes($perfis);

        $existente = $this->gerenciadas()->first(fn (Role $role) => (string) $role->getAttribute('perfil_oficina') === $csv);

        if ($existente) {
            $mudou = $existente->permission_type !== $permissoes['permission_type']
                || json_encode(array_values((array) $existente->permissions)) !== json_encode((array) $permissoes['permissions'])
                || $existente->description !== $descricao;

            if ($mudou) {
                $existente->update(['description' => $descricao, 'permission_type' => $permissoes['permission_type'], 'permissions' => $permissoes['permissions']]);
                $this->registrar('perfil_funcao', 'Função "'.$existente->name.'" atualizada', ['role_id' => $existente->id, 'perfis' => $perfis, 'acao' => 'atualizada']);
            }

            return [$existente, $mudou ? 'atualizada' : 'igual'];
        }

        // Um nome igual ao de uma função antiga (ex.: "Financeiro") não pode confundir: a antiga ganha "(antiga)".
        $gerenciadas = $this->gerenciadas()->pluck('id')->all();

        foreach (Role::query()->get() as $antiga) {
            if (in_array($antiga->id, $gerenciadas, true) || preg_match('/\(antiga\)\s*$/i', $antiga->name)) {
                continue;
            }

            if (PerfilCatalog::normalizar($antiga->name) === PerfilCatalog::normalizar($nome)) {
                $antiga->update(['name' => $antiga->name.' (antiga)']);
                $this->registrar('perfil_funcao', 'Função antiga renomeada para "'.$antiga->name.'"', ['role_id' => $antiga->id, 'acao' => 'renomeada']);
            }
        }

        $role = Role::create(['name' => $nome, 'description' => $descricao, 'permission_type' => $permissoes['permission_type'], 'permissions' => $permissoes['permissions']]);

        DB::table('roles')->where('id', $role->id)->update(['perfil_oficina' => $csv]);

        $this->registrar('perfil_funcao', 'Função "'.$nome.'" criada', ['role_id' => $role->id, 'perfis' => $perfis, 'acao' => 'criada']);

        return [$role->refresh(), 'criada'];
    }

    /**
     * Prévia da migração: cada usuário que está numa função antiga, com o perfil sugerido
     * (pelo nome da função) e o que muda no acesso a valores em reais.
     *
     * @return list<array<string, mixed>>
     */
    public function plano(): array
    {
        $origem = (array) config('perfis.origem', []);
        $gerenciadas = $this->gerenciadas()->pluck('id')->all();
        $linhas = [];

        foreach (User::with('role')->orderBy('name')->get() as $usuario) {
            $role = $usuario->role;

            if (! $role || in_array($role->id, $gerenciadas, true)) {
                continue;
            }

            $sugerido = $origem[PerfilCatalog::normalizar($role->name)] ?? null;

            $linhas[] = [
                'usuario_id' => $usuario->id,
                'nome' => $usuario->name,
                'email' => $usuario->email,
                'ativo' => (bool) $usuario->status,
                'funcao_atual' => $role->name,
                'sugerido' => $sugerido,
                'valores_antes' => $this->funcaoVeValores($role),
                'valores_depois' => $sugerido ? PerfilCatalog::veValores([$sugerido]) : null,
            ];
        }

        return $linhas;
    }

    /**
     * Move os usuários escolhidos para a função do perfil. Tudo em uma transação, com registro
     * (lote) para poder desfazer. Nunca apaga função e nunca deixa a oficina sem acesso total.
     *
     * @param  array<int|string, string>  $escolhas  id do usuário => perfil ('' = manter)
     * @return array{lote: string, movidos: int, ignorados: list<string>}
     */
    public function migrar(array $escolhas, ?User $ator = null): array
    {
        $lote = (string) Str::uuid();
        $movidos = 0;
        $ignorados = [];

        DB::transaction(function () use ($escolhas, $ator, $lote, &$movidos, &$ignorados) {
            foreach ($escolhas as $usuarioId => $perfil) {
                if (! $perfil || ! PerfilCatalog::existe($perfil)) {
                    continue;
                }

                $usuario = User::with('role')->find((int) $usuarioId);

                if (! $usuario) {
                    continue;
                }

                $destino = $this->funcaoPara([$perfil]);

                if ((int) $usuario->role_id === (int) $destino->id) {
                    continue;
                }

                if ($ator && $ator->id === $usuario->id && $destino->permission_type !== 'all') {
                    $ignorados[] = $usuario->name.': você não pode reduzir o seu próprio acesso por aqui.';

                    continue;
                }

                if ($usuario->role?->permission_type === 'all' && $destino->permission_type !== 'all' && ! $this->temOutroAdministrador($usuario->id)) {
                    $ignorados[] = $usuario->name.': é o único com acesso total; a oficina não pode ficar sem um.';

                    continue;
                }

                // A comissão é da função: leva o percentual para a função nova se ela ainda não tem.
                $comissao = $usuario->role?->getAttribute('comissao_percentual');

                if ($comissao && ! (float) $destino->getAttribute('comissao_percentual')) {
                    DB::table('roles')->where('id', $destino->id)->update(['comissao_percentual' => $comissao]);
                }

                $deRole = (int) $usuario->role_id;
                $usuario->forceFill(['role_id' => $destino->id])->save();
                $movidos++;

                $this->registrar('perfil_usuario', $usuario->name.' → '.$destino->name, [
                    'lote' => $lote, 'user_id' => $usuario->id, 'de_role' => $deRole, 'para_role' => $destino->id, 'perfil' => $perfil, 'acao' => 'movido',
                ]);
            }
        });

        return ['lote' => $lote, 'movidos' => $movidos, 'ignorados' => $ignorados];
    }

    /**
     * Último lote de migração que ainda pode ser desfeito.
     */
    public function ultimoLote(): ?string
    {
        $registros = MentoriaRegistro::where('tipo', 'perfil_usuario')->latest('id')->limit(200)->get();
        $desfeitos = $registros->filter(fn ($r) => ($r->dados_json['acao'] ?? null) === 'desfeito')->pluck('dados_json.lote')->all();

        foreach ($registros as $registro) {
            $lote = $registro->dados_json['lote'] ?? null;

            if ($lote && ($registro->dados_json['acao'] ?? null) === 'movido' && ! in_array($lote, $desfeitos, true)) {
                return $lote;
            }
        }

        return null;
    }

    /**
     * Devolve os usuários do lote à função em que estavam (só quem ainda está na função nova).
     */
    public function desfazer(string $lote): int
    {
        $desfeitos = 0;

        DB::transaction(function () use ($lote, &$desfeitos) {
            $movidos = MentoriaRegistro::where('tipo', 'perfil_usuario')->get()
                ->filter(fn ($r) => ($r->dados_json['lote'] ?? null) === $lote && ($r->dados_json['acao'] ?? null) === 'movido');

            foreach ($movidos as $registro) {
                $dados = $registro->dados_json;
                $usuario = User::find($dados['user_id']);

                if (! $usuario || (int) $usuario->role_id !== (int) $dados['para_role'] || ! Role::whereKey($dados['de_role'])->exists()) {
                    continue;
                }

                $usuario->forceFill(['role_id' => $dados['de_role']])->save();
                $desfeitos++;

                $this->registrar('perfil_usuario', $usuario->name.' devolvido à função anterior', array_merge($dados, ['acao' => 'desfeito']));
            }
        });

        return $desfeitos;
    }

    /**
     * Cargos marcados no colaborador => função do usuário ligado a ele. Só age depois que as
     * funções da mentoria foram criadas (antes disso o comportamento é o de sempre) e com
     * travas: ninguém altera o próprio perfil, o único acesso total não sai, Mentor e Suporte
     * não são tocados e o Gerente só mexe em cargos operacionais.
     *
     * @return ?string mensagem para a tela (null = nada a dizer)
     */
    public function sincronizarColaborador(Colaborador $colaborador, ?User $ator = null): ?string
    {
        if (! $colaborador->user_id || ! $this->ativo()) {
            return null;
        }

        $usuario = User::with('role')->find($colaborador->user_id);

        if (! $usuario) {
            return null;
        }

        $atuais = $this->perfisDaFuncao($usuario->role);

        if (array_filter($atuais, fn ($perfil) => PerfilCatalog::interno($perfil))) {
            return null;
        }

        $cargos = Colaborador::ativos()->where('user_id', $usuario->id)->get()
            ->flatMap(fn (Colaborador $c) => $c->cargosLista())->all();
        $esperados = PerfilCatalog::ordenar(array_filter($cargos, fn ($cargo) => ! PerfilCatalog::interno($cargo)));

        if ($esperados === [] || $esperados === $atuais) {
            return null;
        }

        if ($ator && $ator->id === $usuario->id) {
            return 'Cargos salvos. O seu próprio perfil de acesso não é alterado por aqui.';
        }

        $atorTodos = $ator && $ator->role?->permission_type === 'all';
        $atorGerente = $ator && in_array('gerente', $this->perfisDaFuncao($ator->role), true);
        $soOperacional = ! array_filter($esperados, fn ($p) => ! PerfilCatalog::operacional($p)) && ! array_filter($atuais, fn ($p) => ! PerfilCatalog::operacional($p));

        if (! $atorTodos && ! ($atorGerente && $soOperacional)) {
            return 'Cargos salvos. Só o Proprietário muda o perfil de acesso de Gerente e Proprietário.';
        }

        if ($usuario->role?->permission_type === 'all' && ! $this->temOutroAdministrador($usuario->id)
            && PerfilCatalog::permissoes($esperados)['permission_type'] !== 'all') {
            return 'Cargos salvos. '.$usuario->name.' é o único com acesso total e continua com ele.';
        }

        $destino = $this->funcaoPara($esperados);
        $deRole = (int) $usuario->role_id;

        $usuario->forceFill(['role_id' => $destino->id])->save();

        $this->registrar('perfil_usuario', $usuario->name.' → '.$destino->name, [
            'user_id' => $usuario->id, 'de_role' => $deRole, 'para_role' => $destino->id, 'perfil' => implode(',', $esperados), 'acao' => 'sincronizado', 'origem' => 'colaborador',
        ]);

        return 'O perfil de acesso de '.$usuario->name.' agora é "'.$destino->name.'".';
    }

    protected function temOutroAdministrador(int $exceto): bool
    {
        return User::where('id', '!=', $exceto)->where('status', 1)
            ->whereHas('role', fn ($consulta) => $consulta->where('permission_type', 'all'))->exists();
    }

    /**
     * @param  array<string, mixed>  $dados
     */
    protected function registrar(string $tipo, string $observacao, array $dados): void
    {
        MentoriaRegistro::record($tipo, ['observacao' => $observacao, 'dados_json' => $dados]);
    }
}
