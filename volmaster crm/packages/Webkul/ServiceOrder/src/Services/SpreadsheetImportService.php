<?php

namespace Webkul\ServiceOrder\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use Webkul\Contact\Repositories\PersonRepository;
use Webkul\PartInventory\Models\Part;
use Webkul\PartInventory\Models\PartCategory;
use Webkul\PartInventory\Models\PartInventory;
use Webkul\Vehicle\Models\Vehicle;
use Webkul\WhatsApp\Support\BrazilianPhone;

/**
 * "Importar dados do sistema antigo": reads a spreadsheet exported from the
 * shop's previous system (xlsx/xls/csv), guesses which column is which, and
 * imports either clients (+ their vehicles, one per row) or parts (+ stock).
 *
 * The same row-by-row routine runs twice: first as a dry run (nothing is
 * written, only counted/validated) for the preview screen, then for real.
 * Existing records are matched instead of duplicated: clients by CPF/CNPJ,
 * e-mail or phone; vehicles by plate; parts by code.
 */
class SpreadsheetImportService
{
    public const MAX_ROWS = 5000;

    public const TYPE_CLIENTES = 'clientes';

    public const TYPE_PECAS = 'pecas';

    /**
     * Target fields per import type: label, required flag, header synonyms
     * (compared after lowercasing and stripping accents/punctuation).
     */
    public const FIELDS = [
        self::TYPE_CLIENTES => [
            'nome' => ['Nome do cliente', true, ['nome', 'cliente', 'nomecliente', 'nomedocliente', 'razaosocial', 'nomerazaosocial', 'name', 'nomecompleto']],
            'cpf_cnpj' => ['CPF / CNPJ', false, ['cpfcnpj', 'cpf', 'cnpj', 'documento', 'doc', 'cpfoucnpj']],
            'email' => ['E-mail', false, ['email', 'emailcliente', 'mail']],
            'telefone' => ['Telefone / WhatsApp', false, ['telefone', 'celular', 'whatsapp', 'fone', 'tel', 'contato', 'phone', 'telefonecelular', 'cel']],
            'placa' => ['Placa do veículo', false, ['placa', 'placaveiculo', 'placadoveiculo', 'plate']],
            'marca' => ['Marca', false, ['marca', 'fabricante', 'montadora']],
            'modelo' => ['Modelo', false, ['modelo', 'veiculo', 'modeloveiculo', 'model']],
            'ano' => ['Ano', false, ['ano', 'anomodelo', 'anofabricacao', 'anofab', 'year']],
            'cor' => ['Cor', false, ['cor', 'color']],
            'chassi' => ['Chassi', false, ['chassi', 'chassis', 'vin']],
            'km' => ['KM', false, ['km', 'quilometragem', 'kilometragem', 'odometro', 'kmatual']],
            'tipo' => ['Tipo (carro/moto/caminhão)', false, ['tipo', 'tipoveiculo', 'tipodeveiculo', 'categoria', 'especie']],
            'combustivel' => ['Combustível', false, ['combustivel', 'fuel']],
        ],
        self::TYPE_PECAS => [
            'codigo' => ['Código da peça', true, ['codigo', 'cod', 'sku', 'referencia', 'ref', 'codigointerno', 'codproduto', 'codigoproduto', 'codpeca']],
            'nome' => ['Nome / descrição', true, ['nome', 'descricao', 'produto', 'peca', 'item', 'nomedapeca', 'descricaodoproduto']],
            'valor_unitario' => ['Preço de venda', false, ['valor', 'preco', 'precovenda', 'valorunitario', 'vlunit', 'valorvenda', 'precodevenda', 'precounitario']],
            'quantidade' => ['Quantidade em estoque', false, ['quantidade', 'qtd', 'qtde', 'estoque', 'saldo', 'estoqueatual', 'qtdestoque']],
            'ncm' => ['NCM', false, ['ncm']],
            'cest' => ['CEST', false, ['cest']],
            'categoria' => ['Categoria', false, ['categoria', 'grupo', 'familia', 'secao']],
            'fornecedor' => ['Fornecedor', false, ['fornecedor', 'supplier', 'marcafornecedor']],
            'quantidade_minima' => ['Estoque mínimo', false, ['estoqueminimo', 'minimo', 'qtdminima', 'quantidademinima']],
        ],
    ];

    protected const COMBUSTIVEIS = ['Flex', 'Gasolina', 'Etanol', 'Diesel', 'Elétrico', 'Híbrido', 'GNV'];

    /** Lookup maps, loaded once per run. */
    protected array $personByCpf = [];

    protected array $personByEmail = [];

    protected array $personByPhone = [];

    protected array $vehiclePlates = [];

    protected array $partByCode = [];

    protected array $categories = [];

    public function __construct(protected PersonRepository $personRepository) {}

    /**
     * Read the sheet into [headers, rows]. Rows are lists aligned with the
     * headers, already trimmed, with fully empty rows dropped.
     */
    public function read(string $path): array
    {
        $reader = IOFactory::createReaderForFile($path);

        if ($reader instanceof Csv) {
            $sample = (string) file_get_contents($path, false, null, 0, 4096);

            // Brazilian exports are often ";"-separated and Windows-1252.
            $counts = [';' => substr_count($sample, ';'), ',' => substr_count($sample, ','), "\t" => substr_count($sample, "\t")];
            arsort($counts);
            $reader->setDelimiter(array_key_first($counts));

            if (! mb_check_encoding($sample, 'UTF-8')) {
                $reader->setInputEncoding('CP1252');
            }
        }

        $reader->setReadDataOnly(false);

        $sheet = $reader->load($path)->getActiveSheet();

        $data = $sheet->toArray(null, true, true, false);

        $headers = array_map(fn ($header) => trim((string) $header), array_shift($data) ?? []);

        $rows = [];

        foreach ($data as $row) {
            $row = array_map(fn ($value) => is_string($value) ? trim($value) : (is_null($value) ? '' : trim((string) $value)), $row);

            if (implode('', $row) === '') {
                continue;
            }

            $rows[] = $row;
        }

        return [$headers, array_slice($rows, 0, self::MAX_ROWS), count($rows)];
    }

    /**
     * Best-guess column for each field: exact synonym match first, then a
     * header that contains a synonym. Returns [field => column index|null].
     */
    public function guessMapping(string $type, array $headers): array
    {
        $normalized = array_map(fn ($header) => $this->normalizeHeader($header), $headers);

        $mapping = [];
        $used = [];

        foreach ([true, false] as $exact) {
            foreach (self::FIELDS[$type] as $field => [, , $synonyms]) {
                if (isset($mapping[$field])) {
                    continue;
                }

                foreach ($normalized as $index => $header) {
                    if ($header === '' || isset($used[$index])) {
                        continue;
                    }

                    foreach ($synonyms as $synonym) {
                        if ($exact ? $header === $synonym : (strlen($synonym) >= 4 && str_contains($header, $synonym))) {
                            $mapping[$field] = $index;
                            $used[$index] = true;

                            continue 3;
                        }
                    }
                }
            }
        }

        return array_map(fn ($field) => $mapping[$field] ?? null, array_combine(array_keys(self::FIELDS[$type]), array_keys(self::FIELDS[$type])));
    }

    /**
     * Run the import (or only simulate it with $dryRun). $options:
     * tipo_padrao (clientes), warehouse_id (peças).
     */
    public function run(string $type, array $rows, array $mapping, array $options, bool $dryRun): array
    {
        $result = [
            'total' => count($rows),
            'created' => 0,
            'reused' => 0,
            'vehicles_created' => 0,
            'vehicles_skipped' => 0,
            'updated' => 0,
            'errors' => [],
        ];

        $this->loadLookups($type);

        foreach ($rows as $index => $row) {
            $line = $index + 2; // +1 header, +1 human counting

            $values = [];

            foreach ($mapping as $field => $column) {
                $values[$field] = ($column !== null && $column !== '') ? (string) ($row[(int) $column] ?? '') : '';
            }

            try {
                if ($type === self::TYPE_CLIENTES) {
                    $this->importClientRow($values, $options, $dryRun, $result);
                } else {
                    $this->importPartRow($values, $options, $dryRun, $result);
                }
            } catch (\Throwable $e) {
                $result['errors'][] = ['line' => $line, 'message' => $e instanceof \RuntimeException ? $e->getMessage() : 'Erro inesperado: '.Str::limit($e->getMessage(), 150)];
            }
        }

        return $result;
    }

    protected function importClientRow(array $v, array $options, bool $dryRun, array &$result): void
    {
        $nome = $v['nome'];

        if ($nome === '') {
            throw new \RuntimeException('Nome do cliente vazio.');
        }

        $cpf = $this->fixDocument($v['cpf_cnpj']);
        $email = Str::lower($v['email']);
        $phone = BrazilianPhone::national($v['telefone']);

        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = ''; // keep the client, just drop an unusable e-mail
        }

        $personId = ($cpf !== '' ? ($this->personByCpf[$cpf] ?? null) : null)
            ?? ($email !== '' ? ($this->personByEmail[$email] ?? null) : null)
            ?? (strlen($phone) >= 8 ? ($this->personByPhone[$phone] ?? null) : null);

        if ($personId) {
            $result['reused']++;
        } else {
            if ($dryRun) {
                $personId = 'novo-'.$result['created'];
            } else {
                $data = [
                    'entity_type' => 'persons',
                    'name' => Str::limit($nome, 250, ''),
                    'cpf_cnpj' => $cpf !== '' ? $this->formatDocument($cpf) : null,
                    'emails' => $email !== '' ? [['value' => $email, 'label' => 'Trabalho']] : [],
                ];

                if ($v['telefone'] !== '') {
                    $data['contact_numbers'] = [['value' => BrazilianPhone::format($v['telefone']), 'label' => 'WhatsApp']];
                }

                $personId = $this->personRepository->create($data)->id;
            }

            $result['created']++;

            if ($cpf !== '') {
                $this->personByCpf[$cpf] = $personId;
            }

            if ($email !== '') {
                $this->personByEmail[$email] = $personId;
            }

            if (strlen($phone) >= 8) {
                $this->personByPhone[$phone] = $personId;
            }
        }

        $placa = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $v['placa']));

        if ($placa === '') {
            return;
        }

        if (strlen($placa) !== 7) {
            throw new \RuntimeException("Cliente importado, mas a placa \"{$v['placa']}\" é inválida (precisa ter 7 letras/números) -- veículo não cadastrado.");
        }

        if (isset($this->vehiclePlates[$placa])) {
            $result['vehicles_skipped']++;

            return;
        }

        $this->vehiclePlates[$placa] = true;

        $result['vehicles_created']++;

        if ($dryRun) {
            return;
        }

        preg_match('/(19|20)\d{2}/', $v['ano'], $ano);

        Vehicle::create([
            'person_id' => $personId,
            'marca' => Str::limit($v['marca'] ?: 'Não informado', 250, ''),
            'modelo' => Str::limit($v['modelo'] ?: 'Não informado', 250, ''),
            'placa' => $placa,
            'ano' => $ano[0] ?? null,
            'cor' => $v['cor'] ?: null,
            'chassi' => $v['chassi'] ?: null,
            'km' => (int) preg_replace('/\D/', '', $v['km']) ?: 0,
            'tipo' => $this->vehicleType($v['tipo'], $options['tipo_padrao'] ?? 'caminhoes'),
            'combustivel' => $this->fuel($v['combustivel']),
            'status' => 'Ativo',
        ]);
    }

    protected function importPartRow(array $v, array $options, bool $dryRun, array &$result): void
    {
        $codigo = Str::limit($v['codigo'], 250, '');
        $nome = $v['nome'];

        if ($codigo === '' || $nome === '') {
            throw new \RuntimeException('Código ou nome da peça vazio.');
        }

        $valor = $v['valor_unitario'] !== '' ? $this->money($v['valor_unitario']) : 0.0;

        if ($valor === null) {
            throw new \RuntimeException("Preço \"{$v['valor_unitario']}\" não é um número.");
        }

        $quantidade = $v['quantidade'] !== '' ? (int) round((float) $this->money($v['quantidade'])) : 0;

        $ncm = preg_replace('/\D/', '', $v['ncm']);
        $cest = preg_replace('/\D/', '', $v['cest']);

        $attributes = [
            'nome' => Str::limit($nome, 250, ''),
            'valor_unitario' => $valor,
            'ncm' => strlen($ncm) === 8 ? $ncm : null,
            'cest' => strlen($cest) === 7 ? $cest : null,
            'fornecedor' => $v['fornecedor'] ?: null,
            'quantidade_minima' => max(0, (int) preg_replace('/\D/', '', $v['quantidade_minima'])),
        ];

        $key = Str::lower($codigo);

        if (isset($this->partByCode[$key])) {
            // Existing part: refresh its data, never touch its stock.
            $result['updated']++;

            if (! $dryRun && is_int($this->partByCode[$key])) {
                Part::whereKey($this->partByCode[$key])->update(array_filter($attributes, fn ($value) => $value !== null));
            }

            return;
        }

        $result['created']++;

        if ($dryRun) {
            $this->partByCode[$key] = 'novo';

            return;
        }

        if ($v['categoria'] !== '') {
            $attributes['part_category_id'] = $this->categories[Str::lower($v['categoria'])]
                ??= PartCategory::firstOrCreate(['name' => Str::limit($v['categoria'], 250, '')])->id;
        }

        DB::transaction(function () use ($codigo, $attributes, $quantidade, $options, $key) {
            $part = Part::create(['codigo' => $codigo] + $attributes);

            if (! empty($options['warehouse_id'])) {
                PartInventory::create([
                    'part_id' => $part->id,
                    'warehouse_id' => $options['warehouse_id'],
                    'in_stock' => max(0, $quantidade),
                    'allocated' => 0,
                ]);
            }

            $this->partByCode[$key] = $part->id;
        });
    }

    protected function loadLookups(string $type): void
    {
        if ($type === self::TYPE_PECAS) {
            $this->partByCode = Part::pluck('id', 'codigo')->mapWithKeys(fn ($id, $code) => [Str::lower($code) => $id])->all();
            $this->categories = PartCategory::pluck('id', 'name')->mapWithKeys(fn ($id, $name) => [Str::lower($name) => $id])->all();

            return;
        }

        $cpfAttributeId = DB::table('attributes')->where('code', 'cpf_cnpj')->where('entity_type', 'persons')->value('id');

        $this->personByCpf = $cpfAttributeId
            ? DB::table('attribute_values')->where('attribute_id', $cpfAttributeId)->where('entity_type', 'persons')->whereNotNull('text_value')
                ->get(['entity_id', 'text_value'])
                ->mapWithKeys(fn ($row) => [preg_replace('/\D/', '', $row->text_value) => $row->entity_id])->filter(fn ($id, $digits) => $digits !== '')->all()
            : [];

        $this->personByEmail = [];
        $this->personByPhone = [];

        DB::table('persons')->select(['id', 'emails', 'contact_numbers'])->orderBy('id')->chunk(1000, function ($persons) {
            foreach ($persons as $person) {
                foreach ((array) json_decode((string) $person->emails, true) as $email) {
                    if (! empty($email['value'])) {
                        $this->personByEmail[Str::lower($email['value'])] ??= $person->id;
                    }
                }

                foreach ((array) json_decode((string) $person->contact_numbers, true) as $number) {
                    $digits = BrazilianPhone::national((string) ($number['value'] ?? ''));

                    if (strlen($digits) >= 8) {
                        $this->personByPhone[$digits] ??= $person->id;
                    }
                }
            }
        });

        $this->vehiclePlates = Vehicle::pluck('placa')
            ->mapWithKeys(fn ($placa) => [strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $placa)) => true])->all();
    }

    protected function normalizeHeader(string $header): string
    {
        return preg_replace('/[^a-z0-9]/', '', Str::lower(Str::ascii($header)));
    }

    /**
     * Digits-only CPF/CNPJ, restoring leading zeros that Excel drops when the
     * column was stored as a number. Returns '' when it doesn't look like one.
     */
    protected function fixDocument(string $value): string
    {
        $digits = preg_replace('/\D/', '', $value);

        return match (strlen($digits)) {
            9, 10 => str_pad($digits, 11, '0', STR_PAD_LEFT),
            12, 13 => str_pad($digits, 14, '0', STR_PAD_LEFT),
            11, 14 => $digits,
            default => '',
        };
    }

    protected function formatDocument(string $digits): string
    {
        return strlen($digits) === 11
            ? preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $digits)
            : preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $digits);
    }

    /**
     * "R$ 1.234,56" / "1234.56" / "1234,5" -> float; null if not a number.
     */
    protected function money(string $value): ?float
    {
        $value = str_replace(['R$', ' ', "\u{00A0}"], '', $value);

        if (str_contains($value, ',') && str_contains($value, '.')) {
            $value = strrpos($value, ',') > strrpos($value, '.')
                ? str_replace(['.', ','], ['', '.'], $value)
                : str_replace(',', '', $value);
        } elseif (str_contains($value, ',')) {
            $value = str_replace(',', '.', $value);
        }

        return is_numeric($value) ? round((float) $value, 4) : null;
    }

    protected function vehicleType(string $value, string $default): string
    {
        $value = Str::lower(Str::ascii($value));

        return match (true) {
            str_contains($value, 'moto') => 'motos',
            str_contains($value, 'caminh') || str_contains($value, 'truck') || str_contains($value, 'carreta') || str_contains($value, 'cavalo') || str_contains($value, 'onibus') => 'caminhoes',
            str_contains($value, 'carro') || str_contains($value, 'automovel') || str_contains($value, 'passeio') || str_contains($value, 'utilitario') || str_contains($value, 'camionete') => 'carros',
            default => in_array($default, ['carros', 'motos', 'caminhoes']) ? $default : 'caminhoes',
        };
    }

    protected function fuel(string $value): ?string
    {
        $needle = Str::lower(Str::ascii($value));

        if ($needle === '') {
            return null;
        }

        foreach (self::COMBUSTIVEIS as $fuel) {
            if (str_contains($needle, Str::lower(Str::ascii($fuel)))) {
                return $fuel;
            }
        }

        return str_contains($needle, 'alcool') ? 'Etanol' : null;
    }
}
