<?php

/**
 * Planner
 * -----------------------------------------------------------------------------
 * Casca mínima de `CommonDBTM` só para o sistema de notificação do GLPI ter
 * um item de verdade para apontar (`NotificationEvent::raiseEvent()` espera
 * um `CommonDBTM`, e o nome da classe de destino é resolvido a partir de
 * `$item::class` — ver `NotificationTargetEventNoteItem`).
 *
 * Não é a fonte de dados: quem grava/lê a nota continua sendo `EventNotes`
 * (consultas em lote, sem o peso do ciclo de vida de um CommonDBTM — direito
 * por entidade, histórico, etc., que não fazem sentido para uma nota de
 * calendário). Esta classe só existe pelo instante do disparo do evento, com
 * os campos já preenchidos à mão a partir da linha que `EventNotes::save()`
 * acabou de gravar.
 */

namespace GlpiPlugin\Planner;

use CommonDBTM;

final class EventNoteItem extends CommonDBTM
{
    public static function getTable($classname = null): string
    {
        return EventNotes::getTable();
    }

    public static function getTypeName($nb = 0)
    {
        return __('Note', 'planner');
    }
}
