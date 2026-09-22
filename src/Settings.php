<?php

/**
 * Planner
 * -----------------------------------------------------------------------------
 * Configuração do plugin.
 *
 * Guardada no `glpi_configs` do core sob o contexto "plugin:planner", via
 * `Config::getConfigurationValues()` / `setConfigurationValues()` — em vez de
 * uma tabela própria. São meia dúzia de chaves globais (não por entidade, não
 * por usuário); uma tabela só para isso seria peso morto, e o contexto do core
 * já entra de graça no backup/restore e no export de configuração.
 */

namespace GlpiPlugin\Planner;

use Config;

final class Settings
{
    public const CONTEXT = 'plugin:planner';

    /** Nível de detalhe: vê título, descrição e link do item. */
    public const LEVEL_DETAILS = 'details';

    /** Nível de detalhe: vê apenas que o horário está ocupado. */
    public const LEVEL_BUSY = 'busy';

    /**
     * Defaults. Escolhas:
     *  - `team_recursive` = 0: "minha equipe" são os liderados diretos. Ligar
     *    isto faz um diretor enxergar a agenda da empresa inteira, o que quase
     *    nunca é o que se quer por padrão.
     *  - `team_level` / `group_level` = details: quem tem o direito no perfil
     *    já foi autorizado pelo administrador; o nível "busy" existe para
     *    compartilhamentos individuais, onde o dono decide.
     *  - `allow_self_request` = 1: pedir acesso é inofensivo — nada é revelado
     *    até o dono aceitar.
     *  - `override_native_planning` = 1: o plugin existe para SER o
     *    planejamento, não para conviver com dois itens de menu parecidos.
     *    Desligar devolve o item nativo e move o Planner para uma entrada
     *    própria, o que serve para comparar as duas telas durante a adoção.
     *
     * @return array<string, string>
     */
    public static function getDefaults(): array
    {
        return [
            'team_recursive'     => '0',
            'team_level'         => self::LEVEL_DETAILS,
            'group_level'        => self::LEVEL_DETAILS,
            'default_share_level' => self::LEVEL_DETAILS,
            'allow_self_request' => '1',
            'auto_load_team'     => '1',
            'override_native_planning' => '1',
            'override_native_reservation' => '1',
            'default_mode'       => self::MODE_CALENDAR,
            // Mapa tipo virtual => cor, em JSON. Vazio = usar a paleta
            // embutida (ver EventTypes::getDefaultColor()). Guardar só o que
            // foi alterado deixa os tipos não customizados acompanharem uma
            // eventual mudança de paleta numa versão futura do plugin.
            'type_colors'        => '',
            // IDs das 3 categorias (PlanningEventCategory) semeadas na
            // instalação, que distinguem Evento Interno / Viagem / Reunião de
            // um Evento Externo genérico. Guardados por ID, nunca pelo nome —
            // o administrador pode renomear a categoria livremente sem
            // quebrar o filtro, porque nada aqui compara nomes.
            'category_internal_id' => '',
            'category_travel_id'   => '',
            'category_meeting_id'  => '',
        ];
    }

    /**
     * ID da categoria (`PlanningEventCategory`) associada a uma variante de
     * evento externo, ou 0 se a instalação não a semeou (ou ela foi apagada
     * manualmente depois).
     */
    public static function getCategoryId(string $virtual_key): int
    {
        $config_key = EventTypes::categorySettingKey($virtual_key);
        if ($config_key === null) {
            return 0;
        }

        return (int) self::get($config_key);
    }

    public static function setCategoryId(string $virtual_key, int $category_id): void
    {
        $config_key = EventTypes::categorySettingKey($virtual_key);
        if ($config_key === null) {
            return;
        }

        Config::setConfigurationValues(self::CONTEXT, [$config_key => (string) $category_id]);
    }

    /**
     * Cores por tipo de compromisso definidas pelo administrador.
     *
     * @return array<string, string> itemtype => cor hexadecimal
     */
    public static function getTypeColors(): array
    {
        $raw = self::get('type_colors');
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $itemtype => $color) {
            if (is_string($itemtype) && self::isHexColor((string) $color)) {
                $out[$itemtype] = strtolower((string) $color);
            }
        }

        return $out;
    }

    /**
     * @param array<string, string> $colors itemtype => cor; valor vazio ou
     *                                      inválido remove a customização
     *                                      daquele tipo
     */
    public static function saveTypeColors(array $colors): void
    {
        $clean = [];
        foreach ($colors as $itemtype => $color) {
            $color = trim((string) $color);
            // Um valor inválido volta ao padrão em vez de ser gravado: a cor
            // vai direto para um atributo `style` no HTML, e gravar texto
            // arbitrário aqui seria deixar o administrador injetar CSS.
            if ($color !== '' && self::isHexColor($color)) {
                $clean[(string) $itemtype] = strtolower($color);
            }
        }

        Config::setConfigurationValues(self::CONTEXT, [
            'type_colors' => $clean === [] ? '' : json_encode($clean),
        ]);
    }

    private static function isHexColor(string $value): bool
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1;
    }

    /** Modos de visualização da tela principal. */
    public const MODE_CALENDAR = 'calendar';
    public const MODE_LIST     = 'list';
    public const MODE_KANBAN   = 'kanban';

    /**
     * @return array<string, string>
     */
    public static function getModeLabels(): array
    {
        return [
            self::MODE_CALENDAR => __('Calendar', 'planner'),
            self::MODE_LIST     => __('List', 'planner'),
            self::MODE_KANBAN   => __('Kanban', 'planner'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getAll(): array
    {
        $defaults = self::getDefaults();
        $stored   = Config::getConfigurationValues(self::CONTEXT, array_keys($defaults));

        // Config::getConfigurationValues() devolve só as chaves existentes na
        // tabela — uma chave adicionada numa versão nova do plugin ficaria
        // ausente até alguém salvar a configuração. O merge com os defaults
        // evita ter que tratar "ausente" em cada ponto de leitura.
        return array_merge($defaults, $stored);
    }

    public static function get(string $key): string
    {
        return self::getAll()[$key] ?? '';
    }

    public static function isTrue(string $key): bool
    {
        return self::get($key) === '1';
    }

    /**
     * @param array<string, string> $values
     */
    public static function save(array $values): void
    {
        // Só grava chaves conhecidas: o formulário de configuração é um POST
        // como qualquer outro, e aceitar chaves arbitrárias deixaria qualquer
        // administrador escrever lixo no contexto do plugin.
        $allowed = array_intersect_key($values, self::getDefaults());
        if ($allowed === []) {
            return;
        }

        // Valores de lista fechada são conferidos contra as opções reais. Sem
        // isto, um `default_mode` inválido (POST montado à mão, chave editada
        // no banco) faz a tela abrir num modo que não existe: os três painéis
        // ficam escondidos e o usuário vê uma página em branco, sem erro.
        // `team_level`/`group_level` não precisam disso — AccessPolicy já cai
        // para o nível mais restritivo quando não reconhece o valor.
        if (isset($allowed['default_mode']) && !isset(self::getModeLabels()[$allowed['default_mode']])) {
            $allowed['default_mode'] = self::MODE_CALENDAR;
        }
        foreach (['team_level', 'group_level', 'default_share_level'] as $key) {
            if (isset($allowed[$key]) && !in_array($allowed[$key], [self::LEVEL_DETAILS, self::LEVEL_BUSY], true)) {
                $allowed[$key] = self::LEVEL_BUSY;
            }
        }

        $previous = self::getAll();

        Config::setConfigurationValues(self::CONTEXT, $allowed);

        // O GLPI guarda o menu montado em `$_SESSION['glpimenu']` e só o
        // reconstrói quando essa chave some. Ligar ou desligar a substituição
        // do planejamento nativo muda o menu, então sem isto o administrador
        // salvaria a configuração e continuaria vendo o menu antigo até
        // trocar de perfil ou sair e entrar de novo.
        $override_changed = false;
        foreach (['override_native_planning', 'override_native_reservation'] as $key) {
            if (isset($allowed[$key]) && $allowed[$key] !== ($previous[$key] ?? null)) {
                $override_changed = true;
            }
        }

        if ($override_changed && isset($_SESSION['glpimenu'])) {
            unset($_SESSION['glpimenu']);
        }
    }

    public static function purge(): void
    {
        Config::deleteConfigurationValues(self::CONTEXT, array_keys(self::getDefaults()));
    }
}
