# Rastro: consentimento (frente T2)

Contrato: `docs/CONTRATO.md` (seções
2.1 e 4). Base: `docs/BASE.md`. Pasta:
`rastro/`.

## O que a frente entrega

| Arquivo | Papel |
|---|---|
| `consentimento/consentimento.php` | modos, finalidades, provedores, cookie lido no servidor, fila do `<head>`, chave `consent` do navegador, avisos do painel |
| `consentimento/aviso.php` | HTML do aviso próprio, botão "Preferências de cookies", shortcode, botão fixo |
| `assets/js/consent.js` | estado no navegador, API `window.rastro.consent`, evento `rastro:consent`, Consent Mode, revogação |
| `assets/css/aviso.css` | visual do aviso só por variáveis `--rastro-*` |

## Estado e API no navegador

Estado: `{ analise, publicidade, decidido }`.

- `window.rastro.consent.get()`: cópia do estado.
- `window.rastro.consent.set( { analise, publicidade } )`: grava a escolha
  (provedor próprio) ou passa pela WP Consent API (`wp_set_consent`). Com uma
  ponte, não faz nada: a escolha é do aviso do site. Só vale para as
  finalidades que têm destino.
- `window.rastro.consent.abrir()`: reabre o aviso próprio (com escolha, já
  nas finalidades, mostrando o que foi escolhido) ou clica no botão do aviso
  do site (ponte). Devolve `false` quando não há o que abrir.
- Também: `provedor`, `modo`, `finalidades`.
- Evento `rastro:consent` no `document` a cada mudança (`detail` = estado) e
  uma vez na carga quando já há escolha. O da carga sai no
  `DOMContentLoaded`, depois que todos os scripts com `defer` (como o
  `tags.js` da T3) já ligaram os seus ouvintes. Quem chega depois lê
  `window.rastro.consent.get()`.

Cookie próprio `rastro_consent`: `a1p1`, `a1p0`, `a0p1` ou `a0p0`, prazo de
`aviso_dias`, `path=/`, `SameSite=Lax`, `Secure` em https, `max-age` e
`expires`. O contrato lista três valores; `a0p1` também vale porque
"Personalizar" permite só publicidade. Esc no aviso grava
`rastro_consent_visita=1` no `sessionStorage` (recusa só nesta visita).

## Modos (`modo`, seção 2.1)

| Modo | Comportamento |
|---|---|
| `lgpd` (padrão) | aviso com Recusar, Personalizar e Aceitar; nada antes da escolha |
| `aceite_automatico` | aviso próprio aparece; sem escolha guardada e sem interação, depois de `aceite_segundos` grava `a1p0`, fecha o aviso e dispara `rastro:consent` (só análise; publicidade nunca liga sozinha). A contagem para de vez nesta página quando a pessoa interage com o aviso (`focusin`, `pointerenter`, `touchstart`), clica em Personalizar ou aperta Esc. Recusar antes do tempo vale. O botão de preferências continua. Sem destino de análise, não há contagem |
| `basico` | sem aviso, sem botão de preferências, sem cookie de escolha; estado `{ true, true, true }` desde a carga, `rastro:consent` uma vez, Consent Mode começa concedido; pontes e WP Consent API ignoradas (`rastro_consent_provedor()` devolve `proprio`) |

O aceite automático só vale para o aviso próprio: com uma ponte ou a WP
Consent API ativas, o aviso é do site e a contagem não existe.

Painel: com `aceite_automatico` ou `basico`, a aba mostra o aviso "Este modo
não atende a LGPD: ficar parado ou não ser perguntado não é consentimento. A
escolha e o risco são do site."

## Finalidades

`rastro_consent_finalidades()` devolve, nesta ordem, as que têm destino:
`analise` (GA4, PostHog) e `publicidade` (Google Ads, Meta, TikTok,
OpenAI, GTM). O aviso só pergunta por elas; "Aceitar" só concede elas. O
GTM conta só como publicidade e só carrega com ela (LGPD: o contêiner pode
ter tags que não respeitam o Consent Mode); só com o GTM, o aviso pergunta
só pela publicidade.

## Provedores

`rastro_consent_provedor()`:

1. modo `basico` ou `aviso = proprio`: `proprio`;
2. a primeira ponte ativa (filtro `rastro_consent_provedores`);
3. a WP Consent API (`function_exists( 'wp_has_consent' )`);
4. `aviso = automatico`: `proprio`; `aviso = externo`: `nenhum` (nada
   carrega e o painel mostra um erro pelo `rastro_admin_avisos`).

A ponte vem antes da WP Consent API porque é código do próprio site,
registrado de propósito. Ponte cujo `ativo` lança erro: `nenhum` (falha
fechada) e a ação `rastro_falha`.

### Ponte (aviso que o site já tem)

```php
add_filter( 'rastro_consent_provedores', function ( $provedores ) {
	$provedores['meu_site'] = array(
		'ativo' => 'meu_site_aviso_ativo',          // callable, devolve bool
		'js'    => array(
			'cookie' => 'meu_site_consentimento',  // nome do cookie do aviso
			'mapa'   => array(                     // valor do cookie => estado
				'analise'     => array( 'analise' => true ),
				'publicidade' => array( 'analise' => true, 'publicidade' => true ),
				'recusado'    => array(),
			),
			'evento' => 'meu_site:consentimento',  // evento do document (opcional)
			'campo'  => 'escolha',                 // campo do detail (opcional)
			'abrir'  => '[data-meu-site-abrir]',   // botão que reabre o aviso (opcional)
		),
	);
	return $provedores;
} );
```

Nomes reservados: `proprio`, `wp_consent_api`, `nenhum`. Nome
`^[a-z0-9_]{2,40}$`. Valor do mapa `^[A-Za-z0-9_.-]{1,40}$`; só `true` liga.
Sem `campo` no `detail`, o JS lê o cookie de novo. Valor fora do mapa vale
como sem escolha. Exemplo: um tema com aviso próprio (cookie
`meusite_consentimento`, evento `meusite:consentimento`, botão
`[data-meusite-cookies-abrir]`) entra assim; o contrato Node já roda esse caso.

### WP Consent API

Conferido no código oficial do plugin (`assets/js/wp-consent-api.js`) e no
leia-me, em 2026-11-11:

- JS: `wp_has_consent( categoria )` (`statistics` vira análise,
  `marketing` vira publicidade), `wp_set_consent( categoria, 'allow'|'deny' )`;
  eventos `wp_listen_for_consent_change` (no `document`, `detail` com a
  categoria mudada) e `wp_consent_type_defined`.
- Sem tipo de consentimento (`window.wp_consent_type` ou
  `window.wp_fallback_consent_type`), a API responde "tem consentimento"
  para tudo. O Rastro trata isso como negado (falha fechada).
- `decidido`: tipo `optout`, ou um dos cookies
  `consent_api.cookie_prefix + '_statistics'`/`_marketing` preenchido.
- PHP: `wp_has_consent()`; o Rastro se declara pelo filtro
  `wp_consent_api_registered_{plugin_basename}`.

## Fila do `<head>` e configuração

- `wp_head` prioridade 2, `<script id="rastro-fila">` por
  `rastro_script_inline()`: `dataLayer`, `gtag` e
  `gtag('consent','default', …)` com `ad_storage`, `analytics_storage`,
  `ad_user_data`, `ad_personalization` negados e `wait_for_update: 500`; no
  modo básico, os quatro concedidos, sem espera.
- `consent.js` atualiza (`consent update`): `analytics_storage` = análise;
  `ad_storage`, `ad_user_data`, `ad_personalization` = publicidade. Com
  escolha guardada, o update sai na execução do script, antes de qualquer tag.
- `rastroConfig.consent`: `modo`, `provedor`, `finalidades`, `cookie`,
  `visita`, `dias`, `segundos`, `flutuante`, `medir` (a página mede?) e, com
  ponte, `externo`. Nunca a escolha do visitante (HTML igual para todos).
- Saída: fila, `consent.js` e aviso só quando `rastro_precisa_saida()` diz
  sim e o provedor não é `nenhum`. O Rastro liga `rastro_precisa_saida`
  quando há destino e o provedor é o próprio: equipe e cópia local veem o
  aviso para conferir, e `medir` vai `false` (as tags seguem
  `rastro_deve_medir()`). Com ponte ou WP Consent API, só quando a página
  mede.
- Os ganchos do site (`rastro_precisa_saida`, `rastro_config_navegador`,
  `wp_head`, `wp_enqueue_scripts`, `wp_footer`) são ligados no gancho `wp`
  (`rastro_consent_iniciar()`, `rastro_aviso_iniciar()`).

## Aviso próprio

- Região no fim do `<body>` (`wp_footer` 20), `hidden` no HTML, mostrada
  pelo JS sem escolha. Aparecer sozinho não move o foco; aberto por um botão,
  o foco vai para "Recusar" (ou para a primeira caixa, nas finalidades) e
  volta ao botão ao fechar.
- Botões Recusar, Personalizar e Aceitar com a mesma classe. Personalizar
  abre as finalidades com destino, caixas desmarcadas, e "Salvar escolhas".
- Link para a política de privacidade do WordPress (`get_privacy_policy_url()`),
  quando existe.
- Texto: `aviso_texto` (escapado).
- Preferências: shortcode `[rastro_preferencias]`,
  `echo rastro_aviso_botao_preferencias( array( 'classe' => '', 'texto' => '' ) );`
  (devolve o HTML) e o botão fixo discreto, filtro
  `rastro_aviso_botao_flutuante` (`( bool $ativo, string $provedor )`, padrão
  `true` só no provedor próprio). Sem botão no modo básico e com a WP Consent
  API (o Rastro não tem como abrir o aviso dela).
- CSS: `--rastro-fundo`, `--rastro-texto`, `--rastro-borda`,
  `--rastro-destaque`, `--rastro-raio`, `--rastro-fonte` (reserva sóbria em
  cada `var()`; o tema troca no `:root`). Celular primeiro, faixa no rodapé,
  caixa de 420 px no canto a partir de 768 px, `prefers-reduced-motion`.

## Revogação

Quando uma finalidade passa de concedida a negada: `consent update` negado,
cookies apagados no domínio do site e nos pais
(análise: `_ga*`, `_gid`, `ph_*`, `__ph_*` e o cookie de origem, ver
abaixo; publicidade: `_gcl_*`, `_fbp`, `_fbc`, `_ttp`, `_tt_*`, `ttcsid`,
`ttcsid_*`, `ttclid`), `localStorage` e `sessionStorage` do PostHog
(`ph_*`, `__ph_*`) limpos e recarga quando alguma tag tinha carregado. Toda
escolha explícita (também a primeira recusa e o evento de uma ponte) apaga o
que ficou negado, para limpar sobras de plugins antigos.

- **Recarga lida antes do evento.** O `tags.js` para tudo ao ouvir o
  `rastro:consent` da revogação (e aí `carregado()` vira `false`), então o
  `consent.js` pergunta `window.rastro.tags.algumCarregado()` (vale mesmo
  depois do `parar()`; sem ela, `carregado()`) **antes** de disparar o
  evento, e recarrega depois.
- **Aceitar de novo na mesma página espera a recarga.** Depois de uma
  revogação, o `tags.js` fica parado até a página recarregar: um aceite
  feito antes disso só vale na página seguinte (o cookie já fica gravado).
- **Cookie de origem** (`rastro_origem`, ou o nome de
  `rastroConfig.atribuicao.cookie`): negar a análise apaga, menos quando
  "Visita de campanha" está ligada (`consent.manterOrigem`, contrato 2.1) e
  o próprio cookie diz que é de campanha: o JSON em base64url do
  `atribuicao.js` com `v: 1`, `o: 'campanha'` e os dois toques (`p` e `u`,
  ou só `p`) com algum campo de campanha (`s`, `m`, `c`, `n`, `t`, `i` ou
  `k`). Cookie de aceite, de sessão, ilegível, com um toque sem campanha ou
  sem `atob` no navegador: apagado (na dúvida, apaga).
- **Meta:** o `parar()` do `tags.js` chama `fbq('consent', 'revoke')`.
- **TikTok:** cookies `ttcsid`, `ttcsid_<pixel>` e `ttclid` conferidos na
  ajuda oficial ("Using cookies with TikTok Pixel"); sem chamada de
  revogação no código (ver `docs/TAGS.md`, "Não conferido").

## PHP

| Função | Para quê |
|---|---|
| `rastro_consent_provedor(): string` | `proprio`, `wp_consent_api`, nome da ponte ou `nenhum` |
| `rastro_consent_finalidades(): array` | finalidades com destino |
| `rastro_consent_servidor(): array` | `analise`, `publicidade`, `decidido` pelo cookie próprio; `true` nas três no modo básico; `null` com outro provedor |
| `rastro_consent_modo(): string` | `lgpd`, `aceite_automatico` ou `basico` |
| `rastro_consent_pontes(): array` | pontes válidas |
| `rastro_consent_ativo(): bool` | a página leva o consentimento |
| `rastro_consent_aviso_proprio(): bool` | o aviso próprio entra |
| `rastro_consent_dias(): int` | prazo do cookie |
| `rastro_consent_regra_*()` | regras puras: `modo`, `finalidades`, `provedor`, `cookie`, `ponte_js`, `fila` |
| `rastro_consent_redefinir_cache(): void` | testes |
| `rastro_aviso_botao_preferencias( array $args = array() ): string` | botão de preferências |
| `rastro_aviso_botao_flutuante_ativo(): bool` | botão fixo aparece |
| `rastro_aviso_html(): string`, `rastro_aviso_flutuante_html(): string` | HTML do aviso e do botão fixo |

Filtros novos: `rastro_consent_provedores`, `rastro_aviso_botao_flutuante`.
Constantes: `RASTRO_CONSENT_COOKIE`, `RASTRO_CONSENT_VISITA`.

## Não conferido

- Comportamento real no navegador e com cada otimizador: só no DOM falso do
  contrato Node; falta o Playground (seção 12).
- WP Consent API: nomes conferidos no código e no leia-me, sem rodar o
  plugin de verdade com um aviso que a use.
- `window.rastro.tags.algumCarregado()` e `carregado()`: são da T3; o
  consentimento só chama quando existem.
- **Cookies da OpenAI no site:** a documentação oficial do pixel da OpenAI
  não foi lida com a lista de cookies primários que ele grava no site da
  loja (só guias de terceiros e a política de cookies do próprio site da
  OpenAI, que fala dos cookies dela). Nenhum cookie da OpenAI entra na
  limpeza; a revogação usa `oaiq('consent', false)` (conferido) e a recarga.
- Finalidade que ganha destino depois da escolha: o cookie guarda `p0` e o
  aviso não pergunta de novo até o prazo vencer (o formato do cookie não
  separa "recusou" de "não foi perguntado").
- Recusar a análise com "Visita de campanha" ligada (padrão) mantém só o cookie de origem gravado como campanha, com os toques de campanha (revisão de 2026-11-11); o resto é apagado. Antes, a origem ficava inteira ("vai ser registrada de qualquer forma", pedido original): a regra nova segue o pedido só para a visita de campanha.

## Testar

```
php -l rastro/consentimento/consentimento.php
php -l rastro/consentimento/aviso.php
node --check rastro/assets/js/consent.js
php tests/php/rastro-consentimento.test.php
node tests/validate-rastro-consentimento-contract.js
```

O contrato Node roda o `consent.js` num DOM mínimo falso (cookies,
armazenamento, eventos, foco e relógio falsos) e chama o teste PHP quando há
`php` no PATH.
