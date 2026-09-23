/* global FullCalendar, CFG_GLPI, $ */

/**
 * Planner — comportamento da agenda.
 *
 * Usa o FullCalendar que o GLPI 11 já embarca, que é a versão 4 (API com
 * `header`, `eventRender`, `plugins` como strings e `minTime`/`maxTime`).
 * Isso foi conferido lendo public/js/planning.js do core, que registra
 * exatamente os plugins 'resourceTimeline', 'rrule' e 'bootstrap' usados aqui.
 * Escrever contra a API da v5/v6 não funcionaria nesta versão do GLPI.
 *
 * TRÊS MODOS, UMA FONTE. Calendário, Lista e Kanban desenham o mesmo conjunto
 * de eventos. O FullCalendar é o motor: ele decide o período exibido, busca os
 * eventos e expande recorrências. Lista e Kanban leem o resultado por
 * `calendar.getEvents()`, então mostram exatamente o que o calendário mostra —
 * inclusive as ocorrências de eventos recorrentes, que no JSON do servidor
 * chegam como uma regra, não como datas. Trocar de modo não faz nova consulta.
 *
 * Este arquivo é carregado em todas as páginas do GLPI (hook add_javascript),
 * por isso não faz nada na carga: só define o objeto. Tudo começa em init(),
 * chamado pelo template da tela.
 */
var GlpiPlanner = {

    calendar: null,
    config: {},

    /** 'calendar' | 'list' | 'kanban' */
    mode: 'calendar',

    /** Agrupamento das colunas do Kanban: 'state' | 'actor' */
    kanbanGroup: 'state',

    /**
     * Ordem das colunas de SITUAÇÃO, arrastável pelo cabeçalho — ver
     * `bindKanbanColumnReorder()`. Vem do servidor (preferência gravada em
     * `KanbanPrefs`); [1, 0, 2] é o mesmo default de lá.
     */
    kanbanStateOrder: [1, 0, 2],

    /** Período exibido: 'day' | 'week' | 'month'. Vale para os três modos. */
    range: 'week',

    /** Uma raia por pessoa no calendário (visão de equipe). */
    byActor: false,

    /**
     * Modo gerente de grupo: liga em Minha Agenda quando o observador
     * gerencia um grupo (ver `can_group_manager_mode` no servidor). Não muda
     * quais compromissos aparecem — só como são coloridos: uma cor por
     * PESSOA em vez de uma cor por TIPO, que vira uma tag de texto.
     */
    groupManagerMode: false,

    /** Popover travado aberto porque um editor de nota está em uso nele. */
    notePinned: false,

    /** Id do `setTimeout` de fechamento adiado do popover — ver `schedulePopoverHide()`. */
    popoverHideTimer: null,

    /** ids das agendas atualmente marcadas na barra lateral */
    actors: {},

    /** itemtypes atualmente marcados */
    types: [],

    /**
     * Faixas mandadas pelo servidor, quando a tela não as deriva da barra
     * lateral. null = usar as caixas marcadas (comportamento da agenda).
     */
    serverResources: null,

    init: function (config) {
        var root = document.getElementById('planner-app');
        if (!root || typeof FullCalendar === 'undefined') {
            return;
        }

        this.config = config || {};
        this.mode = this.config.default_mode || 'calendar';
        if (Array.isArray(this.config.kanban_state_order) && this.config.kanban_state_order.length === 3) {
            this.kanbanStateOrder = this.config.kanban_state_order.map(Number);
        }

        this.readSidebar();
        this.render();
        this.bindSidebar();
        this.bindToolbar();
        this.bindUserPicker();
        this.bindPopoverDismiss();
        this.bindBfcacheRestore();
        this.applyMode();
    },

    /**
     * Ao voltar para a página pelo botão Voltar do navegador (ou por uma aba
     * restaurada), o Chrome/Firefox podem reexibir o DOM congelado do
     * "bfcache" em vez de recarregar — o `evento.persisted` é o sinal disso.
     * Nesse caso o FullCalendar volta a aparecer com o layout que tinha ANTES
     * de a aba ser congelada (calculado com o painel possivelmente de outro
     * tamanho, ou simplesmente stale), e às vezes sem repintar as reservas.
     * `refetchEvents()` + `updateSize()` refazem a busca e o layout do zero,
     * sem precisar de F5.
     */
    bindBfcacheRestore: function () {
        var self = this;
        window.addEventListener('pageshow', function (e) {
            if (!e.persisted || !self.calendar) {
                return;
            }
            self.calendar.updateSize();
            self.calendar.refetchEvents();
            self.renderPanes();
        });
    },

    // -----------------------------------------------------------------
    // Estado vindo da barra lateral
    // -----------------------------------------------------------------

    readSidebar: function () {
        var self = this;

        self.actors = {};
        $('.planner-actor-toggle:checked').each(function () {
            self.actors[$(this).val()] = {
                id: parseInt($(this).val(), 10),
                name: $(this).data('name'),
                color: $(this).data('color')
            };
        });

        // Só caixas com `value` próprio entram como filtro de tipo. As caixas
        // booleanas da barra lateral ("somente as minhas", "mostrar
        // encerradas") viajam por `data-planner-flag` e não devem virar
        // itemtype — sem esta checagem elas entravam na lista como "on".
        self.types = $('.planner-type-toggle:checked').map(function () {
            var value = $(this).attr('value');

            return value ? value : null;
        }).get();
    },

    /**
     * Prefixo dos ids de recurso. Precisa casar com o `resourceId` que o
     * servidor monta em cada evento, senão as faixas ficam vazias.
     */
    resourcePrefix: function () {
        return this.config.resource_prefix || 'user_';
    },

    /**
     * Raias da visão por pessoa (ou por item, na tela de reservas). Uma por
     * linha marcada, na ordem em que aparecem na barra lateral — a leitura de
     * cima para baixo é a mesma nos dois lugares.
     */
    getResources: function () {
        var self = this;
        var out = [];

        // Quando o servidor manda as faixas, são elas que valem. É o caso da
        // tela de reservas: a barra lateral marca TIPOS de ativo, e cada faixa
        // é um APARELHO daquele tipo — a barra lateral não tem essa lista.
        if (self.serverResources) {
            return self.serverResources;
        }

        $('.planner-actor-toggle:checked').each(function () {
            var resource = {
                id: self.resourcePrefix() + $(this).val(),
                title: $(this).data('name'),
                color: $(this).data('color')
            };

            // itemtype/items_id são lidos pelo handler de arrastar quando o
            // evento muda de faixa: é o que permite reatribuir a tarefa a
            // outra pessoa, no mesmo formato que o endpoint do core espera.
            // Numa tela cujas faixas não são pessoas (a de reservas, por
            // exemplo), não há reatribuição e os campos ficam de fora.
            if (self.config.resource_itemtype) {
                resource.extendedProps = {
                    itemtype: self.config.resource_itemtype,
                    items_id: parseInt($(this).val(), 10)
                };
            }

            out.push(resource);
        });

        return out;
    },

    // -----------------------------------------------------------------
    // Calendário (motor de datas dos três modos)
    // -----------------------------------------------------------------

    render: function () {
        var self = this;
        var el = document.getElementById('planner-calendar');
        if (!el) {
            return;
        }

        // Dias úteis e janela de trabalho vêm da configuração do GLPI
        // (Configuração > Geral > Assistência), para a agenda do plugin não
        // divergir do planejamento nativo. Os defaults cobrem o caso de a
        // variável não estar exposta na página.
        var work_days = (typeof CFG_GLPI !== 'undefined' && CFG_GLPI.planning_work_days)
            ? CFG_GLPI.planning_work_days.map(Number)
            : [1, 2, 3, 4, 5];
        var hidden_days = [0, 1, 2, 3, 4, 5, 6].filter(function (d) {
            return work_days.indexOf(d) === -1;
        });

        self.calendar = new FullCalendar.Calendar(el, {
            plugins: [
                'dayGrid', 'timeGrid', 'list', 'interaction',
                'resourceTimeline', 'rrule', 'bootstrap'
            ],
            // Necessário para as visões de recurso na build GPL do
            // FullCalendar Scheduler — é a mesma chave usada pelo core.
            schedulerLicenseKey: 'GPL-My-Project-Is-Open-Source',
            theme: true,
            // O GLPI grava datas sem fuso; tratar o calendário como UTC faz
            // as strings serem exibidas exatamente como vieram do banco, sem
            // deslocamento. É o que o planejamento nativo faz.
            timeZone: 'UTC',
            defaultView: self.getCalendarView(),
            height: 'auto',
            nowIndicator: true,
            weekNumbers: false,
            eventLimit: true,
            // Por padrão o FullCalendar só refaz a busca quando o novo período
            // sai do intervalo já carregado. Isso deixaria os indicadores do
            // topo (horas, a fazer, concluídos) errados ao estreitar o período
            // — de Semana para Dia, por exemplo —, porque eles são calculados
            // no servidor sobre o intervalo consultado. Buscar sempre mantém
            // números e conteúdo falando do mesmo período.
            lazyFetching: false,
            hiddenDays: hidden_days,
            minTime: (typeof CFG_GLPI !== 'undefined' && CFG_GLPI.planning_begin) || '08:00:00',
            maxTime: (typeof CFG_GLPI !== 'undefined' && CFG_GLPI.planning_end) || '20:00:00',
            // Cabeçalho próprio (na barra superior do template), para os três
            // modos compartilharem a mesma navegação de período.
            header: false,
            // Arrastar e redimensionar. O que decide se UM evento pode ser
            // movido é a propriedade `editable` que vem do servidor, calculada
            // a partir de `canUpdateItem()` do core — este `true` só habilita o
            // gesto; ele não concede permissão nenhuma.
            editable: true,
            // Nada é arrastado de fora para dentro do calendário.
            droppable: false,
            // Rótulo da coluna de raias na visão por pessoa. Sem isto o
            // FullCalendar escreve "Resources", em inglês e sem sentido aqui.
            resourceLabelText: (self.config.labels && self.config.labels.person) || '',
            resourceAreaWidth: '16%',
            views: {
                // Semana e mês por pessoa usam colunas de UM DIA. Com a
                // granularidade horária padrão, uma semana ocupa ~60 colunas e
                // os compromissos ficam fora da tela, exigindo rolagem
                // horizontal para achar qualquer coisa — foi o que apareceu na
                // primeira versão desta visão.
                resourceTimelineWeek: {
                    slotDuration: { days: 1 },
                    slotLabelFormat: [{ weekday: 'short', day: 'numeric', month: 'numeric', omitCommas: true }]
                },
                resourceTimelineMonth: {
                    slotDuration: { days: 1 },
                    slotLabelFormat: [{ day: 'numeric' }]
                }
            },
            resources: function (info, success) {
                success(self.getResources());
            },
            events: function (info, success, failure) {
                self.fetch(info, success, failure);
            },
            eventRender: function (info) {
                self.decorateEvent(info);
            },
            eventClick: function (info) {
                var url = info.event.extendedProps.url;
                if (url) {
                    info.jsEvent.preventDefault();
                    window.location.href = url;
                }
            },
            eventDrop: function (info) {
                self.saveEventTimes(info);
            },
            eventResize: function (info) {
                self.saveEventTimes(info);
            },
            datesRender: function () {
                self.hidePopover();
                self.syncPeriodLabel();
                // Lista e Kanban precisam ser redesenhados aqui, e não só
                // depois de uma busca. O FullCalendar usa `lazyFetching`: ao
                // passar de Semana para Dia, o novo intervalo já está contido
                // no anterior e ele NÃO refaz a busca — sem esta chamada, a
                // tabela continuaria listando a semana inteira e os
                // indicadores continuariam sendo os da semana, enquanto o
                // rótulo do período já mostrava o dia.
                self.renderPanes();
            }
        });

        self.calendar.render();
        self.syncPeriodLabel();
        self.markActive('.planner-ranges', 'range', self.range);
        self.markActive('.planner-modes', 'mode', self.mode);
        self.markActive('.planner-kanban-opts', 'group', self.kanbanGroup);
        $('.planner-team-toggle').toggleClass('active', self.byActor);
    },

    /**
     * Traduz período + "por pessoa" para o nome da visão do FullCalendar.
     * O período é o mesmo nos três modos; só o calendário precisa deste mapa.
     */
    getCalendarView: function () {
        if (this.byActor) {
            return {
                day: 'resourceTimelineDay',
                week: 'resourceTimelineWeek',
                month: 'resourceTimelineMonth'
            }[this.range] || 'resourceTimelineWeek';
        }

        return {
            day: 'timeGridDay',
            week: 'timeGridWeek',
            month: 'dayGridMonth'
        }[this.range] || 'timeGridWeek';
    },

    applyCalendarView: function () {
        if (this.calendar) {
            this.calendar.changeView(this.getCalendarView());
        }
    },

    /**
     * Busca os eventos. O servidor reconfere quais agendas o usuário pode
     * abrir (AccessPolicy), então mandar ids aqui não amplia nada — a lista
     * enviada é só o recorte pedido, nunca a autorização.
     */
    fetch: function (info, success, failure) {
        var self = this;
        var ids = Object.keys(self.actors);

        if (ids.length === 0) {
            self.updateKpis({ total_hours: 0, todo: 0, done: 0, people: 0 });
            success([]);
            // Os painéis são redesenhados no próximo tique para que o
            // FullCalendar já tenha esvaziado seu store antes da leitura.
            setTimeout(function () { self.renderPanes(); }, 0);
            return;
        }

        self.setLoading(true);

        // O nome do parâmetro muda conforme a tela: agendas de pessoas na do
        // planejamento, itens reserváveis na de reservas.
        var payload = {
            start: self.toSqlDate(info.start),
            end: self.toSqlDate(info.end),
            types: self.types,
            // jQuery não serializa array vazio, então o servidor não
            // conseguiria distinguir "sem filtro" de "nenhum tipo marcado".
            // Esta marca resolve: com ela, a lista acima é a palavra final,
            // mesmo vazia.
            types_defined: 1,
            include_done: $('#planner-show-done').is(':checked') ? 1 : 0
        };
        payload[self.config.actor_param || 'users_ids'] = ids;

        // Filtros extras da tela, lidos de caixas marcadas na barra lateral.
        $('[data-planner-flag]').each(function () {
            payload[$(this).data('planner-flag')] = $(this).is(':checked') ? 1 : 0;
        });

        $.ajax({
            url: self.config.events_url,
            method: 'GET',
            dataType: 'json',
            data: payload
        }).done(function (response) {
            self.updateKpis(response.stats || {});

            // Faixas novas só valem depois de guardadas; refetchResources()
            // volta a chamar getResources(), que agora devolve estas.
            if (response.resources) {
                var changed = JSON.stringify(response.resources) !== JSON.stringify(self.serverResources);
                self.serverResources = response.resources;
                if (changed && self.calendar) {
                    self.calendar.refetchResources();
                }
            }

            success(response.events || []);
            setTimeout(function () { self.renderPanes(); }, 0);
        }).fail(function (xhr) {
            self.updateKpis({});
            failure(xhr);
        }).always(function () {
            self.setLoading(false);
        });
    },

    /**
     * O FullCalendar entrega Date em UTC (o calendário está em timeZone UTC);
     * o GLPI espera 'Y-m-d H:i:s' sem fuso. toISOString() já devolve o
     * horário UTC, então basta recortar.
     */
    toSqlDate: function (date) {
        return date.toISOString().slice(0, 19).replace('T', ' ');
    },

    // -----------------------------------------------------------------
    // Edição por arrastar e redimensionar
    // -----------------------------------------------------------------

    /**
     * Grava o novo horário de um compromisso.
     *
     * Chama `ajax/planning.php?action=update_event_times` do PRÓPRIO GLPI, não
     * um endpoint do plugin. Esse endpoint reconfere `canUpdate()` e
     * `canUpdateItem()` antes de gravar, e ainda cuida do que é específico de
     * cada tipo: a tarefa de ITIL precisa do chamado pai no update, a tarefa de
     * projeto tem tabela de relação com a equipe, e a reatribuição entre
     * pessoas mexe em `users_id_tech` ou `users_id` conforme o itemtype.
     * Reimplementar isso no plugin seria reescrever regra de negócio do core
     * com chance de divergir dela a cada atualização.
     *
     * O token CSRF vai no cabeçalho `X-Glpi-Csrf-Token`, acrescentado pelo
     * prefiltro global de jQuery do GLPI (public/js/common.js) — por isso não
     * aparece aqui.
     *
     * Se o servidor recusar, `info.revert()` devolve o evento ao lugar de
     * origem: a tela nunca fica mostrando um horário que não foi salvo.
     */
    saveEventTimes: function (info) {
        var self = this;
        var event = info.event;
        var props = event.extendedProps || {};

        if (!props.itemtype || !props.items_id) {
            info.revert();
            return;
        }

        var data = {
            action: 'update_event_times',
            itemtype: props.itemtype,
            items_id: props.items_id,
            start: event.start.toISOString(),
            end: (event.end || event.start).toISOString(),
            old_start: (info.oldEvent && info.oldEvent.start ? info.oldEvent.start : event.start).toISOString(),
            move_instance: false
        };

        // Arrastar de uma faixa para outra na visão "Por pessoa" reatribui o
        // compromisso. Fora dessa visão não existem recursos e estes campos
        // não são enviados.
        if (info.newResource) {
            var np = info.newResource.extendedProps || {};
            data.new_actor_itemtype = np.itemtype;
            data.new_actor_items_id = np.items_id;
        }
        if (info.oldResource) {
            var op = info.oldResource.extendedProps || {};
            data.old_actor_itemtype = op.itemtype;
            data.old_actor_items_id = op.items_id;
        }

        self.setLoading(true);

        $.ajax({
            url: (self.config.root_doc || '') + '/ajax/planning.php',
            method: 'POST',
            data: data
        }).done(function (response) {
            // O endpoint devolve o retorno de `update()`: vazio/falso quando a
            // permissão ou a validação barram a alteração.
            if (!response) {
                info.revert();
                self.notify('error', self.label('save_denied'));
                return;
            }
            self.notify('info', self.label('saved'));
            if (self.calendar) {
                self.calendar.refetchEvents();
            }
        }).fail(function () {
            info.revert();
            self.notify('error', self.label('save_failed'));
        }).always(function () {
            self.setLoading(false);
        });
    },

    label: function (key) {
        return (this.config.labels && this.config.labels[key]) || '';
    },

    /**
     * Aviso no canto da tela, com a mesma marcação que o GLPI usa para as
     * mensagens pós-redirect (`#messages_after_redirect`), para o retorno do
     * plugin parecer o do resto do sistema.
     *
     * Reconstrói o toast em vez de chamar `glpi_toast_info()` / `glpi_toast_error()`:
     * essas funções são usadas dentro do core, mas NÃO estão disponíveis como
     * globais em todas as páginas — nesta tela as duas saem como `undefined`
     * (conferido no navegador contra o GLPI 11.0.9), e a chamada falhava em
     * silêncio, deixando o usuário sem confirmação nenhuma depois de arrastar
     * um compromisso.
     */
    notify: function (kind, message) {
        if (!message) {
            return;
        }

        var is_error = kind === 'error';

        var $container = $('#messages_after_redirect');
        if (!$container.length) {
            $container = $('<div class="toast-container bottom-right p-3 messages_after_redirect" id="messages_after_redirect"></div>')
                .appendTo('body');
        }

        var $toast = $(
            '<div class="toast" role="alert" aria-live="assertive" aria-atomic="true">' +
                '<div class="toast-header text-white">' +
                    '<strong class="me-auto"></strong>' +
                    '<button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>' +
                '</div>' +
                '<div class="toast-body"></div>' +
            '</div>'
        );

        $toast.find('.toast-header')
            .addClass(is_error ? 'bg-danger' : 'bg-info')
            .find('strong').text(this.label(is_error ? 'msg_error' : 'msg_info'));
        $toast.find('.toast-body').text(message);

        $container.append($toast);

        if (window.bootstrap && window.bootstrap.Toast) {
            new window.bootstrap.Toast($toast[0], { delay: is_error ? 8000 : 4000 }).show();
            $toast.on('hidden.bs.toast', function () { $(this).remove(); });
        } else {
            // Sem o JS do Bootstrap o toast nunca receberia a classe `show` e
            // ficaria invisível; melhor exibi-lo à mão do que engolir o aviso.
            $toast.addClass('show');
            setTimeout(function () { $toast.remove(); }, is_error ? 8000 : 4000);
        }
    },

    // -----------------------------------------------------------------
    // Apresentação dos eventos no calendário
    // -----------------------------------------------------------------

    decorateEvent: function (info) {
        var props = info.event.extendedProps || {};
        var $el = $(info.el);
        var group_manager = this.groupManagerMode && props.level !== 'busy';
        var is_other_person = this.isOtherPerson(props);

        $el.css('border-left-color', props.actorColor || 'transparent');
        // Compromisso de outra pessoa: borda mais grossa — a cor sozinha já
        // identifica QUEM é (é a mesma da barra lateral e do avatar), a
        // espessura é o que faz o olho notar que este cartão é diferente dos
        // outros sem precisar comparar cores primeiro.
        if (is_other_person && !group_manager) {
            $el.css('border-left-width', '5px');
        }

        // Modo gerente de grupo: a cor de fundo passa a identificar a PESSOA,
        // não o TIPO — sobrescreve o `backgroundColor` que o próprio
        // FullCalendar já aplicou a partir do JSON do servidor.
        if (group_manager && props.actorColor) {
            $el.css('background-color', props.actorColor);
            $el.css('border-color', props.actorColor);
        }

        if (props.level === 'busy') {
            $el.addClass('planner-event-busy');
        }
        // Planning::DONE === 2 no core.
        if (props.state === 2) {
            $el.addClass('planner-event-done');
        }

        var view = info.view.type;
        var is_compact = view === 'dayGridMonth' || view.indexOf('list') === 0;

        // Avatar com as iniciais da pessoa, só quando o compromisso não é o
        // meu e mais de uma agenda está aberta — no meu próprio, eu já sei de
        // quem é. Colocado ANTES do título, é o primeiro coisa que o olho
        // encontra ao passar pela linha do tempo.
        if (is_other_person) {
            $el.find('.fc-content, .fc-title').first().before(
                $('<span class="planner-event-avatar"></span>')
                    .css('--planner-actor-color', props.actorColor || '')
                    .text(this.actorInitials(props))
                    .attr('title', props.actorName || '')
            );
        }

        if (!is_compact) {
            var meta = [];
            if (!group_manager && props.actorName && Object.keys(this.actors).length > 1) {
                meta.push(props.actorName);
            }
            if (!group_manager && props.typeLabel) {
                meta.push(props.typeLabel);
            }
            if (meta.length) {
                $el.find('.fc-title').after(
                    $('<span class="planner-event-meta"></span>').text(meta.join(' · '))
                );
            }
        }

        if (group_manager && props.typeLabel) {
            $el.find('.fc-title').after(
                $('<span class="planner-type-tag"></span>').text(props.typeLabel)
            );
        }

        this.bindPopover($el, info.event);
    },

    /**
     * Popover próprio em vez do qtip usado pelo planejamento nativo: é uma
     * dependência a menos e o conteúdo é inserido com .text()/.html() a
     * partir do que o servidor já sanitizou (RichText::getSafeHtml).
     */
    bindPopover: function ($el, event) {
        var self = this;
        var props = event.extendedProps || {};

        $el.on('mouseenter', function () {
            // Pinado: um editor de nota está aberto neste ou noutro
            // compromisso. Passar o mouse por cima de outros cartões não
            // deve fechá-lo à revelia — só um clique fora, ou Salvar/Cancelar,
            // fecham (ver o handler de documento em bindPopoverDismiss()).
            if (self.notePinned) {
                return;
            }
            self.cancelPopoverHide();
            self.hidePopover();
            self.renderPopover(this, event, props);
        }).on('mouseleave', function () {
            if (self.notePinned) {
                return;
            }
            // Atraso, não fechamento imediato: o popover é um elemento à
            // parte no DOM (anexado a `body`, não filho de `$el`), então o
            // cursor SAI de `$el` antes de conseguir alcançá-lo — um
            // `mouseleave` sem atraso fechava o popover no meio do caminho, e
            // um clique no botão "Adicionar nota" nunca chegava a registrar
            // porque o botão já tinha sido removido do DOM. O atraso dá tempo
            // de o cursor chegar ao popover, que cancela o fechamento ao
            // receber o próprio `mouseenter` (ver `renderPopover()`).
            self.schedulePopoverHide();
        });
    },

    schedulePopoverHide: function () {
        var self = this;
        self.cancelPopoverHide();
        self.popoverHideTimer = setTimeout(function () {
            self.hidePopover();
        }, 250);
    },

    cancelPopoverHide: function () {
        if (this.popoverHideTimer) {
            clearTimeout(this.popoverHideTimer);
            this.popoverHideTimer = null;
        }
    },

    renderPopover: function (anchorEl, event, props) {
        var self = this;
        var $pop = $('<div class="planner-popover"></div>');
        $('<div class="planner-popover-title"></div>').text(event.title).appendTo($pop);

        var meta = [];
        if (props.actorName) {
            meta.push(props.actorName);
        }
        if (props.typeLabel) {
            meta.push(props.typeLabel);
        }
        if (props.stateLabel) {
            meta.push(props.stateLabel);
        }
        if (meta.length) {
            $('<div class="planner-popover-meta"></div>').text(meta.join(' · ')).appendTo($pop);
        }

        if (props.content) {
            $('<div class="planner-popover-content"></div>').html(props.content).appendTo($pop);
        }

        // Duração total já registrada no CHAMADO (soma de todas as tarefas,
        // não só desta) — só existe em eventos de tipo Chamado.
        if (props.ticketDuration) {
            $('<div class="planner-popover-meta"></div>')
                .html('<i class="ti ti-clock-hour-4"></i> ' + (self.label('ticket_duration') || 'Total time') + ': ' + props.ticketDuration)
                .appendTo($pop);
        }

        // Nota do compromisso: visível para o dono e para quem tem
        // `canManageNote` (ver `AccessPolicy::canManageNoteFor()`) — o
        // servidor já decidiu isso antes de mandar `props.note`, aqui só se
        // desenha o que chegou. O rótulo muda conforme quem escreveu: no
        // próprio compromisso é só "Nota" (uma instrução pessoal); na agenda
        // de outra pessoa é "Nota do gestor" (deixa claro que veio de quem
        // tem posição de liderança sobre o dono, não de um colega qualquer).
        var is_own_event = String(props.users_id) === String(self.config.me);
        if (props.note) {
            var $note = $('<div class="planner-popover-note"></div>');
            $('<div class="planner-popover-note-label"></div>')
                .html('<i class="ti ti-message-2"></i> ' + (is_own_event
                    ? (self.label('own_note') || 'Note')
                    : (self.label('manager_note') || 'Manager note')))
                .appendTo($note);
            $('<div class="planner-popover-note-text"></div>').text(props.note).appendTo($note);
            // O autor aparece sempre que o servidor manda um, mesmo no
            // próprio compromisso: uma nota "minha" pode ter sido escrita por
            // um gestor que tem `canManageNote` sobre mim, e sem o nome não
            // dá para saber se foi eu mesmo ou alguém com essa posição.
            if (props.noteAuthor) {
                var author_prefix = self.label('note_by') || 'By';
                $('<div class="planner-popover-note-author"></div>').text(author_prefix + ' ' + props.noteAuthor).appendTo($note);
            }
            $note.appendTo($pop);
        }

        if (props.canManageNote) {
            $('<button type="button" class="btn btn-sm btn-ghost-secondary planner-popover-note-edit"></button>')
                .html('<i class="ti ti-note"></i> ' + (props.note
                    ? (self.label('edit_note') || 'Edit note')
                    : (self.label('add_note') || 'Add a note')))
                .on('click', function (e) {
                    e.stopPropagation();
                    self.showNoteEditor($pop, event, props);
                })
                .appendTo($pop);
        }

        // O popover é anexado a `body`, fora de `$el` — sem isto, o cursor
        // saindo de `$el` a caminho do próprio popover (para clicar em
        // "Adicionar nota", por exemplo) fecharia tudo antes de chegar lá.
        // Entrar aqui cancela o fechamento agendado por `$el`; sair daqui
        // reagenda, para o popover não ficar aberto para sempre.
        $pop.on('mouseenter', function () {
            self.cancelPopoverHide();
        }).on('mouseleave', function () {
            if (!self.notePinned) {
                self.schedulePopoverHide();
            }
        });

        $pop.appendTo('body');

        var rect = anchorEl.getBoundingClientRect();
        var top = rect.bottom + window.scrollY + 6;
        var left = Math.min(
            rect.left + window.scrollX,
            window.innerWidth + window.scrollX - $pop.outerWidth() - 12
        );
        $pop.css({ top: top + 'px', left: Math.max(8, left) + 'px' });

        return $pop;
    },

    /**
     * Substitui o conteúdo do popover por um formulário de uma nota só.
     * Pina o popover (`notePinned`) enquanto o formulário está aberto, para
     * que passar o mouse do cartão até a área de texto não o feche antes de
     * a pessoa conseguir digitar.
     */
    showNoteEditor: function ($pop, event, props) {
        var self = this;
        self.notePinned = true;

        $pop.find('.planner-popover-note, .planner-popover-note-edit').remove();

        var $form = $('<div class="planner-popover-note-form"></div>');
        var $textarea = $('<textarea class="form-control form-control-sm" rows="3"></textarea>')
            .val(props.note || '')
            .appendTo($form);

        var $actions = $('<div class="planner-popover-note-actions"></div>');
        var $save = $('<button type="button" class="btn btn-sm btn-primary"></button>')
            .text(self.label('save') || 'Save')
            .appendTo($actions);
        var $cancel = $('<button type="button" class="btn btn-sm btn-ghost-secondary"></button>')
            .text(self.label('cancel') || 'Cancel')
            .appendTo($actions);
        $form.append($actions);
        $form.appendTo($pop);

        $textarea.trigger('focus');

        $cancel.on('click', function () {
            self.hidePopover();
        });

        $save.on('click', function () {
            $.post(
                (self.config.root_doc || '') + '/plugins/planner/ajax/save_event_note.php',
                {
                    itemtype: props.itemtype,
                    items_id: props.items_id,
                    users_id: props.users_id,
                    note: $textarea.val()
                }
            ).done(function (response) {
                if (response && response.ok) {
                    self.notify('info', self.label('note_saved') || self.label('saved') || '');
                    if (self.calendar) {
                        self.calendar.refetchEvents();
                    }
                } else {
                    self.notify('error', self.label('save_failed') || '');
                }
            }).fail(function () {
                self.notify('error', self.label('save_failed') || '');
            }).always(function () {
                self.hidePopover();
            });
        });
    },

    /**
     * Um clique fora fecha o popover pinado — é o único jeito de sair do
     * editor de nota sem usar Salvar/Cancelar. Ligado uma única vez.
     */
    bindPopoverDismiss: function () {
        var self = this;
        $(document).on('mousedown', function (e) {
            if (self.notePinned && !$(e.target).closest('.planner-popover').length) {
                self.hidePopover();
            }
        });
    },

    hidePopover: function () {
        this.cancelPopoverHide();
        this.notePinned = false;
        $('.planner-popover').remove();
    },

    // -----------------------------------------------------------------
    // Modos
    // -----------------------------------------------------------------

    applyMode: function () {
        var self = this;

        $('.planner-pane').each(function () {
            $(this).prop('hidden', $(this).data('pane') !== self.mode);
        });

        self.markActive('.planner-modes', 'mode', self.mode);

        // "Por pessoa" só existe no calendário: Lista e Kanban já identificam
        // a pessoa em cada linha ou card.
        $('.planner-team-toggle').prop('hidden', self.mode !== 'calendar');
        $('.planner-kanban-opts').prop('hidden', self.mode !== 'kanban');

        if (self.mode === 'calendar' && self.calendar) {
            // O calendário foi renderizado dentro de um painel escondido, e
            // nesse estado o FullCalendar calcula alturas zeradas. updateSize()
            // refaz as medidas agora que o painel está visível.
            self.calendar.updateSize();
        } else {
            self.renderPanes();
        }
    },

    /**
     * Eventos que o FullCalendar tem carregados para o período atual, já com
     * as recorrências expandidas em ocorrências. É a fonte de Lista e Kanban.
     */
    getLoadedEvents: function () {
        if (!this.calendar) {
            return [];
        }

        var start = this.calendar.view.activeStart;
        var end = this.calendar.view.activeEnd;

        return this.calendar.getEvents().filter(function (ev) {
            if (!ev.start) {
                return false;
            }
            // getEvents() devolve todo o store, que pode conter ocorrências
            // fora do período exibido.
            return ev.start < end && (ev.end || ev.start) >= start;
        }).sort(function (a, b) {
            return a.start - b.start;
        });
    },

    renderPanes: function () {
        if (this.mode === 'list') {
            this.renderList();
        } else if (this.mode === 'kanban') {
            this.renderKanban();
        }
    },

    // -----------------------------------------------------------------
    // Modo Lista
    // -----------------------------------------------------------------

    renderList: function () {
        var self = this;
        var $box = $('.planner-list').empty();
        var events = self.getLoadedEvents();

        if (!events.length) {
            $box.append(self.buildEmpty());
            return;
        }

        var labels = self.config.labels || {};
        var current_day = null;
        var $tbody = null;

        events.forEach(function (ev) {
            var day = self.formatDayKey(ev.start);

            if (day !== current_day) {
                current_day = day;

                var $group = $('<div class="planner-list-day"></div>');
                $('<div class="planner-list-daytitle"></div>')
                    .text(self.formatDayLabel(ev.start))
                    .appendTo($group);

                var $table = $('<table class="table table-sm planner-list-table"></table>');
                $('<thead><tr>' +
                    '<th class="planner-list-when"></th>' +
                    '<th class="planner-list-who"></th>' +
                    '<th class="planner-list-type"></th>' +
                    '<th></th>' +
                    '<th class="planner-list-state"></th>' +
                  '</tr></thead>').appendTo($table);
                $table.find('th').eq(0).text(labels.col_when || '');
                $table.find('th').eq(1).text(labels.col_who || '');
                $table.find('th').eq(2).text(labels.col_type || '');
                $table.find('th').eq(3).text(labels.col_subject || '');
                $table.find('th').eq(4).text(labels.col_state || '');

                $tbody = $('<tbody></tbody>').appendTo($table);
                $group.append($table);
                $box.append($group);
            }

            $tbody.append(self.buildListRow(ev));
        });
    },

    buildListRow: function (ev) {
        var props = ev.extendedProps || {};
        var $tr = $('<tr class="planner-list-row"></tr>');

        // Colometria: a linha inteira ganha um tom claro da cor do tipo (ou
        // da pessoa, no modo gerente de grupo) — mesmo tratamento do cartão
        // do Kanban, para "vermelho" parecer vermelho também na Lista, não
        // só no ponto colorido de uma célula.
        var row_color = (this.groupManagerMode && props.level !== 'busy' ? props.actorColor : props.typeColor) || '';
        if (row_color) {
            $tr.css('background-color', this.tint(row_color, 0.08));
        }

        var $when = $('<td class="planner-list-when"></td>').text(this.formatTimeRange(ev));
        // Borda na primeira célula, não na linha: `<tr>` não renderiza
        // `border-left` com a tabela em `border-collapse: collapse` (o
        // Bootstrap usa isso em `.table`), a célula sim.
        if (this.isOtherPerson(props)) {
            $when.css('border-left', '3px solid ' + (props.actorColor || 'transparent'));
        }
        $when.appendTo($tr);

        var $who = $('<td class="planner-list-who"></td>');
        $('<span class="planner-avatar planner-avatar-sm"></span>')
            .css('--planner-actor-color', props.actorColor || '')
            .text(this.actorInitials(props))
            .appendTo($who);
        $('<span></span>').text(props.actorName || '').appendTo($who);
        $who.appendTo($tr);

        var $type = $('<td class="planner-list-type"></td>');
        if (props.typeLabel) {
            if (this.groupManagerMode && props.level !== 'busy') {
                $('<span class="planner-type-tag"></span>').text(props.typeLabel).appendTo($type);
            } else {
                $('<span class="planner-type-dot"></span>')
                    .css('background', props.typeColor || '')
                    .appendTo($type);
                $('<span></span>').text(props.typeLabel).appendTo($type);
            }
        }
        $type.appendTo($tr);

        var $subject = $('<td></td>');
        if (props.url) {
            $('<a></a>').attr('href', props.url).text(ev.title).appendTo($subject);
        } else {
            $('<span></span>').text(ev.title).appendTo($subject);
        }
        $subject.appendTo($tr);

        var $state = $('<td class="planner-list-state"></td>');
        if (props.stateLabel) {
            $('<span class="badge"></span>')
                .addClass(this.stateBadgeClass(props.state))
                .text(props.stateLabel)
                .appendTo($state);
        }
        $state.appendTo($tr);

        return $tr;
    },

    // -----------------------------------------------------------------
    // Modo Kanban
    // -----------------------------------------------------------------

    renderKanban: function () {
        var self = this;
        var $box = $('.planner-kanban').empty();
        var events = self.getLoadedEvents();

        var columns = self.kanbanGroup === 'actor'
            ? self.buildActorColumns()
            : self.buildStateColumns();

        events.forEach(function (ev) {
            var props = ev.extendedProps || {};
            var key = self.kanbanGroup === 'actor'
                ? 'u' + props.users_id
                : 's' + (props.state === null || props.state === undefined ? 0 : props.state);

            if (!columns[key]) {
                return;
            }
            columns[key].events.push(ev);
        });

        var keys = Object.keys(columns);
        if (!keys.length) {
            $box.append(self.buildEmpty());
            return;
        }

        // Arrastar entre colunas só existe agrupado por SITUAÇÃO: é aí que
        // a coluna representa um valor que dá para gravar (o estado do
        // compromisso). Agrupado por pessoa, arrastar significaria
        // reatribuir — um gesto que precisa das mesmas validações do arrasto
        // no calendário, que este Kanban não reproduz.
        var draggable = self.kanbanGroup === 'state';

        keys.forEach(function (key) {
            var col = columns[key];
            var $col = $('<div class="planner-kanban-col"></div>');
            if (draggable) {
                // O número puro (sem o prefixo 's' usado só para preservar a
                // ordem das chaves do objeto — ver comentário acima).
                $col.attr('data-state', key.slice(1));
            }

            var $head = $('<div class="planner-kanban-head"></div>');
            if (draggable) {
                // Cabeçalho arrastável para reordenar as PRÓPRIAS colunas —
                // gesto diferente de arrastar um cartão (ver
                // `bindKanbanDragDrop()`, que distingue os dois pelo formato
                // do payload solto).
                $head.attr('draggable', 'true').addClass('planner-kanban-head-draggable');
            }
            $('<span class="planner-kanban-dot"></span>').css('background', col.color).appendTo($head);
            $('<span class="planner-kanban-title"></span>').text(col.title).appendTo($head);
            $('<span class="planner-kanban-count"></span>').text(col.events.length).appendTo($head);
            $head.appendTo($col);

            var $body = $('<div class="planner-kanban-body"></div>');
            if (!col.events.length) {
                $('<div class="planner-kanban-empty"></div>').appendTo($body);
            }
            col.events.forEach(function (ev) {
                $body.append(self.buildKanbanCard(ev, draggable));
            });
            $body.appendTo($col);

            $box.append($col);
        });

        if (draggable) {
            self.bindKanbanDragDrop();
        }
    },

    /** Itemtypes cujo estado este Kanban sabe gravar (ver ajax/update_event_state.php). */
    KANBAN_STATE_ITEMTYPES: ['TicketTask', 'ChangeTask', 'ProblemTask', 'ProjectTask', 'Reminder', 'PlanningExternalEvent'],

    /**
     * Arrastar e soltar nativo do navegador (HTML5 Drag and Drop), sem
     * biblioteca extra: um cartão solto vira uma chamada a
     * `ajax/update_event_state.php`, e o Kanban inteiro é redesenhado a
     * partir da resposta do calendário — mesma fonte única de dados que os
     * outros dois modos usam.
     */
    bindKanbanDragDrop: function () {
        var self = this;
        var $kanban = $('.planner-kanban');

        $kanban.find('.planner-kanban-card[draggable="true"]').on('dragstart', function (e) {
            var $card = $(this);
            $card.addClass('is-dragging');
            e.originalEvent.dataTransfer.effectAllowed = 'move';
            e.originalEvent.dataTransfer.setData('text/plain', JSON.stringify({
                itemtype: $card.data('itemtype'),
                items_id: $card.data('items-id')
            }));
        }).on('dragend', function () {
            $(this).removeClass('is-dragging');
        });

        // Cabeçalho: arrasta a COLUNA inteira, para reordenar livremente
        // (ver `reorderKanbanColumn()`). Um payload com `reorderState` no
        // lugar de `itemtype`/`items_id` é o que distingue este arrasto do
        // de um cartão no handler de 'drop' abaixo, que os dois
        // compartilham.
        $kanban.find('.planner-kanban-head-draggable').on('dragstart', function (e) {
            var state = $(this).closest('.planner-kanban-col').data('state');
            $(this).closest('.planner-kanban-col').addClass('is-dragging');
            e.originalEvent.dataTransfer.effectAllowed = 'move';
            e.originalEvent.dataTransfer.setData('text/plain', JSON.stringify({ reorderState: state }));
        }).on('dragend', function () {
            $(this).closest('.planner-kanban-col').removeClass('is-dragging');
        });

        $kanban.find('.planner-kanban-col').on('dragover', function (e) {
            e.preventDefault();
            e.originalEvent.dataTransfer.dropEffect = 'move';
            $(this).addClass('is-drop-target');
        }).on('dragleave', function () {
            $(this).removeClass('is-drop-target');
        }).on('drop', function (e) {
            e.preventDefault();
            var $col = $(this).removeClass('is-drop-target');

            var raw;
            try {
                raw = JSON.parse(e.originalEvent.dataTransfer.getData('text/plain'));
            } catch (err) {
                return;
            }
            if (!raw) {
                return;
            }

            if (raw.reorderState !== undefined) {
                self.reorderKanbanColumn(parseInt(raw.reorderState, 10), parseInt($col.data('state'), 10));
                return;
            }

            if (!raw.itemtype || !raw.items_id) {
                return;
            }

            var newState = parseInt($col.data('state'), 10);

            $.post(self.config.update_state_url || (self.config.root_doc + '/plugins/planner/ajax/update_event_state.php'), {
                itemtype: raw.itemtype,
                items_id: raw.items_id,
                state: newState
            }).done(function (response) {
                if (response && response.ok) {
                    self.notify('info', self.label('saved'));
                    if (self.calendar) {
                        self.calendar.refetchEvents();
                    }
                } else {
                    self.notify('error', self.label('save_denied'));
                }
            }).fail(function () {
                self.notify('error', self.label('save_failed'));
            });
        });
    },

    /**
     * Move a coluna `fromState` para a posição de `toState` em
     * `kanbanStateOrder`, redesenha na hora (não espera o servidor) e grava a
     * preferência em segundo plano — uma reordenação de colunas é só
     * apresentação, não precisa da mesma re-confirmação que gravar um
     * arrasto de cartão.
     */
    reorderKanbanColumn: function (fromState, toState) {
        if (isNaN(fromState) || isNaN(toState) || fromState === toState) {
            return;
        }

        var order   = this.kanbanStateOrder.slice();
        var fromIdx = order.indexOf(fromState);
        var toIdx   = order.indexOf(toState);
        if (fromIdx === -1 || toIdx === -1) {
            return;
        }

        order.splice(fromIdx, 1);
        order.splice(toIdx, 0, fromState);
        this.kanbanStateOrder = order;
        this.renderKanban();

        $.post((this.config.root_doc || '') + '/plugins/planner/ajax/save_kanban_order.php', { states: order });
    },

    /**
     * As chaves levam um prefixo de letra de propósito. Em JavaScript, chaves
     * de objeto que parecem inteiros são percorridas em ordem NUMÉRICA, não na
     * ordem em que foram escritas — com chaves '0'/'1'/'2' as colunas sairiam
     * sempre como Informação, A fazer, Concluído, e com ids de usuário sairiam
     * em ordem de id em vez da ordem da barra lateral. O prefixo preserva a
     * ordem de inserção.
     */
    buildStateColumns: function () {
        var labels = this.config.labels || {};

        // A tela pode definir as próprias colunas. A de reservas usa
        // "Em andamento / Próximas / Encerradas", que dizem algo sobre uma
        // reserva; "A fazer / Concluído" não diriam.
        if (this.config.state_columns) {
            var out = {};
            this.config.state_columns.forEach(function (c) {
                out['s' + c.state] = { title: c.title, color: c.color, events: [] };
            });
            return out;
        }

        // Planning::INFO / TODO / DONE no core valem 0 / 1 / 2. A ORDEM vem de
        // `this.kanbanStateOrder` (arrastável pelo próprio usuário, ver
        // `bindKanbanColumnReorder()`) — os defaults abaixo só entram quando
        // não há preferência gravada.
        var defs = {
            0: { title: labels.state_info || 'Information', color: '#6b7a90' },
            1: { title: labels.state_todo || 'To do', color: '#2f6df6' },
            2: { title: labels.state_done || 'Done', color: '#12a594' }
        };

        var ordered = {};
        (this.kanbanStateOrder || [1, 0, 2]).forEach(function (state) {
            if (defs[state]) {
                ordered['s' + state] = { title: defs[state].title, color: defs[state].color, events: [] };
            }
        });

        return ordered;
    },

    buildActorColumns: function () {
        var columns = {};

        $('.planner-actor-toggle:checked').each(function () {
            columns['u' + $(this).val()] = {
                title: $(this).data('name'),
                color: $(this).data('color'),
                events: []
            };
        });

        return columns;
    },

    buildKanbanCard: function (ev, draggable) {
        var props = ev.extendedProps || {};
        var group_manager = this.groupManagerMode && props.level !== 'busy';
        var card_color = (group_manager ? props.actorColor : props.typeColor) || '';
        var $card = $('<div class="planner-kanban-card"></div>')
            .css('border-left-color', card_color || 'transparent');

        // Colometria: se o tipo é vermelho, o cartão precisa PARECER
        // vermelho de relance, não só ter uma tarja fina na borda — um tom
        // claro da própria cor no fundo faz isso sem comprometer a leitura
        // do texto (a borda esquerda continua com a cor cheia).
        if (card_color) {
            $card.css('background-color', this.tint(card_color, 0.12));
        }

        if (props.level === 'busy') {
            $card.addClass('planner-event-busy');
        }

        // Só é arrastável se: o Kanban está agrupado por situação, o item
        // tem um itemtype que sabe gravar estado (ver KANBAN_STATE_ITEMTYPES
        // — Reserva fica de fora, sua "situação" é calculada do relógio, não
        // gravada), e o nível é "details" (livre/ocupado não tem itemtype
        // real para identificar o que arrastar).
        var can_drag = draggable
            && props.level !== 'busy'
            && props.itemtype
            && this.KANBAN_STATE_ITEMTYPES.indexOf(props.itemtype) !== -1;

        if (can_drag) {
            $card.attr('draggable', 'true')
                .data('itemtype', props.itemtype)
                .data('items-id', props.items_id);
        }

        $('<div class="planner-kanban-when"></div>')
            .text(this.formatDayLabel(ev.start) + ' · ' + this.formatTimeRange(ev))
            .appendTo($card);

        var $title = $('<div class="planner-kanban-cardtitle"></div>');
        if (props.url) {
            $('<a></a>').attr('href', props.url).text(ev.title).appendTo($title);
        } else {
            $title.text(ev.title);
        }
        $title.appendTo($card);

        // Rodapé do cartão: avatar + NOME da pessoa, e o tipo depois. Só o
        // avatar obrigava a passar o mouse (ou decorar as iniciais) para saber
        // de quem era o compromisso — justamente a pergunta que um Kanban de
        // equipe precisa responder de relance.
        var $foot = $('<div class="planner-kanban-foot"></div>');
        $('<span class="planner-avatar planner-avatar-sm"></span>')
            .css('--planner-actor-color', props.actorColor || '')
            .text(this.actorInitials(props))
            .appendTo($foot);
        $('<span class="planner-kanban-actor"></span>').text(props.actorName || '').appendTo($foot);
        if (props.typeLabel) {
            $('<span class="planner-kanban-type' + (group_manager ? ' planner-type-tag' : '') + '"></span>')
                .text(props.typeLabel).appendTo($foot);
        }
        $foot.appendTo($card);

        return $card;
    },

    // -----------------------------------------------------------------
    // Formatação
    // -----------------------------------------------------------------

    /**
     * O calendário está em timeZone UTC, então as partes UTC da Date são
     * exatamente o horário que veio do banco. Usar getHours() local
     * deslocaria tudo pelo fuso do navegador.
     */
    pad: function (n) {
        return (n < 10 ? '0' : '') + n;
    },

    formatDayKey: function (date) {
        return date.getUTCFullYear() + '-' + this.pad(date.getUTCMonth() + 1) + '-' + this.pad(date.getUTCDate());
    },

    /**
     * Idioma das datas: o do GLPI, não o do navegador.
     *
     * `toLocaleDateString()` sem locale usa a configuração do NAVEGADOR, que
     * não tem relação com a preferência de idioma do usuário no GLPI — um
     * usuário com o GLPI em português num navegador em inglês via "Tuesday,
     * September 22" no meio de uma tela em português. O atributo `lang` do
     * <html> é preenchido pelo GLPI a partir dessa preferência (ver
     * Html::includeHeader()), então é a fonte certa.
     */
    getLocale: function () {
        var lang = (document.documentElement.getAttribute('lang') || '').trim();

        return lang !== '' ? lang : undefined;
    },

    /**
     * O timeZone UTC é obrigatório aqui pelo mesmo motivo do resto do arquivo:
     * as datas do GLPI não têm fuso e o calendário roda em UTC.
     */
    formatDayLabel: function (date) {
        try {
            return date.toLocaleDateString(this.getLocale(), {
                weekday: 'long', day: 'numeric', month: 'long', timeZone: 'UTC'
            });
        } catch (e) {
            return this.formatDayKey(date);
        }
    },

    formatTime: function (date) {
        return this.pad(date.getUTCHours()) + ':' + this.pad(date.getUTCMinutes());
    },

    formatTimeRange: function (ev) {
        if (ev.allDay) {
            return (this.config.labels && this.config.labels.allday) || '';
        }
        var out = this.formatTime(ev.start);
        if (ev.end) {
            out += ' – ' + this.formatTime(ev.end);
        }

        return out;
    },

    stateBadgeClass: function (state) {
        if (state === 2) {
            return 'bg-green-lt';
        }
        if (state === 1) {
            return 'bg-blue-lt';
        }

        return 'bg-secondary-lt';
    },

    buildEmpty: function () {
        var msg = Object.keys(this.actors).length === 0
            ? (this.config.labels && this.config.labels.no_actor)
            : (this.config.labels && this.config.labels.no_event);

        return $('<div class="planner-empty"><i class="ti ti-calendar-off"></i><span></span></div>')
            .find('span').text(msg || '').end();
    },

    // -----------------------------------------------------------------
    // Indicadores e estados
    // -----------------------------------------------------------------

    /**
     * Cada tela escolhe quais indicadores mostra; os seletores ausentes
     * simplesmente não recebem nada. Por isso não há lista fixa de KPIs aqui.
     */
    /**
     * Cada tela escolhe quais indicadores mostra; os seletores ausentes
     * simplesmente não recebem nada. Por isso não há lista fixa de KPIs aqui.
     *
     * `selected` e `people` são coisas diferentes de propósito: o primeiro é
     * quantas linhas estão MARCADAS na barra lateral, o segundo é quantas
     * realmente têm compromisso no período — na tela de reservas, "itens em
     * uso" só faz sentido como o segundo.
     */
    updateKpis: function (stats) {
        $('[data-kpi="count"]').text(stats.events_count !== undefined ? stats.events_count : '—');
        $('[data-kpi="hours"]').text(stats.total_hours !== undefined ? stats.total_hours : '—');
        $('[data-kpi="planned_hours"]').text(stats.planned_hours !== undefined ? stats.planned_hours : '—');
        $('[data-kpi="realised_hours"]').text(stats.realised_hours !== undefined ? stats.realised_hours : '—');
        $('[data-kpi="todo"]').text(stats.todo !== undefined ? stats.todo : '—');
        $('[data-kpi="done"]').text(stats.done !== undefined ? stats.done : '—');
        $('[data-kpi="people"]').text(stats.people !== undefined ? stats.people : '—');
        $('[data-kpi="selected"]').text(Object.keys(this.actors).length);
        this.applyKpiGroup();
    },

    /**
     * Alterna qual conjunto de indicadores aparece: horas (planejadas,
     * realizadas, totais) quando TUDO está marcado na barra lateral — nesse
     * caso não há filtro de fato, e "quanto tempo" é a pergunta que faz
     * sentido —, ou contagem (compromissos, a fazer, concluídos) assim que
     * alguma pessoa ou tipo é desmarcado.
     *
     * Só existe onde os dois grupos existem no HTML (Planejamento); a tela
     * de Reservas não tem `[data-kpi-group]`, então os seletores abaixo
     * simplesmente não casam com nada e a chamada não faz nada.
     */
    applyKpiGroup: function () {
        var everything = this.isEverythingSelected();
        $('[data-kpi-group="all"]').prop('hidden', !everything);
        $('[data-kpi-group="filtered"]').prop('hidden', everything);
    },

    /**
     * Verdadeiro quando todo TIPO está marcado — ou seja, a pessoa não
     * filtrou por tipo de compromisso.
     */
    isEverythingSelected: function () {
        // Só os tipos contam como "filtro" aqui — QUEM aparece (as caixas de
        // agenda na barra lateral) é outro eixo, não um filtro: a maioria das
        // pessoas nunca tem 100% das agendas visíveis marcadas ao mesmo
        // tempo (só "eu" e, se ligado, "minha equipe" vêm marcados por
        // padrão — grupo, compartilhamentos e gerência de grupo são opt-in),
        // então exigir isso faria o modo "horas" quase nunca aparecer, nem
        // na abertura da tela.
        var $types = $('.planner-type-toggle');

        if ($types.length === 0) {
            return true;
        }

        return $types.length === $types.filter(':checked').length;
    },

    setLoading: function (on) {
        $('.planner-loading').prop('hidden', !on);
    },

    syncPeriodLabel: function () {
        if (this.calendar && this.calendar.view) {
            $('[data-period]').text(this.calendar.view.title || '');
        }
    },

    markActive: function (scope, attr, value) {
        $(scope + ' [data-' + attr + ']').removeClass('active');
        $(scope + ' [data-' + attr + '="' + value + '"]').addClass('active');
    },

    // -----------------------------------------------------------------
    // Interações
    // -----------------------------------------------------------------

    bindSidebar: function () {
        var self = this;

        $(document).on('change', '.planner-actor-toggle', function () {
            self.readSidebar();
            // refetchResources redesenha as raias da visão de equipe;
            // refetchEvents recarrega o conteúdo e, no fim, os painéis.
            if (self.calendar) {
                self.calendar.refetchResources();
                self.calendar.refetchEvents();
            }
        });

        $(document).on('change', '.planner-type-toggle, #planner-show-done', function () {
            self.readSidebar();
            if (self.calendar) {
                self.calendar.refetchEvents();
            }
        });
    },

    bindToolbar: function () {
        var self = this;

        $(document).on('click', '.planner-modes [data-mode]', function () {
            self.mode = $(this).data('mode');
            self.applyMode();
        });

        $(document).on('click', '.planner-ranges [data-range]', function () {
            self.range = $(this).data('range');
            self.markActive('.planner-ranges', 'range', self.range);
            self.applyCalendarView();
        });

        // "Por pessoa" é um alternador do calendário, não um período: mantém a
        // semana/mês escolhidos e troca só a forma de empilhar.
        $(document).on('click', '.planner-team-toggle', function () {
            self.byActor = !self.byActor;
            $(this).toggleClass('active', self.byActor);
            self.applyCalendarView();
        });

        // Alterna a apresentação (cor por pessoa + tag de tipo) sem refazer
        // a busca: os campos de que precisa (actorColor, typeLabel) já vêm em
        // todo evento, então basta redesenhar o que já está carregado.
        $(document).on('click', '#planner-group-manager-toggle', function () {
            self.groupManagerMode = !self.groupManagerMode;
            $(this).toggleClass('active', self.groupManagerMode);
            if (self.calendar) {
                self.calendar.rerenderEvents();
            }
            self.renderPanes();
        });

        $(document).on('click', '.planner-kanban-opts [data-group]', function () {
            self.kanbanGroup = $(this).data('group');
            self.markActive('.planner-kanban-opts', 'group', self.kanbanGroup);
            self.renderKanban();
        });

        $(document).on('click', '.planner-prev', function () {
            if (self.calendar) { self.calendar.prev(); }
        });
        $(document).on('click', '.planner-next', function () {
            if (self.calendar) { self.calendar.next(); }
        });
        $(document).on('click', '.planner-today', function () {
            if (self.calendar) { self.calendar.today(); }
        });
    },

    /**
     * Seletor "abrir outra agenda", disponível só para quem tem o direito de
     * ver todas. Acrescenta a pessoa à barra lateral na sessão atual; o
     * acesso em si continua sendo decidido no servidor a cada requisição.
     */
    bindUserPicker: function () {
        var self = this;

        $(document).on('change', 'select[name="planner_add_user"]', function () {
            var id = parseInt($(this).val(), 10);
            if (!id || $('.planner-actor-toggle[value="' + id + '"]').length) {
                return;
            }

            var name = $(this).find('option:selected').text() || ('#' + id);
            var color = self.pickColor(id);

            var $li = $(
                '<li class="planner-actor">' +
                    '<label class="planner-actor-label">' +
                        '<input type="checkbox" class="form-check-input planner-actor-toggle" checked>' +
                        '<span class="planner-avatar"></span>' +
                        '<span class="planner-actor-name"></span>' +
                    '</label>' +
                '</li>'
            );

            $li.find('.planner-actor-toggle').val(id).attr('data-name', name).attr('data-color', color)
                .data('name', name).data('color', color);
            $li.find('.planner-avatar').css('--planner-actor-color', color).text(self.initials(name));
            $li.find('.planner-actor-name').text(name);

            var $section = $('.planner-picker').closest('.planner-section');
            var $list = $section.find('.planner-actors');
            if (!$list.length) {
                $list = $('<ul class="planner-actors mb-2"></ul>').insertBefore($section.find('.planner-picker'));
            }
            $list.append($li);

            self.readSidebar();
            if (self.calendar) {
                self.calendar.refetchResources();
                self.calendar.refetchEvents();
            }
        });
    },

    /**
     * Mesma paleta e MESMA CONTA do servidor
     * (`EventProvider::getActorColor()`): a cor sai do id da pessoa, não da
     * posição na lista. Indexar por posição fazia o avatar recém-adicionado
     * na barra lateral receber um tom, e os eventos dessa pessoa outro.
     */
    pickColor: function (users_id) {
        var palette = [
            '#2f6df6', '#12a594', '#e8833a', '#8b5cf6',
            '#0ea5e9', '#d6336c', '#65a30d', '#f59e0b',
            '#0891b2', '#7c3aed', '#c2410c', '#059669'
        ];

        return palette[Math.abs(parseInt(users_id, 10) || 0) % palette.length];
    },

    /**
     * Se este compromisso é de OUTRA pessoa, não da minha própria agenda —
     * usado para destacar visualmente (borda mais grossa, selo de iniciais)
     * o que não é meu quando mais de uma agenda está aberta ao mesmo tempo.
     *
     * `props.mine` é o campo certo na tela de RESERVAS: lá `users_id` no
     * payload é o id do ITEM reservável (a faixa), não de uma pessoa — usar
     * `users_id` ali marcaria toda reserva como "de outra pessoa", sempre.
     * No Planejamento não existe `mine` e `users_id` É a pessoa da faixa,
     * então a comparação direta vale.
     */
    isOtherPerson: function (props) {
        if (props.level === 'busy') {
            return false;
        }

        // Reservas: `mine` já diz a resposta certa, sem depender de quantas
        // faixas (tipos de ativo, não pessoas) estão marcadas.
        if (props.mine !== undefined) {
            return !props.mine;
        }

        // Planejamento: só vale destacar quando há mais de uma agenda aberta
        // — com uma só, é óbvio de quem é tudo, e destacar não ajudaria.
        return Object.keys(this.actors).length > 1
            && String(props.users_id) !== String(this.config.me);
    },

    /**
     * Iniciais do marcador. Quando o servidor manda as dele, usa essas: é o
     * que mantém o marcador do evento igual ao da barra lateral em telas
     * cujo rótulo do evento não é o mesmo texto do item (a de reservas mostra
     * "Computador - NOTE-01", e a lateral só "NOTE-01").
     */
    actorInitials: function (props) {
        if (props && props.actorInitials) {
            return props.actorInitials;
        }

        return this.initials((props && props.actorName) || '');
    },

    initials: function (name) {
        var parts = String(name).trim().split(/\s+/).filter(Boolean);
        if (!parts.length) {
            return '?';
        }
        if (parts.length === 1) {
            return parts[0].substring(0, 2).toUpperCase();
        }

        return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
    },

    /**
     * Um tom claro de `hex` (mistura com branco), usado como fundo de
     * cartão/linha — a cor cheia continua só na borda/ponto, para o texto em
     * cima permanecer legível.
     */
    tint: function (hex, amount) {
        var m = /^#?([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(hex || '');
        if (!m) {
            return 'transparent';
        }
        var r = parseInt(m[1], 16);
        var g = parseInt(m[2], 16);
        var b = parseInt(m[3], 16);

        return 'rgba(' + r + ', ' + g + ', ' + b + ', ' + amount + ')';
    }
};
