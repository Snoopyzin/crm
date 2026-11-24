<?php

namespace Webkul\ServiceOrder\Services;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Webkul\ServiceOrder\Models\ServiceOrder;
use Webkul\ServiceOrder\Models\ServiceOrderEvent;
use Webkul\ServiceOrder\Models\ServiceOrderExtra;

/**
 * "Anotações e anexos" da OS: o "+" que deixa juntar qualquer coisa à ordem de serviço
 * (texto, fotos, vídeos e outros arquivos). Usado pela tela da OS e pelos formulários de
 * criar e editar a OS, com as mesmas regras.
 */
class ExtrasOs
{
    public const FOTOS = 'jpg,jpeg,png,webp,heic,heif';

    public const VIDEOS = 'mp4,mov,webm,3gp,m4v,avi,mkv';

    public const ARQUIVOS = 'pdf,doc,docx,xls,xlsx,csv,txt,odt,ods,mp3,m4a,wav,ogg';

    /**
     * @return array<string, mixed>
     */
    public static function regras(): array
    {
        return [
            'extra_titulo' => 'nullable|string|max:150',
            'extra_texto' => 'nullable|string|max:5000',
            'extra_fotos' => 'nullable|array|max:20',
            'extra_fotos.*' => 'file|mimes:'.self::FOTOS.'|max:10240',
            'extra_videos' => 'nullable|array|max:5',
            'extra_videos.*' => 'file|mimes:'.self::VIDEOS.'|max:102400',
            'extra_arquivos' => 'nullable|array|max:10',
            'extra_arquivos.*' => 'file|mimes:'.self::ARQUIVOS.'|max:20480',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function mensagens(): array
    {
        return [
            'extra_fotos.max' => 'Envie no máximo 20 fotos por vez.',
            'extra_fotos.*.mimes' => 'Cada foto precisa ser JPG, PNG, WEBP ou HEIC.',
            'extra_fotos.*.max' => 'Cada foto pode ter até 10 MB.',
            'extra_fotos.*.uploaded' => 'Não foi possível enviar uma das fotos (o arquivo passa do limite do servidor).',
            'extra_videos.max' => 'Envie no máximo 5 vídeos por vez.',
            'extra_videos.*.mimes' => 'Cada vídeo precisa ser MP4, MOV, WEBM, 3GP, M4V, AVI ou MKV.',
            'extra_videos.*.max' => 'Cada vídeo pode ter até 100 MB.',
            'extra_videos.*.uploaded' => 'Não foi possível enviar um dos vídeos (o arquivo passa do limite do servidor).',
            'extra_arquivos.max' => 'Envie no máximo 10 arquivos por vez.',
            'extra_arquivos.*.mimes' => 'Cada arquivo precisa ser PDF, Word, Excel, CSV, TXT, ODT, ODS ou áudio (MP3, M4A, WAV, OGG).',
            'extra_arquivos.*.max' => 'Cada arquivo pode ter até 20 MB.',
            'extra_arquivos.*.uploaded' => 'Não foi possível enviar um dos arquivos (o arquivo passa do limite do servidor).',
        ];
    }

    /**
     * A tabela existe? (Servidores que ainda não rodaram a migration seguem funcionando sem a área.)
     */
    public static function disponivel(): bool
    {
        return Schema::hasTable('service_order_extras');
    }

    /**
     * Grava uma anotação/anexo a partir do que veio do formulário (campos extra_*).
     * Sem título, texto nem arquivo, não grava nada e devolve null.
     */
    public function registrarDoFormulario(ServiceOrder $os, Request $request): ?ServiceOrderExtra
    {
        $validos = fn (string $campo) => array_values(array_filter((array) $request->file($campo), fn ($arquivo) => $arquivo instanceof UploadedFile && $arquivo->isValid()));

        return $this->registrar(
            $os,
            $request->input('extra_titulo'),
            $request->input('extra_texto'),
            $validos('extra_fotos'),
            $validos('extra_videos'),
            $validos('extra_arquivos'),
            $request->boolean('extra_visivel_cliente'),
        );
    }

    /**
     * @param  UploadedFile[]  $fotos
     * @param  UploadedFile[]  $videos
     * @param  UploadedFile[]  $arquivos
     */
    public function registrar(ServiceOrder $os, ?string $titulo, ?string $texto, array $fotos = [], array $videos = [], array $arquivos = [], bool $visivelCliente = false): ?ServiceOrderExtra
    {
        $titulo = $titulo !== null ? trim($titulo) : null;
        $texto = $texto !== null ? trim($texto) : null;

        if (! self::disponivel() || ($titulo === '' || $titulo === null) && ($texto === '' || $texto === null) && ! $fotos && ! $videos && ! $arquivos) {
            return null;
        }

        $guardados = [];

        foreach ([ServiceOrderExtra::TIPO_FOTO => $fotos, ServiceOrderExtra::TIPO_VIDEO => $videos, ServiceOrderExtra::TIPO_ARQUIVO => $arquivos] as $tipo => $lista) {
            foreach ($lista as $arquivo) {
                $guardados[] = [
                    'path' => Storage::disk('public')->putFile("service-orders/{$os->id}/extras", $arquivo),
                    'nome' => $arquivo->getClientOriginalName(),
                    'tipo' => $tipo,
                    'tamanho' => (int) $arquivo->getSize(),
                ];
            }
        }

        $dados = [
            'quote_id' => $os->id,
            'user_id' => auth()->guard('user')->id(),
            'titulo' => $titulo !== '' ? $titulo : null,
            'texto' => $texto !== '' ? $texto : null,
            'arquivos' => $guardados ?: null,
        ];

        // Servidor que ainda não rodou a migration segue funcionando (sem a marcação).
        if (\Illuminate\Support\Facades\Schema::hasColumn('service_order_extras', 'visivel_cliente')) {
            $dados['visivel_cliente'] = $visivelCliente;
        }

        $extra = ServiceOrderExtra::create($dados);

        ServiceOrderEvent::record($os, 'extra_adicionado', [
            'titulo' => $extra->titulo,
            'fotos' => count($fotos),
            'videos' => count($videos),
            'arquivos' => count($arquivos),
        ]);

        return $extra;
    }

    /**
     * Quem escreveu a anotação ou quem pode excluir OS pode apagá-la (os arquivos saem junto).
     */
    public function podeRemover(ServiceOrderExtra $extra): bool
    {
        $usuario = auth()->guard('user')->user();

        return $usuario && ((int) $extra->user_id === (int) $usuario->id || bouncer()->hasPermission('service_orders.delete'));
    }

    public function remover(ServiceOrderExtra $extra): void
    {
        foreach ($extra->arquivos ?? [] as $arquivo) {
            Storage::disk('public')->delete($arquivo['path']);
        }

        $extra->delete();
    }
}
