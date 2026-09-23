# Changelog

Formato baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/),
versionamento semântico.

## [0.11.2] - 2026-09-23

### Corrigido

- **Reservas com uma cor diferente por aparelho.** Sem cor customizada pelo
  administrador, o padrão passou a ser calculado por TIPO de ativo
  (`crc32($itemtype)`), a mesma conta usada na tela de Configuração — antes
  era por aparelho individual, então reservas do mesmo tipo apareciam em
  cores diferentes no calendário e na lista.
- **Evento de duração zero quase invisível no calendário.** Uma tarefa
  registrada com `begin` === `end` (sem tempo marcado) renderizava como uma
  linha fininha, sem título legível. Agora tem duração mínima de 15 minutos.
- **Painel "Chamados como técnico" somava a carreira inteira, não o mês.**
  As horas planejada/realizada agora são só do mês corrente.

## [0.11.1] - 2026-09-23

### Removido

- **Lista de chamados no painel "Chamados como técnico".** Ficou só com os
  três totais (planejado, realizado, total) e o link "Ver todos os meus
  chamados" — sem a lista individual, que não era mais desejada.

### Corrigido

- **Texto da nota invisível em alguns temas (ex.: "Auror").** O popover de
  nota usava um fundo claro (`--tblr-warning-lt`) sem declarar a cor de
  texto correspondente, então herdava a cor geral do tema — quase branca
  em temas escuros, texto branco em fundo claro. Agora a cor do texto é
  declarada no próprio contêiner da nota.

## [0.11.0] - 2026-09-23

### Removido

- **"Meu chamado como requerente" (0.10.1) foi revertido.** Não era um
  pedido original — voltar sobre esse tipo virtual, sua consulta
  (`TicketRequesterProvider`) e todas as referências em `EventTypes`/
  `EventProvider`.

### Corrigido

- **Chamado voltou a ser vermelho.** A cor padrão de fábrica estava azul;
  vermelho é o que os usuários já associavam ao tipo mais comum da tela.
- **Paleta de cores com tons vizinhos demais.** Mudança e Evento Interno
  eram os dois roxos quase iguais; Problema e Viagem, os dois laranjas;
  Projeto e Reunião, os dois verde-água. A paleta de fábrica inteira
  (`EventTypes::getDefaultColor()`) foi refeita com 9 matizes espalhados
  pela roda de cor (mais o cinza neutro do Lembrete), então nenhum tipo
  fica parecido com o vizinho num calendário cheio.
- **Notas não apareciam no Kanban.** O popover de compromisso (nota, autor,
  duração do chamado) só era montado no Calendário — o Kanban nunca tinha
  chamado o código que o monta. Cartão do Kanban agora abre o mesmo
  popover ao passar o mouse.
- **Seção de cores de ativo reservável sumia da tela de Configuração para
  quem não tinha o direito NATIVO `reservation`**, mesmo sendo Super-Admin
  com o direito administrativo do PLUGIN. A lista de tipos reserváveis
  usada ali dependia de `ReservationProvider::canView()` — o direito
  PESSOAL de fazer reserva, uma checagem que nunca deveria valer para quem
  só está CONFIGURANDO a cor, não reservando nada. Confirmado revogando o
  direito nativo do perfil Super-Admin de teste e vendo a seção sumir;
  corrigido e reconfirmado que ela volta a aparecer.

### Alterado

- Popover de compromisso (Calendário e Kanban) agora abre no lado DIREITO
  do item, não embaixo — embaixo cobria a linha/cartão seguinte numa Lista
  ou Kanban cheios. Cai para a esquerda se não couber à direita.
- Painel "Chamados como técnico" ganhou um link "Ver todos os meus
  chamados", que leva para a busca nativa de chamados já filtrada por
  "Técnico designado = eu" (o mesmo filtro que a Central usa para o link
  "Meus chamados em andamento") — a lista do painel é só um resumo dos 25
  mais recentes.

## [0.10.1] - 2026-09-23

### Adicionado

- **Chamados como REQUERENTE agora aparecem no Planejamento.** Um chamado em
  que a pessoa é só requerente (sem tarefa atribuída em seu nome) nunca
  aparecia — o planejamento, nativo ou do plugin, só conhecia tarefas de
  técnico. Novo tipo virtual "Meu chamado como requerente"
  (`TicketRequesterProvider`): mostra o chamado como uma barra da abertura
  até a solução (ou até agora, se ainda aberto), com a duração total
  registrada nele disponível no popover, igual à de um chamado de técnico.
- **Cor por TIPO de ativo reservável.** Nova seção em Configuração do
  Plugin: definida a cor de um tipo (ex.: Computador = verde), toda reserva
  DAQUELE TIPO aparece nessa cor no calendário e na lista da tela de
  Reservas — antes cada aparelho tinha sua própria cor fixa, sem opção de
  agrupar visualmente por tipo. Sem customização, cada aparelho continua com
  a cor de sempre.
- **Notificação por e-mail quando uma nota é gravada.** Usa o sistema de
  notificação nativo do GLPI (modelo semeado na instalação, editável em
  Configuração > Notificações como qualquer outro) — o dono do compromisso
  recebe um e-mail com o texto da nota, o autor e um link de volta, sempre
  que alguém que não é ele mesmo grava uma. Anotar o próprio compromisso não
  dispara nada (a pessoa já sabe o que escreveu). Depende de notificações
  estarem ligadas na configuração geral do GLPI, como qualquer notificação.
- Botões da barra superior e da barra de opções (Novo compromisso/Nova
  reserva, Calendário/Lista/Kanban, Dia/Semana/Mês, navegação…) maiores —
  eram os controles mais usados da tela com o tamanho mais discreto.
- Popover de compromisso maior, e o autor da nota agora aparece sempre
  (antes só em compromissos de outra pessoa — numa nota no próprio
  compromisso, escrita por um gestor, não dava para saber quem escreveu).
- **Colometria mais consistente entre Calendário, Lista e Kanban**: cartão do
  Kanban e linha da Lista agora ganham um tom claro da cor do tipo no fundo
  inteiro, não só um traço fino na borda. Compromisso de OUTRA pessoa (numa
  agenda com mais de uma pessoa aberta) ganha borda mais grossa e um selo
  com as iniciais dela, para não precisar comparar cores para saber de quem
  é.
- Indicadores do topo do Planejamento: com tudo marcado (nenhum tipo
  desmarcado), mostram só horas (planejadas/realizadas/totais); ao desmarcar
  algum tipo, trocam para contagem (compromissos/a fazer/concluídos) — os
  dois nunca cabiam ao mesmo tempo sem lotar a barra.

### Alterado

- **Cor de tipo de compromisso deixou de ser personalizável por usuário.**
  A tela "Personalizar minhas cores" saiu da barra lateral do Planejamento;
  só o administrador define, em Configuração do Plugin (Super-Admin
  apenas) — com cada pessoa podendo escolher sua própria cor, "vermelho"
  parava de significar a mesma coisa para quem olhasse a agenda de outra
  pessoa, o oposto do que a colometria deveria garantir.
- Tela de configuração renomeada de "Configuração do Pellissari New
  Planners" para "Configuração do Plugin".
- Painel "Chamados como técnico": a duração ao passar o mouse trocou o
  `title` nativo do navegador (sem estilo, fácil de não notar) por um
  popover próprio, igual ao do calendário.

### Segurança

Nenhum problema novo encontrado nesta rodada além do já corrigido na 0.9.0;
a implementação da notificação por e-mail foi verificada conferindo que ela
grava de fato uma linha em `glpi_queuednotifications`, com destinatário,
assunto e corpo corretos, antes de ser considerada pronta.

## [0.9.0] - 2026-09-23

Revisão de segurança, desempenho e bugs sobre o plugin inteiro — nenhuma tela
nova, todas as correções são internas.

### Segurança

- **Corrigido: um gestor podia anexar nota de gestor em compromissos de
  QUALQUER pessoa, não só de quem ele realmente gerencia.**
  `ajax/save_event_note.php` decidia a permissão a partir do `users_id` que o
  PRÓPRIO CLIENTE mandava no POST, sem reconferir se aquele item era mesmo
  dessa pessoa. Bastava alegar, na requisição, que o dono era um subordinado
  de verdade para anexar um recado em qualquer chamado, reserva ou lembrete
  do sistema — de qualquer pessoa, de qualquer equipe. Corrigido lendo o
  dono sempre DO ITEM já carregado do banco (`users_id_tech` para tarefas,
  `users_id` para os demais), nunca do que o cliente informa. Confirmado com
  um teste de exploração real: a mesma requisição que antes gravava a nota
  agora volta `{"ok":false}` e nada é persistido.

### Corrigido (desempenho)

- **Reservas embutidas na agenda de Planejamento também tinham o N+1 de
  nomes de item** que a 0.8.1 já tinha corrigido do lado da tela de Reservas
  — `ReservationProvider::populatePlanning()` chamava `getFromDB()` uma vez
  POR RESERVA para montar o título do evento. Agora reaproveita a mesma
  lista de itens reserváveis já cacheada por requisição, com fallback em
  lote só para o caso raro de um item ter sido desmarcado como reservável
  depois da reserva existir.
- **Barra lateral do Planejamento buscava cada pessoa visível com uma
  consulta própria** (`View::describeActor()`, um `getFromDB()` por linha
  da barra lateral). Com equipe, grupo e compartilhamentos abertos ao mesmo
  tempo, isso já passava de uma dezena de consultas só para montar nomes.
  Agora é uma consulta só, para todo mundo de uma vez.
- `AccessPolicy::getManagedGroupMembers()` também rodava duas vezes por
  carregamento da tela quando o direito de gerente de grupo está ligado
  (uma vez direto, outra dentro de `getVisibleUsers()`) — agora cacheada
  por requisição, como o resto do padrão já estabelecido em `ReservationView`.

### Corrigido (bugs)

- `ajax/reservation_availability.php` não tinha nenhum teto no intervalo
  consultado — um `begin`/`end` absurdo (manipulado ou por bug de UI) fazia
  a consulta varrer a tabela de reservas inteira. Acrescentado um teto de
  2 anos, generoso o bastante para não atrapalhar um empréstimo de
  equipamento realmente longo (diferente do teto de 31 dias do
  CALENDÁRIO, que é sobre janela de VISUALIZAÇÃO, não sobre duração de
  reserva).
- Nota de um compromisso: teto de 2000 caracteres no texto — é um recado
  curto, não um campo de descrição, e nada impedia um POST com um valor
  desproporcional indo parar na coluna.

## [0.8.1] - 2026-09-23

### Alterado

- **Renomeado para "Pellissari New Planners"** — nome exibido na lista de
  plugins, no menu (quando a substituição do nativo está desligada), na aba
  de direitos de perfil, no título da tela de configuração e no rodapé da
  barra lateral. O diretório (`planner`), o namespace
  (`GlpiPlugin\Planner`), o nome das tabelas e o direito no banco
  (`plugin_planner_planning`) continuam os mesmos — são identificadores
  técnicos, não o nome exibido, e renomeá-los exigiria reinstalar o plugin
  do zero. O autor já estava correto (Pellissari).
- Reservas: os tipos de ativo, dentro de "Filtros", passaram para DEPOIS de
  "Somente as minhas reservas" e "Mostrar encerradas" — antes vinham primeiro.

### Corrigido

- **Lentidão ao abrir a tela de Reservas.** Duas causas, as duas resolvidas:
  - `ReservationEventProvider::getEvents()` fazia uma consulta ao banco POR
    RESERVA para descobrir o nome do aparelho (`getFromDB()` num laço) — com
    muitas reservas no período, isso sozinho já bastava para travar a tela.
    Trocado por reaproveitar a lista de itens reserváveis, que já tem essa
    informação, sem nenhuma consulta extra por reserva.
  - `ReservationView::getReservableItems()` monta a lista de aparelhos
    reserváveis com uma consulta por APARELHO (nome, tipo…), e era chamada
    QUATRO VEZES na mesma requisição (a tela pede direto e de novo via
    `getReservableTypes()`; o endpoint de eventos pede de novo via
    `getEvents()` e `getResources()`) — multiplicando por 4 uma lista que já
    era cara de montar. Agora é calculada uma vez por requisição e
    reaproveitada nas chamadas seguintes.
  - Medido depois da correção com uma carga de teste (33 aparelhos
    reserváveis, 120 reservas na semana): abertura da tela e busca de
    eventos completam em bem menos de meio segundo no servidor.

## [0.8.0] - 2026-09-22

### Corrigido

- **Editor de nota do popover nunca abria de verdade.** O popover é anexado
  fora do elemento do compromisso (direto em `body`); o cursor saindo do
  compromisso a caminho do próprio popover disparava `mouseleave` e fechava
  tudo ANTES de o clique em "Adicionar nota" registrar. Corrigido com um
  atraso no fechamento, cancelado se o cursor entrar no popover a tempo —
  achado e confirmado testando o fluxo completo num navegador de verdade, não
  só lendo o código.
- **Datas/horas do formulário de reserva e do "+ Novo compromisso" não
  aceitavam digitação.** Eram `<input type="datetime-local">` nativo, cujo
  comportamento de edição varia (e em vários casos trava) conforme
  navegador/SO. Trocado pelo mesmo flatpickr que o resto do GLPI usa, com
  `allowInput: true` — sem essa opção o texto visível do flatpickr também
  fica só de leitura, mesmo problema por outro caminho.
- Uma proteção foi acrescentada contra o calendário não repintar as reservas
  ao voltar para a aba pelo botão Voltar do navegador quando ele é restaurado
  do "bfcache" em vez de recarregado (`window.pageshow` com
  `event.persisted`). Testado repetidamente (F5, navegar e voltar, botão
  Voltar) sem conseguir reproduzir o desaparecimento relatado — se persistir
  depois desta versão, precisamos dos passos exatos (navegador, SO, se é no
  modo Calendário ou Lista) para investigar mais a fundo.

### Adicionado

- **Nota de qualquer compromisso, inclusive o PRÓPRIO.** `canManageNoteFor()`
  não bloqueia mais dono=observador — a nota também serve como lembrete
  pessoal ("nota de instrução"), não só como recado de um gestor para um
  subordinado. O rótulo no popover muda conforme o caso ("Nota" vs. "Nota do
  gestor").
- **Ordem das colunas do Kanban de Planejamento, arrastável.** O cabeçalho de
  cada coluna de situação (A fazer/Informação/Concluído) agora se arrasta
  para outra posição; a preferência é gravada por usuário
  (`glpi_plugin_planner_kanban_prefs`) e volta a valer em qualquer sessão
  futura.
- **Duração do chamado no popover do calendário.** Passar o mouse num
  compromisso de tipo Chamado mostra o tempo total já registrado naquele
  chamado (`glpi_tickets.actiontime`, a mesma soma que o core mantém) — é a
  pergunta original ("quanto tempo esse chamado já tomou"), respondida no
  próprio evento do calendário, não só na lista lateral de chamados como
  técnico.
- Confirmado (com um cenário de teste de 2 grupos sobrepostos) que ser gerente
  de N grupos combina os membros de todos eles sem duplicar entradas na barra
  lateral e sem confundir a origem do acesso quando alguém é, ao mesmo tempo,
  liderado direto e membro de um grupo gerenciado — o mais forte
  (responsável direto) sempre vence.

### Alterado

- Reservas: os tipos de ativo entraram para dentro da seção "Filtros" na
  barra lateral, junto com "Somente as minhas reservas" e "Mostrar
  encerradas" — os três mexem na mesma coisa (o que aparece no
  calendário/lista) e antes ficavam em seções separadas sem nada que
  dissesse isso.

## [0.7.0] - 2026-09-22

Entrega os três itens adiados na 0.6.0.

### Adicionado

- **Modo "gerente de grupo" em Minha Agenda.** Quem é `is_manager=1` de um
  grupo no GLPI (`glpi_groups_users`) e tem o novo direito "Ver a agenda dos
  grupos que eu gerencio" enxerga os membros desse grupo como uma quinta
  origem de acesso (`AccessPolicy::REASON_GROUP_MANAGER`, sempre nível
  "details"), numa seção própria da barra lateral ("Grupo que eu gerencio").
  Um alternador na barra de ferramentas troca a apresentação sem nova
  consulta: com ele ligado, cada evento passa a ser colorido pela PESSOA
  (`actorColor`) em vez do TIPO, e o tipo vira uma tag de texto — nos três
  modos (Calendário, Lista, Kanban). Direito novo e isolado
  (`Right::READ_MANAGED_GROUP`), não concedido automaticamente a ninguém além
  do Super-Admin na instalação: um gerente de grupo do GLPI só ganha esta
  visão se o administrador do plugin conceder.
- **Nota do gestor em qualquer compromisso.** Quem é responsável direto
  (`users_id_supervisor`) ou gerente do grupo do dono de um compromisso pode
  anexar uma nota a ele, de qualquer itemtype — Chamado, Reserva, Lembrete,
  o que for. O dono vê a nota ao passar o mouse no popover do calendário;
  quem não é gestor dele não vê nada. Tabela própria
  (`glpi_plugin_planner_notes`, chave única por itemtype+id), endpoint
  dedicado (`ajax/save_event_note.php`) e uma consulta só por carregamento de
  agenda (`EventProvider::attachNotes()`), não uma por evento.
- **Painel "Chamados como técnico"** na barra lateral: lista os chamados em
  que o usuário logado participa como técnico (`Ticket_User::ASSIGN`), com a
  duração total de cada um no `title` ao passar o mouse, e três contadores —
  horas planejadas (janela `begin`/`end` das tarefas), realizadas
  (`actiontime`) e totais (soma das duas). Sempre sobre a própria
  participação, sem depender de direito extra do plugin.

## [0.6.0] - 2026-09-22

### Corrigido

- **Não era possível criar nenhum compromisso pela tela de Planejamento.** A
  tela tinha arrastar, redimensionar e visualizar, mas nenhum botão ou clique
  abria "novo". Corrigido com "+ Novo compromisso": Lembrete e as quatro
  variantes de Evento (Externo/Interno/Viagem/Reunião), com recorrência.
  Postar direto para o formulário nativo do GLPI não era opção: ele tem um
  `Session::checkRight("planning", READ)` incondicional que bloqueia quem só
  tem o direito do plugin — testado e confirmado (403 no formulário nativo,
  200 no endpoint do plugin, para o mesmo usuário sem o direito nativo).
- **Arrastar entre colunas do Kanban não fazia nada.** O Kanban é HTML próprio
  do plugin, não uma visão do FullCalendar — faltava a instrumentação de
  arrastar e soltar por completo. Implementado com Drag and Drop nativo do
  navegador, só na visão agrupada por Situação.
- **Evento criado como "Reunião" era salvo, filtrado e colorido como "Evento
  Externo".** `populatePlanning()` do core devolve uma lista fixa de colunas
  que não inclui `planningeventcategories_id`, mesmo com a categoria gravada
  certinha no banco — a variante nunca chegava a ser lida. Uma consulta
  complementar, pelos IDs já retornados, resolve sem tocar no core.
- A ordem de instalação gravava os 3 IDs de categoria e, na linha seguinte,
  apagava os mesmos 3 campos ao salvar os valores padrão — toda atualização
  do plugin criaria "Evento Interno"/"Viagem"/"Reunião" DE NOVO, duplicando.

### Adicionado

- **Nova taxonomia de tipos**: Chamado, Mudança, Problema, Projeto, Reserva,
  Evento Externo, Evento Interno, Viagem, Reunião, Lembrete — substitui os
  nomes técnicos anteriores ("Alterar tarefa" etc.). As quatro variantes de
  Evento são a MESMA tabela (`PlanningExternalEvent`), diferenciadas pela
  categoria (`PlanningEventCategory`), semeada na instalação.
- **Cores de tipo de compromisso personalizáveis por USUÁRIO**, não só pelo
  administrador. Um ícone de pincel na barra lateral abre um seletor de cor
  por tipo; a precedência é usuário > administrador > paleta de fábrica.
  Isolamento testado: mudar a cor numa sessão não afeta outra.
- **Reserva**: o formulário agora pede a DATA primeiro — tipo de ativo e item
  só aparecem depois, e só os itens LIVRES naquele período. A justificativa
  virou obrigatória (renomeada de "Comentário" para "Justificativa da
  reserva"). Recorrência (diária/semanal com dias específicos/mensal), via
  `Reservation::computePeriodicities()` do próprio core.
- Indicador "Agendas abertas" removido do Planejamento (não tinha uso
  reportado); "Em andamento"/"Itens em uso" removidos das Reservas — sobrou só
  a contagem de reservas do período exibido.
- Limite de 31 dias reforçado no SERVIDOR (`EventProvider`,
  `ReservationEventProvider`), independente do que a interface peça.

### Adiado

Ficaram fora desta rodada por serem subsistemas novos e extensos demais para
entrar com qualidade junto do resto:

- Painel "chamados em que participei como técnico", com horas planejadas,
  realizadas e totais.
- Notas do gestor em popover, visíveis ao subordinado passando o mouse.
- Modo "gerente de grupo" na Minha Agenda, com troca de esquema de cores
  (por pessoa + etiqueta de tipo) quando ativado.

## [0.5.0] - 2026-09-22

### Adicionado

- **Tela de Reservas remodelada** (Ferramentas > Reservas), com a mesma
  estrutura da agenda: Calendário, Lista e Kanban sobre os mesmos dados,
  período compartilhado e barra lateral. O eixo é o item reservável — a visão
  **Por item** empilha uma faixa por recurso no mesmo período, o que a tela
  nativa não permitia (ela mostra um item por vez, escolhido antes numa lista
  separada). Kanban agrupável por situação (Em andamento / Próximas /
  Encerradas) ou por item, filtro "Somente as minhas reservas" e atalho para
  gerenciar itens reserváveis. Substitui o item de menu nativo pelo mesmo
  mecanismo do planejamento, e é desligável na configuração.
- **Reservas aparecem na agenda.** O GLPI não considera reserva um tipo de
  planejamento, então o que a pessoa reservou nunca aparecia na agenda dela —
  nem na nativa, nem na do plugin. Um recurso reservado ocupa o tempo de quem
  reservou tanto quanto uma tarefa, e não vê-lo é o que leva alguém a marcar
  uma reunião em cima de uma sala já reservada. Entram como mais um tipo, com
  filtro e cor próprios, e respeitam o nível "livre/ocupado".
- **Cores dos tipos de compromisso configuráveis**, com botão de voltar ao
  padrão por tipo. Um valor que não seja hexadecimal é recusado: a cor vai
  direto para um atributo `style`, e aceitar texto livre ali seria deixar o
  administrador injetar CSS.

### Alterado

- **Cartão do Kanban mostra o nome da pessoa**, não só o avatar. Antes era
  preciso passar o mouse (ou decorar as iniciais) para saber de quem era o
  compromisso — justamente a pergunta que um Kanban de equipe responde.
- **A barra lateral não tem mais rolagem própria.** Ela estava presa a uma
  altura fixa e, como o conteúdo quase sempre passa dela, aparecia uma segunda
  barra de rolagem dentro da página. Agora a lateral cresce e quem rola é a
  página. Em tela estreita a rolagem interna continua, onde ela de fato ajuda.
- **O aviso de "Ver todas as agendas" virou um ícone com dica ao passar o
  mouse.** Ocupava quatro linhas fixas da barra lateral para algo que se lê
  uma vez.

### Corrigido

- As iniciais do marcador de item eram calculadas duas vezes com regras
  diferentes, e o marcador da barra lateral não batia com o dos cartões. Além
  disso, tiradas do nome inteiro, itens com prefixo comum ("PLANNER-NOTE-01" e
  "PLANNER-PROJETOR") recebiam as mesmas letras; agora saem da primeira letra
  de cada pedaço do nome.

## [0.4.0] - 2026-09-22

Revisão dirigida do código depois das duas refatorações anteriores. Os achados
abaixo saíram dessa revisão e foram confirmados um a um contra uma instância
GLPI 11.0.9 real antes e depois da correção.

### Corrigido

- **Vazamento no nível "livre/ocupado".** O mascaramento apagava título,
  descrição, itemtype e link, mas quatro campos escapavam por serem montados
  depois, a partir da linha original: a **cor do tipo** (que pinta a borda do
  cartão no Kanban e entrega se é chamado, problema ou projeto), o **estado** e
  seu rótulo (que distribuem o cartão entre as colunas "A fazer"/"Concluído" e
  aparecem na coluna Situação da Lista) e a **prioridade** do chamado, que nem
  é usada na tela e ia junto no JSON. Quem recebeu "apenas livre/ocupado"
  agora vê só o horário ocupado.
- **Desmarcar todos os tipos de compromisso mostrava todos os eventos.** Um
  array vazio era lido como "sem filtro". Como o jQuery não serializa array
  vazio, o servidor não conseguia distinguir "não filtrei" de "filtrei para
  nada"; passou a existir uma marca explícita para isso.
- **Lista, Kanban e indicadores congelavam ao estreitar o período.** De Semana
  para Dia o FullCalendar não refaz a busca (o novo intervalo já está contido
  no anterior), então o rótulo do período mudava mas a tabela e os números
  continuavam sendo os da semana.
- **A cor de cada pessoa divergia entre a barra lateral e os eventos.** Os dois
  lados indexavam a paleta pela posição na lista, e cada um iterava numa ordem
  diferente. Agora a cor é derivada do id da pessoa: a mesma pessoa tem o mesmo
  tom em toda a tela, em qualquer ordem.
- **Um modo inicial inválido deixava a tela em branco**, escondendo os três
  painéis sem erro. Valores de lista fechada passaram a ser validados ao
  salvar, caindo para o padrão (ou para o nível mais restritivo).
- **Nenhuma confirmação aparecia após arrastar um compromisso.** O código
  chamava `glpi_toast_info()`, que não existe como global nesta tela; a chamada
  falhava em silêncio. O aviso agora é montado com a mesma marcação que o GLPI
  usa nas mensagens pós-redirect.
- Três rótulos de reserva do Kanban tinham ficado em português no JavaScript,
  resíduo da conversão de idioma.
- `AccessPolicy::filterRequested()` recalculava o mapa inteiro de agendas
  visíveis uma vez por agenda pedida — com oito agendas abertas, oito vezes o
  mesmo trabalho a cada navegação no calendário. Agora calcula uma vez.

### Adicionado

- **Arrastar e redimensionar compromissos**, delegando a gravação ao endpoint
  `update_event_times` do próprio GLPI, que reconfere `canUpdate()` e
  `canUpdateItem()` e cuida do que é específico de cada tipo (chamado pai da
  tarefa de ITIL, tabela de equipe da tarefa de projeto, reatribuição entre
  pessoas). Na visão por pessoa, arrastar entre faixas reatribui o
  compromisso. Eventos recorrentes seguem não arrastáveis: mover uma ocorrência
  de uma série é ambíguo e o core resolve isso com um diálogo próprio que o
  plugin não reproduz.

## [0.3.0] - 2026-09-22

### Alterado

- **Todas as strings-fonte passaram para inglês**, seguindo a convenção do
  GLPI. As 113 strings do plugin foram convertidas, e o português virou
  tradução em vez de texto embutido no código.

### Adicionado

- Catálogos de tradução: `locales/planner.pot` (modelo), `locales/pt_BR.po/.mo`
  (português do Brasil, completo) e `locales/en_GB.po/.mo`.
- `tools/update_locales.sh` — extrai, mescla e compila os catálogos preservando
  as traduções existentes.
- `tools/twig2php.php` — torna as strings dos templates Twig visíveis para o
  `xgettext`, que sozinho deixava 81 das 113 strings de fora.

### Corrigido

- Dois textos de ajuda diziam "campo Responsável"; o GLPI em português rotula
  `users_id_supervisor` como **Supervisor**. Corrigido na tradução.
- Os rótulos de data da Lista e do Kanban seguiam o idioma do **navegador**, e
  não a preferência do usuário no GLPI: quem usava o GLPI em português num
  navegador em inglês via "Tuesday, September 22" no meio de uma tela em
  português. Agora usam o atributo `lang` do `<html>`, que o GLPI preenche a
  partir dessa preferência.
- Sem `locales/en_GB.mo`, um usuário com a interface em inglês numa instância
  cujo padrão é pt_BR receberia o plugin em português — a cadeia de fallback de
  `Plugin::loadLang()` cai no idioma padrão da instância antes do inglês.

## [0.2.0] - 2026-09-22

### Adicionado

- **Três modos de visualização** sobre os mesmos dados: Calendário, Lista e
  Kanban. Período (Dia/Semana/Mês) e navegação são compartilhados pelos três, e
  trocar de modo não faz nova consulta ao servidor.
  - **Lista**: ordem cronológica agrupada por dia, com quem, tipo e situação.
  - **Kanban**: cartões agrupados por Situação ou por Pessoa.
- **Substituição do Planejamento nativo** (ligada por padrão): o item de
  Assistência passa a abrir a tela do plugin mantendo o rótulo do core, e a URL
  antiga `/front/planning.php` redireciona. Exportação iCal e popup de
  disponibilidade seguem no core. Pode ser desligada na configuração.
- Link de configuração em **Configuração > Plugins**.
- Aviso na barra lateral explicando, para quem tem "Ver todas as agendas", por
  que enxerga a agenda de todo mundo.
- Opção de configuração para o modo inicial da tela.

### Alterado

- A visão por pessoa deixou de ser um quarto período e virou um **alternador**
  que respeita o período escolhido: semana e mês passaram a usar colunas de um
  dia. Antes, uma semana rendia ~60 colunas horárias e os compromissos ficavam
  fora da tela.
- "Abrir outra agenda" foi movida para logo acima de "Gerenciar
  compartilhamentos", juntando as duas ações sobre agendas de terceiros.
- A coluna de raias da visão por pessoa passou a se chamar "Pessoa" em vez do
  "Resources" padrão do FullCalendar.

### Corrigido

- Colunas do Kanban saíam fora de ordem: chaves de objeto que parecem inteiros
  são percorridas em ordem numérica em JavaScript, não na ordem de inserção.
- O atributo `hidden` era sobreposto pelo `display` do Tabler, deixando o botão
  "Por pessoa" visível fora do calendário.
- O ponto colorido do tipo não aparecia nas células da tabela do modo Lista.

## [0.1.0] - 2026-09-22

Primeira versão. Validada contra uma instância GLPI 11.0.9 real (imagem
oficial `glpi/glpi:11.0.9`).

### Adicionado

- Tela **Assistência > Planner**: agenda remodelada com visões Dia, Semana,
  Mês, Equipe (linha do tempo por pessoa) e Lista, barra lateral de agendas
  agrupadas pela origem do acesso, filtros por tipo de compromisso e
  indicadores de horas planejadas, a fazer, concluídos e agendas abertas.
- Visibilidade de **equipe** derivada do campo *Responsável* do usuário
  (`users_id_supervisor`), opcionalmente recursiva por toda a hierarquia.
- Visibilidade por **grupo** em comum.
- **Compartilhamento de agenda** entre usuários, nas duas direções: concessão
  pelo dono (ativa na hora) e pedido do interessado (só vale após aceite do
  dono). Revogação imediata por qualquer uma das partes.
- Dois **níveis de detalhe**: "Detalhes" e "Livre/ocupado". No nível
  livre/ocupado, título, descrição, link e itemtype são removidos no servidor,
  antes de a resposta sair.
- Direito próprio `plugin_planner_planning` com aba dedicada em
  **Administração > Perfis > Planner**.
- Tela de configuração do plugin, guardada no contexto `plugin:planner` do
  `glpi_configs`.
- `docker-compose.yml` com GLPI 11.0.9 na porta 8081 para desenvolvimento
  isolado.

### Notas

- Os compromissos são buscados pelo `populatePlanning()` do core, que filtra
  por `canViewItem()` de quem olha. O plugin amplia quais agendas podem ser
  abertas, nunca o que a pessoa pode ler de um chamado. Ver README.
- O planejamento nativo continua disponível e intocado.
- Arrastar/redimensionar eventos está desligado nesta versão; a tela é de
  leitura e navegação.
