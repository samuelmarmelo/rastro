# Rastro: atribuição de origem (frente T5)

Contrato: `docs/CONTRATO.md` (seções
2.1, 7, 10, 12 e 13.2). Pasta: `rastro/`.

| Arquivo | Papel |
|---|---|
| `atribuicao/atribuicao.php` | validação do cookie, meta `_rastro_origem` no pedido, leitura unificada `rastro_pedido_origem()`, atribuição nativa do WooCommerce esperando o aceite, script e configuração do navegador |
| `atribuicao/pedido.php` | caixa "Origem da venda" e coluna "Origem" no painel do pedido |
| `assets/js/atribuicao.js` | captura da visita e cookie `rastro_origem` |

## No navegador (`atribuicao.js`)

Roda só com `rastroConfig.atribuicao` (sem ele, nada). A página leva o script
quando a loja tem WooCommerce, não é cópia local, nem requisição restrita, nem
equipe, e a atribuição não está desligada (ou a atribuição nativa espera o
aceite, para o script liberar o WooCommerce). A configuração sai mesmo sem
tag configurada (filtro `rastro_precisa_saida`).

`rastroConfig.atribuicao`: `modo` (`aceite`, `sessao`, `desligada`), `dias`,
`utmSempre`, `basico`, `woo`, `cookie`, `max`. Nenhum dado do visitante.

Captura em cada página: `utm_source`, `utm_medium`, `utm_campaign`,
`utm_content`, `utm_term`, `utm_id`; qual ID de clique veio (`gclid`,
`gbraid`, `wbraid`, `fbclid`, `ttclid`, `msclkid`, só o nome, o valor nunca é
guardado); host do referrer externo (sem caminho); caminho da página (sem
query string). Fonte: `utm_source` em minúsculas; senão o clique (`google`,
`facebook`, `tiktok`, `bing`); senão o host do referrer sem `www.`; senão
`direto`. Referrer do próprio site (com ou sem `www.`) não é visita nova.

Valor de `utm_*` que parece dado pessoal sai vazio, antes de qualquer
limpeza: com arroba (e-mail) ou com oito dígitos seguidos ou mais (CPF,
telefone, CEP). A arroba também não entra em nenhum texto (fonte, campanha).

Primeira e última visita: a primeira não muda; a última só troca por uma
visita com origem identificada (campanha ou referrer externo) ou depois de 30
minutos sem atividade (`a`, renovado a cada gravação).

Cookie `rastro_origem`: JSON compacto em base64url, até 1536 caracteres
(passou disso, os textos são cortados a 60, 30 e 15 caracteres; se ainda não
couber, não grava), `path=/`, `SameSite=Lax`, `Secure` em https, sem domínio
(só o host). Formato:

```
{ "v": 1, "p": toque, "u": toque, "a": segundos, "o": base }
toque = { f: fonte, s: utm_source, m: utm_medium, c: utm_campaign, n: utm_content,
          t: utm_term, i: utm_id, k: clique, r: referrer, e: entrada, q: segundos }
```

Quando grava (`o` é a base legal do que foi gravado):

| Situação | Grava? | `o` | Prazo |
|---|---|---|---|
| análise aceita (`rastro:consent` ou `window.rastro.consent.get()`) | sim | `aceite` | `atribuicao_dias` |
| modo básico (`rastroConfig.consent.modo === 'basico'` ou `atribuicao.basico`), mesmo sem o evento | sim | `basico` | `atribuicao_dias` |
| visita de campanha (`utm_*` ou ID de clique) com `atribuicao_utm_sempre`, antes do aceite | sim | `campanha` | `atribuicao_dias` |
| modo `sessao`, sem aceite, sem campanha | sim | `sessao` (ou `campanha` se o cookie já era de campanha) | sessão (ou o prazo da campanha) |
| modo `aceite`, sem aceite, sem campanha | não: fica na memória e grava se o aceite vier nesta página | | |
| modo `desligada` | nunca | | |

Depois de uma revogação na mesma página (análise de aceita para negada),
nada mais é gravado nela; quem apaga o cookie é o consentimento (seção 4):
ele só fica quando a visita de campanha está ligada, `o` é `campanha` e a
primeira e a última visita são de campanha (`s`, `m`, `c`, `n`, `t`, `i`
ou `k`); na dúvida, apaga (`docs/CONSENTIMENTO.md`).
`window.rastro.atribuicao.estado()` devolve o toque da página para
depuração.

## No pedido

`woocommerce_checkout_order_created` (checkout clássico) e
`woocommerce_store_api_checkout_order_processed` (checkout de blocos), com
prioridade 20, chamam `rastro_atribuicao_gravar_pedido()`: uma vez por pedido
(meta já existente não é trocada), pelo objeto do pedido
(`update_meta_data` e `save_meta_data`, HPOS), nada em modo `desligada`.

Validação do cookie (`rastro_atribuicao_ler_cookie()`): até 1536
caracteres, só o alfabeto base64url (decodificado sem `base64_decode`),
JSON com profundidade 4, chaves fechadas (`v`, `p`, `u`, `a`, `o` e as do
toque), `v` igual a 1, base na lista. Por campo: texto (letras e números de
qualquer idioma, espaço e `._~:/()+,!|%-`, sem arroba, sem espaço nas
pontas, até 100; nos `utm_*`, tipo `campanha`, oito dígitos seguidos deixam
o campo vazio sem recusar o cookie),
clique da lista, host (`[a-z0-9.-]`, até 100), caminho (começa com `/`, sem
query, até 200), segundos de 2020 até um dia à frente. Qualquer campo
inválido recusa o cookie inteiro.

Meta `_rastro_origem` (protegida, nunca mostrada ao cliente nem em e-mail):

```
array(
  'v'             => 1,
  'primeira'      => array( 'fonte', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content',
                            'utm_term', 'utm_id', 'clique', 'referrer', 'entrada', 'quando' ),
  'ultima'        => array( mesmas chaves ),
  'consentimento' => 'aceite' | 'sessao' | 'campanha' | 'basico',
)
```

`quando` em ISO 8601 UTC (`2026-11-11T14:05:00Z`). Sem cookie válido:
`array( 'v' => 1, 'sem' => 'consentimento' )` quando o modo é `aceite`, o modo
de consentimento não é `basico` e o servidor não sabe de aceite de análise
(`rastro_consent_servidor()` da T2, quando existir); senão
`array( 'v' => 1, 'sem' => 'dados' )`.

## Leitura unificada (para a T6)

`rastro_pedido_origem( WC_Order $pedido, string $modelo = 'primeira' ): array`
(`modelo` `primeira` ou `ultima`; outro valor vale `primeira`). Devolve
sempre estas chaves, nesta ordem, todas texto:

```
fonte, utm_source, utm_medium, utm_campaign, utm_content, utm_term, utm_id,
clique, referrer, entrada, quando, origem_dado
```

Ordem de leitura:

1. `_rastro_origem` (revalidada campo a campo; na `ultima` inválida vale a
   `primeira`): `origem_dado = 'rastro'`.
2. PixelYourSite, meta `pys_enrich_data` (só leitura): primeira visita
   `pys_source`, `pys_landing`, `pys_utm`; última `last_pys_*` (sem eles, os
   `pys_*`). `direct` vira `direto`, `undefined` vira vazio, a página de
   entrada vira só o caminho, o `pys_source` (host) vai para `referrer`.
   `quando` e `clique` ficam vazios. `origem_dado = 'pys'`.
3. Atribuição nativa do WooCommerce, metas `_wc_order_attribution_*`
   (prefixo pelo mesmo filtro `wc_order_attribution_tracking_field_prefix`):
   `source_type`, `utm_source`, `utm_medium`, `utm_campaign`, `utm_content`,
   `utm_term`, `utm_id`, `referrer` (vira host), `session_entry` (vira
   caminho), `session_start_time` (vira ISO). `typein` e `(direct)` viram
   `direto`, `admin` vira `(admin)`, `(none)` vira vazio. A mesma visita vale
   para os dois modelos. `origem_dado = 'woocommerce'`.
4. Nada: `fonte = '(sem dados)'`, ou `'(sem consentimento)'` quando a meta
   do Rastro diz `sem = consentimento` (a linha "Sem consentimento" do
   relatório, D5); `origem_dado = 'nenhum'`.

Valor inválido vindo do PixelYourSite ou do WooCommerce vira vazio (mesmas
regras de caractere e tamanho, e a mesma regra de campanha: arroba ou oito
dígitos seguidos viram vazio).

## Atribuição nativa do WooCommerce

Conferido no código do WooCommerce (tags 8.6.0, 9.0.0 e 11.2.2, a versão da
produção):

- `plugins/woocommerce/src/Internal/Orders/OrderAttributionController.php`:
  filtro `wc_order_attribution_allow_tracking` (`@since 8.5.0`), lido ao
  enfileirar `wc-order-attribution` e passado ao navegador como
  `wc_order_attribution.params.allowTracking`; scripts `sourcebuster-js` e
  `wc-order-attribution`; grava as metas em `woocommerce_checkout_order_created`.
- `plugins/woocommerce/client/legacy/js/frontend/order-attribution.js`:
  `window.wc_order_attribution.setOrderTracking( allow )` (a partir da
  8.6.0; na 8.5.0 não existe). Com `false` apaga os cookies `sbjs_*`; roda
  uma vez na carga com `params.allowTracking`.
- `plugins/woocommerce/src/Internal/Traits/OrderAttributionMeta.php`: campos
  e prefixo `wc_order_attribution_` (metas `_wc_order_attribution_<campo>`).
- `plugins/woocommerce/src/Internal/Integrations/WPConsentAPI.php`: o próprio
  WooCommerce já liga o mesmo filtro à WP Consent API (categoria
  `marketing`) quando ela está ativa.

Com `atribuicao_woo_consentimento` ligado, WooCommerce 8.6.0 ou mais novo e
modo de consentimento diferente de `basico`:

1. O filtro devolve `false` (prioridade 20): HTML igual para todos, cache de
   página seguro.
2. `wc-order-attribution` passa a depender de `rastro-atribuicao`, e este de
   `rastro-consent` quando registrado: o Rastro roda antes e acerta
   `params.allowTracking` conforme o aceite já dado. Sem isso o WooCommerce
   apagaria os cookies `sbjs_*` a cada página antes do aceite chegar. Efeito
   colateral: como o script do WooCommerce não tem `defer`, o WordPress
   carrega os dois do Rastro sem `defer` nessas páginas (no rodapé, na
   ordem certa).
3. Com o aceite de análise (`rastro:consent`), `setOrderTracking( true )`;
   na revogação, `setOrderTracking( false )`.

Versão anterior à 8.6.0: nada muda (o filtro não é usado) e o painel do
Rastro mostra o aviso "indisponível nesta versão" pelo filtro
`rastro_admin_avisos`.

## Painel do pedido

- Caixa "Origem da venda" (`add_meta_boxes`, lateral) nas telas `shop_order`
  e `woocommerce_page_wc-orders` (`wc_get_page_screen_id( 'shop-order' )`),
  com primeira e última visita (fonte, meio, campanha, conteúdo, termo, ID da
  campanha, clique de anúncio, site de origem, página de entrada, quando) e
  de onde veio o dado.
- Coluna "Origem" (fonte e meio da primeira visita), depois do total, pelos
  mesmos ganchos que o WooCommerce usa para a coluna dele:
  `manage_{tela}_columns`, `manage_edit-{tela}_columns`,
  `manage_{tela}_custom_column` (HPOS, recebe o pedido) e
  `manage_{tela}_posts_custom_column` (clássica, recebe o ID).
- Só com `manage_woocommerce`, tudo por `esc_html`.

## Não conferido

- Comportamento real no Playground (cookie, pedido nos dois checkouts,
  caixa e coluna nas duas telas): só testes sem WordPress.
- Formato de `pys_enrich_data` em pedidos reais: lido da descrição do
  contrato (seção 13.2), sem acesso ao banco da produção. A chave
  `pys_utm_id` (IDs de clique do PixelYourSite) não é lida.
- Nome do handle do consentimento (`rastro-consent`, exemplo da base): se a
  T2 usar outro, a dependência não entra e o `atribuicao.js` lê o aceite pelo
  evento (o WooCommerce perde os `sbjs_*` da primeira página).
- Visita de campanha antes do aceite usa o prazo de `atribuicao_dias` (não
  cookie de sessão): decisão desta frente, a confirmar.

## Pedidos para a base (T1)

- O esquema não tem como marcar um campo como indisponível; o aviso da
  atribuição nativa sai por `rastro_admin_avisos`. Sugestão: chave
  `disponivel` (callable) no esquema para o campo aparecer desabilitado.

## Testar

```
php -l rastro/atribuicao/atribuicao.php
php tests/php/rastro-atribuicao.test.php
node tests/validate-rastro-atribuicao-contract.js
```
