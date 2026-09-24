<?php

/**
 * RefactoryTools
 * -----------------------------------------------------------------------------
 * Resposta de UM convidado a uma Reunião ("Aceitar" / "Não posso
 * participar") — botões do popover, ver `renderPopover()` em
 * public/js/refactorytools.js.
 *
 * `MeetingGuest::respond()` só atualiza a linha de quem está LOGADO; não há
 * `users_id` no POST porque não faz sentido responder em nome de outra
 * pessoa.
 */

use GlpiPlugin\Refactorytools\MeetingGuest;
use GlpiPlugin\Refactorytools\Right;

Session::checkRight(Right::NAME, Right::USE_REFACTORYTOOLS);

header('Content-Type: application/json; charset=UTF-8');

$items_id = (int) ($_POST['items_id'] ?? 0);
$status   = (string) ($_POST['status'] ?? '');

$ok = $items_id > 0 && MeetingGuest::respond($items_id, $status);

echo json_encode(['ok' => $ok]);
