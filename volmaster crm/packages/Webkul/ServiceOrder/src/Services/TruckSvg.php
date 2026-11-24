<?php

namespace Webkul\ServiceOrder\Services;

/**
 * Desenha o caminhão da página de acompanhamento do cliente (vista lateral,
 * virado para a direita) com a COR que o cliente informou no cadastro.
 *
 * - Cor: texto livre ("vermelho", "azul escuro", "prata", "#c00") vira uma
 *   pintura com brilho e sombra de verdade (degradê), não uma cor chapada.
 * - Silhueta: cavalo mecânico com carreta baú (padrão) ou caminhão baú
 *   (toco/3-4/VUC/Atego/Accelo/Cargo/Delivery...), conforme tipo/modelo.
 * - Marca: ajusta detalhes da cabine (altura do teto, para-brisa, aerofólio).
 *
 * Sem dependências do Laravel: pode ser testado direto na linha de comando.
 * O SVG mantém os ids/classes que o CSS da página já anima
 * (#truck-g, .bob-wrap, .wheel-spin, #exhaust, #headlight-glow).
 */
class TruckSvg
{
    /** palavra-chave => [hex, nome] ; as mais específicas primeiro */
    private const CORES = [
        'azul marinho' => '#13306F', 'azul escuro' => '#16367F', 'azul claro' => '#5BA8E8', 'celeste' => '#6DB8EE',
        'verde escuro' => '#1E6B3A', 'verde claro' => '#6CC27A', 'verde musgo' => '#4A6B3A',
        'vermelho escuro' => '#9E1F27', 'vinho' => '#6E1B2B', 'bordo' => '#6E1B2B', 'bordô' => '#6E1B2B',
        'cinza chumbo' => '#4A525B', 'cinza escuro' => '#4A525B', 'cinza claro' => '#B9C0C7', 'grafite' => '#3B434C', 'chumbo' => '#4A525B',
        'branco' => '#F1F3F5', 'branca' => '#F1F3F5', 'gelo' => '#E7EBEF', 'perola' => '#EEF0F2', 'pérola' => '#EEF0F2',
        'preto' => '#1F2329', 'preta' => '#1F2329',
        'prata' => '#C2C9D0', 'prateado' => '#C2C9D0', 'cinza' => '#808B96',
        'vermelho' => '#D1262C', 'vermelha' => '#D1262C', 'vermelho vivo' => '#E0202A',
        'azul' => '#1F6FD6',
        'verde' => '#2F9D4F',
        'amarelo' => '#F2BE1A', 'amarela' => '#F2BE1A',
        'laranja' => '#F27A1A',
        'marrom' => '#7A4A2B', 'caramelo' => '#B5733A',
        'bege' => '#D8C6A0', 'areia' => '#D8C6A0', 'dourado' => '#C9A44C', 'ouro' => '#C9A44C',
        'roxo' => '#6C3FB5', 'lilas' => '#9A7BD0', 'lilás' => '#9A7BD0',
        'rosa' => '#E86AA6',
    ];

    public static function resolveColor(?string $texto): array
    {
        $padrao = ['hex' => '#1F6FD6', 'nome' => 'azul', 'achou' => false];
        $t = trim((string) $texto);

        if ($t === '') {
            return $padrao;
        }

        if (preg_match('/#?([0-9a-f]{6})\b/i', $t, $m)) {
            return ['hex' => '#'.strtoupper($m[1]), 'nome' => $t, 'achou' => true];
        }

        $semAcento = mb_strtolower($t);
        $ordenadas = self::CORES;
        uksort($ordenadas, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        foreach ($ordenadas as $palavra => $hex) {
            if (str_contains($semAcento, $palavra)) {
                $base = $hex;

                // "claro"/"escuro" quando a palavra-chave não trouxe o tom
                if (! str_contains($palavra, 'claro') && preg_match('/\bclar[oa]\b/u', $semAcento)) {
                    $base = self::mix($base, '#FFFFFF', .3);
                } elseif (! str_contains($palavra, 'escuro') && preg_match('/\bescur[oa]\b/u', $semAcento)) {
                    $base = self::mix($base, '#000000', .35);
                }

                return ['hex' => strtoupper($base), 'nome' => $palavra, 'achou' => true];
            }
        }

        return $padrao;
    }

    public static function mix(string $a, string $b, float $t): string
    {
        [$ar, $ag, $ab] = self::rgb($a);
        [$br, $bg, $bb] = self::rgb($b);

        return sprintf('#%02X%02X%02X', $ar + ($br - $ar) * $t, $ag + ($bg - $ag) * $t, $ab + ($bb - $ab) * $t);
    }

    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    /** 'cavalo' (cavalo mecânico + carreta baú) ou 'bau' (caminhão baú). */
    public static function silhueta(?string $marca, ?string $modelo, ?string $tipo = null): string
    {
        $texto = mb_strtolower(trim(($marca ?? '').' '.($modelo ?? '').' '.($tipo ?? '')));

        if (preg_match('/\b(toco|truck|3\/4|vuc|atego|accelo|cargo|delivery|tector|constellation\s*(1[0-9]|2[0-6])|hr|bongo|iveco daily|daily|express)\b/u', $texto)) {
            return 'bau';
        }

        return 'cavalo';
    }

    /** Pequenos ajustes de cabine por marca. */
    private static function perfil(?string $marca): array
    {
        $m = mb_strtolower((string) $marca);

        return match (true) {
            str_contains($m, 'scania') => ['teto' => 3, 'rake' => 5, 'deflet' => 4.5, 'faixa' => true],
            str_contains($m, 'volvo') => ['teto' => 3.5, 'rake' => 3.5, 'deflet' => 5, 'faixa' => true],
            str_contains($m, 'mercedes') => ['teto' => 4, 'rake' => 4.5, 'deflet' => 4, 'faixa' => false],
            str_contains($m, 'daf') => ['teto' => 2.5, 'rake' => 2.5, 'deflet' => 5.5, 'faixa' => false],
            str_contains($m, 'iveco') => ['teto' => 3.5, 'rake' => 5.5, 'deflet' => 4.5, 'faixa' => true],
            str_contains($m, 'man') => ['teto' => 3, 'rake' => 3, 'deflet' => 4.5, 'faixa' => false],
            default => ['teto' => 3.5, 'rake' => 4, 'deflet' => 4.5, 'faixa' => false],
        };
    }

    private static function roda(float $x, bool $dupla = false): string
    {
        $porcas = '';

        foreach ([0, 72, 144, 216, 288] as $ang) {
            $porcas .= '<circle cx="0" cy="-2.6" r=".62" fill="#7d858c" transform="rotate('.$ang.')"/>';
        }

        $furos = '';

        foreach ([0, 60, 120, 180, 240, 300] as $ang) {
            $furos .= '<ellipse cx="0" cy="-3.4" rx=".9" ry="1.3" fill="#50575d" transform="rotate('.$ang.')"/>';
        }

        $um = fn (float $dx) => '<g transform="translate('.($x + $dx).',52.6)">'
            .'<circle r="7.6" fill="url(#tkTire)"/>'
            .'<circle r="7.6" fill="none" stroke="#0e1013" stroke-width=".6"/>'
            .'<circle r="5.1" fill="url(#tkRim)"/>'
            .'<g class="wheel-spin">'.$furos.$porcas.'<circle r="1.7" fill="#9aa2a9"/><circle r=".7" fill="#50575d"/></g>'
            .'</g>';

        return ($dupla ? $um(-1.6) : '').$um($dupla ? 1.0 : 0.0);
    }

    /**
     * @param  string  $cor  texto de cor do cadastro do veículo
     * @param  string  $silhueta  'cavalo' | 'bau'
     */
    public static function render(?string $cor, ?string $marca = null, string $silhueta = 'cavalo'): string
    {
        $c = self::resolveColor($cor)['hex'];
        $luz = self::mix($c, '#FFFFFF', .42);
        $meio = self::mix($c, '#FFFFFF', .12);
        $sombra = self::mix($c, '#000000', .34);
        $fundo = self::mix($c, '#000000', .55);
        $claro = (self::lum($c) > 0.72);
        $detalhe = $claro ? self::mix($c, '#000000', .45) : self::mix($c, '#FFFFFF', .55);
        $p = self::perfil($marca);
        $bau = $silhueta === 'bau';

        $teto = $p['teto'];
        $rake = $p['rake'];

        // cabine: alta com sleeper (cavalo) ou baixa (caminhão baú)
        if ($bau) {
            $cabX = 100; $topo = 12 + $teto * .3;
            $cab = "M{$cabX},41 V".($topo + 2).' Q'.$cabX.','.$topo.' '.($cabX + 3).','.$topo.' H'.(134 - $rake * .4)
                .' Q'.(139 - $rake * .3).','.$topo.' '.(140.4 - $rake * .1).','.($topo + 5).' L143.4,34 Q144,38.5 141,40.6 L136,41 Z';
            $para = 'M'.(132 - $rake * .4).','.($topo + 2.4).' H'.(135 - $rake * .3).' Q'.(137.4 - $rake * .2).','.($topo + 2.4).' '.(138 - $rake * .1).','.($topo + 5).' L141.2,31 H129.5 Z';
            $janela = 'M'.($cabX + 8).','.($topo + 3).' H127 L130.5,31 H'.($cabX + 8).' Z';
            $sleeper = '';
        } else {
            $cabX = 93;
            $alto = 3 + $teto * .2;
            $cab = "M{$cabX},41 V".($alto + 3).' Q'.$cabX.','.$alto.' '.($cabX + 3).','.$alto.' H118 Q122,'.$alto.' 124,'.($alto + 4.5)
                .' L127.5,'.(9.6 + $teto * .1).' H'.(132 - $rake * .3).' Q'.(137.5 - $rake * .2).','.(9.6 + $teto * .1).' '.(138.6 - $rake * .1).','.(15 + $teto * .2)
                .' L142.6,34 Q143.2,38.6 140.4,40.6 L135,41 Z';
            $para = 'M128,'.(12.2 + $teto * .1).' H'.(132 - $rake * .3).' Q'.(135.4 - $rake * .2).','.(12.2 + $teto * .1).' '.(136.4 - $rake * .1).','.(15.4 + $teto * .2).' L140,30.6 H126.8 Z';
            $janela = 'M110,'.(13 + $teto * .2).' H124 L128.8,30.6 H110 Z';
            $sleeper = '<rect x="'.($cabX + 3.4).'" y="'.($alto + 12).'" width="10" height="9.2" rx="1.2" fill="url(#tkGlass)" stroke="'.$sombra.'" stroke-width=".5"/>';
        }

        $deflet = $bau ? '' :
            '<path d="M'.($cabX + .6).','.($alto + 1).' L'.($cabX + .6).','.($alto - $p['deflet']).' Q'.($cabX + .6).','.($alto - $p['deflet'] - 1.6).' '.($cabX + 3).','.($alto - $p['deflet'] - 1.6)
            .' H'.($cabX + 19).' Q'.($cabX + 24).','.($alto - $p['deflet'] - 1.4).' '.($cabX + 26.5).','.($alto + 3.6).' L'.($cabX + 24).','.($alto + 2.6).' H'.($cabX + .6).' Z" fill="url(#tkCab)"/>';

        // carroceria: carreta baú (cavalo) ou baú sobre o chassi (caminhão)
        if ($bau) {
            $carroceria = self::carroceriaBau(0, 98, 8.5, 40);
            $rodas = self::roda(22, true).self::roda(37, true).self::roda(130);
            $paralamas = '<path d="M13,52.6 Q13,43 22,43 H38 Q47,43 47,52.6" fill="none" stroke="#15181b" stroke-width="2.2"/>'
                .'<path d="M121,52.6 Q121,43 130,43 Q139,43 139,52.6" fill="none" stroke="#15181b" stroke-width="2.2"/>';
            $tanque = self::tanque(66, 80);
            $quinta = '';
            $chassiW = 142;
        } else {
            $carroceria = self::carroceriaBau(0, 89, 6, 38.4);
            $rodas = self::roda(14).self::roda(27).self::roda(40).self::roda(97, true).self::roda(110, true).self::roda(131);
            $paralamas = '<path d="M5,52.6 Q5,42.4 14,42.4 H41 Q50,42.4 50,52.6" fill="none" stroke="#15181b" stroke-width="2.2"/>'
                .'<path d="M88,52.6 Q88,42.6 97,42.6 H111 Q120,42.6 120,52.6" fill="none" stroke="#15181b" stroke-width="2.2"/>'
                .'<path d="M122,52.6 Q122,43.6 131,43.6 Q140,43.6 140,52.6" fill="none" stroke="#15181b" stroke-width="2.2"/>';
            $tanque = self::tanque(116, 126);
            $quinta = '<rect x="84" y="39.6" width="14" height="2" rx=".8" fill="#3b4148"/>';
            $chassiW = 142;
        }

        $faixa = $p['faixa'] ? '<rect x="'.($cabX + 1).'" y="25" width="'.(138 - $cabX).'" height="2.6" fill="'.$detalhe.'" opacity=".85"/>' : '';

        $svg = '<defs>'
            .'<linearGradient id="tkCab" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="'.$luz.'"/><stop offset=".22" stop-color="'.$meio.'"/><stop offset=".55" stop-color="'.$c.'"/><stop offset="1" stop-color="'.$sombra.'"/></linearGradient>'
            .'<linearGradient id="tkCabDark" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="'.$sombra.'"/><stop offset="1" stop-color="'.$fundo.'"/></linearGradient>'
            .'<linearGradient id="tkGlass" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#cfe4f3"/><stop offset=".45" stop-color="#6f93ad"/><stop offset="1" stop-color="#2f4a5e"/></linearGradient>'
            .'<linearGradient id="tkBau" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#ffffff"/><stop offset=".6" stop-color="#eceff2"/><stop offset="1" stop-color="#c9cfd5"/></linearGradient>'
            .'<linearGradient id="tkSteel" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#eef1f3"/><stop offset=".5" stop-color="#b6bdc4"/><stop offset="1" stop-color="#7c858d"/></linearGradient>'
            .'<radialGradient id="tkTire" cx=".5" cy=".5" r=".5"><stop offset=".62" stop-color="#2a2d31"/><stop offset="1" stop-color="#111316"/></radialGradient>'
            .'<radialGradient id="tkRim" cx=".42" cy=".4" r=".7"><stop offset="0" stop-color="#f4f6f7"/><stop offset=".6" stop-color="#bcc3c9"/><stop offset="1" stop-color="#8a939b"/></radialGradient>'
            .'</defs>'
            .'<ellipse cx="72" cy="60" rx="74" ry="2.6" fill="#000" opacity=".22"/>'
            .$carroceria
            // chassi, quinta roda, perna de apoio, tanques
            .'<rect x="0" y="41" width="'.$chassiW.'" height="3.6" rx="1" fill="#262b30"/>'
            .'<rect x="6" y="41" width="30" height="1" fill="#4a525a"/>'
            .$quinta
            .(! $bau ? '<rect x="60" y="44.6" width="1.6" height="8.4" fill="#2f353b"/><rect x="58" y="52.6" width="5.6" height="1.3" rx=".5" fill="#2f353b"/>' : '')
            .$tanque
            .'<rect x="1" y="44.4" width="44" height="1.5" fill="#1d2126"/>'
            // escapamento (atrás da cabine)
            .'<g><rect x="'.($cabX - 3.6).'" y="'.($bau ? 10 : 6).'" width="2.6" height="'.($bau ? 28 : 32).'" rx="1.1" fill="url(#tkSteel)"/>'
            .'<path d="M'.($cabX - 2.3).','.($bau ? 10 : 6).' q0,-3.4 2.6,-3.4" fill="none" stroke="#a8b0b7" stroke-width="1.5" stroke-linecap="round"/>'
            .'<g id="exhaust" fill="#c3ccd4"><circle cx="'.($cabX - 1.2).'" cy="'.($bau ? 6.4 : 2.4).'" r="1.8"/><circle cx="'.($cabX - 1.2).'" cy="'.($bau ? 6.4 : 2.4).'" r="1.8"/><circle cx="'.($cabX - 1.2).'" cy="'.($bau ? 6.4 : 2.4).'" r="1.8"/></g></g>'
            // cabine
            .$deflet
            .'<path d="'.$cab.'" fill="url(#tkCab)"/>'
            .'<path d="M'.($cabX).',34 H142.6 L143.2,38.6 Q143.2,40.6 140.4,40.6 L135,41 H'.$cabX.' Z" fill="url(#tkCabDark)"/>'
            .$faixa
            .$sleeper
            // para-brisa, janela da porta, divisões
            .'<path d="'.$para.'" fill="url(#tkGlass)" stroke="'.$sombra.'" stroke-width=".55"/>'
            .'<path d="'.$janela.'" fill="url(#tkGlass)" stroke="'.$sombra.'" stroke-width=".55"/>'
            .'<path d="M113,'.(15 + $teto * .2).' L119,'.(15 + $teto * .2).' L113,24 Z" fill="#fff" opacity=".22"/>'
            .'<path d="M'.($bau ? 108 : 108.6).',12 V40.2 M'.($bau ? 108 : 108.6).',34 H132" stroke="'.$sombra.'" stroke-width=".55" fill="none" opacity=".8"/>'
            .'<rect x="'.($bau ? 111 : 112).'" y="29.6" width="4.6" height="1.2" rx=".6" fill="'.$fundo.'"/>'
            // espelho, degrau, faróis, para-choque
            .'<path d="M133.4,'.(17 + $teto * .2).' L136.6,'.(18.6 + $teto * .2).'" stroke="#2a2e33" stroke-width="1" stroke-linecap="round"/>'
            .'<rect x="135.4" y="'.(17.6 + $teto * .2).'" width="2.6" height="8.4" rx="1.1" fill="#23272c"/><rect x="135.9" y="'.(18.4 + $teto * .2).'" width=".8" height="6" rx=".4" fill="#9fb4c4" opacity=".6"/>'
            .'<rect x="113" y="39.6" width="10" height="1.8" rx=".6" fill="#8d959c"/>'
            .'<ellipse id="headlight-glow" cx="147.2" cy="31" rx="5.6" ry="2.6" fill="#ffe08a" opacity=".55"/>'
            .'<rect x="140.2" y="28.8" width="3.6" height="3.4" rx="1.1" fill="#fff3c4" stroke="#caa84a" stroke-width=".4"/>'
            .'<rect x="140.8" y="33.4" width="2.6" height="1.5" rx=".6" fill="#ffb547"/>'
            .'<rect x="137" y="37.6" width="8.4" height="3.6" rx="1.4" fill="#2b3036"/><rect x="137.6" y="38.2" width="7.2" height="1" rx=".5" fill="#4d555d"/>'
            .'<rect x="'.($cabX + 28).'" y="'.($bau ? 12 : 9.6).'" width="6.4" height="1.5" rx=".6" fill="#ffb547" opacity=".95"/>'
            // para-lamas e rodas
            .$paralamas
            .$rodas
            // para-barros traseiro
            .'<rect x="'.($bau ? 3 : 1.4).'" y="47" width="1.2" height="9" fill="#15181b"/>';

        // ids únicos por caminhão (vários na mesma página não podem repetir ids)
        $uid = substr(md5($c.$silhueta.$marca), 0, 5);

        return str_replace(['id="tk', 'url(#tk'], ['id="tk'.$uid, 'url(#tk'.$uid], $svg);
    }

    private static function lum(string $hex): float
    {
        [$r, $g, $b] = self::rgb($hex);

        return (0.2126 * $r + 0.7152 * $g + 0.0722 * $b) / 255;
    }

    private static function carroceriaBau(float $x0, float $x1, float $topo, float $base): string
    {
        $h = $base - $topo;
        $costelas = '';

        for ($x = $x0 + 4; $x < $x1 - 2; $x += 4.4) {
            $costelas .= '<rect x="'.round($x, 2).'" y="'.($topo + 2).'" width="1" height="'.($h - 4).'" fill="#dfe3e7"/>';
        }

        $fita = '';

        for ($x = $x0 + 1; $x < $x1 - 2; $x += 6) {
            $fita .= '<rect x="'.$x.'" y="'.($base - 3.6).'" width="3" height="1.6" fill="#d3262b"/><rect x="'.($x + 3).'" y="'.($base - 3.6).'" width="3" height="1.6" fill="#f2f3f4"/>';
        }

        return '<rect x="'.$x0.'" y="'.$topo.'" width="'.($x1 - $x0).'" height="'.$h.'" rx="1.6" fill="url(#tkBau)"/>'
            .'<rect x="'.$x0.'" y="'.$topo.'" width="'.($x1 - $x0).'" height="2" rx="1" fill="#d7dce0"/>'
            .$costelas
            .'<rect x="'.$x0.'" y="'.($base - 2).'" width="'.($x1 - $x0).'" height="2" fill="#bfc6cc"/>'
            .$fita
            .'<rect x="'.($x0 + 0.4).'" y="'.($topo + 2).'" width="1.1" height="'.($h - 4).'" fill="#c3c9cf"/>';
    }

    private static function tanque(float $x0, float $x1): string
    {
        return '<rect x="'.$x0.'" y="44.6" width="'.($x1 - $x0).'" height="8.2" rx="3.4" fill="url(#tkSteel)"/>'
            .'<rect x="'.($x0 + 3).'" y="44.6" width=".9" height="8.2" fill="#6f777e"/><rect x="'.($x1 - 4).'" y="44.6" width=".9" height="8.2" fill="#6f777e"/>';
    }
}
