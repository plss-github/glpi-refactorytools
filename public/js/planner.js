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

    /** Período exibido: 'day' | 'week' | 'month'. Vale para os três modos. */
    range: 'week',

    /** Uma raia por pessoa no calendário (visão de equipe). */
    byActor: false,

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

        this.readSidebar();
        this.render();
        this.bindSidebar();
        this.bindToolbar();
        this.bindUserPicker();
        this.applyMode();
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

        $el.css('border-left-color', props.actorColor || 'transparent');

        if (props.level === 'busy') {
            $el.addClass('planner-event-busy');
        }
        // Planning::DONE === 2 no core.
        if (props.state === 2) {
            $el.addClass('planner-event-done');
        }

        var view = info.view.type;
        var is_compact = view === 'dayGridMonth' || view.indexOf('list') === 0;

        if (!is_compact) {
            var meta = [];
            if (props.actorName && Object.keys(this.actors).length > 1) {
                meta.push(props.actorName);
            }
            if (props.typeLabel) {
                meta.push(props.typeLabel);
            }
            if (meta.length) {
                $el.find('.fc-title').after(
                    $('<span class="planner-event-meta"></span>').text(meta.join(' · '))
                );
            }
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
            self.hidePopover();

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

            $pop.appendTo('body');

            var rect = this.getBoundingClientRect();
            var top = rect.bottom + window.scrollY + 6;
            var left = Math.min(
                rect.left + window.scrollX,
                window.innerWidth + window.scrollX - $pop.outerWidth() - 12
            );
            $pop.css({ top: top + 'px', left: Math.max(8, left) + 'px' });
        }).on('mouseleave', function () {
            self.hidePopover();
        });
    },

    hidePopover: function () {
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

        $('<td class="planner-list-when"></td>').text(this.formatTimeRange(ev)).appendTo($tr);

        var $who = $('<td class="planner-list-who"></td>');
        $('<span class="planner-avatar planner-avatar-sm"></span>')
            .css('--planner-actor-color', props.actorColor || '')
            .text(this.actorInitials(props))
            .appendTo($who);
        $('<span></span>').text(props.actorName || '').appendTo($who);
        $who.appendTo($tr);

        var $type = $('<td class="planner-list-type"></td>');
        if (props.typeLabel) {
            $('<span class="planner-type-dot"></span>')
                .css('background', props.typeColor || '')
                .appendTo($type);
            $('<span></span>').text(props.typeLabel).appendTo($type);
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

        keys.forEach(function (key) {
            var col = columns[key];
            var $col = $('<div class="planner-kanban-col"></div>');

            var $head = $('<div class="planner-kanban-head"></div>');
            $('<span class="planner-kanban-dot"></span>').css('background', col.color).appendTo($head);
            $('<span class="planner-kanban-title"></span>').text(col.title).appendTo($head);
            $('<span class="planner-kanban-count"></span>').text(col.events.length).appendTo($head);
            $head.appendTo($col);

            var $body = $('<div class="planner-kanban-body"></div>');
            if (!col.events.length) {
                $('<div class="planner-kanban-empty"></div>').appendTo($body);
            }
            col.events.forEach(function (ev) {
                $body.append(self.buildKanbanCard(ev));
            });
            $body.appendTo($col);

            $box.append($col);
        });
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

        // Planning::INFO / TODO / DONE no core valem 0 / 1 / 2.
        return {
            's1': { title: labels.state_todo || 'To do', color: '#2f6df6', events: [] },
            's0': { title: labels.state_info || 'Information', color: '#6b7a90', events: [] },
            's2': { title: labels.state_done || 'Done', color: '#12a594', events: [] }
        };
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

    buildKanbanCard: function (ev) {
        var props = ev.extendedProps || {};
        var $card = $('<div class="planner-kanban-card"></div>')
            .css('border-left-color', props.typeColor || 'transparent');

        if (props.level === 'busy') {
            $card.addClass('planner-event-busy');
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
            $('<span class="planner-kanban-type"></span>').text(props.typeLabel).appendTo($foot);
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
        $('[data-kpi="todo"]').text(stats.todo !== undefined ? stats.todo : '—');
        $('[data-kpi="done"]').text(stats.done !== undefined ? stats.done : '—');
        $('[data-kpi="people"]').text(stats.people !== undefined ? stats.people : '—');
        $('[data-kpi="selected"]').text(Object.keys(this.actors).length);
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
    }
};
