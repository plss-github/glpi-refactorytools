<?php

/**
 * RefactoryTools
 * -----------------------------------------------------------------------------
 * Notificação por e-mail quando alguém grava uma nota num compromisso (ver
 * `EventNotes::save()`, que dispara `NotificationEvent::raiseEvent('new_note', ...)`).
 *
 * O nome desta classe não é livre: `NotificationTarget::getInstanceClass()`
 * resolve `NotificationTarget<NomeDaClasse>` DENTRO DO MESMO NAMESPACE do
 * item (`GlpiPlugin\Refactorytools\EventNoteItem` -> `GlpiPlugin\Refactorytools\NotificationTargetEventNoteItem`),
 * não o prefixo `Plugin<Nome>NotificationTarget<Classe>` usado por plugins
 * antigos sem namespace — conferido lendo `NotificationTarget.php` do core.
 */

namespace GlpiPlugin\Refactorytools;

use NotificationTarget;
use User;

class NotificationTargetEventNoteItem extends NotificationTarget
{
    /**
     * Marcador para achar de volta a linha certa em `addSpecificTargets()` —
     * ver o comentário longo lá embaixo sobre por que a resolução do
     * destinatário não pode acontecer em `addAdditionalTargets()`.
     * Qualquer valor serve, desde que não bata com nenhuma das constantes
     * `Notification::GLOBAL_ADMINISTRATOR/ENTITY_ADMINISTRATOR/ITEM_TECH_IN_CHARGE/ITEM_TECH_GROUP_IN_CHARGE/ITEM_USER/AUTHOR`
     * (1, 11, 5, 23, 6, 3 no core 11.0.9) — a coluna é `int unsigned`, por
     * isso um número alto em vez de um valor negativo.
     */
    private const TARGET_OWNER = 9999;

    public function getEvents()
    {
        return [
            'new_note' => __('New note', 'refactorytools'),
        ];
    }

    /**
     * Só REGISTRA a opção "Dono do compromisso" na lista que a tela de
     * administração usa para montar o checkbox de destinatários — não
     * resolve nada ainda. `addTarget()` roda dentro do CONSTRUTOR de
     * `NotificationTarget`, antes de `setEvent()` ter sido chamado por
     * `NotificationEvent::raiseEvent()`; qualquer método que precise do
     * evento (como `addUserByField()`, que acabou tentando resolver a
     * classe do modo de envio antes de ela existir — foi o que gerou "Class
     * name must be a valid object or a string" ao testar) tem que ficar
     * fora daqui.
     */
    public function addAdditionalTargets($event = '')
    {
        $this->addTarget(self::TARGET_OWNER, __('Schedule owner', 'refactorytools'));
    }

    /**
     * Resolução de verdade do destinatário — chamada por `addForTarget()`
     * (via `NotificationEventAbstract::raise()`, já depois de `setEvent()`)
     * para cada linha de `glpi_notificationtargets` da notificação, que
     * `plugin_refactorytools_seed_note_notification()` semeia apontando para
     * `self::TARGET_OWNER`.
     */
    public function addSpecificTargets($data, $options)
    {
        if ((int) ($data['items_id'] ?? 0) === self::TARGET_OWNER) {
            $this->addUserByField('users_id_owner', false);
        }
    }

    public function addDataForTemplate($event, $options = [])
    {
        $note = $this->obj;

        $author = new User();
        $author_name = $author->getFromDB((int) $note->getField('users_id_author'))
            ? $author->getFriendlyName()
            : '';

        $itemtype = (string) $note->getField('itemtype');
        $items_id = (int) $note->getField('items_id');
        $url      = '';

        $item = getItemForItemtype($itemtype);
        if ($item !== false && method_exists($item, 'getFormURLWithID')) {
            $url = $item::getFormURLWithID($items_id);
        }

        $this->data['##refactorytoolsnote.content##'] = (string) $note->getField('note');
        $this->data['##refactorytoolsnote.author##']   = $author_name;
        $this->data['##refactorytoolsnote.url##']      = $url !== '' ? $this->getUrlBase() . $url : '';
    }
}
