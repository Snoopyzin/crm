<?php

namespace Webkul\ServiceOrder\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\ServiceOrder\Services\SpreadsheetImportService;
use Webkul\Warehouse\Models\Warehouse;

/**
 * Configurações > Importar dados: 4-step wizard to bring clients+vehicles or
 * parts+stock from the shop's previous system via a spreadsheet export.
 * 1) pick type + upload, 2) match columns, 3) dry-run preview, 4) import.
 * The uploaded file only lives (privately) between steps 1 and 4.
 */
class ImportController extends Controller
{
    protected const SESSION_KEY = 'volmaster_import';

    public function __construct(protected SpreadsheetImportService $importer) {}

    public function index(): View
    {
        return view('service_order::import.index');
    }

    public function upload(): RedirectResponse
    {
        $this->validate(request(), [
            'type' => 'required|in:'.SpreadsheetImportService::TYPE_CLIENTES.','.SpreadsheetImportService::TYPE_PECAS,
            'file' => 'required|file|max:10240',
        ], [
            'file.required' => 'Escolha a planilha exportada do sistema antigo.',
            'file.max' => 'A planilha pode ter no máximo 10 MB.',
        ]);

        $file = request()->file('file');

        $extension = strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, ['xlsx', 'xls', 'csv', 'txt'])) {
            return back()->withErrors(['file' => 'Formato não suportado. Envie uma planilha .xlsx, .xls ou .csv.']);
        }

        $this->forgetUpload();

        $path = $file->storeAs('volmaster-imports', Str::uuid().'.'.$extension, 'local');

        session([self::SESSION_KEY => [
            'type' => request('type'),
            'path' => $path,
            'name' => $file->getClientOriginalName(),
        ]]);

        return redirect()->route('admin.import.mapping');
    }

    public function mapping(): View|RedirectResponse
    {
        if (! $upload = $this->currentUpload()) {
            return redirect()->route('admin.import.index');
        }

        try {
            [$headers, $rows, $total] = $this->importer->read(Storage::disk('local')->path($upload['path']));
        } catch (\Throwable $e) {
            $this->forgetUpload();

            session()->flash('error', 'Não foi possível ler essa planilha. Confira se o arquivo abre no Excel e tente de novo.');

            return redirect()->route('admin.import.index');
        }

        if (! array_filter($headers) || ! $rows) {
            $this->forgetUpload();

            session()->flash('error', 'A planilha está vazia ou não tem a linha de títulos das colunas na primeira linha.');

            return redirect()->route('admin.import.index');
        }

        $mapping = $upload['mapping'] ?? $this->importer->guessMapping($upload['type'], $headers);

        return view('service_order::import.mapping', [
            'upload' => $upload,
            'fields' => SpreadsheetImportService::FIELDS[$upload['type']],
            'headers' => $headers,
            'sample' => array_slice($rows, 0, 5),
            'total' => $total,
            'max' => SpreadsheetImportService::MAX_ROWS,
            'mapping' => $mapping,
            'options' => $upload['options'] ?? [],
            'warehouses' => Warehouse::orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * Dry run: validates every row and shows what would happen.
     */
    public function preview(): View|RedirectResponse
    {
        if (! $upload = $this->currentUpload()) {
            return redirect()->route('admin.import.index');
        }

        $fields = SpreadsheetImportService::FIELDS[$upload['type']];

        $mapping = [];

        foreach (array_keys($fields) as $field) {
            $column = request("mapping.$field");

            $mapping[$field] = ($column === null || $column === '') ? null : (int) $column;
        }

        $missing = collect($fields)->filter(fn ($field, $key) => $field[1] && $mapping[$key] === null)->map(fn ($field) => $field[0]);

        $upload['mapping'] = $mapping;
        $upload['options'] = [
            'tipo_padrao' => request('tipo_padrao', 'caminhoes'),
            'warehouse_id' => request('warehouse_id') ?: null,
        ];

        session([self::SESSION_KEY => $upload]);

        if ($missing->isNotEmpty()) {
            session()->flash('error', 'Escolha a coluna de: '.$missing->implode(', ').'.');

            return redirect()->route('admin.import.mapping');
        }

        if ($upload['type'] === SpreadsheetImportService::TYPE_PECAS && $mapping['quantidade'] !== null && ! $upload['options']['warehouse_id']) {
            session()->flash('error', 'Escolha em qual depósito o estoque das peças vai entrar.');

            return redirect()->route('admin.import.mapping');
        }

        [, $rows] = $this->importer->read(Storage::disk('local')->path($upload['path']));

        $result = $this->importer->run($upload['type'], $rows, $mapping, $upload['options'], true);

        return view('service_order::import.result', ['upload' => $upload, 'result' => $result, 'dryRun' => true]);
    }

    public function import(): View|RedirectResponse
    {
        $upload = $this->currentUpload();

        if (! $upload || empty($upload['mapping'])) {
            return redirect()->route('admin.import.index');
        }

        @set_time_limit(600);

        [, $rows] = $this->importer->read(Storage::disk('local')->path($upload['path']));

        $result = $this->importer->run($upload['type'], $rows, $upload['mapping'], $upload['options'], false);

        $this->forgetUpload();

        return view('service_order::import.result', ['upload' => $upload, 'result' => $result, 'dryRun' => false]);
    }

    public function cancel(): RedirectResponse
    {
        $this->forgetUpload();

        return redirect()->route('admin.import.index');
    }

    /**
     * Ready-to-fill template, ";"-separated with a BOM so Excel opens the
     * accents correctly.
     */
    public function template(string $kind): StreamedResponse
    {
        // Not "{type}": Krayin binds that route parameter globally to lead types.
        $type = $kind;

        abort_unless(isset(SpreadsheetImportService::FIELDS[$type]), 404);

        $headers = array_map(fn ($field) => $field[0], SpreadsheetImportService::FIELDS[$type]);

        $example = $type === SpreadsheetImportService::TYPE_CLIENTES
            ? ['João da Silva', '123.456.789-09', 'joao@email.com', '(62) 99999-0000', 'ABC1D23', 'Volvo', 'FH 540', '2020', 'Azul', '', '350000', 'caminhão', 'Diesel']
            : ['FLT-001', 'Filtro de óleo', '89,90', '10', '84212300', '', 'Filtros', 'Fornecedor X', '2'];

        return response()->streamDownload(function () use ($headers, $example) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_values($headers), ';');
            fputcsv($out, $example, ';');
            fclose($out);
        }, 'modelo-'.$type.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    protected function currentUpload(): ?array
    {
        $upload = session(self::SESSION_KEY);

        return $upload && Storage::disk('local')->exists($upload['path']) ? $upload : null;
    }

    protected function forgetUpload(): void
    {
        if ($upload = session(self::SESSION_KEY)) {
            Storage::disk('local')->delete($upload['path']);
        }

        session()->forget(self::SESSION_KEY);
    }
}
