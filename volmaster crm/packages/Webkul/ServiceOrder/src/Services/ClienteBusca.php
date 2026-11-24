<?php

namespace Webkul\ServiceOrder\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Webkul\ServiceOrder\Services\Credito\CreditoCliente;
use Webkul\ServiceOrder\Support\Documento;

/**
 * Pesquisa de cliente por nome, código, CPF/CNPJ, telefone ou placa (aba "Buscar cliente" e campo de
 * pesquisa da abertura da OS). Só lê. Cada resultado leva a situação do cadastro (validade da análise
 * de crédito) e, só para quem pode ver crédito, a cor.
 */
class ClienteBusca
{
    public const LIMITE = 30;

    /**
     * O que foi digitado já dá para pesquisar: 2 letras ou mais, ou um número (os códigos de cliente
     * começam em 1, então "7" é uma busca válida).
     */
    public static function termoValido(string $termo): bool
    {
        $termo = trim($termo);

        return mb_strlen($termo) >= 2 || ($termo !== '' && ctype_digit($termo));
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function buscar(string $termo): Collection
    {
        $termo = trim($termo);
        $digitos = preg_replace('/\D/', '', $termo) ?? '';
        $placa = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $termo) ?? '');
        $ids = collect();

        $ids = $ids->merge(DB::table('persons')->where('name', 'like', '%'.$termo.'%')->limit(self::LIMITE)->pluck('id'));

        // Código do cliente ("12", "#12" ou "Nº 12"): número inteiro e curto.
        if ($digitos !== '' && strlen($digitos) <= 9 && preg_match('/^(?:n[º°o]?\s*|#\s*)?\d+$/iu', $termo) && Schema::hasColumn('persons', 'codigo')) {
            $ids = $ids->merge(DB::table('persons')->where('codigo', (int) $digitos)->pluck('id'));
        }

        if (strlen($placa) >= 3) {
            $ids = $ids->merge(
                DB::table('vehicles')->whereRaw("REPLACE(UPPER(placa), '-', '') like ?", ['%'.$placa.'%'])->limit(self::LIMITE)->pluck('person_id')
            );
        }

        $atributo = null;

        if (strlen($digitos) >= 4) {
            $atributo = $this->atributoDocumento();

            if ($atributo) {
                $ids = $ids->merge(
                    DB::table('attribute_values')
                        ->where('attribute_id', $atributo)->where('entity_type', 'persons')->whereNotNull('text_value')
                        ->get(['entity_id', 'text_value'])
                        ->filter(fn ($linha) => str_contains(preg_replace('/\D/', '', (string) $linha->text_value), $digitos))
                        ->pluck('entity_id')
                );
            }

            $ids = $ids->merge(
                DB::table('persons')->whereNotNull('contact_numbers')->get(['id', 'contact_numbers'])
                    ->filter(fn ($pessoa) => collect((array) json_decode((string) $pessoa->contact_numbers, true))
                        ->contains(fn ($numero) => str_contains(preg_replace('/\D/', '', (string) ($numero['value'] ?? '')), $digitos)))
                    ->pluck('id')
            );
        }

        return $this->montar($ids->filter()->unique()->take(self::LIMITE)->values());
    }

    /**
     * Um cliente pelo id (para chegar na OS já com o cliente escolhido).
     *
     * @return array<string, mixed>|null
     */
    public function porId(int $id): ?array
    {
        return $this->montar(collect([$id]))->first();
    }

    /**
     * Para onde vai o "Cadastrar cliente": o que foi digitado entra no campo certo do Cadastro.
     *
     * @return array<string, string>
     */
    public function paraOCadastro(string $termo): array
    {
        $termo = trim($termo);

        if ($termo === '') {
            return [];
        }

        $digitos = preg_replace('/\D/', '', $termo) ?? '';
        $soNumeros = ! preg_match('/[A-Za-z]/', $termo);

        // 11 dígitos tanto podem ser um CPF quanto um celular com DDD: o que fecha os dígitos verificadores é CPF.
        if ($soNumeros && Documento::valido($termo)) {
            return ['cpf_cnpj' => $termo];
        }

        if ($soNumeros && strlen($digitos) >= 10) {
            return ['whatsapp' => $termo];
        }

        if (preg_match('/^[A-Za-z]{3}-?\d[A-Za-z0-9]\d{2}$/', $termo)) {
            return ['placa' => strtoupper($termo)];
        }

        return ['name' => $termo];
    }

    /**
     * @param  Collection<int, int|string>  $ids
     * @return Collection<int, array<string, mixed>>
     */
    protected function montar(Collection $ids): Collection
    {
        if ($ids->isEmpty()) {
            return collect();
        }

        $comCodigo = Schema::hasColumn('persons', 'codigo');
        $atributo = $this->atributoDocumento();

        $veiculos = DB::table('vehicles')->whereIn('person_id', $ids)->orderBy('placa')->get(['id', 'person_id', 'placa', 'marca', 'modelo'])->groupBy('person_id');
        $documentos = $atributo
            ? DB::table('attribute_values')->where('attribute_id', $atributo)->where('entity_type', 'persons')->whereIn('entity_id', $ids)->pluck('text_value', 'entity_id')
            : collect();

        // Crédito é dado restrito: quem não pode ver crédito não recebe cor, validade nem aviso.
        $credito = app(CreditoCliente::class);
        $mostrarCor = CreditoCliente::disponivel() && $credito->podeVer();

        return DB::table('persons')->whereIn('id', $ids)->orderBy('name')
            ->get(array_merge(['id', 'name', 'contact_numbers'], $comCodigo ? ['codigo'] : []))
            ->map(function ($pessoa) use ($veiculos, $documentos, $credito, $mostrarCor, $comCodigo) {
                $numeros = (array) json_decode((string) $pessoa->contact_numbers, true);
                $primeiro = collect($numeros)->first(fn ($numero) => ! empty($numero['value']));
                $lista = collect($veiculos[$pessoa->id] ?? []);

                $situacao = null;

                if ($mostrarCor) {
                    $validade = $credito->validade((int) $pessoa->id);
                    $situacao = [
                        'vencida' => $validade['vencida'],
                        'nunca' => $validade['nunca'],
                        'dias' => $validade['dias'],
                        'validade_dias' => $validade['validade_dias'],
                        'cor' => $credito->corEfetiva((int) $pessoa->id),
                    ];
                }

                return [
                    'id' => (int) $pessoa->id,
                    'nome' => $pessoa->name,
                    'codigo' => $comCodigo ? $pessoa->codigo : null,
                    'documento' => $documentos[$pessoa->id] ?? null,
                    'telefones' => ClienteContatos::linhaTelefones($numeros),
                    'veiculos' => $lista->map(fn ($v) => trim($v->placa.' - '.$v->marca.' '.$v->modelo))->all(),
                    'credito' => $situacao,
                    'nova_os' => route('admin.service_orders.cadastro.create', array_filter([
                        'name' => $pessoa->name,
                        'cpf_cnpj' => $documentos[$pessoa->id] ?? null,
                        'whatsapp' => $primeiro['value'] ?? null,
                    ])),
                    'abrir_os' => route('admin.service_orders.create', ['person_id' => $pessoa->id]),
                    'ficha' => route('admin.contacts.persons.view', $pessoa->id),
                ];
            });
    }

    protected function atributoDocumento(): ?int
    {
        $id = DB::table('attributes')->where('code', 'cpf_cnpj')->where('entity_type', 'persons')->value('id');

        return $id ? (int) $id : null;
    }
}
