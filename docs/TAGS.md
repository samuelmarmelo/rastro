# Rastro: tags e desempenho (frente T3)

Contrato: `docs/CONTRATO.md` (seções 1,
2.1, 5, 6, 10 e 12). Arquivos: `rastro/tags/tags.php`
e `rastro/assets/js/tags.js`.

## O que a frente entrega

| Peça | Papel |
|---|---|
| `rastro_destinos()` | os sete destinos com finalidade, ativo e configuração para o navegador |
| `tags` em `window.rastroConfig` | só os destinos ativos, só quando o `tags.js` está na página |
| `assets/js/tags.js` | espera o consentimento, carrega cada destino aceito uma vez, fila de eventos |
| meta `google-site-verification` | no `wp_head` das páginas do site, sem depender de consentimento |
| avisos do painel | GTM com tags diretas e outros plugins de medição ativos |

Nada de terceiro sai no HTML. O servidor só imprime os IDs dentro do JSON da
configuração (`rastro_json()`, com `JSON_HEX_*`, pela base). Os carregadores
rodam no navegador, depois do aceite.

## Destinos

| Destino | Finalidade | Carrega com | Carregador | Início |
|---|---|---|---|---|
| `gtm` | publicidade | publicidade (LGPD: o contêiner pode ter tags que não respeitam o Consent Mode) | `https://www.googletagmanager.com/gtm.js?id=` | `dataLayer.push({ 'gtm.start', event: 'gtm.js' })` |
| `ga4` | analise | análise | `https://www.googletagmanager.com/gtag/js?id=` | `gtag('js')`, `gtag('config', ID, { send_page_view: true, page_location })` |
| `ads` | publicidade | publicidade | o mesmo `gtag/js` (um script só com GA4) | `gtag('config', ID)` |
| `meta` | publicidade | publicidade | `https://connect.facebook.net/en_US/fbevents.js` | `fbq('set', 'autoConfig', false, ID)`, `fbq('init', ID)`, `fbq('track', 'PageView')` |
| `tiktok` | publicidade | publicidade | `https://analytics.tiktok.com/i18n/pixel/events.js` (fila `ttq`) | `ttq.load(ID)`, `ttq.page()` |
| `openai` | publicidade | publicidade | `https://bzrcdn.openai.com/sdk/oaiq.min.js` | `oaiq('init', { pixelId, debug })`, `oaiq('measure', 'page_viewed', { type: 'contents' })` |
| `posthog` | analise | análise | trecho oficial, `array.js` do host da região | `posthog.init` com `person_profiles: 'identified_only'` |

Cada destino de `rastro_destinos()` é
`array( 'finalidade', 'carrega_com', 'ativo', 'id', 'navegador' )`.

- **GA4:** `page_location` é `rastro_url_campanha()`: em página pública, o
  caminho limpo com só as chaves de campanha (`utm_source`, `utm_medium`,
  `utm_campaign`, `utm_content`, `utm_term`, `utm_id`, `gclid`, `gbraid`,
  `wbraid`, `fbclid`, `ttclid`, `msclkid`; as outras são descartadas), para o
  GA4 não perder a atribuição da campanha; valor de `utm_*` com arroba ou
  oito dígitos seguidos sai vazio. Em página privada, `rastro_url_limpa()`
  (sem query string, só até o endpoint). Só sai quando é endereço completo
  (com protocolo), como o GA4 pede.
- **Ads:** `rotulo_compra` vem de `ads_rotulo_compra`, validado de novo.
- **Meta:** `fbq('set', 'autoConfig', false, ID)` antes do `init`: sem a
  configuração automática (cliques em botões e metadados da página). Isso
  não desliga a correspondência avançada automática: ela fica desligada no
  Gerenciador de Eventos da Meta (ver "Correspondência avançada" abaixo).
- **PostHog:** `api_host` `https://us.i.posthog.com` ou
  `https://eu.i.posthog.com` (`posthog_regiao`), `defaults` `2026-08-30`,
  `person_profiles: 'identified_only'`, `session_recording.maskAllInputs`. Em
  página privada também `disable_session_recording: true` e
  `autocapture: false`. No navegador: `before_send` tira consulta e âncora
  dos endereços e, em página privada, troca `$current_url` e `$pathname` pelo
  endereço saneado do servidor; `maskCapturedNetworkRequestFn` tira a
  consulta das requisições gravadas.

### Página privada (chave do pedido)

A chave do pedido (`key=wc_order_...`) e o número do pedido nunca saem:

1. **Google (GA4 e Ads):** antes de qualquer `gtag('config', ...)`, em
   página privada, `gtag('set', 'page_location', <endereço saneado>)` e
   `gtag('set', 'page_referrer', '')` (forma de um campo por chamada, a da
   referência de configuração do GA4; vale para todos os IDs do `gtag`).
2. **Referrer da próxima página:** `<meta name="referrer" content="strict-origin">`
   no `wp_head` (prioridade 1) das páginas privadas, quando o Rastro tem
   algum destino (`rastro_tags_meta_referrer()`, regra pura
   `rastro_tags_meta_referrer_html()`). Depende só do tipo da página e da
   configuração, nunca do visitante: cache de página seguro. A próxima
   página e os scripts de terceiro recebem só a origem do site.
3. **Barra de endereço no pedido recebido:** quando GTM, Meta, TikTok ou
   OpenAI (que leem o endereço sozinhos) vão carregar e o endereço tem algo
   além do caminho saneado (`key`, número do pedido, outra consulta), o
   `tags.js` chama `history.replaceState( null, '', <caminho de rastroConfig.pagina.url> )`
   uma vez, logo antes do primeiro deles. Só análise (GA4, PostHog, que
   recebem o endereço saneado) não mexe na barra.

   **Efeito colateral:** recarregar o pedido recebido depois disso abre o
   endereço sem a chave, e o WooCommerce mostra a página de pedido recebido
   sem os dados do pedido. A compra não repete (meta `_rastro_compra_enviada`).

### Correspondência avançada

O Rastro não manda dado pessoal. A correspondência avançada **automática**
(o pixel lê campos de e-mail e telefone da página) não tem desligamento
conferido por código: tem de ficar desligada no Gerenciador de Eventos da
Meta (configurações do pixel) e no Gerenciador de Eventos do TikTok
(configurações do pixel). O `autoConfig` desligado só tira os cliques e os
metadados da página.

### Meta Pixel no cabeçalho (`meta_cabecalho`, padrão ligado)

A Meta pede o código base entre `<head>` e `</head>` de toda página
([Get Started](https://developers.facebook.com/docs/meta-pixel/get-started),
conferido em 2026-11-16) e o `<noscript>` com a imagem do PageView. O
Rastro segue esse desenho sem quebrar a regra "nada de terceiro antes do
aceite":

| Situação | `<head>` (`wp_head`, prioridade 3, `script#rastro-meta-pixel`) | Rodapé (`wp_footer`) | `tags.js` |
|---|---|---|---|
| Modo com consentimento (`lgpd`, `aceite_automatico`) | a fila `fbq` oficial sem a inserção do `fbevents.js`, com `autoConfig` desligado, `init` e `PageView`. Nenhum pedido à Meta | nada (sem JavaScript não há como pedir consentimento) | depois do aceite de publicidade, insere o `fbevents.js` (a fila do `<head>` é processada) e não repete `init` nem `PageView` |
| Modo `basico`, página pública | o código oficial completo, com o `fbevents.js` assíncrono | `<noscript><img ... facebook.com/tr?id=ID&ev=PageView&noscript=1></noscript>` | marca a Meta como carregada e só entrega eventos |
| Qualquer modo, página privada | só a fila (o `tags.js` saneia o endereço antes de carregar, D4) | nada | igual à primeira linha |
| Campo desligado, equipe, cópia local, destino desligado pela ponte | nada | nada | carrega como antes |

- O `<noscript>` vai no rodapé: dentro do `<head>`, o HTML só aceita `link`,
  `style` e `meta` no `<noscript>`; a documentação avançada da Meta aceita
  "no head ou no body".
- Se um otimizador tirar o script do `<head>`, o `tags.js` não acha a fila
  e carrega do jeito antigo (fila, `init` e `PageView` próprios).
- A revogação segue igual: `fbq('consent', 'revoke')` e recarga.
- Consent API da Meta: `revoke` antes do `init` só pausa o envio; o
  `fbevents.js` ainda baixaria. Por isso o Rastro nem insere o script antes
  do aceite, e a fila não precisa de `revoke`.

### Filtro `rastro_destinos`

Recebe a lista inteira. Só `ativo` e `id` valem: o ID passa de novo pela
validação do esquema da base e um destino só fica ativo com ID válido. A
finalidade e a configuração do navegador são sempre as do Rastro (uma ponte
não consegue transformar um pixel de anúncio em "análise"). Exemplo da ponte
(D8): `$d['posthog']['ativo'] = false;`.

## `tags.js`

### Consentimento

Só a API do contrato (seção 4), sem implementar consentimento:

- evento `rastro:consent` no `document` (`detail` = `{ analise, publicidade, decidido }`);
- `window.rastro.consent.get()` na carga, quando existe (escolha feita antes);
- modo `basico` (seção 2.1): com `rastroConfig.consent.modo === 'basico'` e
  nenhum evento recebido, vale tudo concedido desde a carga;
- modo `aceite_automatico`: só a análise pode chegar sozinha, então GTM,
  Ads, Meta, TikTok e OpenAI seguem esperando a publicidade.

Cada destino carrega com uma das finalidades de `carrega_com`. Revogação (um
destino carregado perdeu o aceite): `parar()`. A recarga da página é da
frente de consentimento.

### Agenda (`carregamento`)

| Valor | Quando a porta abre |
|---|---|
| `ocioso` (padrão) | depois do `load`, em `requestIdleCallback` com `timeout: 2000` (sem ele, no próximo ciclo), ou na primeira interação, o que vier antes |
| `interacao` | primeira `pointerdown`, `keydown`, `scroll` ou `touchstart`; limite de 8 s |
| `imediato` | na hora do aceite |

A agenda começa no primeiro aceite. Aberta a porta, um aceite novo (ex.:
publicidade depois da análise) carrega na hora. Escutas passivas, removidas
quando a porta abre. `preconnect` do host só no momento da carga, um por
host. Cada `<script>` é `async`, uma vez por chave (`data-rastro-tag`), antes
do primeiro script da página, como nos trechos oficiais.

### `window.rastro.event( nome, params, opcoes )`

Evento canônico no formato do GA4 (nome `^[a-z][a-z0-9_]{0,39}$`). Devolve
`true` quando entrou na fila e `false` quando foi recusado (nome inválido,
sem aceite ou parado).

- Dispara `rastro:event` no `document` com `{ nome, params, opcoes }` (cópias)
  na hora da chamada, mesmo antes do aceite. É aviso local: nada sai da
  página por ele, e quem ouvir confere o consentimento.
- Fila só na memória da página (até 100 eventos). Cada destino recebe cada
  evento uma vez: na carga dele (a fila inteira) ou na hora, se já carregou.
  Um destino que carrega depois (publicidade aceita mais tarde) recebe também
  os eventos já na fila.
- Sem aceite (`decidido` sem nenhuma finalidade), a fila é descartada e os
  eventos novos são recusados.
- `opcoes.event_id` (`^[A-Za-z0-9_.:-]{1,80}$`) vira `eventID` na Meta e
  `event_id` no TikTok. Sem ele, a compra usa `rastro-<transaction_id>`.

| Destino | Entrega |
|---|---|
| GTM | eventos de loja: `dataLayer.push({ ecommerce: null })` e depois `{ event, ecommerce: params }`; outros: `{ event, ...params }` |
| GA4 | `gtag('event', nome, { ...params, send_to: G-ID })`, todos os eventos |
| Ads | `gtag('event', nome, { ...params, send_to: AW-ID })` para `view_item_list`, `view_item`, `add_to_cart`, `begin_checkout`, `purchase`, `search`; na compra, com rótulo, também `gtag('event', 'conversion', { send_to: 'AW-ID/rótulo', value, currency, transaction_id })` |
| Meta | `ViewContent`, `AddToCart`, `InitiateCheckout` (com `num_items`), `AddPaymentInfo`, `Purchase`, `Search` (`search_string`); dados `value`, `currency`, `content_type: 'product'`, `content_ids`, `contents` (`id`, `quantity`) |
| TikTok | `ViewContent`, `AddToCart`, `InitiateCheckout`, `AddPaymentInfo`, `Purchase`, `Search`; dados `value`, `currency`, `content_type`, `content_ids`, `contents` (`content_id`, `content_type`, `content_name`, `quantity`, `price`), `search_string` |
| PostHog | mesmo nome, só `value`, `currency` e `quantidade_itens` |
| OpenAI | nenhum evento da loja (só `page_viewed` na carga) |

Eventos fora do mapa (`remove_from_cart`, `view_cart` e nomes próprios) vão
só para GTM, GA4 e PostHog.

### `window.rastro.tags`

- `carregado( destino )`: o destino carregou? Sem argumento: algum carregou?
  Depois de `parar()`, sempre `false`.
- `algumCarregado()`: algum destino carregou nesta página, mesmo depois de
  `parar()` (a revogação usa para decidir a recarga).
- `parar()`: para tudo nesta página (agenda cancelada, fila apagada, eventos
  recusados, nada carrega de novo), `posthog.opt_out_capturing()`,
  `oaiq('consent', false)` e `fbq('consent', 'revoke')` quando carregados.
  TikTok: `ttq.revokeConsent()` quando carregado (Cookie Consent Mode
  oficial: para de compartilhar, inclusive o que estava na fila); a recarga
  da página completa.

Cada carregador e cada entrega rodam num `try/catch`: um destino com erro não
conta como carregado e não derruba os outros.

### Para a frente T4 (eventos da loja)

- `eventos.js` deve depender do handle `rastro-tags`
  (`rastro_registrar_script( 'rastro-eventos', 'eventos.js', array( 'rastro-tags' ) )`)
  e conferir `'function' === typeof window.rastro.event` antes de chamar.
- Mandar os `params` já no formato do GA4 (seção 6): `items` com `item_id`
  em texto, `price` e `quantity` em número, `value` em número e `currency`
  com três letras maiúsculas. O `tags.js` não converte nomes.
- A compra só precisa de `transaction_id` para o `eventID`; outro ID de
  deduplicação vai em `opcoes.event_id`.
- A guarda de "uma vez por pedido" (meta e `sessionStorage`) é da T4: o
  `tags.js` só não repete o mesmo evento por destino dentro da página.

## Verificação do Google

`<meta name="google-site-verification" content="..." />` no `wp_head`
(prioridade 1). O valor (`google_verificacao`) passa de novo pela validação
do esquema na impressão e sai com `esc_attr`. Não depende de consentimento
nem da medição (sai para a equipe logada e mesmo sem nenhuma tag), e não sai
em requisição restrita: painel, login, AJAX, REST, cron, feed, prévia,
Personalizar e 404.

## Verificações de domínio (aba Verificações)

Aba própria no painel (`verificacao`, ordem 45, antes de Relatórios). Cada
campo vira uma `<meta>` no `wp_head` (prioridade 1), com as mesmas regras
da verificação do Google acima. Lista em `rastro_tags_verificacoes()`
(campo => `name` da meta).

| Campo | Meta | Valor aceito | Onde pegar |
|---|---|---|---|
| `google_verificacao` | `google-site-verification` | 20 a 100 letras, números, `-` ou `_` | Search Console, método tag HTML |
| `pinterest_verificacao` | `p:domain_verify` | 20 a 64 letras ou números | Pinterest: Configurações, Contas reivindicadas, Sites, Reivindicar, tag HTML |
| `facebook_verificacao` | `facebook-domain-verification` | 20 a 64 letras ou números | Gerenciador de Negócios da Meta: Configurações do negócio, Segurança da marca, Domínios, método meta tag |

Todas aceitam a tag inteira colada (fica só o `content`). A Meta lista três
métodos (registro TXT no DNS, arquivo HTML ou meta tag no `<head>` da página
inicial) e confere de novo de tempos em tempos: o campo fica preenchido
depois de verificar ([Verifying your Domain](https://developers.facebook.com/docs/sharing/domain-verification/verifying-your-domain),
conferido em 2026-11-16). O nome da meta só aparece no Gerenciador de
Negócios; o formato do valor (30 letras minúsculas e números nos casos
vistos) não está publicado, por isso a regra aceita de 20 a 64.

Pinterest: a ajuda oficial lista quatro métodos (Google Merchant Center,
tag HTML, arquivo HTML ou registro TXT no DNS) e mostra a tag só na conta
([Claim your website](https://help.pinterest.com/en/business/article/claim-your-website),
conferido em 2026-11-16); a especificação oficial da API v5 tem o método
`METATAG`. O nome `p:domain_verify` e o valor (32 caracteres hexadecimais nos
casos vistos) vêm da tag que o Pinterest entrega; a regra aceita de 20 a 64
letras ou números.

### Arquivo de verificação (TikTok e outros)

Campos `arquivo_verificacao_nome` (até 100 letras, números, `-` ou `_`,
terminando em `.txt`) e `arquivo_verificacao_conteudo` (letras, números e
`= : . _ -`, até 300). Com os dois preenchidos, `GET` ou `HEAD` em
`<página inicial>/<nome>` responde o conteúdo como `text/plain; charset=utf-8`
com `X-Content-Type-Options: nosniff` (módulo `verificacao/arquivo.php`, no
`parse_request`, prioridade 0). Nada é gravado em disco; o painel, outros
métodos e outros endereços seguem normais. Serve para o arquivo de
assinatura do TikTok for Developers e para qualquer serviço que peça um
`.txt` simples na raiz. O registro TXT no DNS fica com quem administra o
domínio.

## TikTok Pixel no cabeçalho (`tiktok_cabecalho`, padrão ligado)

O TikTok pede o código base no topo do `<head>`. O trecho oficial chama
`ttq.load()`, que baixa o `events.js` na hora, mesmo com `holdConsent()`.
Então:

- Modo `basico`, página pública: o código oficial completo no `wp_head`
  (prioridade 3, `script#rastro-tiktok-pixel`) com `ttq.load(ID)` e
  `ttq.page()`; o `tags.js` recebe `cabecalho: 'completo'` e não carrega de
  novo (só entrega os eventos).
- Com consentimento (`lgpd`, `aceite_automatico`) ou em página privada:
  nada no `<head>`; o `tags.js` carrega depois do aceite de publicidade,
  como antes.

## Avisos do painel (`rastro_admin_avisos`)

- GTM ligado junto com GA4, Ads, Meta ou TikTok diretos: aviso de contar a
  mesma visita duas vezes (OpenAI e PostHog não entram, não são tags do
  contêiner).
- Plugin de medição ativo (pelo arquivo principal em `active_plugins`, pela
  rede no multisite, pela constante ou pela classe): `warning` quando o
  Rastro tem destino, `info` quando não tem. O Rastro não desativa plugin de
  ninguém. Lista no filtro `rastro_tags_plugins_conhecidos`.

| Plugin | Arquivo principal | Constante / classe |
|---|---|---|
| PixelYourSite | `pixelyoursite/facebook-pixel-master.php` | `PYS_FREE_VERSION` |
| PixelYourSite Pro | `pixelyoursite-pro/pixelyoursite-pro.php` | `PYS_VERSION` (não conferido) |
| Google Analytics for WooCommerce (antigo WooCommerce Google Analytics Integration) | `woocommerce-google-analytics-integration/woocommerce-google-analytics-integration.php` | `WC_GOOGLE_ANALYTICS_INTEGRATION_VERSION`, `WC_Google_Analytics_Integration` |
| Site Kit by Google | `google-site-kit/google-site-kit.php` | `GOOGLESITEKIT_VERSION` |
| GTM4WP | `duracelltomi-google-tag-manager/duracelltomi-google-tag-manager-for-wordpress.php` | `GTM4WP_VERSION` |
| MonsterInsights | `google-analytics-for-wordpress/googleanalytics.php` | `MONSTERINSIGHTS_VERSION`, `MonsterInsights_Lite` |
| Meta for WooCommerce | `facebook-for-woocommerce/facebook-for-woocommerce.php` | `WC_Facebook_Loader` |
| TikTok | `tiktok-for-business/tiktok-for-woocommerce.php` | (só o arquivo) |
| Google for WooCommerce | `google-listings-and-ads/google-listings-and-ads.php` | `WC_GLA_VERSION` |

Nomes conferidos em 2026-11-11 no código de cada plugin no diretório de
plugins do WordPress (versão em desenvolvimento), menos o Pro.

## Funções e ganchos

Do contrato: `rastro_destinos(): array` e o filtro `rastro_destinos`.

Acrescentadas nesta frente:

| Função | Para quê |
|---|---|
| `rastro_destinos_ativos(): array` | chaves dos destinos ativos |
| `rastro_destinos_montar( array $config, array $pagina ): array` | regra pura dos destinos |
| `rastro_destinos_aplicar_filtro( array $destinos, $filtrado, array $config, array $pagina ): array` | regra pura do filtro |
| `rastro_tags_finalidades(): array` | finalidade e `carrega_com` fixos |
| `rastro_tags_navegador_destino( string $destino, string $id, array $config, array $pagina ): array` | configuração do navegador de um destino |
| `rastro_tags_id( string $chave, $bruto ): string` | ID validado pelo esquema ('' inválido) |
| `rastro_tags_posthog_host( string $regiao ): string` | host da API do PostHog |
| `rastro_tags_config_navegador(): array` | chave `tags` |
| `rastro_google_verificacao(): string`, `rastro_tags_meta_html( $valor ): string` | verificação do Google |
| `rastro_tags_regra_avisos( array $ativos, array $plugins, array $nomes ): array` | regra pura dos avisos |
| `rastro_tags_plugins_conhecidos(): array`, `rastro_tags_plugins_ativos(): array` | plugins de medição |

| Gancho | Tipo | Assinatura |
|---|---|---|
| `rastro_destinos` | filtro | `( array $destinos )`; só `ativo` e `id` valem |
| `rastro_tags_plugins_conhecidos` | filtro | `( array $lista )`: `nome => array( 'arquivos', 'constantes', 'classes' )` |

Ligações: `rastro_config_navegador` (10), `wp_enqueue_scripts` (20, depois do
consentimento; depende de `rastro-consent` quando registrado), `wp_head` (1,
verificação), `rastro_admin_avisos` (10).

A chave `tags` só entra na configuração quando `rastro-tags` está na fila:
no WordPress, `wp_enqueue_scripts` roda dentro do `wp_head` na prioridade 1,
antes do `<script id="rastro-config">`.

## CSP

Hosts a liberar com cada destino ligado (para quem usa Content Security
Policy). Os scripts do Rastro são do próprio site.

| Destino | `script-src` | `connect-src` | `img-src` |
|---|---|---|---|
| GTM | `https://www.googletagmanager.com` | `https://www.googletagmanager.com`, `https://www.google.com` | `https://www.googletagmanager.com` |
| GA4 | `https://www.googletagmanager.com` | `https://www.googletagmanager.com`, `https://*.google-analytics.com`, `https://*.g.doubleclick.net`, `https://www.google.com`, `https://pagead2.googlesyndication.com` | `https://www.googletagmanager.com`, `https://*.google-analytics.com`, `https://*.g.doubleclick.net`, `https://www.google.com` |
| Ads | `https://www.googletagmanager.com`, `https://www.googleadservices.com`, `https://www.google.com` | `https://www.googletagmanager.com`, `https://www.googleadservices.com`, `https://googleads.g.doubleclick.net`, `https://pagead2.googlesyndication.com`, `https://www.google.com`, `https://ad.doubleclick.net` | `https://www.googletagmanager.com`, `https://www.googleadservices.com`, `https://googleads.g.doubleclick.net`, `https://pagead2.googlesyndication.com`, `https://www.google.com` |
| Meta | `https://connect.facebook.net` | `https://www.facebook.com`, `https://connect.facebook.net` | `https://www.facebook.com` |
| TikTok | `https://analytics.tiktok.com` | `https://analytics.tiktok.com` | `https://analytics.tiktok.com` |
| OpenAI | `https://bzrcdn.openai.com` | `https://bzrcdn.openai.com`, `https://bzr.openai.com` | `https://bzr.openai.com` |
| PostHog (us) | `https://us-assets.i.posthog.com` | `https://us.i.posthog.com`, `https://us-assets.i.posthog.com` | |
| PostHog (eu) | `https://eu-assets.i.posthog.com` | `https://eu.i.posthog.com`, `https://eu-assets.i.posthog.com` | |

Google: cada domínio de país do Google (`https://www.google.<TLD>`) entra
um por um, se o site atende fora do Brasil (a CSP não aceita curinga no fim
do nome). O PostHog também aceita `https://*.posthog.com` e pede
`worker-src 'self' blob: data:` para a gravação de sessão. O inline
`rastro-config` precisa de nonce (o WordPress repassa pelo filtro de
atributos) ou hash.

## Conferido (Protocolo da verdade, 2026-11-11)

- GTM: trecho oficial do contêiner (`gtm.start`, `event: 'gtm.js'`,
  `gtm.js?id=`), página "Data layer" do Google Tag Platform.
- GA4: `page_location` (endereço completo com protocolo) e `send_page_view`
  (booleano, padrão `true`) na página "Measure pageviews"; itens e compra
  (`transaction_id`, `value`, `tax`, `shipping`, `currency`, `coupon`,
  `items` com `item_id`, `item_name`, `price`, `quantity`, `item_category`,
  `item_variant`) no guia de comércio eletrônico do GA4.
- Google Ads: `gtag('event', 'conversion', { send_to: 'TAG_ID/CONVERSION_LABEL', value, currency })`
  e `transaction_id` na ajuda do Google Ads.
- Meta: `fbq('set', 'autoConfig', false, 'ID')` acima do `init` (página
  "Advanced" do Meta Pixel: desliga o envio de cliques em botões e
  metadados da página); `fbq('consent', 'revoke')` pausa o envio do Pixel
  (página "General Data Protection Regulation" do Meta Pixel).
- Google: `gtag('set', 'page_location', ...)` e
  `gtag('set', 'page_referrer', ...)` na referência de configuração do GA4
  (`page_location` até 1000 caracteres, `page_referrer` até 420).
- Meta: trecho oficial do Pixel (fila `fbq`, `fbevents.js`, `init`,
  `PageView`), eventos padrão `ViewContent`, `AddToCart`,
  `InitiateCheckout`, `AddPaymentInfo`, `Purchase`, `Search`, propriedades
  `content_ids`, `content_type`, `contents` (`id`, `quantity`), `currency`,
  `value`, `num_items`, `search_string`, e `{ eventID }` no quarto
  argumento.
- TikTok: eventos padrão atuais `ViewContent`, `AddToCart`,
  `InitiateCheckout`, `AddPaymentInfo`, `Purchase`, `Search` (o `Purchase`
  é o recomendado hoje para compra; `CompletePayment` e `PlaceAnOrder` não
  estão mais na lista).
- OpenAI: carregador `bzrcdn.openai.com/sdk/oaiq.min.js`, `init` com
  `pixelId` e `debug`, `measure` `page_viewed` com `type: 'contents'`,
  `oaiq('consent', false)` e os hosts de CSP.
- PostHog: `api_host` por região, `defaults` mais recente `2026-08-30`,
  `person_profiles: 'identified_only'`, `disable_session_recording`,
  `autocapture`, `before_send`, `array.js` em `<região>-assets.i.posthog.com`.
- CSP do Google (página "Content Security Policy" do Google Tag Platform).

## Não conferido

- **TikTok (conferido em 2026-11-16):** o trecho de carga oficial
  (business-api.tiktok.com, Install the pixel using code, v1.3) bate com o
  do Rastro, com `holdConsent`, `revokeConsent` e `grantConsent` na lista.
  Eventos atuais: `ViewContent`, `AddToCart`, `InitiateCheckout`,
  `AddPaymentInfo`, `Search` e `Purchase` (`CompletePayment` e
  `PlaceAnOrder` saíram da lista). Parâmetros `contents`, `content_ids`,
  `content_type`, `value`, `currency`, `search_string`; `{ event_id }` no
  terceiro argumento do `ttq.track` (página Event deduplication). O
  `ttq.load()` baixa o `events.js` mesmo com `holdConsent()`: por isso, com
  consentimento, o TikTok só carrega depois do aceite.
- **TikTok e Meta, hosts de CSP:** não há página oficial lida com a lista;
  os da tabela são os do carregador e do envio conhecidos.
- **Google, instalação nova:** a página de instalação do gtag.js hoje mostra
  o script `gtm.js?id=TAG_ID` e diz que `gtag('config')` está obsoleto, mas
  que continua funcionando. O contrato pede `gtag/js` com `config` (preciso
  para o `page_location` saneado e o `send_to`); a troca para `gtag('set')`
  fica para decidir.
- **Google Ads, eventos do GA4 com `send_to: AW-ID`** (`view_item`,
  `add_to_cart`, `begin_checkout`, `purchase`, `search`): o contrato pede, a
  ajuda lida só mostra o `conversion`.
- **OpenAI, eventos da loja:** a documentação mostra `contents_viewed`,
  `items_added`, `checkout_started` e `order_created` com `amount` inteiro
  (unidade não clara). Ficaram fora até a decisão; só `page_viewed` sai.
- **PostHog, trecho:** copiado do trecho oficial; a
  página atual mostra uma lista de métodos menor. `session_recording.maskAllInputs`
  e `maskCapturedNetworkRequestFn` não aparecem na página de configuração
  (estão no manual de gravação de sessão, não lido).
- **PixelYourSite Pro:** arquivo `pixelyoursite-pro/pixelyoursite-pro.php` e
  constante `PYS_VERSION` não conferidos (plugin pago, fora do diretório).
- **GA4 em página privada:** `page_referrer` vazio: a referência diz que o
  campo é um endereço e não diz se o vazio é aceito ou se o `gtag.js` volta
  ao `document.referrer`. O título da página não é trocado.
- **Correspondência avançada automática por código:** nem a Meta nem o
  TikTok documentam como desligar no código; a página da Meta sobre
  `autoConfig` não cita a correspondência avançada. Fica no Gerenciador de
  Eventos de cada um.
- **TikTok, verificação de domínio:** o TikTok for Developers verifica
  domínio por registro TXT no DNS (fora do WordPress) e prefixo de endereço
  por um arquivo de assinatura; o Business Center verifica a empresa por
  documentos. Nenhuma meta tag oficial do TikTok foi encontrada. O Rastro
  oferece o arquivo (seção Verificações de domínio); nome e conteúdo exatos
  vêm da tela do TikTok.
- **TikTok, cookies:** a ajuda oficial cita `_ttp`, `ttcsid_*` e `ttclid`
  (primeira parte); a limpeza na revogação é do consentimento
  (`docs/CONSENTIMENTO.md`).
- **Comportamento real** em navegador com cada serviço (só o DOM falso do
  contrato); falta o teste no Playground com IDs fictícios e `RASTRO_LOCAL`.

## Testar

```
php -l rastro/tags/tags.php
node --check rastro/assets/js/tags.js
php tests/php/rastro-tags.test.php
node tests/validate-rastro-tags-contract.js
```

O contrato roda o `tags.js` num DOM mínimo falso com relógio falso
(`requestIdleCallback`, `setTimeout`, eventos de interação) e um
consentimento falso (`rastro:consent`), e roda o teste PHP quando há `php`
no PATH.

## Fila dos eventos da loja

`eventos.js` (T4) pode rodar antes do `tags.js` e empilha `[ nome, params, opcoes ]` em `window.rastroFilaEventos`. Ao iniciar, o `tags.js` entrega a fila na ordem pelo `window.rastro.event()`, deixa-a vazia e troca o `push` para entregar na hora. Contrato: `tests/validate-rastro-tags-contract.js`.
