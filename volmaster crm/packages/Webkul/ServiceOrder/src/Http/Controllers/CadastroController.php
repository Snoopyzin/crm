<?php

namespace Webkul\ServiceOrder\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Attribute\Repositories\AttributeValueRepository;
use Webkul\Contact\Repositories\PersonRepository;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Models\ServiceOrderEvent;
use Webkul\ServiceOrder\Repositories\ServiceOrderRepository;
use Webkul\ServiceOrder\Services\ClienteContatos;
use Webkul\ServiceOrder\Services\CodigoSequencial;
use Webkul\ServiceOrder\Services\Credito\CreditoCliente;
use Webkul\ServiceOrder\Services\WorkflowService;
use Webkul\Vehicle\Repositories\VehicleRepository;
use Webkul\WhatsApp\Support\BrazilianPhone;

/**
 * Stage 1, "Cadastro": today, creating a Person and a Vehicle are always two
 * separate screens (confirmed nowhere in the codebase combines them -- see
 * Portal\ServiceRequestController::convert()'s own comment about this gap).
 * This is the single-screen entry point: client + vehicle + a fresh
 * ServiceOrder shell, all in one submit.
 */
class CadastroController extends Controller
{
    public function __construct(
        protected PersonRepository $personRepository,
        protected VehicleRepository $vehicleRepository,
        protected ServiceOrderRepository $serviceOrderRepository
    ) {}

    public function create(): View
    {
        return view('service_order::cadastro.create', [
            // Tamanho da carteira: quantos clientes e qual o último código.
            'carteira' => CodigoSequencial::disponivel('persons') ? CodigoSequencial::carteira('persons') : null,
            // Quanto o servidor aceita de arquivos por cadastro (fotos e vídeos do checklist).
            'limiteUpload' => $this->limiteUpload(),
        ]);
    }

    /**
     * O menor entre "upload_max_filesize" e "post_max_size" do PHP, em bytes
     * (0 = sem limite conhecido).
     */
    protected function limiteUpload(): int
    {
        $bytes = function (string $valor): int {
            $valor = trim($valor);
            $numero = (int) $valor;

            return match (strtolower(substr($valor, -1))) {
                'g' => $numero * 1024 ** 3,
                'm' => $numero * 1024 ** 2,
                'k' => $numero * 1024,
                default => $numero,
            };
        };

        $limites = array_filter([$bytes((string) ini_get('upload_max_filesize')), $bytes((string) ini_get('post_max_size'))]);

        return $limites ? min($limites) : 0;
    }

    /**
     * Endereço de um CEP (para preencher rua, bairro, cidade e estado sozinho).
     * A consulta é feita pelo servidor (ViaCEP, com a BrasilAPI de reserva) e
     * guardada por 30 dias.
     */
    public function cep(string $cep): JsonResponse
    {
        $cep = preg_replace('/\D/', '', $cep);

        if (strlen($cep) !== 8) {
            return response()->json(['encontrado' => false, 'mensagem' => 'O CEP precisa ter 8 dígitos.'], 422);
        }

        $endereco = Cache::get("cep:{$cep}");

        if (! $endereco) {
            [$endereco, $consultou] = $this->consultarCep($cep);

            if (! $consultou) {
                return response()->json(['encontrado' => false, 'mensagem' => 'Não foi possível consultar o CEP agora. Preencha o endereço à mão.']);
            }

            if (! $endereco) {
                return response()->json(['encontrado' => false, 'mensagem' => 'CEP não encontrado. Confira o número ou preencha o endereço à mão.']);
            }

            Cache::put("cep:{$cep}", $endereco, now()->addDays(30));
        }

        return response()->json(['encontrado' => true] + $endereco);
    }

    public function store(): RedirectResponse
    {
        // "go" digitado em minúsculas vale como "GO".
        if (is_string(request('estado'))) {
            request()->merge(['estado' => strtoupper(trim(request('estado')))]);
        }

        $telefone = function ($attribute, $value, $fail) {
            if ($value !== null && $value !== '' && ! BrazilianPhone::e164($value)) {
                $fail('Telefone inválido -- use +55 (DDD) NÚMERO.');
            }
        };

        // Only CPF/CNPJ and WhatsApp are mandatory; everything else
        // (name, vehicle -- software-only services have none --, origin,
        // second phones, address...) is optional and can be completed later.
        $this->validate(request(), [
            'name' => 'nullable|string|max:255',
            'cpf_cnpj' => ['required', 'string', 'max:20', function ($attribute, $value, $fail) {
                if (! in_array(strlen(preg_replace('/\D/', '', (string) $value)), [11, 14])) {
                    $fail('CPF precisa ter 11 dígitos e CNPJ 14 dígitos.');
                }
            }],
            'email' => 'nullable|email|max:255',
            'whatsapp' => ['required', 'string', 'max:30', function ($attribute, $value, $fail) {
                if (! BrazilianPhone::e164($value)) {
                    $fail('WhatsApp inválido -- use +55 (DDD) NÚMERO.');
                }
            }],
            'whatsapp_responsavel' => 'nullable|string|max:120',
            'telefone_fixo' => ['nullable', 'string', 'max:30', $telefone],
            'telefone_fixo_responsavel' => 'nullable|string|max:120',
            'telefone_2' => ['nullable', 'string', 'max:30', $telefone],
            'telefone_2_responsavel' => 'nullable|string|max:120',
            'cep' => ['nullable', 'string', 'max:9', function ($attribute, $value, $fail) {
                if ($value !== null && $value !== '' && strlen(preg_replace('/\D/', '', $value)) !== 8) {
                    $fail('O CEP precisa ter 8 dígitos.');
                }
            }],
            'logradouro' => 'nullable|string|max:255',
            'numero' => 'nullable|string|max:20',
            'complemento' => 'nullable|string|max:120',
            'bairro' => 'nullable|string|max:120',
            'cidade' => 'nullable|string|max:120',
            'estado' => ['nullable', 'string', 'size:2', Rule::in(ClienteContatos::UFS)],
            'placa' => 'nullable|string|max:20|unique:vehicles,placa',
            'marca' => 'nullable|string|max:255',
            'modelo' => 'nullable|string|max:255',
            'ano' => 'nullable|integer|digits:4',
            'tipo' => 'nullable|in:carros,motos,caminhoes',
            'origem_cliente' => 'nullable|in:'.implode(',', ServiceOrder::ORIGENS),
            // O que o motorista contou na chegada.
            'relato_motorista' => 'nullable|string|max:2000',
            'defeito_relatado' => 'nullable|string|max:2000',
            // Checklist de entrada do veículo.
            'descricao_entrada' => 'nullable|string|max:2000',
            'chassi' => ['nullable', 'string', 'max:30', function ($attribute, $value, $fail) {
                if (! request('placa')) {
                    $fail('Informe a placa do veículo para registrar o chassi.');
                }
            }],
            'km_entrada' => 'nullable|integer|min:0|max:9999999',
            'combustivel_entrada' => ['nullable', Rule::in(ServiceOrder::COMBUSTIVEIS)],
            'itens_entrada' => 'nullable|string|max:2000',
            'fotos' => 'nullable|array|max:20',
            'fotos.*' => 'file|mimes:jpg,jpeg,png,webp,heic,heif|max:10240',
            'videos' => 'nullable|array|max:3',
            'videos.*' => 'file|mimes:mp4,mov,webm,3gp,m4v,avi,mkv|max:102400',
        ], [
            'cpf_cnpj.required' => 'Informe o CPF ou CNPJ do cliente.',
            'whatsapp.required' => 'Informe o WhatsApp do cliente.',
            'km_entrada.integer' => 'O km precisa ser um número inteiro, sem pontos ou vírgulas.',
            'fotos.max' => 'Envie no máximo 20 fotos por vez.',
            'fotos.*.mimes' => 'Cada foto precisa ser JPG, PNG, WEBP ou HEIC.',
            'fotos.*.max' => 'Cada foto pode ter até 10 MB.',
            'fotos.*.uploaded' => 'Não foi possível enviar uma das fotos (o arquivo passa do limite do servidor).',
            'videos.max' => 'Envie no máximo 3 vídeos por vez.',
            'videos.*.mimes' => 'Cada vídeo precisa ser MP4, MOV, WEBM, 3GP, M4V, AVI ou MKV.',
            'videos.*.max' => 'Cada vídeo pode ter até 100 MB.',
            'videos.*.uploaded' => 'Não foi possível enviar um dos vídeos (o arquivo passa do limite do servidor).',
        ]);

        // A returning client (same CPF/CNPJ or WhatsApp) gets the new OS on
        // their existing record instead of a duplicate (the duplicate would
        // also clash on the person's unique contact key).
        $numeros = ClienteContatos::numeros(request()->all());
        $endereco = ClienteContatos::endereco(request()->all());

        $person = $this->existingClient(request('cpf_cnpj'), request('whatsapp'));
        $jaCadastrado = (bool) $person;

        if ($person) {
            $this->completeExistingClient($person, $numeros, $endereco);
        } else {
            $person = $this->personRepository->create(array_filter([
                'entity_type' => 'persons',
                // No name typed: use the CPF/CNPJ so the client is still identifiable.
                'name' => request('name') ?: request('cpf_cnpj'),
                'cpf_cnpj' => request('cpf_cnpj'),
                'emails' => request('email') ? [['value' => request('email'), 'label' => 'Trabalho']] : [],
                'contact_numbers' => $numeros,
                'endereco' => $endereco,
            ], fn ($value) => $value !== null));
        }

        $vehicle = request('placa') ? $this->vehicleRepository->create([
            'person_id' => $person->id,
            'marca' => request('marca') ?: 'Não informado',
            'modelo' => request('modelo') ?: 'Não informado',
            'placa' => request('placa'),
            'ano' => request('ano'),
            'chassi' => request('chassi') ?: null,
            'km' => (int) request('km_entrada'),
            'tipo' => request('tipo') ?: 'caminhoes',
            'status' => 'Ativo',
        ]) : null;

        $serviceOrder = $this->serviceOrderRepository->create([
            'person_id' => $person->id,
            'vehicle_id' => $vehicle?->id,
            // O registro é a Ordem de Serviço do veículo; "Cadastro do cliente" é só a etapa em que ela começa.
            'subject' => ServiceOrder::STAGE_ORDEM_SERVICO.' - '.($vehicle?->placa ?? (request('name') ?: request('cpf_cnpj'))),
            'workshop_status' => ServiceOrder::STAGE_CADASTRO,
            // Cliente que volta e não repete a resposta: vale a origem que ele já tinha.
            'origem_cliente' => request('origem_cliente') ?: ($jaCadastrado ? ServiceOrder::origemDoCliente($person->id) : null),
        ]);

        // A OS nasce na chegada do veículo à oficina.
        if ($vehicle) {
            ServiceOrderEvent::record($serviceOrder, 'chegada_veiculo', ['placa' => $vehicle->placa]);
        }

        // Checklist de entrada: descrição, km, combustível, itens, fotos e vídeos.
        $fotos = array_values(array_filter((array) request()->file('fotos'), fn ($arquivo) => $arquivo?->isValid()));
        $videos = array_values(array_filter((array) request()->file('videos'), fn ($arquivo) => $arquivo?->isValid()));

        app(WorkflowService::class)->saveChecklistEntrada(
            $serviceOrder,
            request()->only(['descricao_entrada', 'km_entrada', 'combustivel_entrada', 'itens_entrada', 'relato_motorista', 'defeito_relatado']),
            $fotos,
            $videos
        );

        $codigo = $person->codigo ? ' '.($jaCadastrado ? 'Cliente já cadastrado: ' : 'Código do cliente: ').CodigoSequencial::rotulo((int) $person->codigo).'.' : '';
        $anexos = $fotos || $videos ? ' Checklist com '.count($fotos).' '.(count($fotos) === 1 ? 'foto' : 'fotos').' e '.count($videos).' '.(count($videos) === 1 ? 'vídeo' : 'vídeos').'.' : '';

        // Cliente que já existia com cadastro vencido (última análise de crédito além da validade da Ficha).
        $aviso = '';
        $credito = app(CreditoCliente::class);

        if ($jaCadastrado && CreditoCliente::disponivel() && $credito->podeVer()) {
            $validade = $credito->validade((int) $person->id);

            if ($validade['vencida']) {
                $aviso = ' Atenção: '.($validade['nunca']
                    ? 'este cliente ainda não tem análise de crédito'
                    : 'a última análise de crédito foi há '.$validade['dias'].' dias (a validade é de '.$validade['validade_dias'].')')
                    .'. Peça nova consulta cadastral ao Financeiro.';
            }
        }

        session()->flash('success', 'Cadastro criado com sucesso.'.$codigo.$anexos.$aviso);

        return redirect()->route('admin.service_orders.view', $serviceOrder->id);
    }

    /**
     * A returning client keeps what is already on file: only the phones he
     * did not have yet are added, and the address is filled only if there is
     * none (nothing already registered is overwritten).
     */
    protected function completeExistingClient($person, array $numeros, ?array $endereco): void
    {
        $valores = ['entity_type' => 'persons', 'entity_id' => $person->id];
        $merged = ClienteContatos::mesclar($person->contact_numbers ?? [], $numeros);

        if ($merged !== ($person->contact_numbers ?? [])) {
            $person->forceFill(['contact_numbers' => $merged])->save();

            // O Krayin guarda uma cópia dos contatos nos atributos: mantém as duas iguais.
            $valores['contact_numbers'] = $merged;
        }

        $atual = ClienteContatos::enderecoDe($person->id) ?? [];

        if ($endereco && empty($atual['address'] ?? null) && empty($atual['postcode'] ?? null)) {
            $valores['endereco'] = $endereco;
        }

        if (count($valores) > 2) {
            app(AttributeValueRepository::class)->save($valores);
        }
    }

    /**
     * ViaCEP first, BrasilAPI as the spare. Returns [address|null, consulted]:
     * "consulted" is false only when no service answered.
     *
     * @return array{0: array<string, string>|null, 1: bool}
     */
    protected function consultarCep(string $cep): array
    {
        try {
            $resposta = Http::timeout(5)->acceptJson()->get("https://viacep.com.br/ws/{$cep}/json/");

            if ($resposta->successful()) {
                if ($resposta->json('erro')) {
                    return [null, true];
                }

                return [[
                    'logradouro' => (string) $resposta->json('logradouro'),
                    'bairro' => (string) $resposta->json('bairro'),
                    'cidade' => (string) $resposta->json('localidade'),
                    'estado' => strtoupper((string) $resposta->json('uf')),
                ], true];
            }
        } catch (\Throwable) {
            // cai para a BrasilAPI
        }

        try {
            $resposta = Http::timeout(5)->acceptJson()->get("https://brasilapi.com.br/api/cep/v1/{$cep}");

            if ($resposta->successful()) {
                return [[
                    'logradouro' => (string) $resposta->json('street'),
                    'bairro' => (string) $resposta->json('neighborhood'),
                    'cidade' => (string) $resposta->json('city'),
                    'estado' => strtoupper((string) $resposta->json('state')),
                ], true];
            }

            if ($resposta->status() === 404) {
                return [null, true];
            }
        } catch (\Throwable) {
            // sem resposta de nenhum serviço
        }

        return [null, false];
    }

    /**
     * Existing client with the same CPF/CNPJ (digits) or WhatsApp number.
     */
    protected function existingClient(?string $cpfCnpj, ?string $whatsapp)
    {
        $digits = preg_replace('/\D/', '', (string) $cpfCnpj);

        if ($digits !== '') {
            $attributeId = DB::table('attributes')->where('code', 'cpf_cnpj')->where('entity_type', 'persons')->value('id');

            $personId = DB::table('attribute_values')
                ->where('attribute_id', $attributeId)
                ->where('entity_type', 'persons')
                ->whereNotNull('text_value')
                ->get(['entity_id', 'text_value'])
                ->first(fn ($row) => preg_replace('/\D/', '', $row->text_value) === $digits)
                ?->entity_id;

            if ($personId && ($person = $this->personRepository->find($personId))) {
                return $person;
            }
        }

        $national = BrazilianPhone::national($whatsapp);

        if (strlen($national) >= 10) {
            return $this->personRepository->getModel()->newQuery()
                ->where('contact_numbers', 'like', '%'.substr($national, -4).'%') // cheap prefilter
                ->get()
                ->first(fn ($person) => collect($person->contact_numbers ?? [])
                    ->contains(fn ($number) => BrazilianPhone::national($number['value'] ?? '') === $national));
        }

        return null;
    }
}
