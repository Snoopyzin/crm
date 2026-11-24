<?php

namespace Webkul\ServiceOrder\Services\Perfis;

use Illuminate\Support\Str;

/**
 * Catálogo dos perfis da mentoria (Config/perfis.php) e a soma das permissões
 * quando um usuário acumula cargos.
 */
class PerfilCatalog
{
    /**
     * @return array<string, array<string, mixed>> chave do perfil => definição
     */
    public static function todos(): array
    {
        return (array) config('perfis.perfis', []);
    }

    public static function existe(string $perfil): bool
    {
        return isset(static::todos()[$perfil]);
    }

    public static function nome(string $perfil): string
    {
        return (string) (static::todos()[$perfil]['nome'] ?? $perfil);
    }

    public static function operacional(string $perfil): bool
    {
        return (bool) (static::todos()[$perfil]['operacional'] ?? false);
    }

    public static function interno(string $perfil): bool
    {
        return (bool) (static::todos()[$perfil]['interno'] ?? false);
    }

    public static function somenteLeitura(string $perfil): bool
    {
        return (bool) (static::todos()[$perfil]['somente_leitura'] ?? false);
    }

    public static function marcador(): string
    {
        return (string) config('perfis.marcador', '[Mentoria] ');
    }

    /**
     * Perfis válidos, sem repetir, na ordem do catálogo.
     *
     * @param  array<int, string>  $perfis
     * @return list<string>
     */
    public static function ordenar(array $perfis): array
    {
        return array_values(array_filter(array_keys(static::todos()), fn ($chave) => in_array($chave, $perfis, true)));
    }

    /**
     * "Chefe de oficina + Técnico".
     *
     * @param  array<int, string>  $perfis
     */
    public static function nomeDaFuncao(array $perfis): string
    {
        return implode(' + ', array_map(fn ($perfil) => static::nome($perfil), static::ordenar($perfis)));
    }

    /**
     * Todas as chaves de acesso que existem no sistema.
     *
     * @return list<string>
     */
    public static function chavesDoSistema(): array
    {
        return array_values(array_unique(array_column((array) config('acl'), 'key')));
    }

    /**
     * Permissões da função para um conjunto de perfis: a SOMA das chaves de cada um. Algum
     * perfil de acesso total (proprietário, suporte) dá acesso total. Como nenhum perfil sem
     * valores lista 'ver_valores_financeiros', quem acumula só perfis assim (por exemplo
     * Chefe + Técnico) continua sem ver valores.
     *
     * @param  array<int, string>  $perfis
     * @return array{permission_type: string, permissions: ?list<string>}
     */
    public static function permissoes(array $perfis): array
    {
        $perfis = static::ordenar($perfis);

        foreach ($perfis as $perfil) {
            if (! empty(static::todos()[$perfil]['todos'])) {
                return ['permission_type' => 'all', 'permissions' => null];
            }
        }

        $existentes = array_flip(static::chavesDoSistema());
        $chaves = [];

        foreach ($perfis as $perfil) {
            foreach ((array) (static::todos()[$perfil]['chaves'] ?? []) as $chave) {
                if (isset($existentes[$chave])) {
                    $chaves[$chave] = true;
                }
            }
        }

        $chaves = array_keys($chaves);
        sort($chaves);

        return ['permission_type' => 'custom', 'permissions' => $chaves];
    }

    /**
     * Esse conjunto de perfis enxerga valores em reais?
     *
     * @param  array<int, string>  $perfis
     */
    public static function veValores(array $perfis): bool
    {
        $permissoes = static::permissoes($perfis);

        return $permissoes['permission_type'] === 'all' || in_array('ver_valores_financeiros', (array) $permissoes['permissions'], true);
    }

    /**
     * Nome sem acento e em minúsculas, para comparar nomes de função.
     */
    public static function normalizar(string $nome): string
    {
        return strtolower(trim((string) preg_replace('/\s*\(antiga\)\s*$/i', '', Str::ascii($nome))));
    }
}
