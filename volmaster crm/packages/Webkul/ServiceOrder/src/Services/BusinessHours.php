<?php

namespace Webkul\ServiceOrder\Services;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Horário comercial da oficina. Os prazos das barreiras contam somente as
 * horas em que a oficina está aberta (alertas críticos ignoram isso).
 */
class BusinessHours
{
    /** @var array<string, array{0: string, 1: string}> */
    protected array $schedule;

    public function __construct(?array $schedule = null)
    {
        $this->schedule = $schedule ?? (array) WorkshopSettings::get('horario_comercial', []);
    }

    /**
     * Soma $minutes minutos de horário comercial a partir de $from.
     */
    public function addMinutes(CarbonInterface $from, int|float $minutes): Carbon
    {
        $cursor = Carbon::instance($from)->copy();

        if ($minutes <= 0) {
            return $cursor;
        }

        $remaining = (int) round($minutes * 60); // segundos (aceita fração: o modo de teste gera 1,5 min)

        // Sem horário configurado: conta em relógio corrido.
        if (empty($this->schedule)) {
            return $cursor->addSeconds($remaining);
        }

        for ($guard = 0; $guard < 800; $guard++) {
            $window = $this->windowFor($cursor);

            if ($window) {
                [$start, $end] = $window;

                if ($cursor->lt($start)) {
                    $cursor = $start->copy();
                }

                if ($cursor->lt($end)) {
                    $available = $end->getTimestamp() - $cursor->getTimestamp();

                    if ($remaining <= $available) {
                        return $cursor->addSeconds($remaining);
                    }

                    $remaining -= $available;
                }
            }

            $cursor = $cursor->copy()->addDay()->startOfDay();
        }

        return $cursor;
    }

    /**
     * Minutos de horário comercial entre $from e $to (0 se $to <= $from).
     */
    public function diffMinutes(CarbonInterface $from, CarbonInterface $to): float
    {
        if ($to->lte($from)) {
            return 0;
        }

        if (empty($this->schedule)) {
            return ($to->getTimestamp() - $from->getTimestamp()) / 60;
        }

        $seconds = 0;
        $day = Carbon::instance($from)->copy()->startOfDay();
        $last = Carbon::instance($to)->copy()->startOfDay();

        while ($day->lte($last)) {
            $window = $this->windowFor($day);

            if ($window) {
                $start = $window[0]->max($from);
                $end = $window[1]->min($to);

                if ($end->gt($start)) {
                    $seconds += $end->getTimestamp() - $start->getTimestamp();
                }
            }

            $day = $day->copy()->addDay();
        }

        return $seconds / 60;
    }

    /**
     * Fechamento do expediente do dia de $from; se a oficina já fechou (ou
     * está fechada no dia), o fechamento do próximo dia útil. Sem horário
     * configurado, vale o fim do dia corrido.
     */
    public function endOfBusinessDay(CarbonInterface $from): Carbon
    {
        $cursor = Carbon::instance($from)->copy();

        if (empty($this->schedule)) {
            return $cursor->endOfDay()->startOfSecond();
        }

        for ($guard = 0; $guard < 60; $guard++) {
            $window = $this->windowFor($cursor);

            if ($window && $cursor->lt($window[1])) {
                return $window[1]->copy();
            }

            $cursor = $cursor->copy()->addDay()->startOfDay();
        }

        return $cursor;
    }

    /**
     * Avança $days dias úteis (dias em que a oficina abre), mantendo a hora
     * dentro do expediente do dia de chegada.
     */
    public function addBusinessDays(CarbonInterface $from, int $days): Carbon
    {
        $cursor = Carbon::instance($from)->copy();

        if (empty($this->schedule)) {
            return $cursor->addDays($days);
        }

        $time = $cursor->format('H:i:s');
        $moved = 0;

        for ($guard = 0; $guard < 400 && $moved < $days; $guard++) {
            $cursor = $cursor->copy()->addDay()->startOfDay();

            if ($this->windowFor($cursor)) {
                $moved++;
            }
        }

        $cursor->setTimeFromTimeString($time);
        $window = $this->windowFor($cursor);

        if ($window && $cursor->gt($window[1])) {
            return $window[1]->copy();
        }

        return $cursor;
    }

    /**
     * A oficina abre neste dia?
     */
    public function isBusinessDay(CarbonInterface $day): bool
    {
        return empty($this->schedule) ? $day->isWeekday() : (bool) $this->windowFor(Carbon::instance($day));
    }

    /**
     * Quantos dias de funcionamento há de $from a $to (inclusive).
     */
    public function businessDaysBetween(CarbonInterface $from, CarbonInterface $to): int
    {
        $day = Carbon::instance($from)->copy()->startOfDay();
        $last = Carbon::instance($to)->copy()->startOfDay();
        $count = 0;

        while ($day->lte($last)) {
            if ($this->isBusinessDay($day)) {
                $count++;
            }

            $day = $day->copy()->addDay();
        }

        return $count;
    }

    /**
     * O n-ésimo dia útil (segunda a sexta) do mês de $month.
     */
    public static function nthWeekday(CarbonInterface $month, int $n): Carbon
    {
        $day = Carbon::instance($month)->copy()->startOfMonth();
        $found = 0;

        while (true) {
            if ($day->isWeekday() && ++$found === $n) {
                return $day->endOfDay();
            }

            $day = $day->copy()->addDay();
        }
    }

    /**
     * A oficina está aberta neste instante?
     */
    public function isOpen(CarbonInterface $at): bool
    {
        if (empty($this->schedule)) {
            return true;
        }

        $window = $this->windowFor(Carbon::instance($at));

        return $window && $at->gte($window[0]) && $at->lt($window[1]);
    }

    /**
     * [abertura, fechamento] do dia de $day, ou null se estiver fechada.
     *
     * @return array{0: Carbon, 1: Carbon}|null
     */
    protected function windowFor(CarbonInterface $day): ?array
    {
        $hours = $this->schedule[(string) $day->isoWeekday()] ?? null;

        if (! $hours || count($hours) < 2) {
            return null;
        }

        $start = Carbon::instance($day)->copy()->setTimeFromTimeString($hours[0]);
        $end = Carbon::instance($day)->copy()->setTimeFromTimeString($hours[1]);

        return $end->gt($start) ? [$start, $end] : null;
    }
}
