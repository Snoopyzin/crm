<?php

namespace Webkul\ServiceOrder\Services;

use Illuminate\Support\Collection;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Models\ServiceOrderItem;
use Webkul\User\Models\User;

/**
 * Descobre quem são os usuários de cada destino de uma barreira
 * (chefe, consultor, gerente, proprietario...), a partir do papel da oficina
 * configurado em cada função (roles.perfil_oficina).
 */
class RecipientResolver
{
    /**
     * @param  string[]  $destinos
     * @return Collection<int, User>
     */
    public function resolve(array $destinos, ?ServiceOrder $serviceOrder = null, ?ServiceOrderItem $item = null): Collection
    {
        $users = collect();

        foreach ($destinos as $destino) {
            $users = $users->merge(match ($destino) {
                'tecnico_da_os' => $this->tecnicoDaOs($serviceOrder, $item),
                'consultor' => $this->consultor($serviceOrder),
                default => $this->byPerfil($destino),
            });
        }

        return $users->unique('id')->values();
    }

    /**
     * Todos os usuários ativos com o papel informado. Uma função pode
     * acumular papéis ("chefe,tecnico"): quem acumula recebe os alertas de cada um.
     */
    public function byPerfil(string $perfil): Collection
    {
        return User::where('status', 1)
            ->whereHas('role', fn ($query) => $query->whereRaw('FIND_IN_SET(?, perfil_oficina)', [$perfil]))
            ->get();
    }

    protected function tecnicoDaOs(?ServiceOrder $serviceOrder, ?ServiceOrderItem $item): Collection
    {
        $mechanicId = $item?->funcionario_id ?: $serviceOrder?->tecnicoDesignadoId();

        if (! $mechanicId) {
            return collect();
        }

        return User::where('status', 1)->where('mechanic_id', $mechanicId)->get();
    }

    /**
     * O consultor da OS é quem a abriu, se tiver o papel de consultor; se
     * não, todos os consultores recebem.
     */
    protected function consultor(?ServiceOrder $serviceOrder): Collection
    {
        $consultores = $this->byPerfil('consultor');

        $dono = $serviceOrder?->user_id ? $consultores->firstWhere('id', $serviceOrder->user_id) : null;

        return $dono ? collect([$dono]) : $consultores;
    }
}
