<?php

namespace GlpiPlugin\Refactorytools;

use CronTask;

final class ReservationHistoryCron
{
    public static function cronClearHistory(CronTask $task): int
    {
        global $DB;

        $cutoff = date('Y-m-d H:i:s', strtotime('-30 days'));

        $DB->delete(ReservationHistory::getTable(), [
            ['date', '<', $cutoff],
        ]);

        $task->addVolume(1);

        return 1;
    }

    public static function cronInfo(string $name): array
    {
        return [
            'description' => __('Clear reservation history older than 30 days', 'refactorytools'),
        ];
    }
}
