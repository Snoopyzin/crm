<?php

namespace Webkul\ServiceOrder\Services\Perfis;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Webkul\Mechanic\Models\Mechanic;
use Webkul\User\Models\User;

/**
 * Logins de demonstração (um por perfil) para mostrar o sistema ao patrão: cada um usa a função
 * oficial da mentoria do seu perfil (a mesma da tela "Perfis da mentoria"), então o acesso que ele
 * vê é exatamente o que a oficina real teria. As senhas são sorteadas a cada execução e só
 * aparecem para quem rodou; rodar de novo troca as senhas, não duplica os usuários.
 */
class LoginsDemonstracao
{
    /**
     * @var array<string, string> perfil => nome mostrado
     */
    public const PERFIS = [
        'gerente' => 'Gerente',
        'consultor' => 'Consultor',
        'chefe' => 'Chefe de oficina',
        'tecnico' => 'Técnico',
    ];

    public function __construct(protected PerfilService $perfis) {}

    /**
     * @return list<array{perfil: string, nome: string, email: string, senha: string, funcao: string, acao: string}>
     */
    public function criar(string $dominio = 'demo.local'): array
    {
        $resultado = [];

        DB::transaction(function () use (&$resultado, $dominio) {
            foreach (self::PERFIS as $perfil => $rotulo) {
                $role = $this->perfis->funcaoPara([$perfil]);
                $email = $perfil.'@'.$dominio;
                $senha = Str::password(12, symbols: false);

                $dados = [
                    'name' => $rotulo.' (demonstração)',
                    'password' => bcrypt($senha),
                    'status' => 1,
                    'role_id' => $role->id,
                    'view_permission' => 'global',
                ];

                // O técnico precisa de um mecânico ligado para ver "Minhas tarefas".
                if ($perfil === 'tecnico') {
                    $dados['mechanic_id'] = $this->mecanico()->id;
                }

                $usuario = User::query()->where('email', $email)->first();

                if ($usuario) {
                    $usuario->forceFill($dados)->save();
                } else {
                    $usuario = User::create($dados + ['email' => $email]);
                }

                $resultado[] = [
                    'perfil' => $perfil,
                    'nome' => $dados['name'],
                    'email' => $email,
                    'senha' => $senha,
                    'funcao' => $role->name,
                    'acao' => $usuario->wasRecentlyCreated ? 'criado' : 'senha trocada',
                ];
            }
        });

        return $resultado;
    }

    /**
     * O que seria criado, sem gravar nada.
     *
     * @return list<array{perfil: string, email: string, existe: bool}>
     */
    public function simular(string $dominio = 'demo.local'): array
    {
        return collect(self::PERFIS)->map(fn (string $rotulo, string $perfil) => [
            'perfil' => $perfil,
            'email' => $perfil.'@'.$dominio,
            'existe' => User::query()->where('email', $perfil.'@'.$dominio)->exists(),
        ])->values()->all();
    }

    protected function mecanico(): Mechanic
    {
        return Mechanic::query()->firstOrCreate(
            ['nome' => 'Técnico (demonstração)'],
            ['especialidade' => 'Diesel', 'status' => 'Ativo']
        );
    }
}
