<?php

/**
 * Planner
 * -----------------------------------------------------------------------------
 * Redireciona `/front/planning.php` para a tela do plugin.
 *
 * O hook `redefine_menus` troca para onde o ITEM DE MENU aponta, mas não
 * cobre quem chega na URL antiga: um favorito, um link colado num chamado, o
 * atalho de teclado do core. Sem isto, "substituir o planejamento" seria
 * verdade só pelo menu, e o usuário cairia na tela antiga sem entender por quê.
 *
 * O que NÃO é redirecionado, e por quê:
 *
 *  - `genical`          exportação iCal, consumida por clientes de calendário
 *                       externos com token na URL. Redirecionar quebraria as
 *                       assinaturas de agenda já configuradas.
 *  - `checkavailability` popup de disponibilidade, aberto de dentro do
 *                       formulário de tarefa. É outra tela, que o plugin não
 *                       substitui.
 *  - `_in_modal`        a tela nativa aberta dentro de um modal.
 *
 * Roda no `post_init`, antes de qualquer saída, então o redirect não esbarra
 * em "headers already sent".
 *
 * POR QUE NÃO USA `Html::redirect()`: no GLPI 11 esse método não manda um
 * header — ele lança `Glpi\Exception\RedirectException`, que é convertida em
 * resposta HTTP pelo `RedirectExceptionListener`, inscrito em
 * `KernelEvents::EXCEPTION`. Esse evento só existe durante o TRATAMENTO da
 * requisição, e `post_init` roda antes, no BOOT do kernel
 * (`InitializePlugins::onPostBoot()` → `Plugin::init()`). A exceção escapa sem
 * ninguém para pegá-la e o usuário recebe 500 — foi exatamente o que aconteceu
 * ao testar contra o GLPI 11.0.9. Não há hook de plugin na fase de requisição
 * antes do arquivo legado ser incluído, então o header cru é o caminho.
 */

namespace GlpiPlugin\Planner;

use Session;

final class NativeRedirect
{
    /**
     * Parâmetros que indicam um uso da tela nativa que o plugin não substitui.
     */
    private const PASSTHROUGH_PARAMS = [
        'genical',
        'checkavailability',
        '_in_modal',
        'ajax',
    ];

    public static function handle(): void
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        if (PHP_SAPI === 'cli') {
            return;
        }

        foreach (self::PASSTHROUGH_PARAMS as $param) {
            if (isset($_GET[$param]) || isset($_POST[$param])) {
                return;
            }
        }

        // Sessão ainda não autenticada: deixar o core cuidar do login, senão o
        // usuário é jogado numa tela do plugin que vai recusá-lo de qualquer
        // forma e ele perde a URL de destino.
        if (!Session::getLoginUserID()) {
            return;
        }

        $target = self::getTarget();

        if ($target === null) {
            return;
        }

        $target = $CFG_GLPI['root_doc'] . $target;

        // Defensivo: se algo já tiver emitido saída, um header aqui viraria um
        // warning e a página ficaria meio renderizada. Melhor não redirecionar.
        if (headers_sent()) {
            return;
        }

        // Nada foi escrito na sessão neste ponto, mas fechá-la explicitamente
        // evita depender do shutdown handler para persistir o que o boot já
        // tenha gravado.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        header('Location: ' . $target, true, 302);
        exit;
    }

    /**
     * Para onde esta requisição deve ser desviada, ou null para deixá-la
     * seguir para o core.
     *
     * Cada tela substituída tem sua própria chave de configuração e seu
     * próprio direito: quem pode usar o Planner não necessariamente pode
     * reservar, e vice-versa.
     */
    private static function getTarget(): ?string
    {
        $path = self::getRequestPath();

        if ($path === null) {
            return null;
        }

        if (
            str_ends_with($path, '/front/planning.php')
            && Settings::isTrue('override_native_planning')
            && Right::canUse()
        ) {
            return Menu::getPlannerPage();
        }

        // `reservation.php` é a tela de calendário de UM item reservável, que
        // é o destino do item de menu "Reservas". `reservationitem.php` (a
        // lista de itens reserváveis) NÃO é redirecionada: é a tela de
        // administração dos itens, que o plugin não substitui e para a qual
        // a própria tela nova manda o usuário.
        if (
            str_ends_with($path, '/front/reservation.php')
            && Settings::isTrue('override_native_reservation')
            && ReservationView::canView()
        ) {
            // Abrir a tela nativa de um item específico continua fazendo
            // sentido (é como se reserva de fato, com checagem de conflito);
            // só a entrada "geral" do menu é desviada.
            if ((int) ($_GET['reservationitems_id'] ?? 0) > 0) {
                return null;
            }

            return ReservationMenu::getPage();
        }

        return null;
    }

    /**
     * O GLPI 11 serve os arquivos legados através de `public/index.php`, então
     * `SCRIPT_NAME` aponta para o front controller. Quem carrega o caminho
     * original é a URI da requisição.
     */
    private static function getRequestPath(): ?string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if ($uri === '') {
            return null;
        }

        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path)) {
            return null;
        }

        return rtrim($path, '/');
    }
}
