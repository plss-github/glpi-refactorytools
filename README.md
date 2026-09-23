# Pellissari RefactoryTools

Plugin para GLPI 11.0.x que remodela a tela de planejamento e resolve o que o
planejamento nativo não cobre: **um supervisor enxergar a agenda da equipe**,
e **uma pessoa liberar a própria agenda para outra, com aceite**.

Por padrão ele **substitui** o item *Planejamento* de Assistência: o rótulo do
menu continua o mesmo, só o destino muda. "Pellissari RefactoryTools" é o nome
do plugin, não do item de menu. A substituição pode ser desligada na
configuração, e aí as duas telas convivem lado a lado.

O diretório e o namespace internos continuam `refactorytools`/`GlpiPlugin\Refactorytools` —
são identificadores técnicos (nome de tabela, autoload, direito no banco), não
o nome exibido. Só o nome exibido mudou.

---

## Por que existe

No GLPI 11 a permissão para ver a agenda de outra pessoa tem três níveis
(`Planning::READMY`, `READGROUP`, `READALL`) e é aplicada **uma única vez**:
no momento em que a pessoa é adicionada ao painel. A lista escolhida fica
gravada em `glpi_users.plannings`, e a busca de eventos percorre essa lista sem
reconferir nada. Consequências práticas:

- não existe o conceito de "minha equipe" — quem lidera 6 pessoas precisa
  adicioná-las manualmente, uma a uma;
- não existe consentimento — ou o perfil vê todo mundo, ou não vê ninguém;
- uma agenda adicionada ao painel continua rendendo eventos depois que o
  direito é retirado do perfil.

O RefactoryTools recalcula a autorização a cada requisição de eventos, a partir do
estado atual (perfil, campo Responsável, grupos, compartilhamentos).

---

## Como a visibilidade é decidida

Quatro origens, cumulativas. Quando mais de uma se aplica, vale a mais
permissiva.

| Origem | De onde vem | Quem libera |
|---|---|---|
| **Minha agenda** | sempre | — |
| **Minha equipe** | campo *Responsável* do usuário (`users_id_supervisor`) | perfil |
| **Meus grupos** | grupo em comum | perfil |
| **Compartilhada comigo** | linha aceita e vigente em `glpi_plugin_refactorytools_shares` | o dono da agenda |

Cada origem entrega um **nível de detalhe**:

- **Detalhes** — título, descrição, estado e link do compromisso;
- **Livre/ocupado** — apenas o horário ocupado. Título vira "Ocupado" e
  descrição, link e itemtype saem do JSON **no servidor**, não na tela.

### Direitos de perfil

Em **Administração > Perfis > RefactoryTools**:

| Direito | Efeito |
|---|---|
| Usar o RefactoryTools | abre a tela e a própria agenda. Sem ele, nada funciona |
| Ver a agenda da minha equipe | liberados diretos pelo campo Responsável |
| Ver a agenda dos meus grupos | colegas de grupo |
| Ver todas as agendas | acesso administrativo; ignora nível e sempre vê detalhes |
| Compartilhar a própria agenda | conceder acesso e responder pedidos recebidos |
| Solicitar acesso a uma agenda | pedir; só vale depois do aceite do dono |

O direito é `plugin_refactorytools_planning`, independente do `planning` nativo.
Desinstalar o plugin remove o direito de todos os perfis.

### Compartilhamento com aceite

- **Concessão** (o dono concede): nasce ativa, ninguém precisa consentir em
  receber.
- **Pedido** (o interessado pede): nasce `pending` e **não libera nada** até o
  dono aceitar. O solicitante não consegue aprovar o próprio pedido.
- Dono e destinatário podem encerrar a qualquer momento; o corte é imediato.
- Um par (dono, destinatário) tem no máximo uma linha, com índice único.

---

## Limite importante: direito sobre o item continua valendo

Os compromissos são buscados pelo `populatePlanning()` do próprio core, que
filtra cada linha por `canViewItem()` **de quem está olhando**.

Ou seja: o plugin amplia **quais agendas** podem ser abertas. Ele nunca amplia
o que a pessoa pode ler de um chamado, problema, mudança ou projeto. Um
supervisor sem direito de leitura nos chamados da equipe verá lacunas na
agenda dela.

Isso é deliberado. É o mesmo comportamento do planejamento nativo com
`READALL`, e reescrever as consultas para contornar significaria
reimplementar o filtro de visibilidade do GLPI — cada erro nessa
reimplementação seria um vazamento. O nível "livre/ocupado" **não** contorna
isso: ele apaga o conteúdo de eventos que a pessoa já podia ver, em vez de
revelar eventos que ela não podia.

Se um supervisor precisa ver o conteúdo dos chamados da equipe, o caminho é
dar a ele o direito de leitura desses chamados no perfil.

---

## Duas telas

O plugin substitui dois itens de menu do GLPI, mantendo os rótulos do core:

| Item nativo | Vira |
|---|---|
| Assistência > **Planejamento** | a agenda remodelada, com visibilidade de equipe |
| Ferramentas > **Reservas** | a agenda de recursos reserváveis |

As duas usam a mesma estrutura: três modos, período compartilhado, barra
lateral com o que exibir. O que muda é o eixo — na agenda cada faixa é uma
**pessoa**, nas reservas cada faixa é um **item reservável**.

Na tela de Reservas, a visão **Por item** empilha todos os recursos no mesmo
período. A tela nativa mostra um item por vez, escolhido antes numa lista
separada, então comparar dois recursos exigia duas abas.

**Reservas também aparecem na agenda.** O GLPI não considera reserva um tipo
de planejamento, então o que a pessoa reservou não aparecia na agenda dela. Um
recurso reservado ocupa o tempo de quem reservou tanto quanto uma tarefa.

## A tela

**Três modos sobre os mesmos dados**, trocáveis a qualquer momento:

| Modo | Para quê |
|---|---|
| **Calendário** | grade de horários; com *Por pessoa* vira uma linha do tempo com uma raia por integrante |
| **Lista** | tudo do período em ordem cronológica, agrupado por dia, com quem, tipo e situação em colunas |
| **Kanban** | cartões em colunas, agrupados por **Situação** (A fazer / Informação / Concluído) ou por **Pessoa** |

O período (Dia, Semana, Mês) e a navegação (anterior, hoje, próximo) são
compartilhados pelos três. Trocar de modo não faz nova consulta ao servidor.

- **Barra lateral**: agendas agrupadas pela origem do acesso, com avatar, cor
  e marcação de nível; filtros por tipo de compromisso; abrir outra agenda e
  atalho para os compartilhamentos.
- **Indicadores**: horas planejadas, a fazer, concluídos e agendas abertas,
  calculados sobre os mesmos eventos já carregados (sem consulta extra).
- Cor **por tipo** de compromisso (a legenda fica na barra lateral); a cor da
  pessoa aparece como barra à esquerda do evento e no avatar.

Usa o FullCalendar que o GLPI já embarca (versão 4) e as variáveis de tema do
Tabler, então acompanha tema claro/escuro e a paleta da instância sem trazer
um segundo sistema de design.

A tela é de **leitura e navegação**: arrastar e redimensionar estão desligados
porque mover um compromisso exige as validações do core (recorrência, estado
do chamado pai, disponibilidade). Melhor não oferecer um gesto que salvaria
pela metade. Para editar, clique no evento e vá ao item.

---

## Instalação

```bash
# a pasta do plugin precisa se chamar exatamente "refactorytools"
cp -r refactorytools /var/www/glpi/plugins/

php bin/console glpi:plugin:install -u <seu_usuario> refactorytools
php bin/console glpi:plugin:activate refactorytools
```

A instalação cria a tabela `glpi_plugin_refactorytools_shares`, registra o direito
`plugin_refactorytools_planning` em todos os perfis (com 0, "sem acesso") e concede o
conjunto completo ao(s) perfil(is) Super-Admin — sem isso, nem quem instalou
conseguiria abrir a tela.

Depois, libere os direitos aos demais perfis em **Administração > Perfis >
Pellissari RefactoryTools** e revise **Assistência > Pellissari RefactoryTools >
Configuração**.

### Requisitos

- GLPI 11.0.x
- PHP 8.2+
- campo *Responsável* (`users_id_supervisor`) preenchido nos usuários, para a
  visibilidade de equipe funcionar

---

## Configuração

Em **Assistência > RefactoryTools > Configuração** (exige "Ver todas as agendas"):

| Opção | Padrão | Observação |
|---|---|---|
| Incluir toda a hierarquia abaixo do supervisor | desligado | ligado, um diretor enxerga quase toda a empresa |
| Nível de detalhe para a equipe | Detalhes | ignorado por quem tem "Ver todas as agendas" |
| Nível de detalhe para colegas de grupo | Detalhes | |
| Nível sugerido ao compartilhar | Detalhes | só pré-seleciona o campo |
| Permitir pedidos de acesso | ligado | o pedido não revela nada sozinho |
| Cores dos tipos de compromisso | paleta do plugin | uma cor por tipo, com botão de voltar ao padrão |
| Substituir o Planejamento nativo | ligado | ver abaixo |
| Substituir as Reservas nativas | ligado | ver abaixo |
| Modo inicial | Calendário | o usuário troca à vontade |
| Abrir a agenda já com a equipe marcada | ligado | |

Guardada no `glpi_configs` do core, contexto `plugin:refactorytools`. O link para esta
tela fica em **Configuração > Plugins**, no ícone de engrenagem do RefactoryTools, e
também na barra lateral da agenda para quem tem "Ver todas as agendas".

### Sobre a substituição do Planejamento nativo

Ligada (padrão), duas coisas acontecem:

1. O item *Planejamento* de Assistência passa a abrir a tela do plugin,
   mantendo rótulo, ícone e atalho de teclado do core.
2. Quem acessa `/front/planning.php` (favorito, link antigo) é redirecionado.

**Não** são redirecionadas a exportação iCal (`genical`) nem o popup de
disponibilidade (`checkavailability`): são telas do core que o plugin não
substitui, e redirecionar a primeira quebraria assinaturas de agenda já
configuradas em clientes externos.

Desligada, o planejamento nativo volta ao normal e o RefactoryTools aparece como
entrada separada em Assistência.

### Sobre a substituição das Reservas

Mesma mecânica: o item *Reservas* de Ferramentas passa a abrir a tela do
plugin, e `/front/reservation.php` redireciona.

**Não** é redirecionado o acesso a um item específico
(`/front/reservation.php?reservationitems_id=N`): é ali que se reserva de
fato, com a checagem de conflito do core. A tela nova leva para lá. A lista de
itens reserváveis (`/front/reservationitem.php`), que é a tela de
administração, também segue intacta.

---

## Idiomas

As strings-fonte estão em **inglês**, como manda a convenção do GLPI, e o
plugin traz o catálogo **pt_BR** completo. Um idioma sem catálogo cai no
inglês. Os comentários do código continuam em português, por serem documentação
interna e não texto de interface.

```
locales/refactorytools.pot   modelo para novos idiomas
locales/pt_BR.po/.mo  português do Brasil
locales/en_GB.po/.mo  inglês (tradução idêntica — ver notas de arquitetura)
```

Para acrescentar um idioma, copie o `.pot`, traduza e rode a ferramenta:

```bash
cp locales/refactorytools.pot locales/es_ES.po   # traduza este arquivo
bash tools/update_locales.sh              # atualiza o .pot, mescla e compila
```

Rode `tools/update_locales.sh` sempre que mexer em algum `__()` ou `_n()`. Ele
preserva as traduções existentes (marca como *fuzzy* só o que mudou). Precisa
de PHP e gettext; se a máquina não tiver os dois:

```bash
docker run --rm -v "$PWD:/p" -w /p alpine:3.20 \
  sh -c 'apk add --no-cache gettext bash php83-cli >/dev/null \
         && PHP_BIN=php83 bash tools/update_locales.sh'
```

---

## Ambiente de desenvolvimento

O `docker-compose.yml` deste repositório sobe um GLPI 11.0.9 limpo na porta
**8081**, com esta pasta montada como plugin:

```bash
cp .env.example .env
docker compose up -d
docker compose exec glpi php bin/console glpi:plugin:install -u glpi refactorytools
docker compose exec glpi php bin/console glpi:plugin:activate refactorytools
# http://localhost:8081  (glpi / glpi)
```

O ambiente compartilhado da equipe fica em
`../analyticdesign/docker-compose.yml`, na porta 8080, e monta os dois plugins.

---

## Notas de arquitetura

**`plugin_refactorytools_check_config()` não pode usar as classes do plugin.**
`Plugin::checkPluginState()` a chama **antes** de `Plugin::activate()` registrar
o autoloader PSR-4. Qualquer `GlpiPlugin\Refactorytools\*` ali derruba a ativação com
`ClassNotFoundError`. Só classes do core.

**Não chamar `Session::checkCSRF()` nos endpoints.** No GLPI 11 o
`CheckCsrfListener` do kernel já valida o token em todo POST não-stateless,
antes de o arquivo legado ser incluído, e o **consome**. Uma segunda checagem
encontra o token gasto e derruba 100% das ações com 403.

**Padrão `front/` + `ajax/` em vez de Controller com rotas por atributo.** É o
caminho garantidamente estável em qualquer instalação 11.0.x, e é o que o
próprio core ainda usa no planejamento. Migrar para Controllers é um follow-up
razoável.

**A autorização mora em `src/AccessPolicy.php`.** Os endpoints nunca confiam na
lista de agendas que chega na requisição: ela é apenas o recorte pedido, e
`filterRequested()` descarta silenciosamente o que a pessoa não pode ver.

**`Html::redirect()` não funciona no `post_init`.** No GLPI 11 ele lança
`RedirectException`, tratada por um listener de `KernelEvents::EXCEPTION` — um
evento que só existe durante o tratamento da requisição. O `post_init` roda
antes, no boot do kernel, então a exceção escapa e vira 500. Por isso
`NativeRedirect` emite o header diretamente.

**O FullCalendar é o motor de datas dos três modos.** Ele decide o período,
busca os eventos e expande recorrências; Lista e Kanban leem o resultado por
`calendar.getEvents()`. Isso garante que os três nunca discordem sobre o que
está no período, e que ocorrências de eventos recorrentes — que no JSON do
servidor chegam como uma regra, não como datas — apareçam nos três.

**Ao mexer em `templates/`, limpe o cache do Twig.** O GLPI compila e guarda os
templates; sem `php bin/console cache:clear` a tela continua servindo a versão
antiga, sem erro nenhum, o que confunde muito durante o desenvolvimento.

**O catálogo `en_GB` não é redundante.** As strings-fonte já estão em inglês,
mas a cadeia de fallback de `Plugin::loadLang()` é "idioma do usuário, senão
idioma padrão da **instância**, senão `en_GB.mo`". Sem `locales/en_GB.mo`, um
usuário com a interface em inglês numa instância cujo padrão é pt_BR cai no
segundo ramo e o GLPI registra o catálogo **português** sob o locale `en_GB` —
o usuário passa a ver o plugin em português.

**O `xgettext` não enxerga templates Twig.** Rodá-lo com `--language=PHP` sobre
um `.twig` também não resolve: o analisador PHP só olha dentro de
`<?php ... ?>`. Por isso `tools/twig2php.php` gera arquivos "sombra" com as
chamadas na mesma linha do original, antes da extração. Sem isso, 81 das 113
strings do plugin ficavam de fora do catálogo.

---

## Licença

AGPL-3.0. O GLPI é GPL-3.0-or-later; a GPLv3 §13 permite expressamente combinar
código GPLv3 com AGPLv3 num mesmo programa. Ver `LICENSE`.
