<?php

/**
 * RefactoryTools
 * -----------------------------------------------------------------------------
 * Ações sobre compartilhamentos de agenda.
 *
 * Recebe POST do formulário da tela de compartilhamentos e volta para ela.
 * Cada ação é verificada duas vezes: o direito de perfil aqui (quem pode
 * conceder, quem pode pedir) e a relação com a linha dentro de
 * `Share::decide()` / `Share::remove()` (só o dono decide sobre a própria
 * agenda). A segunda é a que vale se algum chamador futuro esquecer a
 * primeira.
 *
 * CSRF: NÃO chamar `Session::checkCSRF()` aqui. No GLPI 11 o
 * `CheckCsrfListener` do kernel já valida o token em todo POST não-stateless,
 * antes de o arquivo legado ser incluído, e o CONSOME (validateCSRF() faz
 * unset do token, e o listener não pede `preserve_token` no caminho de
 * formulário). Uma segunda checagem aqui encontra o token já gastou e derruba
 * 100% das ações com 403 — foi exatamente o que aconteceu ao testar contra o
 * GLPI 11.0.9 antes desta correção. O token continua sendo obrigatório: quem
 * garante é o kernel, e `$PLUGIN_HOOKS['csrf_compliant']` em setup.php declara
 * que este plugin envia o token em seus formulários.
 */

include('../../../inc/includes.php');

use GlpiPlugin\Refactorytools\Right;
use GlpiPlugin\Refactorytools\Settings;
use GlpiPlugin\Refactorytools\Share;

Session::checkRight(Right::NAME, Right::USE_REFACTORYTOOLS);

$me        = (int) Session::getLoginUserID();
$action    = (string) ($_POST['action'] ?? '');
$shares_id = (int) ($_POST['shares_id'] ?? 0);
$users_id  = (int) ($_POST['users_id'] ?? 0);
$level     = (string) ($_POST['level'] ?? Settings::get('default_share_level'));

$ok      = false;
$message = '';

switch ($action) {
    case 'grant':
        if (!Right::has(Right::SHARE_OWN)) {
            break;
        }
        $ok      = Share::grant($me, $users_id, $level);
        $message = $ok
            ? __('Access granted.', 'refactorytools')
            : __('Could not grant access.', 'refactorytools');
        break;

    case 'request':
        if (!Right::has(Right::REQUEST_ACCESS) || !Settings::isTrue('allow_self_request')) {
            break;
        }
        // O pedido é gravado com o outro usuário como DONO e o solicitante
        // como destinatário — a ordem dos argumentos é o que garante que o
        // pedido caia na caixa de decisão da pessoa certa.
        $ok      = Share::request($users_id, $me, $level);
        $message = $ok
            ? __('Request sent. It takes effect when the person accepts it.', 'refactorytools')
            : __('Could not send the request.', 'refactorytools');
        break;

    case 'accept':
        $ok      = Share::decide($shares_id, Share::STATUS_ACCEPTED, $me);
        $message = $ok
            ? __('Request accepted.', 'refactorytools')
            : __('Could not accept the request.', 'refactorytools');
        break;

    case 'refuse':
        $ok      = Share::decide($shares_id, Share::STATUS_REFUSED, $me);
        $message = $ok
            ? __('Request refused.', 'refactorytools')
            : __('Could not refuse the request.', 'refactorytools');
        break;

    case 'revoke':
        $ok      = Share::decide($shares_id, Share::STATUS_REVOKED, $me);
        $message = $ok
            ? __('Access revoked.', 'refactorytools')
            : __('Could not revoke access.', 'refactorytools');
        break;

    case 'delete':
        $ok      = Share::remove($shares_id, $me);
        $message = $ok
            ? __('Share removed.', 'refactorytools')
            : __('Could not remove the share.', 'refactorytools');
        break;

    default:
        $message = __('Unknown action.', 'refactorytools');
}

if ($message === '') {
    $message = __('You are not allowed to perform this action.', 'refactorytools');
}

Session::addMessageAfterRedirect(htmlescape($message), false, $ok ? INFO : ERROR);

Html::back();
