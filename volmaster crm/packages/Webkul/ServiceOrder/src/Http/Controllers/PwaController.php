<?php

namespace Webkul\ServiceOrder\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Webkul\ServiceOrder\Services\AlertService;
use Webkul\ServiceOrder\Services\WorkshopSettings;

/**
 * Arquivos do app instalável (PWA): manifesto, ícones, service worker, a
 * chave pública de push e as confirmações "entregue"/"aberto" enviadas pelo
 * service worker. Rotas públicas: o service worker não tem sessão; as
 * confirmações usam o token aleatório de cada alerta.
 */
class PwaController extends Controller
{
    public function manifest(): JsonResponse
    {
        return response()->json([
            'name' => 'Volmaster Oficina',
            'short_name' => 'Volmaster',
            'description' => 'Alertas e tarefas da oficina',
            'start_url' => '/'.config('app.admin_path').'/workshop/alertas',
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => '#0b3e7a',
            'theme_color' => '#0E90D9',
            'icons' => [
                ['src' => '/workshop-icone-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'],
                ['src' => '/workshop-icone-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
            ],
        ])->header('Content-Type', 'application/manifest+json');
    }

    /**
     * App do dono: abre direto no Painel do Dono, com o login do próprio dono.
     */
    public function manifestDono(): JsonResponse
    {
        return response()->json([
            'name' => 'Painel do Dono',
            'short_name' => 'Dono',
            'description' => 'Pátio, alertas, indicadores e financeiro da oficina',
            'start_url' => '/'.config('app.admin_path').'/mentoria/painel-celular',
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => '#0b3e7a',
            'theme_color' => '#0b3e7a',
            'icons' => [
                ['src' => '/workshop-icone-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'],
                ['src' => '/workshop-icone-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
            ],
        ])->header('Content-Type', 'application/manifest+json');
    }

    /**
     * Ícone quadrado gerado a partir do logo (sem arquivos estáticos para
     * subir junto no deploy por zip).
     */
    public function icon(int $size): Response
    {
        abort_unless(in_array($size, [192, 512], true), 404);

        $canvas = imagecreatetruecolor($size, $size);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 11, 62, 122));

        $logoPath = base_path('packages/Webkul/ServiceOrder/src/Resources/assets/volmaster-logo.png');

        if (is_file($logoPath) && ($logo = @imagecreatefrompng($logoPath))) {
            $w = imagesx($logo);
            $h = imagesy($logo);
            $max = (int) round($size * 0.6);
            $scale = min($max / $w, $max / $h);
            $nw = max(1, (int) round($w * $scale));
            $nh = max(1, (int) round($h * $scale));

            imagealphablending($canvas, true);
            imagecopyresampled($canvas, $logo, (int) (($size - $nw) / 2), (int) (($size - $nh) / 2), 0, 0, $nw, $nh, $w, $h);
        }

        ob_start();
        imagepng($canvas);
        $png = ob_get_clean();

        return response($png, 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'public, max-age=86400']);
    }

    public function serviceWorker(): Response
    {
        return response(view('service_order::pwa.service-worker')->render(), 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Service-Worker-Allowed' => '/',
            'Cache-Control' => 'no-cache',
        ]);
    }

    /**
     * Chave pública VAPID, necessária para inscrever o aparelho.
     */
    public function vapidKey(): JsonResponse
    {
        return response()->json(['chave' => (string) WorkshopSettings::get('push_vapid_public')]);
    }

    /**
     * Confirmação do service worker: entregue (chegou ao aparelho) ou
     * aberto (a pessoa tocou na notificação).
     */
    public function ack(Request $request, string $token, string $status): JsonResponse
    {
        $mapa = ['entregue' => 'entregue', 'aberto' => 'aberto'];

        abort_unless(isset($mapa[$status]), 404);

        $ok = app(AlertService::class)->acknowledge($token, $mapa[$status]);

        return response()->json(['ok' => $ok], $ok ? 200 : 404);
    }
}
