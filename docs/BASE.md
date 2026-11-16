# Rastro: base (frente T1)

Contrato: `docs/CONTRATO.md` (seções 1,
3.1 a 3.4, 10 e 12). Pasta: `rastro/`.

## O que a base entrega

| Arquivo | Papel |
|---|---|
| `rastro.php` | cabeçalho de plugin completo (Rastro, PHP 7.4, text domain `rastro`), constantes, carregador dos módulos |
| `nucleo/config.php` | esquema único dos campos, validação, leitura e gravação da opção `rastro_config` |
| `nucleo/regras.php` | onde medir: cópia local, equipe, requisição restrita, tipo e privacidade da página, endereço saneado |
| `nucleo/saida.php` | `window.rastroConfig` no `<head>`, script inline e registro de scripts fora do atraso dos otimizadores |
| `admin/admin.php` | menu "Rastro", abas pelo filtro, formulário gerado pelo esquema, salvar por aba |
| `assets/css/admin.css` | ajustes pequenos do painel, só nas telas do Rastro |

O Rastro não depende de nenhum site. O que é próprio de um site entra por
uma ponte do site (contrato, seção 9). Os testes carregam os arquivos direto.

### Carga

- Com `RASTRO_VERSAO` já definida, `rastro.php` volta sem fazer nada: o
  plugin próprio ativo vence a cópia embutida. As funções do carregador ficam
  dentro de `if ( ! function_exists( ... ) )`, então a mesma pasta incluída
  duas vezes não dá erro fatal.
- Constantes: `RASTRO_VERSAO`, `RASTRO_ARQUIVO`, `RASTRO_DIR` (com barra
  final), `RASTRO_URL` (`plugins_url( '', RASTRO_ARQUIVO )` com barra final).
- Carga imediata ao incluir (vale em `plugins_loaded`, como plugin próprio, e
  em `after_setup_theme`, embutido).
- `rastro_modulos()` já traz a lista inteira, na ordem: `nucleo/config.php`,
  `nucleo/regras.php`, `nucleo/saida.php`, `logs/logs.php`, `consentimento/consentimento.php`,
  `consentimento/aviso.php`, `tags/tags.php`, `loja/eventos.php`,
  `atribuicao/atribuicao.php`, `atribuicao/pedido.php`,
  `relatorios/dados.php`, `relatorios/relatorios.php` e, só no painel,
  `admin/admin.php`. Arquivo ausente é pulado em silêncio; erro num módulo
  vira a ação `rastro_falha` e o site segue.

## Esquema (`rastro_config`, uma opção, autoload)

| Chave | Aba | Tipo | Regra | Padrão |
|---|---|---|---|---|
| `gtm` | geral | id | `/^GTM-[A-Z0-9]{4,12}$/D`, maiúsculas | `''` |
| `ga4` | geral | id | `/^G-[A-Z0-9]{6,14}$/D`, maiúsculas | `''` |
| `ads` | geral | id | `/^AW-[0-9]{6,14}$/D` | `''` |
| `ads_rotulo_compra` | geral | id | `/^[A-Za-z0-9_-]{6,40}$/D` | `''` |
| `meta` | geral | id | `/^[0-9]{8,20}$/D` | `''` |
| `tiktok` | geral | id | `/^[A-Z0-9]{15,30}$/D`, maiúsculas | `''` |
| `openai` | geral | id | `/^[A-Za-z0-9_-]{10,64}$/D` | `''` |
| `openai_debug` | geral | booleano | `'1'` ou `'0'` | `'0'` |
| `posthog` | geral | id | `/^phc_[A-Za-z0-9]{20,80}$/D` | `''` |
| `posthog_regiao` | geral | selecao | `us`, `eu` | `us` |
| `posthog_logs` | geral | booleano | logs no PostHog Logs (`docs/LOGS.md`) | `'1'` |
| `posthog_logs_console` | geral | booleano | console do navegador nos logs | `'0'` |
| `google_verificacao` | verificacao | id | `/^[A-Za-z0-9_-]{20,100}$/D`; aceita a tag `<meta>` colada (fica o `content`) | `''` |
| `facebook_verificacao` | verificacao | id | `/^[A-Za-z0-9]{20,64}$/D`; aceita a tag `<meta>` colada | `''` |
| `pinterest_verificacao` | verificacao | id | `/^[A-Za-z0-9]{20,64}$/D`; aceita a tag `<meta>` colada | `''` |
| `carregamento` | geral | selecao | `ocioso`, `interacao`, `imediato` | `ocioso` |
| `eventos_loja` | eventos | booleano | | `'1'` |
| `evento_view_item_list`, `evento_view_item`, `evento_add_to_cart`, `evento_remove_from_cart`, `evento_view_cart`, `evento_begin_checkout`, `evento_add_payment_info`, `evento_purchase`, `evento_search` | eventos | booleano | | `'1'` |
| `valor_compra` | woocommerce | selecao | `produtos`, `total` | `produtos` |
| `id_produto` | woocommerce | selecao | `id`, `sku` | `id` |
| `status_relatorio` | woocommerce | lista | status de `wc_get_order_statuses()` sem `wc-` (depois do `init`); senão pending, processing, on-hold, completed, cancelled, refunded, failed | `processing`, `completed` |
| `atribuicao` | woocommerce | selecao | `aceite`, `sessao`, `desligada` | `aceite` |
| `atribuicao_dias` | woocommerce | inteiro | 1 a 365 | `90` |
| `atribuicao_woo_consentimento` | woocommerce | booleano | | `'1'` |
| `aviso` | consentimento | selecao | `automatico`, `proprio`, `externo` | `automatico` |
| `aviso_texto` | consentimento | texto | até 400 caracteres, sem HTML | texto padrão da seção 4 |
| `aviso_dias` | consentimento | inteiro | 30 a 395 | `180` |
| `medir_equipe` | consentimento | booleano | | `'0'` |

Cada entrada tem `aba`, `tipo`, `rotulo`, `ajuda`, `padrao` e, conforme o
tipo, `regex`, `maiusculas`, `extrair_content`, `opcoes`, `opcoes_de`,
`min`, `max`, `marca` (texto da caixa), `exemplo` e `erro` (mensagem).

Leitura: nada do banco é usado sem revalidar. Inválido vira o padrão; ID
vazio fica vazio (desligado). Gravação: campo inválido mantém o valor
anterior e devolve a mensagem em pt-BR.

## Funções públicas

Do contrato (assinaturas fixas): `rastro_config_esquema(): array`,
`rastro_config_padrao(): array`, `rastro_config_validar_campo( string $chave, $bruto )`
(valor limpo, `''` vazio, `null` inválido), `rastro_config_ler( $bruto ): array`,
`rastro_config(): array`, `rastro_config_campo( string $chave )`,
`rastro_config_salvar( array $bruto ): array` (`config`, `erros`),
`rastro_tem_destino(): bool`, `rastro_ambiente_local(): bool`,
`rastro_e_equipe(): bool`, `rastro_requisicao_restrita(): bool`,
`rastro_pagina_tipo(): string`, `rastro_pagina_privada(): bool`,
`rastro_deve_medir(): bool`, `rastro_url_limpa(): string`,
`rastro_regra_medir( array $c ): bool`, `rastro_config_navegador(): array`,
`rastro_script_inline( string $id, string $js ): void`,
`rastro_registrar_script( string $handle, string $arquivo_relativo, array $deps = array() ): bool`.

Acrescentadas na base (as frentes podem usar):

| Função | Para quê |
|---|---|
| `rastro_carregar_arquivo( string $modulo, string $pasta = RASTRO_DIR ): bool` | carrega um arquivo com a guarda do SafeWidget |
| `rastro_config_eventos(): array` | eventos canônicos (nome => rótulo) |
| `rastro_config_destinos(): array` | chaves dos destinos (`gtm`, `ga4`, `ads`, `meta`, `tiktok`, `openai`, `posthog`) |
| `rastro_config_guardada(): array` | a opção revalidada, sem o filtro `rastro_config` |
| `rastro_config_ligado( string $chave ): bool` | campo booleano ligado? |
| `rastro_config_opcoes( array $campo ): array` | opções de seleção ou lista |
| `rastro_config_status_opcoes(): array` | status aceitos em `status_relatorio` |
| `rastro_config_redefinir_cache(): void`, `rastro_regras_redefinir_cache(): void` | testes |
| `rastro_regra_local( string $host, string $ambiente ): bool` | regra pura da cópia local |
| `rastro_regra_privada( string $tipo, array $sinais ): bool` | regra pura da página privada |
| `rastro_regra_url_limpa( string $uri, bool $privada, string $tipo ): string` | caminho sem query string; em página privada, só até o endpoint |
| `rastro_regra_url_campanha( string $uri, bool $privada, string $tipo ): string` | endereço medido (`page_location` do GA4): o caminho limpo e, em página pública, só `utm_source`, `utm_medium`, `utm_campaign`, `utm_content`, `utm_term`, `utm_id`, `gclid`, `gbraid`, `wbraid`, `fbclid`, `ttclid`, `msclkid` (a primeira de cada, nessa ordem, consulta até 900 caracteres); página privada sem consulta nenhuma |
| `rastro_regra_valor_campanha( $valor ): string` | texto de campanha: vazio com arroba ou oito dígitos seguidos (e-mail, CPF, telefone), até 100 caracteres; ID de clique só com `[A-Za-z0-9._~-]` |
| `rastro_regra_chaves_campanha(): array` | a lista fechada de chaves de campanha |
| `rastro_url_campanha(): string`, `rastro_url_absoluta( string $caminho ): string`, `rastro_requisicao_uri(): string` | endereço medido absoluto (host do `home_url`) |
| `rastro_requisicao_metodo(): string` | método da requisição |
| `rastro_precisa_saida(): bool` | a página leva o `<script id="rastro-config">`? |
| `rastro_json( $dado ): string` | JSON com `JSON_HEX_*` para dentro de `<script>` (`''` se falhar) |
| `rastro_atributos_saida(): array`, `rastro_atributos_mesclar( array $atributos ): array` | atributos que tiram o script do atraso dos otimizadores |
| `rastro_admin_url( string $aba = '', array $args = array() ): string` | endereço de uma aba |
| `rastro_admin_aviso( string $tipo, string $texto, array $itens = array() ): void` | aviso do painel (`role="status"`) |
| `rastro_admin_campo( string $chave, array $campo, $valor ): void` | controle de um campo do esquema |
| `rastro_admin_salvar_dados( string $aba, array $postado ): ?array` | regra do salvar por aba (testável) |

## Ganchos

| Gancho | Tipo | Assinatura |
|---|---|---|
| `rastro_modulos` | filtro | `( string[] $modulos )` |
| `rastro_falha` | ação | `( string $modulo, \Throwable $erro )` |
| `rastro_carregado` | ação | `()` |
| `rastro_config` | filtro | `( array $config )`, resultado revalidado |
| `rastro_e_equipe` | filtro | `( bool $equipe )`, só `true` vale |
| `rastro_pagina_privada` | filtro | `( bool $privada )`, só acrescenta |
| `rastro_deve_medir` | filtro | `( bool $medir, array $contexto )`; não liga sem destino nem em requisição restrita |
| `rastro_config_navegador` | filtro | `( array $config )`; cada módulo acrescenta a sua chave (`consent`, `tags`, `eventos`, `atribuicao`) |
| `rastro_precisa_saida` | filtro | `( bool $precisa )`, padrão `false`; o aviso próprio (T2) devolve `true` para a configuração sair sem medição |
| `rastro_admin_abas` | filtro | `( array $abas )`: `chave => array( 'titulo', 'capacidade', 'callback', 'ordem' )`; o callback recebe a chave da aba |
| `rastro_admin_avisos` | filtro | `( array $avisos )`: lista de `array( 'tipo' => 'warning'\|'info'\|'error', 'texto' => string )` |

`wp_head` prioridade 1: `rastro_head_config()` imprime
`<script data-no-optimize="1" data-no-defer="1" data-cfasync="false" id="rastro-config" class="nowprocket">window.rastroConfig={...};window.rastro=window.rastro||{};</script>`.
Base de `rastroConfig`: `versao`, `pagina` (`tipo`, `privada`, `url`),
`carregamento`, `debug` (só com `WP_DEBUG`).

## Como as outras frentes entram

- Criam os seus arquivos nos caminhos da lista de `rastro_modulos()`; ninguém
  edita o carregador.
- Scripts: `rastro_registrar_script( 'rastro-consent', 'consent.js' )` e
  depois `wp_enqueue_script`. Handle sempre `rastro-...`, arquivo dentro de
  `assets/js/`. Rodapé, `defer`, versão pela data do arquivo.
- Script inline: `rastro_script_inline( 'rastro-fila', $js )`, dados sempre
  por `rastro_json()`.
- Aba nova (T6): `add_filter( 'rastro_admin_abas', ... )` com `ordem` 50 e
  `capacidade` `manage_woocommerce`. A capacidade do menu é a da primeira aba
  que o usuário vê.
- Situação da aba Geral: mostra o provedor de consentimento quando
  `rastro_consent_provedor()` existir (T2).

## Otimizadores e WordPress 7

- No WordPress 7, os scripts com `src` saem por `wp_get_script_tag()`, que
  passa pelo filtro `wp_script_attributes`; é por ele que o Rastro marca os
  seus scripts (id `rastro-*-js`). Os inline vão por
  `wp_inline_script_attributes`. `script_loader_tag` continua ligado para as
  versões em que o HTML passa por ele, sem repetir atributo.
- Os `data-*` vêm antes do `src` (a documentação do Rocket Loader da
  Cloudflare pede `data-cfasync` antes do `src`).
- Exclusões, só com o otimizador instalado e só para o caminho
  `.../rastro/assets/js/`:
  - LiteSpeed Cache (`LSCWP_V`): `litespeed_optm_js_defer_exc`,
    `litespeed_optimize_js_excludes`. Conferidos no código do LiteSpeed Cache
    7.9.3 (`src/optimize.cls.php`), que também respeita `data-no-optimize`,
    `data-no-defer` e `data-cfasync="false"`.
  - WP Rocket (`WP_ROCKET_VERSION`): `rocket_delay_js_exclusions`
    (expressão contra a tag inteira), `rocket_exclude_defer_js` (contra o
    `src`), `rocket_exclude_js` (minificação). Conferidos no código do WP
    Rocket 3.24; os padrões vão com `preg_quote`.
  - Autoptimize (`AUTOPTIMIZE_PLUGIN_VERSION`): `autoptimize_filter_js_exclude`
    (texto separado por vírgula ou array com os padrões nas chaves).
    Conferido no Autoptimize 3.1.17 do diretório de plugins.

## Não conferido

- Classe `nowprocket` como exclusão do WP Rocket: no código do WP Rocket 3.24
  só achamos o parâmetro de endereço `?nowprocket`. A classe fica porque o
  contrato pede e não faz mal; a exclusão garantida é a dos filtros acima.
- Autoptimize: ele ignora scripts com `data-noptimize` (sem hífen depois do
  "no"), não `data-no-optimize`. Os scripts com `src` saem da junção pelo
  filtro; o inline `rastro-config` não tem marcação própria do Autoptimize.
- Comportamento real em cada otimizador (só o código foi lido; falta o teste
  no Playground com cada um ativo).

## Testar

```
php -l rastro/rastro.php
php tests/php/rastro-base.test.php
node tests/validate-rastro-base-contract.js
```

`tests/php/stubs-rastro.php` é o stub compartilhado das frentes do Rastro
(só a T1 edita). Estado da requisição em `$GLOBALS['rastro_teste']`
(`rastro_teste_padrao()` lista as chaves: opções, ações já disparadas, host,
ambiente, painel, AJAX, login, capacidades, perguntas do WordPress e do
WooCommerce, método, endereço, `$_GET`, `$_POST`, cookies, nonce). Use
`rastro_teste_reset( array $sobre = array(), bool $limpar_ganchos = false )`
entre os casos e `rastro_teste_saida( callable $fn, array $args = array() )`
para capturar HTML. `wp_die`, `wp_send_json*` e nonce recusado lançam
`Rastro_Teste_Parada`. Sem WooCommerce: defina `RASTRO_TESTE_SEM_WOO` antes de
incluir. Para testar `RASTRO_LOCAL` ou a guarda do plugin próprio, use um
processo novo (constantes não se desfazem), como os cenários do
`rastro-base.test.php`.
