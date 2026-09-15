<?php

namespace App\Support\Panel;

use Carbon\CarbonInterface;

/**
 * Short relative dates of the panel in the condominium timezone: "hoje 09:38", "ontem 18:20", "13 set".
 */
final class PanelDate
{
    /**
     * @var list<string>
     */
    public const MONTHS = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];

    /**
     * Today and yesterday always show the time; older dates show day and month (plus the time when requested)
     * and the year when it is not the current one.
     */
    public static function relative(CarbonInterface $moment, bool $withTime = false): string
    {
        $timezone = config('condo.timezone');
        $local = $moment->copy()->setTimezone($timezone);
        $today = now($timezone)->startOfDay();

        if ($local->isSameDay($today)) {
            return 'hoje '.$local->format('H:i');
        }

        if ($local->isSameDay($today->copy()->subDay())) {
            return 'ontem '.$local->format('H:i');
        }

        $date = $local->format('d').' '.self::MONTHS[$local->month - 1];

        if ($local->year !== $today->year) {
            $date .= ' '.$local->year;
        }

        return $withTime ? $date.' '.$local->format('H:i') : $date;
    }
}
