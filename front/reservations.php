<?php

/**
 * RefactoryTools
 * -----------------------------------------------------------------------------
 * Tela de Reservas remodelada (Ferramentas > Reservas).
 *
 * O direito exigido é o do CORE (`reservation`), não o do plugin: esta tela
 * substitui uma tela nativa e não deve pedir uma permissão nova de quem já
 * podia reservar. O direito do plugin governa a agenda, que é outro assunto.
 */

use Glpi\Exception\Http\AccessDeniedHttpException;
use GlpiPlugin\Refactorytools\ReservationMenu;
use GlpiPlugin\Refactorytools\ReservationView;

if (!ReservationView::canView()) {
    // `Html::displayRightError()` está depreciado desde o GLPI 11 (avisa e
    // faz exatamente isto por baixo) — lançar direto evita o aviso de
    // depreciação no log a cada acesso sem direito, e continua funcionando
    // se o método for removido numa versão futura do core.
    throw new AccessDeniedHttpException();
}

Html::header(
    Reservation::getTypeName(Session::getPluralNumber()),
    $_SERVER['PHP_SELF'],
    'tools',
    ReservationMenu::getMenuItemKey(),
    // Sem opção de submenu: o item "Reservas" já é o destino, e uma opção de
    // mesmo nome só duplicaria a migalha na trilha.
    ''
);

ReservationView::show();

Html::footer();
