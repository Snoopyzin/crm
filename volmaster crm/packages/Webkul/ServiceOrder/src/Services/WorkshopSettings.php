<?php

namespace Webkul\ServiceOrder\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Configurações da oficina (tabela workshop_settings). Os valores padrão da
 * mentoria ficam em DEFAULTS; o que a oficina grava sobrescreve o padrão.
 * Nada de prazo, percentual ou horário fica fixo nas regras de negócio.
 */
class WorkshopSettings
{
    public const DEFAULTS = [
        // Chave geral do piloto de indicadores e alertas: enquanto desligada,
        // a oficina usa o fluxo antigo (sem bloqueios, barreiras nem
        // restrição de valores). O piloto liga numa oficina por vez.
        'piloto_ativo' => false,

        // Modo de teste das barreiras (A6): divide os prazos por 'modo_teste_divisor'. Só vale
        // com o piloto ligado e só na oficina em que foi marcado. Ver Services/ModoTeste.php.
        'modo_teste_barreiras' => false,
        // Sub-opção: divide também o tempo padrão dos serviços (cronômetro do técnico).
        'modo_teste_tempo_padrao' => false,
        'modo_teste_divisor' => 10,

        // ISO weekday (1 = segunda ... 7 = domingo) => [início, fim]. Dia
        // ausente = oficina fechada. Prazos das barreiras contam só aqui.
        'horario_comercial' => [
            '1' => ['08:00', '18:00'],
            '2' => ['08:00', '18:00'],
            '3' => ['08:00', '18:00'],
            '4' => ['08:00', '18:00'],
            '5' => ['08:00', '18:00'],
            '6' => ['08:00', '12:00'],
        ],

        // Horário de silêncio das notificações (exceto alertas críticos).
        'silencio_ativo' => true,

        // O dono pode pedir também um resumo das barreiras vencidas a cada 2 horas (desligado por padrão).
        'dono_resumo_barreiras' => false,
        'silencio_inicio' => '20:00',
        'silencio_fim' => '07:00',

        // Alertas do mesmo destinatário dentro dessa janela viram uma só notificação.
        'agrupar_alertas_minutos' => 5,

        // SMS de reserva.
        'sms_provedor' => 'log',
        'sms_limite_mensal' => 200,
        'sms_custo_unitario' => 0.12,
        'sms_nao_aberto_minutos' => 10,
        'sms_twilio_sid' => '',
        'sms_twilio_token' => '',
        'sms_twilio_from' => '',

        // Web Push (chaves geradas por `php artisan workshop:vapid-keys`).
        'push_vapid_public' => '',
        'push_vapid_private' => '',
        'push_vapid_subject' => '',

        // Ficha de Enquadramento (capítulo 3.1): o que muda de uma oficina para outra.
        'ficha_razao_social' => '',
        'ficha_cnpj' => '',
        'ficha_cidade' => '',
        // ate_6 | 7_a_15 | 16_ou_mais colaboradores
        'ficha_porte' => 'ate_6',
        'ficha_boxes' => null,
        'ficha_elevadores' => null,
        'ficha_veiculo_externo' => false,
        'valor_hora_mao_obra' => null,
        'diaria_patio_valor' => null,
        'diaria_patio_horas_pronto' => null,
        'diaria_patio_horas_nao_autorizado' => null,
        // Validade do cadastro do cliente: a última análise de crédito vale por tantos dias (reunião de 19/11/2026).
        'cadastro_validade_dias' => 90,
        'garantia_servico_dias' => 90,
        'garantia_pecas_dias' => 90,
        'horario_cafe' => '09:00',
        'horario_lanche' => '15:00',
        'dono_horas_prospeccao' => 8,
        // Valor cheio do bônus por cargo, em % do salário base (cargo => %).
        'bonus_cheio_percentual' => [],
        // Faturamento (ou resultado) mínimo do mês para pagar a parte de resultado da Fase 2.
        'gatilho_resultado' => null,
        'inicio_mentoria' => null,

        // Parâmetros financeiros (capítulo 10.1): alimentam a DRE e o preço mínimo da hora e da peça.
        'pct_impostos' => null,
        'pct_comissoes' => null,
        'pct_desp_var_pecas' => null,
        'lucro_desejado' => null,
        'margem_desejada_pecas' => null,
        'produtividade_alvo' => 80,
        'horas_mes_tecnico' => 176,
        'encargos_percentual' => null,
        // Horário limite do relatório diário do financeiro ao dono.
        'relatorio_financeiro_horario' => '17:30',

        // Metas e parâmetros da Ficha de Enquadramento usados pelos indicadores.
        'meta_faturamento_mensal' => null,
        'auditorias_amostragem_mes' => 4,
        'zel_banheiros_por_dia' => 3,

        // Indicadores do técnico: verde até 100% do tempo padrão, amarelo até
        // a margem, vermelho acima. Meta de eficiência em %.
        'tecnico_margem_percentual' => 120,
        'tecnico_meta_eficiencia' => 80,

        'causas_atraso_diagnostico' => [
            'Falta de ferramenta',
            'Falta de informação técnica',
            'Falta de qualificação',
            'Defeito intermitente',
            'Espera de peça ou equipamento',
            'Outra',
        ],

        'motivos_atraso_operacao' => [
            'Falta de ferramenta',
            'Peça com defeito ou errada',
            'Serviço mais complexo que o previsto',
            'Falta de informação técnica',
            'Falta de qualificação',
            'Outro',
        ],

        'motivos_pausa' => [
            'Aguardando peça',
            'Ferramenta',
            'Almoço',
            'Outro serviço',
            'Outro',
        ],

        'meios_aprovacao' => ['WhatsApp', 'Telefone', 'Presencial', 'E-mail', 'Portal do cliente'],
    ];

    /** Chaves guardadas criptografadas. */
    protected const SECRETS = ['sms_twilio_token', 'push_vapid_private'];

    protected static ?array $cache = null;

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = static::all();

        return $all[$key] ?? $default ?? (static::DEFAULTS[$key] ?? null);
    }

    public static function all(): array
    {
        if (static::$cache !== null) {
            return static::$cache;
        }

        $values = static::DEFAULTS;

        if (Schema::hasTable('workshop_settings')) {
            foreach (DB::table('workshop_settings')->get() as $row) {
                $decoded = json_decode((string) $row->valor, true);

                $value = json_last_error() === JSON_ERROR_NONE ? $decoded : $row->valor;

                if (in_array($row->chave, static::SECRETS, true) && is_string($value) && $value !== '') {
                    try {
                        $value = Crypt::decryptString($value);
                    } catch (\Throwable) {
                        $value = '';
                    }
                }

                $values[$row->chave] = $value;
            }
        }

        return static::$cache = $values;
    }

    public static function set(string $key, mixed $value): void
    {
        $stored = $value;

        if (in_array($key, static::SECRETS, true) && is_string($value) && $value !== '') {
            $stored = Crypt::encryptString($value);
        }

        DB::table('workshop_settings')->updateOrInsert(
            ['chave' => $key],
            ['valor' => json_encode($stored, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]
        );

        static::flush();
    }

    public static function flush(): void
    {
        static::$cache = null;
    }
}
