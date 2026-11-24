<?php

namespace Webkul\ServiceOrder\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Webkul\ServiceOrder\Http\Controllers\WorkshopSettingsController;
use Webkul\ServiceOrder\Models\BonusApuracao;
use Webkul\ServiceOrder\Models\Colaborador;
use Webkul\ServiceOrder\Models\IndicatorDefinition;
use Webkul\ServiceOrder\Models\MentoriaRegistro;
use Webkul\ServiceOrder\Services\Bonus\BonusService;
use Webkul\ServiceOrder\Services\Indicators\IndicatorService;
use Webkul\ServiceOrder\Support\BrNumber;

/**
 * Importação das planilhas de medição na virada para o sistema (capítulo 9.3):
 * colaboradores e cargos, metas preenchidas, registros de medição por dia,
 * valores mensais dos indicadores e notas de bônus já fechadas. Tudo que
 * entra por aqui fica marcado com origem "planilha".
 *
 * As colunas são reconhecidas pelo nome do cabeçalho (sem acentos e sem
 * diferenciar maiúsculas); cada tipo tem um modelo para baixar.
 */
class HistoricoImportService
{
    public const TIPOS = [
        'colaboradores' => [
            'nome' => 'Colaboradores e cargos',
            'colunas' => ['nome', 'cpf', 'cargos', 'salario_base', 'admissao', 'registro_antes_inicio', 'ferias_vencimento', 'exame_vencimento', 'epi_vencimento', 'cnh'],
            'exemplo' => ['José da Silva', '123.456.789-00', 'tecnico + chefe', '4500,00', '03/11/2026', 'sim', '01/03/2027', '10/01/2027', '01/02/2027', 'E'],
        ],
        'metas' => [
            'nome' => 'Metas preenchidas',
            'colunas' => ['codigo', 'meta'],
            'exemplo' => ['CON-04', '92'],
        ],
        'medicao' => [
            'nome' => 'Registros de medição por dia',
            'colunas' => ['tipo', 'data', 'valor', 'ok', 'colaborador', 'observacao'],
            'exemplo' => ['zel_limpeza', '05/10/2026', '', 'sim', 'Maria', ''],
        ],
        'indicadores' => [
            'nome' => 'Valores mensais dos indicadores (Painel do Dono)',
            'colunas' => ['periodo', 'codigo', 'realizado'],
            'exemplo' => ['2026-10', 'CON-01', '88,5'],
        ],
        'bonus' => [
            'nome' => 'Notas de bônus já fechadas',
            'colunas' => ['periodo', 'colaborador', 'cargo', 'nota', 'faixa', 'valor'],
            'exemplo' => ['2026-10', 'José da Silva', 'tecnico', '92,5', '100', '450,00'],
        ],
    ];

    public function __construct(
        protected SpreadsheetImportService $planilhas,
        protected IndicatorService $indicators,
    ) {}

    /**
     * @return array{criados: int, ignorados: int, erros: list<string>, total: int}
     */
    public function importar(string $tipo, string $caminho, bool $simular = false): array
    {
        abort_unless(isset(self::TIPOS[$tipo]), 404);

        [$cabecalho, $linhas, $total] = $this->planilhas->read($caminho);

        $indice = $this->indiceDasColunas($tipo, $cabecalho);
        $resultado = ['criados' => 0, 'ignorados' => 0, 'erros' => [], 'total' => $total];

        // Simulação: faz tudo e desfaz no fim, só para mostrar o que aconteceria.
        if ($simular) {
            DB::beginTransaction();
        }

        try {
            foreach (array_values($linhas) as $numero => $linha) {
                $dados = collect($indice)->map(fn ($posicao) => $posicao === null ? '' : trim((string) ($linha[$posicao] ?? '')))->all();

                try {
                    $criou = DB::transaction(fn () => $this->linha($tipo, $dados));

                    $criou ? $resultado['criados']++ : $resultado['ignorados']++;
                } catch (\InvalidArgumentException $exception) {
                    $resultado['ignorados']++;
                    $resultado['erros'][] = 'Linha '.($numero + 2).': '.$exception->getMessage();
                }
            }
        } finally {
            if ($simular) {
                DB::rollBack();
            }
        }

        return $resultado;
    }

    /**
     * Conteúdo do modelo (CSV) de cada tipo.
     */
    public function modelo(string $tipo): string
    {
        abort_unless(isset(self::TIPOS[$tipo]), 404);

        $definicao = self::TIPOS[$tipo];

        return "\xEF\xBB\xBF".implode(';', $definicao['colunas'])."\n".implode(';', $definicao['exemplo'])."\n";
    }

    /**
     * @return array<string, ?int> coluna => posição no cabeçalho
     */
    protected function indiceDasColunas(string $tipo, array $cabecalho): array
    {
        $normalizados = array_map(fn ($c) => $this->normalizar($c), $cabecalho);
        $indice = [];

        foreach (self::TIPOS[$tipo]['colunas'] as $coluna) {
            $posicao = array_search($this->normalizar($coluna), $normalizados, true);
            $indice[$coluna] = $posicao === false ? null : $posicao;
        }

        // As colunas obrigatórias precisam existir.
        $obrigatorias = ['colaboradores' => ['nome'], 'metas' => ['codigo', 'meta'], 'medicao' => ['tipo', 'data'], 'indicadores' => ['periodo', 'codigo', 'realizado'], 'bonus' => ['periodo', 'colaborador', 'cargo', 'nota']];

        foreach ($obrigatorias[$tipo] as $coluna) {
            if ($indice[$coluna] === null) {
                throw new \InvalidArgumentException('A planilha precisa ter a coluna "'.$coluna.'". Baixe o modelo para conferir.');
            }
        }

        return $indice;
    }

    protected function linha(string $tipo, array $d): bool
    {
        return match ($tipo) {
            'colaboradores' => $this->colaborador($d),
            'metas' => $this->meta($d),
            'medicao' => $this->medicao($d),
            'indicadores' => $this->indicador($d),
            'bonus' => $this->bonus($d),
        };
    }

    protected function colaborador(array $d): bool
    {
        if ($d['nome'] === '') {
            throw new \InvalidArgumentException('Linha sem nome.');
        }

        $validos = array_keys(WorkshopSettingsController::PAPEIS);
        $cargos = collect(preg_split('/\s*[+\/;,|]\s*/', Str::lower($this->semAcentos($d['cargos'] ?? ''))) ?: [])
            ->map(fn ($c) => $this->cargo($c))->filter()->unique()->intersect($validos)->values();

        $dados = [
            'cpf' => $d['cpf'] ?: null,
            'cargos' => $cargos->implode(',') ?: null,
            'salario_base' => BrNumber::parse($d['salario_base'] ?? ''),
            'admissao' => $this->data($d['admissao'] ?? ''),
            'registro_antes_inicio' => $this->simNao($d['registro_antes_inicio'] ?? ''),
            'ferias_vencimento' => $this->data($d['ferias_vencimento'] ?? ''),
            'exame_periodico_vencimento' => $this->data($d['exame_vencimento'] ?? ''),
            'epi_vencimento' => $this->data($d['epi_vencimento'] ?? ''),
            'cnh_categoria' => ($d['cnh'] ?? '') !== '' ? strtoupper($d['cnh']) : null,
        ];

        $existente = Colaborador::whereRaw('LOWER(nome) = ?', [Str::lower($d['nome'])])->first();

        if ($existente) {
            $existente->update(array_filter($dados, fn ($v) => $v !== null));

            return false;
        }

        Colaborador::create($dados + ['nome' => $d['nome'], 'classificacao' => 'oficina', 'ativo' => true]);

        return true;
    }

    protected function meta(array $d): bool
    {
        $definicao = IndicatorDefinition::find(strtoupper($d['codigo']));

        if (! $definicao) {
            $this->indicators->definitions(apenasAtivos: false);
            $definicao = IndicatorDefinition::find(strtoupper($d['codigo']));
        }

        if (! $definicao) {
            throw new \InvalidArgumentException('Indicador "'.$d['codigo'].'" não existe.');
        }

        $definicao->update(['meta_oficina' => BrNumber::parse($d['meta'])]);

        return true;
    }

    protected function medicao(array $d): bool
    {
        $tipo = $this->tipoDeRegistro($d['tipo']);
        $data = $this->data($d['data']);

        if (! $tipo) {
            throw new \InvalidArgumentException('Tipo de registro "'.$d['tipo'].'" desconhecido.');
        }

        if (! $data) {
            throw new \InvalidArgumentException('Data inválida: "'.$d['data'].'".');
        }

        $colaborador = ($d['colaborador'] ?? '') !== '' ? Colaborador::whereRaw('LOWER(nome) = ?', [Str::lower($d['colaborador'])])->first() : null;
        $definicao = MentoriaRegistro::TIPOS[$tipo];

        $atributos = [
            'tipo' => $tipo,
            'ocorrido_em' => Carbon::parse($data)->setTime(12, 0),
            'valor' => BrNumber::parse($d['valor'] ?? ''),
            'ok' => $this->simNao($d['ok'] ?? ''),
            'user_id' => $colaborador?->user_id,
            'mechanic_id' => ($definicao['colaborador'] ?? null) === 'mechanic' ? $colaborador?->mechanic_id : null,
            'observacao' => ($d['observacao'] ?? '') ?: ($colaborador ? null : (($d['colaborador'] ?? '') ?: null)),
            'origem' => 'planilha',
        ];

        // Importar a mesma planilha duas vezes não duplica.
        if (MentoriaRegistro::where($atributos)->exists()) {
            return false;
        }

        MentoriaRegistro::create($atributos + ['registrado_por' => auth()->guard('user')->id()]);

        return true;
    }

    protected function indicador(array $d): bool
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $d['periodo'])) {
            throw new \InvalidArgumentException('Período "'.$d['periodo'].'" inválido (use AAAA-MM).');
        }

        $codigo = strtoupper($d['codigo']);

        if (! $this->indicators->definition($codigo)) {
            throw new \InvalidArgumentException('Indicador "'.$d['codigo'].'" não existe.');
        }

        $valor = BrNumber::parse($d['realizado']);

        if ($valor === null) {
            throw new \InvalidArgumentException('Valor "'.$d['realizado'].'" inválido.');
        }

        DB::table('indicator_snapshots')->updateOrInsert(
            ['periodo' => $d['periodo'], 'codigo' => $codigo, 'escopo' => 'oficina', 'escopo_id' => 0],
            ['realizado' => $valor, 'meta' => null, 'situacao' => 'sem_dado', 'calculado_em' => now(), 'origem' => 'planilha']
        );

        return true;
    }

    protected function bonus(array $d): bool
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $d['periodo'])) {
            throw new \InvalidArgumentException('Período "'.$d['periodo'].'" inválido (use AAAA-MM).');
        }

        $colaborador = Colaborador::whereRaw('LOWER(nome) = ?', [Str::lower($d['colaborador'])])->first();
        $cargo = $this->cargo(Str::lower($this->semAcentos($d['cargo'])));

        if (! $colaborador) {
            throw new \InvalidArgumentException('Colaborador "'.$d['colaborador'].'" não cadastrado: importe os colaboradores primeiro.');
        }

        if (! $cargo) {
            throw new \InvalidArgumentException('Cargo "'.$d['cargo'].'" desconhecido.');
        }

        if (BonusApuracao::where('periodo', $d['periodo'])->where('colaborador_id', $colaborador->id)->where('cargo', $cargo)->exists()) {
            return false;
        }

        $nota = BrNumber::parse($d['nota']);
        $faixa = isset($d['faixa']) && $d['faixa'] !== '' ? (int) BrNumber::parse($d['faixa']) : ($nota === null ? 0 : app(BonusService::class)->faixa($nota));

        BonusApuracao::create([
            'periodo' => $d['periodo'],
            'colaborador_id' => $colaborador->id,
            'cargo' => $cargo,
            'fase' => 1,
            'nota' => $nota,
            'faixa_percentual' => $faixa,
            'valor' => BrNumber::parse($d['valor'] ?? ''),
            'status' => BonusApuracao::APROVADO,
            'origem' => 'planilha',
            'calculado_em' => now(),
            'aprovado_em' => now(),
        ]);

        return true;
    }

    protected function tipoDeRegistro(string $texto): ?string
    {
        $alvo = $this->normalizar($texto);

        foreach (MentoriaRegistro::TIPOS as $chave => $definicao) {
            if ($this->normalizar($chave) === $alvo || $this->normalizar($definicao['label']) === $alvo) {
                return $chave;
            }
        }

        return null;
    }

    protected function cargo(string $texto): ?string
    {
        $texto = trim($texto);

        return match (true) {
            str_contains($texto, 'propriet'), str_contains($texto, 'dono') => 'proprietario',
            str_contains($texto, 'gerente') => 'gerente',
            str_contains($texto, 'chefe') => 'chefe',
            str_contains($texto, 'consultor') => 'consultor',
            str_contains($texto, 'tecnico'), str_contains($texto, 'mecanico'), str_contains($texto, 'eletricista') => 'tecnico',
            str_contains($texto, 'peca') => 'pecas',
            str_contains($texto, 'financ') => 'financeiro',
            str_contains($texto, 'zelad'), str_contains($texto, 'copa') => 'zeladoria',
            default => null,
        };
    }

    protected function data(string $texto): ?string
    {
        $texto = trim($texto);

        if ($texto === '') {
            return null;
        }

        try {
            if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $texto, $m)) {
                // 31/02 e semelhantes não existem: data inválida, não "3 de março".
                return checkdate((int) $m[2], (int) $m[1], (int) $m[3])
                    ? Carbon::create((int) $m[3], (int) $m[2], (int) $m[1])->toDateString()
                    : null;
            }

            // Número de série do Excel.
            if (is_numeric($texto) && (float) $texto > 20000) {
                return Carbon::create(1899, 12, 30)->addDays((int) $texto)->toDateString();
            }

            return Carbon::parse($texto)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    protected function simNao(string $texto): ?bool
    {
        $texto = $this->normalizar($texto);

        return match (true) {
            $texto === '' => null,
            in_array($texto, ['sim', 's', '1', 'true', 'x', 'ok', 'feito', 'realizada', 'realizado'], true) => true,
            in_array($texto, ['nao', 'n', '0', 'false', 'nok'], true) => false,
            default => null,
        };
    }

    protected function normalizar(string $texto): string
    {
        return preg_replace('/[^a-z0-9]+/', '_', trim(Str::lower($this->semAcentos($texto)), ' _'));
    }

    protected function semAcentos(string $texto): string
    {
        return Str::ascii($texto);
    }
}
