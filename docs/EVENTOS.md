# Rastro: eventos da loja (frente T4)

Contrato: `docs/CONTRATO.md` (seções
1, 2 D3 e D4, 3.2, 6, 10, 12 e 13.2). Base: `docs/BASE.md`.

| Arquivo | Papel |
|---|---|
| `rastro/loja/eventos.php` | monta os dados dos eventos no PHP, sem dado pessoal; compra uma vez por pedido |
| `rastro/assets/js/eventos.js` | dispara os eventos no navegador (JS puro, sem dependência) |
| `tests/php/rastro-eventos.test.php` | regras, item, modos, composto, compra, status, liga e desliga, dado pessoal |
| `tests/validate-rastro-eventos-contract.js` | estrutura, proibições e o `eventos.js` num DOM mínimo falso |

## Quando liga

Tudo junto: WooCommerce ativo (`WC()` e `wc_get_product()` existem),
`eventos_loja` ligado, a página medida (`rastro_deve_medir()`: tem destino,
não é cópia local, não é requisição restrita, não é equipe) e o evento
ligado na sua chave `evento_<nome>`. Sem nada disso, nem a chave `eventos`
nem o script saem. Medir também no carrinho, no checkout e no pedido
recebido é a decisão D3; o endereço dessas páginas sai saneado pela base
(D4).

## Contrato com o módulo de tags (T3)

O `eventos.js` não envia nada a terceiro. Cada evento sai assim:

```js
if ( window.rastro && typeof window.rastro.event === 'function' ) {
  window.rastro.event( nome, params, opcoes );
} else {
  window.rastroFilaEventos = window.rastroFilaEventos || [];
  window.rastroFilaEventos.push( [ nome, params, opcoes ] );
}
```

A T3 precisa, ao criar `window.rastro.event`, esvaziar
`window.rastroFilaEventos` na ordem (cada entrada é
`[ nome, params, opcoes ]`, com `params` e `opcoes` sempre objetos) e
deixar a fila vazia. Os eventos da carga da página saem quando o
`eventos.js` roda (rodapé, `defer`); a ordem entre os dois scripts não
importa por causa da fila.

- `nome`: nome canônico GA4 da seção 6.
- `params`: formato GA4 (`currency`, `value`, `items`, mais os do evento).
- `opcoes`: `{}`; na compra, `{ event_id: 'rastro-<id do pedido>' }` para
  deduplicar (Meta `eventID`, e o mesmo valor na T7).

## Dados no `rastroConfig.eventos`

| Chave | Conteúdo |
|---|---|
| `moeda` | moeda da loja |
| `id_produto` | `id` ou `sku` |
| `ligados` | eventos ligados |
| `carga` | eventos da carga da página: `{ nome, params, opcoes?, guarda? }` |
| `produto` | ID do produto da página de produto |
| `produtos` | item por ID (produto da página, filhos de agrupado, itens da lista), para o `add_to_cart` |
| `variacoes` | item por ID de variação (até 50), para o `add_to_cart` de produto variável |
| `carrinho` | item por chave do carrinho, para o `remove_from_cart` |
| `checkout` | `{ params, assinatura }` do carrinho, para o `add_payment_info` |

Item (GA4): `item_id` (ID, ou SKU com `id_produto = sku`; SKU vazio cai no
ID), `item_name`, `price`, `quantity`, `item_category` (primeira
categoria, quando há), `item_variant` (variação, quando há). Valores em
número com ponto, arredondados em duas casas, sem imposto. Numa variação, o
`item_id` é o da variação e o `item_name` é o do produto pai.

## Eventos

| Evento | Onde | Como |
|---|---|---|
| `view_item_list` | loja, categoria, busca | carga; até 20 produtos da consulta principal; `item_list_id` (`loja`, `categoria`, `busca`) e `item_list_name` (nome da categoria) |
| `view_item` | página de produto | carga |
| `add_to_cart` | formulário `form.cart` (simples, variável, agrupado, composto e o tipo comedouro como um item só) | envio do formulário, na fase de captura (antes do AJAX do tema); variação obrigatória no `variations_form`; envio repetido em 1,5 s não conta |
| `add_to_cart` | botões das listas | evento `added_to_cart` do WooCommerce, só com `window.jQuery` (sem jQuery, nada quebra); botão dentro de `form.cart` não conta de novo |
| `remove_from_cart` | carrinho e minicart | clique em `a[href*="remove_item="]` (o link de `wc_get_cart_remove_url()`); chave por `data-cart_item_key`, `data-cart-key` ou o próprio endereço; item de `carrinho` ou, sem ele, o mínimo do link (`data-product_id`, `data-product_sku`); uma vez por chave |
| `view_cart` | carrinho | carga |
| `begin_checkout` | checkout (fora do pagar pedido) | carga; uma vez por carrinho na sessão (`sessionStorage`, guarda `begin_checkout:<assinatura>`) |
| `add_payment_info` | checkout | primeira mudança feita por quem compra em `input[name="payment_method"]` (mudança por script não conta) ou, sem mudança, o envio de `form.checkout` com a forma marcada; uma vez por carrinho na sessão; `payment_type` = ID da forma |
| `purchase` | pedido recebido | carga; ver abaixo |
| `search` | busca | carga; `search_term` sem dado pessoal (termo com `@` ou com 8 dígitos ou mais não sai) |

## Compra uma vez por pedido

1. Número do pedido pelo `order-received` e chave pelo `key` da URL, com os
   mesmos filtros do WooCommerce (`woocommerce_thankyou_order_id`,
   `woocommerce_thankyou_order_key`). Sem número ou sem chave, o pedido nem
   é buscado.
2. `wc_get_order()`, `instanceof WC_Order` e `$order->key_is_valid( $chave )`
   antes de qualquer outra leitura; o ID precisa bater com o da URL.
3. Só `processing`, `completed` e `on-hold` (`has_status()`), lista do
   filtro `rastro_eventos_status_compra` (sem o prefixo `wc-`; `failed`,
   `cancelled`, `refunded`, `checkout-draft` e `trash` nunca valem).
   **Pix:** pedido que fica `pending` no pedido recebido (o pagamento ainda
   não confirmou) não manda compra pelo navegador, e a página não volta a
   ser aberta quando o Pix confirma. Essa compra só chega com a frente T7
   (compra pelo servidor). Quem preferir mandar o pendente pelo navegador
   acrescenta `pending` pelo filtro, sabendo que um Pix que nunca for pago
   conta como compra.
4. Meta `_rastro_compra_enviada` (data ISO 8601) já existe: nada sai. Não
   existe: toma a trava curta `rastro_compra_trava_<id>` (`add_option()`
   sem carga automática com a hora; mais de 60 s vale como trava morta e é
   trocada; outra requisição com a trava: nada sai), lê as metas de novo do
   banco (`read_meta_data( true )`), confere a meta outra vez, monta a
   compra, grava a meta com `update_meta_data()` e `save_meta_data()`
   (compatível com HPOS), tira a trava e imprime. A mesma requisição não
   grava duas vezes (memória da requisição). A trava reduz a janela de duas
   abas marcarem juntas, mas não é atômica: o `add_option()` do WordPress
   confere a opção e depois usa `INSERT ... ON DUPLICATE KEY UPDATE`.
5. No navegador, guarda extra `purchase:rastro-<id>` no `sessionStorage`;
   ao sair a compra, as marcas de `begin_checkout` e `add_payment_info` são
   limpas (a próxima finalização é outra).

`purchase`: `transaction_id` (número do pedido, `get_order_number()`),
`value` (`valor_compra = produtos`: soma das linhas com desconto e sem
imposto; `total`: `get_total()`, com frete e impostos), `currency`, `tax`
(`get_total_tax()`), `shipping` (`get_shipping_total()`), `coupon`
(códigos de `get_coupon_codes()`, separados por vírgula, só quando há e
**só com o filtro `rastro_eventos_enviar_cupom` devolvendo `true`**; padrão
`false`, porque um cupom pode ser pessoal, ex.: o nome ou o e-mail de quem
ganhou; vale também para o `coupon` do `begin_checkout` e do
`add_payment_info`) e `items`. Produto apagado: o item sai com o ID e o nome guardados no pedido.

## Produto composto

O pai sai como um item só; o valor dos filhos soma no preço do pai (total
das linhas dividido pela quantidade do pai); os filhos nunca saem.
Carrinho: filho tem `composite_parent` (chave do pai), pai tem
`composite_children`. Pedido: filho tem a meta `_composite_parent`, que
aponta para a `_composite_cart_key` do pai. São as chaves do Composite
Products (conferidas na cópia embutida em
`inc/integrations/composite-products/`). Outros tipos de filho entram pelo
filtro `rastro_eventos_chaves_filho` (`chave no carrinho => meta da chave do
pai no pedido`, ex.: `'bundled_by' => '_bundle_cart_key'`).

O tipo nativo comedouro (classe que estende `WC_Product_Simple`, escolhas
gravadas no próprio item, sem filhos no carrinho) não tem tratamento
próprio: sai como produto simples, um item.

## Sem dado pessoal

Só catálogo, quantidades, valores, moeda, cupom (só com o filtro), ID da
forma de pagamento e, na compra, o número do pedido. Do pedido o módulo só
chama `get_id`, `key_is_valid`, `has_status`, `get_meta`, `read_meta_data`,
`get_items`, `get_order_number`,
`get_currency`, `get_total`, `get_total_tax`, `get_shipping_total`,
`get_coupon_codes`, `update_meta_data` e `save_meta_data` (o teste PHP
recusa qualquer outro). A chave do pedido nunca sai. O `eventos.js` não lê
cookie nem `localStorage` e não escreve na página.

## Cache de página

Fora do carrinho e do checkout (que o WooCommerce já não deixa em cache), o
`rastroConfig.eventos` é igual para todo visitante: catálogo da página.
Os itens do carrinho só saem nessas duas páginas. O filtro
`rastro_eventos_carrinho_em_toda_pagina` (padrão `false`) leva o carrinho
para toda página medida, mas faz o HTML variar por visitante: só para quem
não usa cache de página nessas páginas. Padrão recomendado: desligado
(revisão de 2026-11-11). Sem ele, o `remove_from_cart` do minicart fora do
carrinho e do checkout sai com o mínimo do link, ou não sai quando o link
não traz o produto (minicart de tema sem o produto no link: remoções no
minicart em outras páginas não são enviadas).

## Ganchos

| Gancho | Tipo | Assinatura |
|---|---|---|
| `rastro_config_navegador` | filtro (usado, prioridade 20) | acrescenta `eventos` |
| `wp_enqueue_scripts` | ação (usada, prioridade 20) | `rastro-eventos` (`eventos.js`) por `rastro_registrar_script()` |
| `rastro_eventos_chaves_filho` | filtro | `( array $chaves )`: chave do filho no carrinho => meta da chave do pai no pedido |
| `rastro_eventos_carrinho_em_toda_pagina` | filtro | `( bool $toda )`, padrão `false` |
| `rastro_eventos_status_compra` | filtro | `( string[] $status )`, padrão `processing`, `completed`, `on-hold`; `failed`, `cancelled`, `refunded`, `checkout-draft` e `trash` sempre saem |
| `rastro_eventos_enviar_cupom` | filtro | `( bool $enviar )`, padrão `false`; só `true` liga o `coupon` |
| `woocommerce_thankyou_order_id`, `woocommerce_thankyou_order_key` | filtros do WooCommerce (aplicados) | os mesmos do pedido recebido do WooCommerce |
| `rastro_falha` | ação | `( 'loja/eventos', \Throwable $erro )`; a página segue sem os eventos |

## Pendências e limites conhecidos

- **Carrinho e checkout de blocos** (o Rastro foi conferido nos clássicos):
  `view_cart`, `begin_checkout` e `purchase` saem (dados do PHP, mesmo
  endpoint de pedido recebido). Não saem `remove_from_cart` (o bloco usa
  botão sem link `remove_item=`), `add_payment_info` (o bloco não usa
  `name="payment_method"`) e o `add_to_cart` dos blocos de produto (o
  evento nativo `wc-blocks_added_to_cart` não traz o produto). O
  `begin_checkout` sai com o carrinho da carga da página: mudar a
  quantidade dentro do bloco do checkout não manda outro. Sem erro em
  nenhum caso.
- **Pix e outros pagamentos que ficam `pending`** no pedido recebido: sem
  compra pelo navegador até a T7 (ver "Compra uma vez por pedido").
- `add_to_cart` conta no envio do formulário: se o servidor recusar depois
  (estoque, validação do composto), o evento já saiu.
- Preço do composto na página de produto é o do catálogo (`get_price()`);
  no carrinho e no pedido é o valor real com os filhos.
- A meta da compra é gravada na primeira impressão, antes do aceite de
  cookies: se o visitante não aceitar nessa página, a compra não chega aos
  destinos e não sai de novo (falha fechada; a T7 cobre pelo servidor).
- Termo de busca com 8 dígitos ou mais (ex.: código de barras) não sai.
- Mais de 50 variações: as outras saem com os dados do pai e a escolha dos
  campos `attribute_*`.

## Conferido

No código do WooCommerce 11.3.0-beta.2 (cópia local; a produção usa
11.2.2): `WC_Order::key_is_valid()` (`hash_equals` com a chave),
`has_status()`, `get_order_number()`, `get_coupon_codes()` (desde 3.7),
`get_total_tax()`, `get_shipping_total()`, `update_meta_data()`,
`save_meta_data()`; `wc_get_price_excluding_tax()`,
`wc_get_product_terms()`, `wc_get_formatted_variation()`,
`WC_Product_Variation::get_attribute_summary()`; o pedido recebido do
atalho de checkout (`order-received`, `key`, filtros
`woocommerce_thankyou_order_id` e `woocommerce_thankyou_order_key`);
`templates/cart/cart.php` (link `remove` com `data-product_id` e
`data-product_sku`), `templates/cart/mini-cart.php`
(`data-cart_item_key`), `templates/checkout/payment-method.php`
(`name="payment_method"`) e `assets/js/frontend/add-to-cart.js`
(`added_to_cart` e `removed_from_cart` com `[ fragments, cart_hash, botão ]`).
No Composite Products embutido: chaves e metas da seção acima, formulário
`cart composite_form` com `add-to-cart` e `quantity`. No tema: minicart com
link `wc_get_cart_remove_url()` e `data-cart-key`; envio do formulário por
AJAX que dispara `added_to_cart` com o botão do formulário.

## Não conferido

- Comportamento na versão 11.2.2 da produção (lida a 11.3.0-beta.2).
- Nomes dos parâmetros GA4 na documentação oficial do Google nesta sessão:
  seguem o contrato (seção 6 e 13.2) e os eventos recomendados do GA4.
- Que o `trigger( 'click' )` do checkout clássico na forma já marcada não
  gera `change` de quem compra (o filtro de `isTrusted` cobre o que for
  disparado por script).
- Nome dos campos de pagamento e de remoção no checkout e no carrinho de
  blocos.
- Chaves do Product Bundles (`bundled_by`, `_bundle_cart_key`): só pelo
  filtro, sem código do plugin para conferir.
- Comportamento real no Playground com os destinos carregados (frente T3
  ainda em construção).

## Testar

```
php -l rastro/loja/eventos.php
php tests/php/rastro-eventos.test.php
node --check rastro/assets/js/eventos.js
node tests/validate-rastro-eventos-contract.js
```
