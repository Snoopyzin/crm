<?php

namespace Webkul\ServiceOrder\Services\Indicators;

/**
 * Resultado do cálculo de um indicador num período. $realizado é null
 * quando não há dado (nenhuma OS ou registro no período): nesse caso o
 * indicador sai da conta do bônus e os pesos dos demais são reescalonados.
 */
class IndicatorResult
{
    /**
     * @param  int[]  $osIds  OS que formam o número (clique na linha do painel)
     */
    public function __construct(
        public ?float $realizado,
        public ?float $numerador = null,
        public ?float $denominador = null,
        public array $osIds = [],
        public ?string $detalhe = null,
    ) {}

    public static function none(?string $detalhe = null): self
    {
        return new self(null, null, null, [], $detalhe ?? 'Sem dados no período');
    }

    /**
     * Razão em percentual (uma casa decimal); sem denominador, sem dado.
     */
    public static function ratio(float|int $numerator, float|int $denominator, array $osIds = []): self
    {
        if ($denominator <= 0) {
            return self::none();
        }

        return new self(round($numerator / $denominator * 100, 1), (float) $numerator, (float) $denominator, $osIds);
    }

    /**
     * Valor direto (contagem, média, dias...).
     */
    public static function value(float|int $value, array $osIds = [], ?float $numerator = null, ?float $denominator = null): self
    {
        return new self(round((float) $value, 1), $numerator, $denominator, $osIds);
    }

    public function hasData(): bool
    {
        return $this->realizado !== null;
    }

    public function toArray(): array
    {
        return [
            'realizado' => $this->realizado,
            'numerador' => $this->numerador,
            'denominador' => $this->denominador,
            'os_ids' => $this->osIds,
            'detalhe' => $this->detalhe,
        ];
    }
}
