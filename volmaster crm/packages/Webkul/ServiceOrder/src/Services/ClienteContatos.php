<?php

namespace Webkul\ServiceOrder\Services;

use Illuminate\Support\Facades\DB;
use Webkul\WhatsApp\Support\BrazilianPhone;

/**
 * Telefones e endereço do cliente no Novo Cadastro.
 *
 * Telefones ficam em persons.contact_numbers: o WhatsApp sempre em primeiro
 * (é o que o sistema usa para enviar mensagens), depois o fixo e o 2º
 * telefone, cada um com o nome do responsável pelo contato (opcional).
 *
 * O endereço fica no atributo "Endereço" do cliente (tipo address): rua em
 * "address", cidade em "city", estado em "state", CEP em "postcode", mais
 * número, complemento e bairro.
 */
class ClienteContatos
{
    public const UFS = [
        'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO',
    ];

    /**
     * Campo do formulário => [rótulo gravado no contato, campo do responsável].
     */
    public const TELEFONES = [
        'whatsapp' => ['WhatsApp', 'whatsapp_responsavel'],
        'telefone_fixo' => ['Fixo', 'telefone_fixo_responsavel'],
        'telefone_2' => ['Telefone 2', 'telefone_2_responsavel'],
    ];

    /**
     * Lista de contatos do formulário, na ordem WhatsApp, fixo, 2º telefone.
     * Número repetido entra uma vez só.
     *
     * @return array<int, array{value: string, label: string, responsavel?: string}>
     */
    public static function numeros(array $input): array
    {
        $vistos = [];
        $numeros = [];

        foreach (self::TELEFONES as $campo => [$rotulo, $campoResponsavel]) {
            $valor = trim((string) ($input[$campo] ?? ''));
            $nacional = BrazilianPhone::national($valor);

            if ($nacional === '' || in_array($nacional, $vistos, true)) {
                continue;
            }

            $vistos[] = $nacional;
            $numero = ['value' => BrazilianPhone::format($valor), 'label' => $rotulo];

            if (($responsavel = trim((string) ($input[$campoResponsavel] ?? ''))) !== '') {
                $numero['responsavel'] = $responsavel;
            }

            $numeros[] = $numero;
        }

        return $numeros;
    }

    /**
     * Acrescenta aos contatos de um cliente já cadastrado só os números que
     * ele ainda não tem (nada que já existe é alterado ou apagado).
     *
     * @param  array<int, array>  $atuais
     * @param  array<int, array>  $novos
     * @return array<int, array>
     */
    public static function mesclar(array $atuais, array $novos): array
    {
        $existentes = collect($atuais)->map(fn ($n) => BrazilianPhone::national($n['value'] ?? ''))->filter()->all();

        foreach ($novos as $numero) {
            if (! in_array(BrazilianPhone::national($numero['value']), $existentes, true)) {
                $atuais[] = $numero;
            }
        }

        return array_values($atuais);
    }

    /**
     * Endereço no formato do atributo do cliente, ou null se nada foi preenchido.
     *
     * @return array<string, string>|null
     */
    public static function endereco(array $input): ?array
    {
        $endereco = [];

        foreach (['address' => 'logradouro', 'numero' => 'numero', 'complemento' => 'complemento', 'bairro' => 'bairro', 'city' => 'cidade', 'state' => 'estado'] as $chave => $campo) {
            if (($valor = trim((string) ($input[$campo] ?? ''))) !== '') {
                $endereco[$chave] = $chave === 'state' ? strtoupper($valor) : $valor;
            }
        }

        if (($cep = preg_replace('/\D/', '', (string) ($input['cep'] ?? ''))) !== '') {
            $endereco['postcode'] = strlen($cep) === 8 ? substr($cep, 0, 5).'-'.substr($cep, 5) : $cep;
        }

        return $endereco ? $endereco + ['country' => 'BR'] : null;
    }

    /**
     * Endereço gravado de um cliente (lido direto do atributo "Endereço"), ou null.
     *
     * @return array<string, string>|null
     */
    public static function enderecoDe(?int $personId): ?array
    {
        if (! $personId) {
            return null;
        }

        $json = DB::table('attribute_values as av')
            ->join('attributes as a', 'a.id', '=', 'av.attribute_id')
            ->where('a.code', 'endereco')
            ->where('a.entity_type', 'persons')
            ->where('av.entity_type', 'persons')
            ->where('av.entity_id', $personId)
            ->value('av.json_value');

        $dados = $json ? json_decode($json, true) : null;

        return is_array($dados) && $dados ? $dados : null;
    }

    /**
     * "+55 (62) 99999-9999 (Maria, WhatsApp) / +55 (62) 3333-4444 (Fixo)".
     */
    public static function linhaTelefones(?array $numeros): string
    {
        return collect($numeros ?? [])
            ->filter(fn ($n) => ! empty($n['value']))
            ->map(function ($n) {
                $detalhe = collect([$n['responsavel'] ?? null, $n['label'] ?? null])->filter()->implode(', ');

                return BrazilianPhone::format($n['value']).($detalhe ? " ({$detalhe})" : '');
            })
            ->implode(' / ');
    }

    /**
     * "Rua das Flores, 120 - Sala 3 - Centro" (cidade, estado e CEP ficam em campos próprios).
     */
    public static function linhaEndereco(?array $endereco): string
    {
        $endereco ??= [];
        $rua = collect([$endereco['address'] ?? null, $endereco['numero'] ?? null])->filter()->implode(', ');

        return collect([$rua, $endereco['complemento'] ?? null, $endereco['bairro'] ?? null])->filter()->implode(' - ');
    }
}
