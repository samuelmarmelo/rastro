# Contrato do Rastro

> Fonte da verdade do Rastro: rastreamento, consentimento de cookies (LGPD) e
> relatórios de venda do WooCommerce por origem. Mudou o desenho, atualize
> aqui no mesmo commit. As seções 1 a 8, 10 e 12 vêm do contrato original
> (2026-11-11) e são citadas pelos comentários do código; a seção 14 é a
> distribuição pelo repositório público.
>
> Manuais de cada parte: `docs/BASE.md`, `docs/CONSENTIMENTO.md`,
> `docs/TAGS.md`, `docs/EVENTOS.md`, `docs/ATRIBUICAO.md`,
> `docs/RELATORIOS.md`. Atualização e releases: `docs/ATUALIZADOR.md` e
> `docs/RELEASE.md`.

## 1. Regras gerais

1. **Plugin sem dependência de site.** O Rastro não chama função de tema
   nem de outro plugin. O que é específico de um site vive numa ponte do
   próprio site, que só usa ganchos públicos do Rastro (`rastro_*`).
2. **Um plugin, dois jeitos de rodar.** A pasta `rastro/` deste repositório,
   com `rastro.php` de cabeçalho de plugin completo, é o plugin instalável em
   qualquer WordPress. Um tema ou plugin de site pode trazer uma cópia
   embutida da mesma pasta (sincronizada de uma versão publicada, seção 14).
   Com o plugin próprio ativo, a cópia embutida não carrega (guarda
   `RASTRO_VERSAO`).
3. **Prefixo único:** funções `rastro_*`, constantes `RASTRO_*`, opções
   `rastro_*`, metas de pedido `_rastro_*`, classes CSS `rastro-*`, global JS
   `window.rastro`, cookies `rastro_consent` e `rastro_origem`, eventos do
   `document` `rastro:*`, text domain `rastro`. PHP 7.4. JS puro, sem
   biblioteca, sem etapa de build, sem CDN.
4. **Nada de terceiro antes do aceite.** O `<head>` leva só a fila
   (`dataLayer`, `gtag`, Consent Mode v2 negado) e a configuração. Os
   carregadores de terceiros só rodam com o consentimento da finalidade
   certa (análise ou publicidade), e fora do caminho crítico (seção 5).
5. **Sem dado pessoal.** Nenhum nome, e-mail, CPF, telefone, endereço, ID de
   usuário ou de cliente, IP ou chave de pedido sai para terceiros. O número
   do pedido sai só como `transaction_id` (necessário para deduplicar a
   compra). Correspondência avançada e API de conversões ficam para a frente
   T7, com decisão própria de quem administra o site.
6. **HTML igual para todo visitante** nas páginas públicas (cache de página
   seguro): a escolha de cookies e a origem são lidas no navegador.
7. **Falha fechada e SafeWidget:** sem configuração válida, sem
   consentimento, em cópia local ou com erro num módulo, nada sai e o site
   segue igual. Cada módulo carrega com guarda `is_readable` e `try/catch`.
8. **Soft delete:** nada é apagado. Opções, metas e arquivos antigos ficam;
   o que sai de uso é arquivado.
9. Regras da casa: componentes reutilizáveis, textos visíveis em pt-BR sem
   travessão (o Rastro é público, então citar o GitHub vale), uma branch por frente, contrato de teste por
   frente (`tests/validate-rastro-*.js` e `tests/php/rastro-*.test.php`),
   documentação por frente em `docs/`.

## 2. Decisões (com o motivo)

| # | Decisão | Motivo |
|---|---|---|
| D1 | Nome de trabalho "Rastro", prefixo `rastro` | curto, pt-BR, sem colisão no repositório |
| D2 | Um motor de consentimento com duas finalidades (`analise`, `publicidade`) e três provedores de aviso: o aviso do próprio Rastro, o aviso que o site já tem (ponte) e a WP Consent API | "adicionando banner de Cookie (caso não tenha ainda)": onde já existe aviso, o Rastro usa o que existe |
| D3 | Eventos de loja também no carrinho, no checkout e no pedido recebido, e também para cliente logado; equipe (quem tem `edit_posts` ou `manage_woocommerce`) nunca é medida | sem isso não há `begin_checkout` nem `purchase`, que são o pedido central. Os plugins de medição comuns já medem essas páginas, muitas vezes com correspondência avançada; o Rastro mede menos e sem dado pessoal |
| D4 | Nas páginas privadas o endereço enviado é saneado (sem `key`, sem número do pedido, sem query string) onde a ferramenta permite (GA4, `dataLayer`, PostHog do Rastro). Meta e TikTok leem o endereço sozinhos: no pedido recebido o `tags.js` troca o endereço da barra pelo saneado (`history.replaceState`) antes de GTM, Meta, TikTok e OpenAI carregarem, GA4 e Ads recebem `gtag('set', 'page_location' / 'page_referrer')` e as páginas privadas levam `<meta name="referrer" content="strict-origin">` (revisão de 2026-11-11); o resto fica para a T7 (compra pelo servidor) | não vazar a chave do pedido |
| D5 | Atribuição de origem gravada no navegador só depois do aceite de análise (padrão). Modo alternativo "sessão": cookie de sessão antes do aceite, por interesse legítimo | LGPD; a escolha da base legal é de quem administra o site. O relatório mostra a linha "Sem consentimento" para o que não pôde ser atribuído |
| D6 | Relatório lê, nesta ordem: meta do Rastro, meta do PixelYourSite (só leitura), atribuição nativa do WooCommerce, "Sem dados" | histórico desde o primeiro dia, sem importar nada |
| D7 | Relatório calcula a partir dos pedidos (com cache curto), sem tabela nova | volume da loja (dezenas de pedidos por mês) não justifica tabela; a porta fica aberta (função única de dados) |
| D8 | PostHog: destino próprio do Rastro; um site que já carrega o PostHog por conta própria desliga esse destino pela ponte (filtro `rastro_destinos`) e recebe os eventos pelo `rastro:event` | não carregar duas vezes |
| D9 | Compra pelo servidor (Meta API de conversões, GA4 Measurement Protocol, TikTok Events API) e segredos cifrados: frente T7, planejada e não construída agora. Ela também cobre o pedido Pix que fica `pending` no pedido recebido (sem compra pelo navegador, revisão de 2026-11-11) | exige tokens que só quem administra o site cola, e decisão sobre correspondência avançada |

### 2.1 Modos de consentimento (pedido original, 2026-11-11)

"Tem que ter dois modos, um modo que respeita LGPD e o outro que é um modo
básico [...] Também poderia ter um auto-aceite [...] depois de 2 segundos o
pop-up some e a pessoa meio que auto-aceita o Analytics [...] se a pessoa
vier com um parâmetro UTM na URL, ela vai ser registrada de qualquer forma."

| Modo (`modo`) | Aviso | Análise | Publicidade |
|---|---|---|---|
| `lgpd` (padrão) | aviso com Recusar, Personalizar e Aceitar | só depois do "Aceitar" | só depois do "Aceitar" |
| `aceite_automatico` | aviso aparece e some sozinho depois de `aceite_segundos` (padrão 2, de 1 a 30) sem escolha | liga no fim do tempo (grava `a1p0`) e o aviso some | só com "Aceitar" (nunca sozinha) |
| `basico` | nenhum aviso | sempre | sempre |

- No `aceite_automatico`, clicar em "Recusar" antes do tempo vale; depois,
  o botão "Preferências de cookies" continua disponível para recusar. A
  contagem para quando a pessoa interage com o aviso (foco, toque ou mouse
  sobre ele).
- `basico`: o estado é `{ analise: true, publicidade: true, decidido: true }`
  desde a carga, sem cookie de escolha, o Consent Mode começa concedido e o
  botão de preferências não aparece. Provedores externos (ponte, WP Consent
  API) são ignorados.
- **Aviso no painel (Protocolo da verdade):** ficar parado não é
  consentimento pela LGPD (art. 8º: manifestação livre, informada e
  inequívoca), então `aceite_automatico` e `basico` não atendem a LGPD. O
  painel diz isso no próprio campo; a escolha e o risco são do site.
- **Visita de campanha (`atribuicao_utm_sempre`, padrão ligado):** quem chega
  com `utm_*` ou ID de clique tem a origem gravada no cookie próprio
  `rastro_origem` em qualquer modo, mesmo antes do aceite. Fica só no site
  (relatório e pedido); nenhuma tag de terceiro carrega por causa disso.
  Visita sem campanha segue a regra de `atribuicao`.

## 3. Arquitetura

```
rastro/
├── rastro.php                    cabeçalho de plugin, constantes, carregador (T1)
├── nucleo/
│   ├── config.php                esquema único de campos, leitura, validação (T1)
│   ├── regras.php                onde medir, cópia local, equipe, contexto da página (T1)
│   └── saida.php                 configuração para o navegador, registro de scripts, exclusão dos otimizadores (T1)
├── admin/
│   └── admin.php                 menu, abas, formulário gerado pelo esquema, salvar (T1)
├── consentimento/
│   ├── consentimento.php         provedores, fila do <head>, Consent Mode v2 (T2)
│   └── aviso.php                 HTML do aviso próprio e botão de preferências (T2)
├── tags/
│   └── tags.php                  destinos, meta de verificação, avisos de duplicidade (T3)
├── loja/
│   └── eventos.php               dados dos eventos do WooCommerce, deduplicação da compra (T4)
├── atribuicao/
│   ├── atribuicao.php            leitura do cookie, gravação no pedido, atribuição nativa do WooCommerce (T5)
│   └── pedido.php                caixa e coluna no pedido (T5)
├── relatorios/
│   ├── dados.php                 leitura e agregação (T6)
│   └── relatorios.php            tela, AJAX, CSV (T6)
└── assets/
    ├── js/consent.js  (T2)   js/tags.js  (T3)   js/eventos.js  (T4)
    ├── js/atribuicao.js  (T5)   js/relatorios.js  (T6)
    └── css/aviso.css  (T2)   css/admin.css  (T1)   css/relatorios.css  (T6)
```

Carga: `rastro.php` define `RASTRO_VERSAO`, `RASTRO_ARQUIVO`, `RASTRO_DIR`,
`RASTRO_URL` e carrega, nesta ordem e com guarda `is_readable`, os arquivos
de `rastro_modulos()` (filtro `rastro_modulos`). A lista completa já nasce na
T1; arquivo ausente é pulado. Assim cada frente só acrescenta os seus
arquivos e ninguém edita o carregador.

`RASTRO_URL`: `plugins_url( '', RASTRO_ARQUIVO )` com barra final (funciona
embutido e como plugin próprio).

### 3.1 Configuração (T1, `nucleo/config.php`)

Uma opção só: `rastro_config` (array, autoload). O esquema
`rastro_config_esquema()` é a fonte da verdade dos campos: chave, aba, tipo,
rótulo, ajuda, padrão e regra. A tela, o salvar e a leitura saem dele.

| Chave | Aba | Regra | Padrão |
|---|---|---|---|
| `gtm` | geral | `^GTM-[A-Z0-9]{4,12}$` (maiúsculas) | `''` |
| `ga4` | geral | `^G-[A-Z0-9]{6,14}$` (maiúsculas) | `''` |
| `ads` | geral | `^AW-[0-9]{6,14}$` | `''` |
| `ads_rotulo_compra` | geral | `^[A-Za-z0-9_-]{6,40}$` (rótulo da conversão de compra) | `''` |
| `meta` | geral | `^[0-9]{8,20}$` | `''` |
| `tiktok` | geral | `^[A-Z0-9]{15,30}$` (maiúsculas) | `''` |
| `openai` | geral | `^[A-Za-z0-9_-]{10,64}$` | `''` |
| `openai_debug` | geral | `1` ou `0` | `0` |
| `posthog` | geral | `^phc_[A-Za-z0-9]{20,80}$` | `''` |
| `posthog_regiao` | geral | `us` ou `eu` | `us` |
| `google_verificacao` | geral | `^[A-Za-z0-9_-]{20,100}$` (só o valor de `content`; se colarem a tag inteira, o valor é extraído) | `''` |
| `carregamento` | geral | `ocioso`, `interacao` ou `imediato` | `ocioso` |
| `eventos_loja` | eventos | `1` ou `0` | `1` |
| `evento_*` (um por evento da seção 6) | eventos | `1` ou `0` | `1` |
| `valor_compra` | woocommerce | `total` (com frete e impostos) ou `produtos` (só os itens, com desconto) | `produtos` |
| `id_produto` | woocommerce | `id` ou `sku` | `id` |
| `status_relatorio` | woocommerce | lista de status do WooCommerce sem o prefixo `wc-` | `processing`, `completed` |
| `atribuicao` | woocommerce | `aceite`, `sessao` ou `desligada` | `aceite` |
| `atribuicao_utm_sempre` | woocommerce | `1` ou `0` (seção 2.1) | `1` |
| `atribuicao_dias` | woocommerce | inteiro de 1 a 365 | `90` |
| `atribuicao_woo_consentimento` | woocommerce | `1` ou `0` (a atribuição nativa do WooCommerce espera o aceite) | `1` |
| `modo` | consentimento | `lgpd`, `aceite_automatico` ou `basico` (seção 2.1) | `lgpd` |
| `aceite_segundos` | consentimento | inteiro de 1 a 30 | `2` |
| `aviso` | consentimento | `automatico`, `proprio` ou `externo` | `automatico` |
| `aviso_texto` | consentimento | texto de até 400 caracteres, sem HTML | texto padrão (seção 4) |
| `aviso_dias` | consentimento | inteiro de 30 a 395 | `180` |
| `medir_equipe` | consentimento | `1` ou `0` | `0` |

Funções (assinaturas fixas):

- `rastro_config_esquema(): array`
- `rastro_config_padrao(): array`
- `rastro_config_validar_campo( string $chave, $bruto )`: valor limpo, `''`
  para vazio, `null` para inválido.
- `rastro_config_ler( $bruto ): array` (limpa um array inteiro; inválido
  vira o padrão).
- `rastro_config(): array` (opção lida e validada, com cache estático;
  filtro `rastro_config`).
- `rastro_config_campo( string $chave )`.
- `rastro_config_salvar( array $bruto ): array` devolve
  `array( 'config' => array, 'erros' => array<string,string> )`; campo
  inválido mantém o valor anterior.
- `rastro_tem_destino(): bool` (algum ID de destino válido).

### 3.2 Onde medir (T1, `nucleo/regras.php`)

- `rastro_ambiente_local(): bool`: host `127.0.0.1`, `localhost`, `[::1]`,
  `*.localhost`, `*.test`, ou ambiente `local`/`development`. A constante
  `RASTRO_LOCAL` (true) liga de propósito numa cópia de teste. Nunca no
  repositório.
- `rastro_e_equipe(): bool`: logado com `edit_posts` ou `manage_woocommerce`
  (filtro `rastro_e_equipe`); com `medir_equipe` ligado devolve false.
- `rastro_requisicao_restrita(): bool`: `wp-admin`, login, AJAX, REST, cron,
  feed, prévia, Personalizar, 404.
- `rastro_pagina_tipo(): string`: `inicio`, `produto`, `categoria`, `loja`,
  `busca`, `carrinho`, `checkout`, `pedido_recebido`, `conta`, `outra`.
- `rastro_pagina_privada(): bool`: `conta`, `checkout`, `pedido_recebido`,
  pagar pedido, resposta a POST, endereço com `key` ou `wc_order`, e o filtro
  `rastro_pagina_privada` (a ponte acrescenta documento do pedido e Rastreio
  de Pedidos).
- `rastro_deve_medir(): bool`: tem destino, não é cópia local, não é
  requisição restrita, não é equipe. Filtro `rastro_deve_medir`. Calculado
  uma vez por requisição. Regra pura testável:
  `rastro_regra_medir( array $c ): bool`.
- `rastro_url_limpa(): string`: endereço da página sem query string; em
  página privada, só o caminho até o endpoint (ex.:
  `/finalizar-compra/pedido-recebido/`).

Página privada **não** impede a medição (D3); ela muda o que sai: endereço
saneado, sem replay de sessão, sem captura automática de cliques e
formulários (PostHog do Rastro com `autocapture` desligado ali).

### 3.3 Saída para o navegador (T1, `nucleo/saida.php`)

- `wp_head` prioridade 1: `<script id="rastro-config">` com
  `window.rastroConfig = {...}` (JSON com `JSON_HEX_*`). Base:
  `versao`, `pagina` (`tipo`, `privada`, `url`), `carregamento`, `debug`
  (só com `WP_DEBUG`). Cada módulo acrescenta a sua chave pelo filtro
  `rastro_config_navegador` (`consent`, `tags`, `eventos`, `atribuicao`).
  Só imprime quando `rastro_deve_medir()` ou quando o aviso próprio precisa
  aparecer.
- `rastro_registrar_script( $handle, $arquivo, $deps = array() )`: registra
  com versão por `filemtime`, no rodapé, com `defer`, e marca o script para
  ficar fora do atraso dos otimizadores: atributos `data-no-optimize="1"`,
  `data-no-defer="1"`, `data-cfasync="false"`, classe `nowprocket`, e os
  filtros de exclusão do LiteSpeed Cache, WP Rocket e Autoptimize quando
  existem. O atraso é o do próprio Rastro (seção 5), igual em qualquer
  hospedagem.
- Mesmos atributos nos scripts inline do Rastro
  (`rastro_script_inline( $id, $js )`).

### 3.4 Painel (T1, `admin/admin.php`)

Menu próprio "Rastro" (`dashicons-chart-line`, `manage_options`), slug
`rastro`. Abas: **Geral**, **Eventos**, **WooCommerce**, **Consentimento**
(campos do esquema) e **Relatórios** (T6, `manage_woocommerce`). Registro de
abas pelo filtro `rastro_admin_abas` (`titulo`, `capacidade`, `callback`,
`ordem`). Salvar: `admin-post` `rastro_salvar`, nonce, capacidade, PRG com
aviso por campo inválido. Campos de ID com `autocomplete="off"`,
`spellcheck="false"`. Avisos do painel pelo filtro `rastro_admin_avisos`
(T3 usa para duplicidade com outros plugins de medição). UI sóbria, nativa do
WordPress (`form-table`, `nav-tab`), sem biblioteca.

## 4. Consentimento (T2)

**Estado no navegador:** `{ analise: bool, publicidade: bool, decidido: bool }`.

**API (`assets/js/consent.js`):**

- `window.rastro.consent.get()`, `.set( { analise, publicidade } )`,
  `.abrir()` (mostra o aviso ou as preferências).
- Evento `rastro:consent` no `document` a cada mudança
  (`detail` = estado), e uma vez na carga quando já há escolha.
- Cookie próprio `rastro_consent` (`a1p1`, `a1p0`, `a0p0`; `aviso_dias`,
  `path=/`, `SameSite=Lax`, `Secure` em https). Sem dado pessoal.

**Fila do `<head>`** (`wp_head` prioridade 2, inline, pequena):
`dataLayer`, `gtag`, `gtag('consent','default', …)` com `ad_storage`,
`analytics_storage`, `ad_user_data`, `ad_personalization` negados e
`wait_for_update: 500`. Na mudança: `consent update` (`analytics_storage` =
`analise`; as três de anúncio = `publicidade`).

**Provedores** (`rastro_consent_provedor()`: `proprio`, `wp_consent_api` ou o
nome registrado por uma ponte; filtro `rastro_consent_provedores` registra
`nome => array( 'ativo' => callable, 'js' => array config )`):

| Provedor | Como lê | Quando |
|---|---|---|
| `proprio` | aviso do Rastro, cookie `rastro_consent` | padrão quando nenhum outro está ativo, ou `aviso = proprio` |
| `wp_consent_api` | `wp_has_consent` no navegador (`statistics` → análise, `marketing` → publicidade), evento `wp_listen_for_consent_change` | plugin WP Consent API ativo e `aviso` diferente de `proprio` |
| ponte (ex.: `meusite`) | cookie e evento descritos na configuração do provedor: `cookie`, `mapa` (valor do cookie → estado), `evento` (nome do evento do `document` e campo do `detail`), `abrir` (seletor do botão que reabre) | registrado por código do site |

Com `aviso = externo` e nenhum provedor externo ativo, nada carrega e o
painel avisa (falha fechada).

**Aviso próprio** (`consentimento/aviso.php`, `assets/css/aviso.css`):
região no fim do `<body>`, escondida no HTML e mostrada pelo JS quando não há
escolha. Botões "Recusar", "Personalizar" e "Aceitar" com o mesmo tamanho e
destaque. "Personalizar" abre as duas finalidades (análise, publicidade) com
caixas desmarcadas por padrão e "Salvar escolhas". Link para a política de
privacidade do WordPress. Esc com foco dentro fecha e vale como recusa só
nesta visita. Não prende o foco ao aparecer sozinho. Botão
"Preferências de cookies" pelo shortcode `[rastro_preferencias]` e pela
função `rastro_aviso_botao_preferencias()`; o tema sem lugar próprio recebe o
botão fixo discreto no canto (filtro `rastro_aviso_botao_flutuante`).
Visual por variáveis CSS (`--rastro-fundo`, `--rastro-texto`,
`--rastro-borda`, `--rastro-destaque`, `--rastro-raio`, `--rastro-fonte`),
sóbrio, sem sobrepor a página inteira. Texto padrão: "Usamos cookies para
medir o uso do site e os nossos anúncios, só com a sua permissão."
Só mostra as finalidades que têm destino configurado (sem tag de anúncio,
não pergunta por publicidade).

**Revogação:** nega no Consent Mode, apaga os cookies de medição conhecidos
(`_ga*`, `_gid`, `_gcl_*`, `_fbp`, `_fbc`, `_ttp`, `_tt_*`, `ttcsid*`,
`ttclid`, `ph_*`, `__ph_*`, `rastro_origem`, no domínio do site e no pai),
limpa `localStorage` e `sessionStorage` do PostHog e recarrega a página se
alguma tag já tinha carregado (lido antes de disparar o evento, por
`window.rastro.tags.algumCarregado()`). `rastro_origem` só fica quando a
visita de campanha está ligada e o próprio cookie é de campanha (`o:
'campanha'` e os toques de campanha); na dúvida, apaga (revisão de
2026-11-11). Aceitar de novo na mesma página espera a recarga.

**PHP:** `rastro_consent_provedor(): string`,
`rastro_consent_finalidades(): array` (quais finalidades têm destino),
`rastro_consent_servidor(): array` (lê o cookie próprio no servidor, só para
decisões do servidor como a T5; `null` nas chaves quando o provedor não é o
próprio).

## 5. Tags e desempenho (T3)

**Destinos** (`rastro_destinos()`, filtro `rastro_destinos`): cada um com
`finalidade`, `ativo` e a configuração para o navegador.

| Destino | Finalidade | Carregador oficial | Início |
|---|---|---|---|
| `gtm` | publicidade (o contêiner pode ter tags de anúncio que não respeitam o Consent Mode) → carrega só com `publicidade` (LGPD; revisão de 2026-11-11) | `https://www.googletagmanager.com/gtm.js?id=` | `gtm.start` |
| `ga4` | analise | `https://www.googletagmanager.com/gtag/js?id=` | `gtag('config', ID, { send_page_view: true, page_location: url limpa })` |
| `ads` | publicidade | mesmo `gtag/js` (um só script quando há GA4) | `gtag('config', ID)` |
| `meta` | publicidade | `https://connect.facebook.net/en_US/fbevents.js` | `fbq('init', ID)`, `fbq('track','PageView')` |
| `tiktok` | publicidade | `https://analytics.tiktok.com/i18n/pixel/events.js` (trecho oficial `ttq`) | `ttq.load(ID)`, `ttq.page()` |
| `openai` | publicidade | `https://bzrcdn.openai.com/sdk/oaiq.min.js` | `oaiq('init', …)`, `page_viewed` |
| `posthog` | analise | trecho oficial, host da região | `posthog.init` com `person_profiles: 'identified_only'`, sem replay em página privada |

Os trechos oficiais e os nomes de parâmetro são conferidos na documentação
atual de cada serviço antes de escrever (Protocolo da verdade); o que não
puder ser conferido fica anotado em `docs/TAGS.md`.

**`assets/js/tags.js`:**

- Espera `rastro:consent`. Para cada destino cuja finalidade foi aceita e
  que ainda não carregou: agenda a carga conforme `carregamento`:
  `ocioso` (depois do `load`, em `requestIdleCallback` com limite de 2 s, ou
  na primeira interação, o que vier antes), `interacao` (primeira interação:
  `pointerdown`, `keydown`, `scroll`, `touchstart`; limite de 8 s) ou
  `imediato`.
- `preconnect` do host só no momento da carga.
- `window.rastro.event( nome, params, opcoes )`: evento canônico no formato
  GA4. Entra numa fila e é entregue a cada destino quando ele carrega:
  `dataLayer.push( { event, ecommerce } )` (antes, `{ ecommerce: null }`)
  para o GTM; `gtag('event', …, { send_to })` para GA4 e Ads; mapa da seção 6
  para Meta, TikTok e PostHog. Também dispara `rastro:event` no `document`.
  A fila vive só na memória da página; sem aceite, é descartada.
- `window.rastro.tags.carregado( destino )`, `window.rastro.tags.parar()`.
- Cada destino num `try/catch`: um carregador com erro não derruba os outros.

**Dono único da tag:** com GTM e GA4/Ads/Meta diretos ao mesmo tempo, o
painel avisa do risco de contar duas vezes. Com PixelYourSite, WooCommerce
Google Analytics Integration, Site Kit ou outro plugin de medição ativo, o
painel avisa (`rastro_admin_avisos`). O Rastro não desativa plugin de
ninguém.

**Verificação do Google:** `<meta name="google-site-verification">` no
`wp_head` de toda página pública, com o valor validado e `esc_attr`. Não
depende de consentimento (não é cookie nem script).

**CSP:** hosts a liberar listados em `docs/TAGS.md` e cobrados no
teste de contrato.

## 6. Eventos da loja (T4)

Só com WooCommerce ativo e `eventos_loja` ligado. Dados montados no PHP
(`loja/eventos.php`), sem dado pessoal, impressos como JSON da página
(`rastroConfig.eventos`) ou em atributos `data-rastro-*`; o `eventos.js`
dispara com `window.rastro.event()`.

Item (formato GA4): `item_id` (ID ou SKU conforme `id_produto`),
`item_name`, `price`, `quantity`, `item_category` (primeira categoria),
`item_variant` (variação, quando houver). Moeda da loja. Valores com ponto e
duas casas.

| Evento canônico | Quando | Meta | TikTok | Ads |
|---|---|---|---|---|
| `view_item_list` | arquivo da loja, categoria, busca (até 20 itens) | (não envia) | (não envia) | `view_item_list` |
| `view_item` | página de produto | `ViewContent` | `ViewContent` | `view_item` |
| `add_to_cart` | botão da página de produto e adicionar por AJAX (`added_to_cart`) | `AddToCart` | `AddToCart` | `add_to_cart` |
| `remove_from_cart` | remover no carrinho e no minicart | (não envia) | (não envia) | (não envia) |
| `view_cart` | página do carrinho | (não envia) | (não envia) | (não envia) |
| `begin_checkout` | página de checkout (uma vez por sessão de checkout) | `InitiateCheckout` | `InitiateCheckout` | `begin_checkout` |
| `add_payment_info` | escolha da forma de pagamento (uma vez) | `AddPaymentInfo` | `AddPaymentInfo` | (não envia) |
| `purchase` | pedido recebido, uma vez por pedido | `Purchase` (`eventID` = `rastro-<id do pedido>`) | evento de compra do TikTok (nome conferido na documentação) | `purchase` e `conversion` com `send_to: AW-ID/rótulo` quando há rótulo |
| `search` | resultado de busca | `Search` | `Search` | `search` |

`purchase`: `transaction_id` (número do pedido), `value` (conforme
`valor_compra`), `currency`, `tax`, `shipping`, `coupon` (código do cupom,
só com o filtro `rastro_eventos_enviar_cupom`, padrão desligado: um cupom
pode ser pessoal; revisão de 2026-11-11), `items`.

**Deduplicação da compra:** meta `_rastro_compra_enviada` (data ISO) gravada
quando a página de pedido recebido imprime o evento pela primeira vez; na
recarga o evento não sai de novo. No navegador, guarda extra em
`sessionStorage`. Só pedidos com status pago ou a pagar aceitos pela loja
(`processing`, `completed`, `on-hold`, filtro `rastro_eventos_status_compra`);
pedido falho ou cancelado não gera compra. Pix que fica `pending` no pedido
recebido não gera compra pelo navegador (T7). Trava curta
`rastro_compra_trava_<id>` (`add_option`, 60 s) antes de conferir e gravar a
meta. O pedido só é lido depois de conferir a chave do pedido da URL com
a do pedido (`$order->key_is_valid()`), como o WooCommerce faz.

Compatível com o carrinho e o checkout clássicos e sem
erro com os de blocos (eventos de bloco ficam anotados como pendência se não
couberem).

Mapa para o PostHog: mesmo nome canônico, propriedades sem `items` completos
(só `value`, `currency`, `quantidade_itens`).

## 7. Atribuição de origem (T5)

**Captura (`assets/js/atribuicao.js`):** na primeira página da visita lê
`utm_source`, `utm_medium`, `utm_campaign`, `utm_content`, `utm_term`,
`utm_id`, os IDs de clique (`gclid`, `gbraid`, `wbraid`, `fbclid`, `ttclid`,
`msclkid`; guarda só **qual** veio, nunca o valor), o host do `referrer`
(sem caminho) e o caminho da página de entrada (sem query string).

Fonte de tráfego derivada: `utm_source` quando existe; senão o ID de clique
(`google`, `facebook`, `tiktok`, `bing`); senão o host do referrer externo;
senão `direto`. Referrer do próprio site não conta como visita nova.

Guarda dois toques, **primeira visita** e **última visita** (a última só é
trocada por uma visita com origem identificada, ou depois de 30 minutos sem
atividade), no cookie `rastro_origem` (JSON compacto em base64url, até
1,5 KB, `SameSite=Lax`, `Secure` em https, `atribuicao_dias`). Modo `aceite`:
só grava com `analise` aceita; antes disso o toque da página fica na memória
e é gravado se o aceite vier na mesma página. Modo `sessao`: cookie de sessão
antes do aceite, com prazo depois. Modo `desligada`: nada.

**No pedido (`atribuicao/atribuicao.php`):** em
`woocommerce_checkout_order_created` e
`woocommerce_store_api_checkout_order_processed`, lê o cookie, valida cada
campo (lista fechada de chaves, tamanho máximo, só caracteres seguros) e
grava a meta `_rastro_origem`:

```
array(
  'v'        => 1,
  'primeira' => array( 'fonte', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'utm_id', 'clique', 'referrer', 'entrada', 'quando' ),
  'ultima'   => array( mesmas chaves ),
  'consentimento' => 'aceite' | 'sessao',
)
```

Sem cookie: `_rastro_origem = array( 'v' => 1, 'sem' => 'consentimento' | 'dados' )`.
Compatível com HPOS (`$order->update_meta_data`). Meta protegida (prefixo
`_`), nunca mostrada ao cliente nem em e-mail.

**Atribuição nativa do WooCommerce:** com `atribuicao_woo_consentimento`
ligado, a captura nativa (`sbjs_*`) só é permitida depois do aceite de
análise, pelo gancho e pela função de navegador que o WooCommerce oferece
para isso (nomes conferidos no código do WooCommerce instalado antes de
usar; se não existirem na versão, o campo aparece como indisponível e nada
muda).

**No painel do pedido (`atribuicao/pedido.php`):** caixa "Origem da venda"
(primeira e última visita, fonte, campanha, página de entrada) e coluna
"Origem" na lista de pedidos (clássica e HPOS), só para `manage_woocommerce`.

**Leitura unificada:** `rastro_pedido_origem( WC_Order $pedido, string $modelo = 'primeira' ): array`
devolve as chaves acima a partir de, nesta ordem: `_rastro_origem`; metas do
PixelYourSite (só leitura, nomes na seção 11); atribuição nativa do
WooCommerce (`_wc_order_attribution_*`, vale para os dois modelos); senão
`fonte = '(sem dados)'`. Campo `origem_dado` diz de onde veio (`rastro`,
`pys`, `woocommerce`, `nenhum`).

## 8. Relatórios do WooCommerce (T6)

Tela Rastro → **Relatórios** (`manage_woocommerce`), no formato pedido:

- Abas de dimensão: Fonte de tráfego, Página de entrada, `utm_source`,
  `utm_medium`, `utm_campaign`, `utm_content`, `utm_term`.
- Período: hoje, ontem, 7 dias, 30 dias (padrão), este mês, mês passado, 90
  dias, 12 meses, personalizado (máximo 366 dias).
- Modelo: Primeira visita (padrão) ou Última visita.
- Totais: quantidade de valores da dimensão, Pedidos, Venda bruta, Venda
  líquida, Venda total.
- Gráfico de linhas por dia (até 8 séries: as 7 maiores e "Outras"), em SVG
  gerado pelo `relatorios.js`, com legenda clicável, foco por teclado e
  tabela equivalente (acessível).
- Tabela ordenável (Pedidos, Venda bruta, Venda líquida, Venda total), 25,
  50 ou 100 linhas.
- "Baixar CSV" (UTF-8 com BOM, `;`, valores com vírgula decimal).
- Status contados: `status_relatorio` (aba WooCommerce).

Definições (por pedido, na moeda da loja, valor negativo vira zero):
**Venda bruta** = soma do subtotal dos itens, sem imposto e antes de
desconto; **Venda líquida** = bruta menos desconto menos os itens
reembolsados (total sem imposto das linhas de produto dos reembolsos;
reembolso de frete, de taxa ou só de valor fica fora); **Venda total** =
total do pedido (com frete e impostos) menos reembolsado. A bruta e a total
são as do relatório que a loja usa hoje (seção 13.2); a líquida difere dele
de propósito (revisão de 2026-11-11: o reembolso de frete não é venda de
produto). Contas em centavos
inteiros, formatadas só na saída.

`relatorios/dados.php`:
`rastro_relatorio_dados( array $args ): array` com `dimensao`, `inicio`,
`fim`, `modelo`, `status`; devolve `linhas`, `totais`, `serie` (por dia).
Lê os pedidos em páginas de 200 (`wc_get_orders` com `return => ids`,
pedidos da página em lote, memória solta por página, até 50 páginas ou 10
mil pedidos e depois `truncado`; HPOS ou não), usa
`rastro_pedido_origem()`, e guarda o resultado em transient de 10 minutos
(chave com o hash dos argumentos; apagado quando um pedido muda de status).
Função pura de agregação separada para teste:
`rastro_relatorio_agregar( array $pedidos, string $dimensao ): array`.

AJAX `rastro_relatorio` e `rastro_relatorio_csv`: nonce, capacidade,
argumentos por lista fechada, datas validadas. Nenhum dado de cliente na
resposta (só agregados).

## 9. Pontes de site

Cada site que precisa de algo próprio (aviso de cookies que já existe,
PostHog carregado por outro plugin, páginas privadas a mais) escreve uma
ponte no próprio código, só com os ganchos públicos: `rastro_config`,
`rastro_consent_provedores`, `rastro_destinos`, `rastro_pagina_privada`,
`rastro_admin_avisos`, `rastro_falha` e o evento `rastro:event` no
navegador. A ponte nunca edita arquivos do Rastro.

## 10. Frentes, donos de arquivo e ordem de merge

| # | Branch | Arquivos exclusivos |
|---|---|---|
| C0 | `docs/contratos-2026-11-11-plugin-rastreamento` | este contrato |
| T1 | `feat/rastro-base` | `rastro/rastro.php`, `rastro/nucleo/*`, `rastro/admin/*`, `rastro/assets/css/admin.css`, `tests/validate-rastro-base-contract.js`, `tests/php/rastro-base.test.php`, `tests/php/stubs-rastro.php`, `docs/BASE.md` |
| T2 | `feat/rastro-consentimento` | `rastro/consentimento/*`, `rastro/assets/js/consent.js`, `rastro/assets/css/aviso.css`, testes e doc `CONSENTIMENTO` |
| T3 | `feat/rastro-tags` | `rastro/tags/*`, `rastro/assets/js/tags.js`, testes e doc `TAGS` |
| T4 | `feat/rastro-eventos-loja` | `rastro/loja/*`, `rastro/assets/js/eventos.js`, testes e doc `EVENTOS` |
| T5 | `feat/rastro-atribuicao` | `rastro/atribuicao/*`, `rastro/assets/js/atribuicao.js`, testes e doc `ATRIBUICAO` |
| T6 | `feat/rastro-relatorios` | `rastro/relatorios/*`, `rastro/assets/js/relatorios.js`, `rastro/assets/css/relatorios.css`, testes e doc `RELATORIOS` |
| T7 | (planejada) `feat/rastro-servidor` | compra pelo servidor, segredos cifrados; inclui a compra dos pedidos Pix que ficam `pending` no pedido recebido (o navegador não manda, filtro `rastro_eventos_status_compra`) |
| T9 | `feat/atualizador-assinado`, `feat/instalador-plugin-proprio`, `feat/release-assinada` | repositório público, atualizador assinado, instalador e release (seção 14) |

Stubs de teste
compartilhados: só `tests/php/stubs-rastro.php` (T1); as outras frentes põem
stubs extras dentro do próprio arquivo de teste.

Dependências: T2 a T6 partem da T1. T3 usa a API de consentimento da seção 4
e T4 usa `window.rastro.event` da seção 5 só pelos nomes deste contrato, por
isso as cinco podem ser construídas em paralelo e empilhadas depois.

**Ordem de merge (histórico):** C0 → T1 → T2 → T3 → T4 → T5 → T6 → T9.

## 11. Leitura de pedidos antigos (PixelYourSite)

Só leitura, para o relatório ter histórico (D6). Meta `pys_enrich_data`
(array) com `pys_source` e `last_pys_source` (`direct` ou o host do referrer
sem `www.`), `pys_landing` e `last_pys_landing` (endereço de entrada sem
query string), `pys_utm` e `last_pys_utm` (texto
`utm_source:x|utm_medium:y|utm_campaign:z|utm_term:t|utm_content:c`, com
`undefined` onde falta). `rastro_pedido_origem()` traduz: `direct` vira
`direto`, `undefined` vira vazio, e a página de entrada vira só o caminho.
Nenhum código do PixelYourSite é copiado.

## 12. Testes e verificação

- Por frente: contrato Node (estrutura, nomes, proibições: nenhum
  nome de site dentro de `rastro/`, nenhum CDN além dos hosts da
  seção 5, nenhum `innerHTML` com dado externo, nenhum terceiro antes do
  aceite, scripts executados em DOM mínimo) e teste PHP (regras puras,
  validação, escape, agregação).
- Suíte inteira: `node tests/run.js` (todos os `tests/validate-*.js` menos
  `*.archived.js`) verde antes de cada merge e de cada release.
- Lint: `php -l` nos PHP e `node --check` nos JS.
- WordPress local (Playground) com IDs fictícios e `RASTRO_LOCAL`: aviso, aceite parcial e total,
  revogação, eventos da loja até o pedido recebido, origem gravada no pedido,
  relatório com pedidos de teste.
- Revisão independente de segurança e de LGPD antes do merge.

## 13. Levantamentos

### 13.2 PixelYourSite (referência funcional)

Lido só para entender comportamento e dados. Nenhum código copiado.

**Metas nos pedidos antigos (leitura da seção 7, D6):** meta `pys_enrich_data`
(array) com as chaves `pys_source` e `last_pys_source` (fonte de tráfego:
`direct` ou o host do referrer sem `www.`), `pys_landing` e
`last_pys_landing` (endereço de entrada sem query string), `pys_utm` e
`last_pys_utm` (texto `utm_source:x|utm_medium:y|utm_campaign:z|utm_term:t|utm_content:c`,
com `undefined` onde falta). `rastro_pedido_origem()` traduz: `direct` vira
`direto`, `undefined` vira vazio, e a página de entrada vira só o caminho.

**Fórmulas do relatório deles (mantidas na seção 8 para os números baterem
na troca):** bruta = soma dos subtotais dos itens, sem imposto e antes do
desconto; líquida = bruta menos desconto menos (reembolsado menos imposto
reembolsado); total = total do pedido menos reembolsado; valor negativo
vira zero. Os status contados por padrão são Processando e Concluído.
**Diferença no Rastro (revisão de 2026-11-11):** a líquida tira só os itens
reembolsados (linhas de produto dos reembolsos, sem imposto); reembolso de
frete ou só de valor deixa a líquida do Rastro maior que a deles.

**Nomes de evento conferidos neles** (cruzar com a documentação oficial
antes de usar): Meta `ViewContent`, `AddToCart`, `InitiateCheckout`,
`Purchase`; TikTok `ViewContent`, `AddToCart`, `InitiateCheckout`,
`PlaceAnOrder`, `CompletePayment`; GA4 `view_item`, `view_item_list`,
`add_to_cart`, `remove_from_cart`, `begin_checkout`, `purchase`; Google Ads
`conversion` com `send_to: AW-ID/rótulo`, sem itens.
