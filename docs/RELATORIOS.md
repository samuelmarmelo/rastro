# Rastro: relatórios do WooCommerce (frente T6)

Contrato: `docs/CONTRATO.md` (seção 8,
com D6 e D7 da seção 2, a leitura `rastro_pedido_origem()` da seção 7 e as
fórmulas da seção 13.2, com a líquida revista). Base: `docs/BASE.md`.

## Arquivos

| Arquivo | Papel |
|---|---|
| `relatorios/dados.php` | listas fechadas, períodos, fórmulas, origem do pedido, agregação, leitura em páginas, cache |
| `relatorios/relatorios.php` | aba "Relatórios", tela, AJAX `rastro_relatorio`, CSV `rastro_relatorio_csv` |
| `assets/js/relatorios.js` | totais, gráfico SVG, legenda, teclado, tabela equivalente, tabela ordenável, paginação, troca por AJAX |
| `assets/css/relatorios.css` | visual nativo do WordPress, sem recurso externo |

Os dois PHP já estão na lista de `rastro_modulos()` da base; nada no
carregador mudou.

## Tela (Rastro, aba Relatórios)

- Aba `relatorios` pelo filtro `rastro_admin_abas`: ordem 50, capacidade
  `manage_woocommerce`, callback `rastro_relatorios_tela` (recebe a chave da
  aba). Só aparece com o WooCommerce carregado (`wc_get_orders`). Quem só
  gerencia a loja vê só esta aba (a base usa a capacidade da primeira aba
  visível no menu).
- Linha "Status contados" com os status da configuração `status_relatorio`
  (aba WooCommerce), na ordem da loja, e o link "Mudar na aba WooCommerce"
  para quem tem `manage_options`.
- Dimensões (links comuns, o JS troca por AJAX): Fonte de tráfego, Página de
  entrada, `utm_source`, `utm_medium`, `utm_campaign`, `utm_content`,
  `utm_term`.
- Período: hoje, ontem, 7 dias, 30 dias (padrão), este mês, mês passado, 90
  dias, 12 meses (de hoje menos 12 meses mais 1 dia até hoje) e personalizado
  (`de` e `ate` em AAAA-MM-DD, datas reais de 2000 a 2100, invertidas trocam,
  até 366 dias contando os dois). Tudo no fuso do site (`wp_timezone()`).
  Filtro inválido no endereço vira aviso e volta aos 30 dias.
- Atribuição: Primeira visita (padrão) ou Última visita.
- Totais: quantidade de valores da dimensão, Pedidos, Venda bruta, Venda
  líquida, Venda total.
- Gráfico de linhas por dia em SVG (`createElementNS`), métrica Venda total
  ou Pedidos. Até 8 séries: com mais de 8 valores, as 7 maiores (por venda
  total) e "Outras" com a soma do resto; com 8 ou menos, todas. "Outras" é
  tracejada. Legenda com botões `aria-pressed` (ocultar e mostrar). O SVG
  recebe foco (`tabindex="0"`): setas, Home e End percorrem os dias, Esc
  limpa; a leitura do dia sai numa linha `aria-live`. Tabela equivalente
  (dia por série) num `<details>`.
- Tabela por valor da dimensão: ordena por Pedidos, Venda bruta, Venda
  líquida e Venda total (botão no cabeçalho, `aria-sort`), 25, 50 ou 100
  linhas por página, linha de total no rodapé.
- "Baixar Excel" (2026-11-13): link para
  `admin-post.php?action=rastro_relatorio_xlsx`, planilha .xlsx que abre
  direto no Microsoft Excel. Pedido original: a loja usa o Excel e prefere a
  planilha ao CSV.
- "Baixar CSV": link para `admin-post.php?action=rastro_relatorio_csv` com
  nonce e os filtros; o JS atualiza os dois links a cada troca.
- Sem JavaScript: os filtros funcionam como formulário GET e o Excel e o CSV
  baixam; os
  totais, o gráfico e a tabela pedem JavaScript (`<noscript>` avisa).
- Movimento: só uma transição de cor, dentro de
  `prefers-reduced-motion: no-preference`. Celular: filtros, dimensões,
  totais e legenda quebram linha; tabelas rolam na horizontal.

## Fórmulas (por pedido, centavos inteiros, negativo vira zero)

- **Venda bruta**: soma de `get_subtotal()` dos itens `line_item` (sem
  imposto, antes do desconto), cada um convertido para centavos.
- **Venda líquida**: bruta menos `get_discount_total()` menos os **itens
  reembolsados**: soma de `get_total()` (sem imposto, valor absoluto) das
  linhas `line_item` de cada reembolso de `get_refunds()`, lidos só quando
  `get_total_refunded()` é maior que zero. Reembolso de frete, de taxa ou só
  de valor (sem linhas de produto) não muda a líquida, só a total. É
  diferente do relatório do PixelYourSite (que tira reembolsado menos
  imposto reembolsado, frete incluso): revisão de 2026-11-11, contrato
  seções 8 e 13.2.
- **Venda total**: `get_total()` (com frete e impostos) menos
  `get_total_refunded()`.

Conversão: `round( valor * 100 )` (19,99 vira 1999; 0,1 + 0,2 vira 30).
Formatação só na saída (JS com `Intl.NumberFormat` na moeda da loja; CSV com
vírgula decimal).

## Funções

| Função | Para quê |
|---|---|
| `rastro_relatorio_dados( array $args ): array` | `dimensao`, `inicio`, `fim` (AAAA-MM-DD), `modelo`, `status` (opcional, padrão `status_relatorio`); devolve `linhas`, `totais`, `serie` (`dias`, `series`), e também `args`, `moeda`, `truncado`, `erro` (`''`, `argumentos` ou `woocommerce`) |
| `rastro_relatorio_agregar( array $pedidos, string $dimensao, array $dias = array() ): array` | agregação pura (o terceiro argumento opcional é o eixo dos dias; vazio, os dias dos registros) |
| `rastro_relatorio_registro( $pedido, string $modelo ): ?array` | pedido em registro sem dado de cliente: `dia`, `bruta`, `liquida`, `total`, `origem` (só as 7 dimensões) |
| `rastro_relatorio_formulas( array $v ): array`, `rastro_relatorio_valores( $pedido ): array`, `rastro_relatorio_centavos( $valor ): int` | fórmulas |
| `rastro_relatorio_periodo( string $periodo, string $de = '', string $ate = '', ?DateTimeInterface $agora = null ): ?array` | período em `inicio`, `fim`, `dias` |
| `rastro_relatorio_args( array $args ): ?array` | validação por lista fechada |
| `rastro_relatorio_origem( $pedido, string $modelo ): array` | origem do pedido nas dimensões |
| `rastro_relatorio_cache_invalidar(): void` | sobe a versão do cache |
| `rastro_relatorio_csv_linhas()`, `rastro_relatorio_csv_texto()`, `rastro_relatorio_csv_celula()`, `rastro_relatorio_csv_moeda()` | CSV |
| `rastro_relatorio_xlsx_linhas()`, `rastro_relatorio_xlsx_preparar()`, `rastro_xlsx()` (em `relatorios/xlsx.php`) | planilha do Excel |
| `rastro_relatorios_filtros( array $fonte ): ?array` | filtros de uma requisição (dimensão, período, modelo) |

Filtro novo: `rastro_relatorio_max_paginas` (`int`, padrão 50 páginas de 200,
constante `RASTRO_RELATORIO_MAX_PAGINAS`, ou 10 mil pedidos por relatório).
Passando do limite, o relatório sai com `truncado` e a tela avisa: os
números cobrem só os primeiros 10 mil pedidos do período (do mais antigo
para o mais novo). Para um período maior, escolha um período menor ou suba
o filtro sabendo do custo.

## Origem do pedido (dependência da T5)

A leitura é sempre `rastro_pedido_origem( $pedido, $modelo )`, chamada só
quando `function_exists( 'rastro_pedido_origem' )` (T5 constrói em paralelo;
esta frente nunca a define). Sem ela, ou com erro nela (vira a ação
`rastro_falha`, ponto `relatorios-origem`), vale a reserva
`rastro_relatorio_origem_reserva()`: todas as chaves da T5 vazias e
`fonte = '(sem dados)'`, `origem_dado = 'nenhum'`. Com a T5 fora, o relatório
mostra uma linha só, "(sem dados)", com os números certos.

Valor vazio numa dimensão vira "(sem dados)" na Fonte de tráfego e
"(não definido)" nas outras. Os valores são limpos de caracteres de controle
e cortados em 200 caracteres.

## Leitura dos pedidos

`wc_get_orders()` em páginas de 200, com:

```
'type'         => 'shop_order',
'status'       => array( 'completed', 'processing' ),   // sem o prefixo wc-
'date_created' => '<timestamp do início>...<timestamp do fim>',
'limit'        => 200,
'page'         => 1, 2, ...,
'paginate'     => true,
'orderby'      => 'date',
'order'        => 'ASC',
'return'       => 'ids',
```

Os IDs de cada página viram pedidos em lote
(`rastro_relatorio_carregar_pedidos()`):

1. `WC()->order_factory->get_orders( $ids, true )` quando existe: conferido
   em `includes/class-wc-order-factory.php` (versão em desenvolvimento):
   prepara os caches (`_prime_post_caches()`), agrupa por classe e lê os
   pedidos com `read_multiple()` do armazenamento (posts ou HPOS), pulando
   os inválidos. O `wc_get_orders()` com `return => objects` no HPOS faz o
   mesmo (`OrdersTableDataStore::query()` chama `order_factory->get_orders()`
   e `prime_caches_for_orders()`), mas aqui os objetos só existem por
   página. Erro na fábrica (vira `rastro_falha`, ponto `relatorios-leitura`):
   segue para o passo 2.
2. Sem a fábrica: `_prime_post_caches( $ids, false, true )` na tabela de
   posts (sem HPOS) e `wc_get_order()` um a um.

Depois de cada página, `wp_cache_flush_runtime()` (quando existe) solta da
memória os pedidos já resumidos: o registro guarda só o dia, os valores e a
origem.

Conferido no código do WooCommerce 11.3 (cópia local da versão 11.3.0-beta.2):

- `includes/class-wc-order-query.php` e
  `includes/abstracts/abstract-wc-object-query.php`: `limit`, `page`,
  `paginate`, `orderby`, `order`, `return`, `type` (padrão
  `wc_get_order_types( 'view-orders' )`; aqui `shop_order` deixa os
  reembolsos de fora).
- Status sem `wc-`: o data store de posts
  (`class-wc-order-data-store-cpt.php`) e o do HPOS
  (`OrdersTableQuery::sanitize_status`) acrescentam o prefixo quando o status
  existe.
- `date_created` com `...` e timestamps: os dois data stores tratam número
  como UTC, com precisão de segundo, nos dois lados inclusivos
  (`WC_Data_Store_WP::parse_date_for_wp_query`, coluna `post_date_gmt`;
  `OrdersTableQuery::process_date_args`, coluna `date_created_gmt`). Por isso
  os limites são timestamps (meia-noite do primeiro dia e 23:59:59 do último,
  no fuso do site), e não AAAA-MM-DD (que só tem precisão de dia).
- `paginate => true` devolve um objeto com `orders`, `total` e
  `max_num_pages` nos dois data stores.
- Ganchos de invalidação: `woocommerce_order_status_changed`
  (`class-wc-order.php`), `woocommerce_order_refunded`
  (`wc-order-functions.php`), `woocommerce_refund_deleted`
  (`class-wc-ajax.php`), `woocommerce_trash_order` e `woocommerce_delete_order`
  (os dois data stores).

O dia de cada pedido é o de `get_date_created()` no fuso do site. Objetos que
não são `shop_order` (reembolso) são pulados.

## Cache

Transient `rastro_relatorio_<md5>` de 10 minutos; o hash leva os argumentos
validados, a versão do cache (opção `rastro_relatorio_cache_versao`, sem
autoload) e `RASTRO_VERSAO`. Mudança de status, reembolso (criado ou
apagado), lixeira e exclusão de pedido só sobem a versão: nada é apagado,
as entradas antigas expiram sozinhas.

## AJAX, CSV e Excel

- `wp_ajax_rastro_relatorio` (POST): `manage_woocommerce`, nonce
  `rastro_relatorio` no campo `nonce` (403 em qualquer falha), filtros por
  lista fechada e data validada (400 quando inválidos). Resposta:
  `filtros`, `moeda`, `truncado`, `erro`, `linhas` (`valor`, `pedidos`,
  `bruta`, `liquida`, `total`), `totais`, `serie`. Só agregados: nenhum ID,
  nome, e-mail, telefone ou endereço.
- `admin_post_rastro_relatorio_csv` e `wp_ajax_rastro_relatorio_csv` (GET):
  `manage_woocommerce` e `check_admin_referer( 'rastro_relatorio_csv' )`.
  UTF-8 com BOM, separador `;`, vírgula decimal sem milhar, texto que começa
  com `=`, `+`, `-`, `@`, tabulação ou retorno de carro ganha apóstrofo
  (reuso adaptado de `gestao-futebol-clube/inc/financeiro/csv.php`). Colunas:
  dimensão, Pedidos, Venda bruta, Venda líquida, Venda total, e a linha
  "Total". Nome: `relatorio-<dimensao>-<inicio>-a-<fim>-<modelo>.csv` (um
  dia só: `relatorio-<dimensao>-<dia>-<modelo>.csv`).
- `admin_post_rastro_relatorio_xlsx` e `wp_ajax_rastro_relatorio_xlsx` (GET):
  `manage_woocommerce` e `check_admin_referer( 'rastro_relatorio_xlsx' )`.
  Mesmas colunas e linha "Total" do CSV, mesmo nome com `.xlsx`. Pedidos e
  valores vão como números (moeda no formato `"R$" #,##0.00`, inteiro com
  milhar), então o Excel soma e ordena; cabeçalho e total em negrito;
  cabeçalho fixo ao rolar. Gerado por `relatorios/xlsx.php` sem biblioteca e
  sem a extensão zip do servidor: XML do Office Open XML e ZIP sem compressão
  montado com `pack` e `crc32`. Todo texto vai como `inlineStr`, que o Excel
  nunca trata como fórmula (não precisa do apóstrofo do CSV); caracteres que o
  XML não aceita são retirados.
- Nenhuma rota `nopriv`.

## Arquivos da tela

`admin_enqueue_scripts` prioridade 20, só na tela do Rastro
(`rastro_admin_tela_id()`), na aba `relatorios` e para `manage_woocommerce`:
`rastro_registrar_script( 'rastro-relatorios', 'relatorios.js' )` (rodapé,
`defer`) e o estilo `rastro-relatorios`. A configuração vai antes do script
(`wp_add_inline_script( ..., 'before' )`) como
`window.rastroRelatorio = rastro_json(...)`: endereços, nonces, dados
iniciais, rótulos e textos.

## Para quem integra (ponte)

Nada a ligar além de carregar o Rastro: a aba entra sozinha pelo filtro. Se a
loja quiser os mesmos números do PixelYourSite na troca, conferir que
`status_relatorio` está em Processando e Concluído (é o padrão).

## Não conferido

- Comportamento numa loja de verdade (só o código do WooCommerce foi lido;
  falta o Playground com pedidos de teste, HPOS ligado e desligado, como pede
  a seção 12).
- Os números iguais aos do relatório do PixelYourSite numa loja real: a bruta
  e a total seguem a seção 13.2 (o arredondamento deles não foi medido); a
  líquida difere de propósito quando há reembolso de frete ou só de valor.
- Desempenho com dezenas de milhares de pedidos num período (o limite de 50
  páginas é uma proteção, não uma medida). A fábrica de pedidos do
  WooCommerce (`order_factory->get_orders()`) foi lida na versão em
  desenvolvimento; a versão em que ela entrou não foi conferida (sem ela, a
  leitura volta para um a um).
- Leitores de tela reais no gráfico (a leitura por `aria-live` e a tabela
  equivalente foram testadas só no DOM falso do contrato).

## Testar

```
php -l rastro/relatorios/dados.php
php -l rastro/relatorios/relatorios.php
php tests/php/rastro-relatorios.test.php
node --check rastro/assets/js/relatorios.js
node tests/validate-rastro-relatorios-contract.js
```

`tests/php/rastro-relatorios.test.php` usa `tests/php/stubs-rastro.php` e põe
os seus próprios stubs (`wp_timezone`, `wc_get_orders`, pedido e reembolso
falsos) antes dele. Carrega só a base e os dois arquivos da T6, nunca o
`rastro.php` inteiro, para o teste da origem de reserva continuar valendo
depois que a T5 entrar.
