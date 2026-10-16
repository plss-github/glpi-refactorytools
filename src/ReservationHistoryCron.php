<?php

namespace GlpiPlugin\Refactorytools;

use CronTask;

final class ReservationHistoryCron
{
    public const RETENTION_DAYS = 30;

    public static function cronClearHistory(CronTask $task): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $cutoff = date('Y-m-d H:i:s', strtotime('-' . self::RETENTION_DAYS . ' days'));

        $DB->delete(ReservationHistory::getTable(), [
            'date' => ['<', $cutoff],
        ]);

        $deleted = (int) $DB->affectedRows();
        if ($deleted > 0) {
            $task->addVolume($deleted);
            return 1;
        }

        return 0;
    }

    public static function cronInfo(string $name): array
    {
        return [
            'description' => sprintf(
                __('Clear reservation history older than %d days', 'refactorytools'),
                self::RETENTION_DAYS
            ),
        ];
    }
}
