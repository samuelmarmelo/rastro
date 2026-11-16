<?php
/**
 * Testes do consentimento do Rastro (consentimento/consentimento.php e
 * consentimento/aviso.php), sem WordPress: modos, escolha do provedor,
 * finalidades pela configuração, cookie lido no servidor, fila do <head>,
 * chave `consent` do navegador, HTML do aviso escapado e nada impresso sem
 * destino.
 *
 * Uso: php tests/php/rastro-consentimento.test.php (sai com 1 se algo falhar).
 * Contrato: docs/CONTRATO.md, seções 2.1 e 4.
 * IDs fictícios, só com o formato de cada serviço.
 *
 * @package Rastro
 */

define( 'ABSPATH', __DIR__ . '/' );

require __DIR__ . '/stubs-rastro.php';

// Stub extra (só neste teste): plugin_basename.
if ( ! function_exists( 'plugin_basename' ) ) {
	function plugin_basename( $arquivo ) { return 'rastro/' . basename( (string) $arquivo ); } // phpcs:ignore
}

$falhas = 0;
$total  = 0;
function confere( $nome, $obtido, $esperado ) { // phpcs:ignore
	global $falhas, $total;
	++$total;
	if ( $obtido !== $esperado ) {
		++$falhas;
		fwrite( STDERR, "FALHOU: {$nome}\n  esperado: " . var_export( $esperado, true ) . "\n  obtido:   " . var_export( $obtido, true ) . "\n" );
	}
}
function contem( $nome, $texto, $trecho ) { // phpcs:ignore
	confere( $nome, false !== strpos( (string) $texto, $trecho ), true );
}
function nao_contem( $nome, $texto, $trecho ) { // phpcs:ignore
	confere( $nome, false === strpos( (string) $texto, $trecho ), true );
}

/** Zera o estado e as memórias do Rastro (os ganchos dos módulos ficam). */
function novo( array $config = array(), array $sobre = array() ) { // phpcs:ignore
	rastro_teste_reset( array( 'opcoes' => array( 'rastro_config' => $config ) ) + $sobre );
	rastro_config_redefinir_cache();
	rastro_regras_redefinir_cache();
	rastro_consent_redefinir_cache();
	// O reset dos stubs zera os shortcodes.
	rastro_aviso_registrar_shortcode();
}

$falhas_registradas = array();
add_action(
	'rastro_falha',
	static function ( $modulo, $erro ) use ( &$falhas_registradas ) {
		$falhas_registradas[] = array( $modulo, get_class( $erro ), $erro->getMessage() );
	},
	10,
	2
);

rastro_teste_reset();
require __DIR__ . '/../../rastro/rastro.php';

$com_destino = array(
	'ga4'  => 'G-ABC1234',
	'meta' => '1111222233334444',
);

/* 0. Carga e ganchos ----------------------------------------------------------- */

confere( 'módulo carregado', function_exists( 'rastro_consent_provedor' ) && function_exists( 'rastro_aviso_botao_preferencias' ), true );
confere( 'sem falha ao carregar', $falhas_registradas, array() );
confere( 'parte pública ligada no wp', has_action( 'wp', 'rastro_consent_iniciar' ), 1 );
confere( 'aviso ligado no wp', has_action( 'wp', 'rastro_aviso_iniciar' ), 1 );
confere( 'fila ainda não ligada antes do wp', has_action( 'wp_head', 'rastro_consent_fila' ), false );
confere( 'avisos do painel ligados', has_filter( 'rastro_admin_avisos', 'rastro_consent_filtro_avisos' ), 10 );
confere( 'shortcode registrado', shortcode_exists( 'rastro_preferencias' ), true );
confere( 'WP Consent API: o Rastro se declara', has_filter( 'wp_consent_api_registered_rastro/rastro.php', '__return_true' ), 10 );
do_action( 'wp' );
confere( 'fila no wp_head, prioridade 2', has_action( 'wp_head', 'rastro_consent_fila' ), 2 );
confere( 'precisa saída ligado', has_filter( 'rastro_precisa_saida', 'rastro_consent_filtro_precisa_saida' ), 10 );
confere( 'navegador ligado', has_filter( 'rastro_config_navegador', 'rastro_consent_filtro_navegador' ), 10 );
confere( 'scripts ligados', has_action( 'wp_enqueue_scripts', 'rastro_consent_scripts' ), 10 );
confere( 'aviso no wp_footer', has_action( 'wp_footer', 'rastro_aviso_imprimir' ), 20 );

/* 1. Regras puras ------------------------------------------------------------- */

confere( 'modo: lgpd', rastro_consent_regra_modo( 'lgpd' ), 'lgpd' );
confere( 'modo: aceite automático', rastro_consent_regra_modo( 'aceite_automatico' ), 'aceite_automatico' );
confere( 'modo: básico', rastro_consent_regra_modo( 'basico' ), 'basico' );
confere( 'modo: inválido vira lgpd', rastro_consent_regra_modo( 'livre' ), 'lgpd' );
confere( 'modo: não texto vira lgpd', rastro_consent_regra_modo( null ), 'lgpd' );

confere( 'finalidades: nenhuma', rastro_consent_regra_finalidades( array() ), array() );
confere( 'finalidades: GA4 só análise', rastro_consent_regra_finalidades( array( 'ga4' => 'G-X' ) ), array( 'analise' ) );
confere( 'finalidades: PostHog só análise', rastro_consent_regra_finalidades( array( 'posthog' => 'phc_x' ) ), array( 'analise' ) );
foreach ( array( 'ads', 'meta', 'tiktok', 'openai' ) as $chave ) {
	confere( "finalidades: {$chave} só publicidade", rastro_consent_regra_finalidades( array( $chave => 'x' ) ), array( 'publicidade' ) );
}
confere( 'finalidades: GTM só publicidade', rastro_consent_regra_finalidades( array( 'gtm' => 'GTM-X' ) ), array( 'publicidade' ) );
confere( 'finalidades: GTM com GA4, as duas', rastro_consent_regra_finalidades( array( 'gtm' => 'GTM-X', 'ga4' => 'G-X' ) ), array( 'analise', 'publicidade' ) );
confere( 'finalidades: vazio e não texto não contam', rastro_consent_regra_finalidades( array( 'ga4' => '', 'meta' => 1 ) ), array() );

confere( 'provedor: automático sem nada, próprio', rastro_consent_regra_provedor( 'automatico', array(), false ), 'proprio' );
confere( 'provedor: automático com ponte', rastro_consent_regra_provedor( 'automatico', array( 'site' ), true ), 'site' );
confere( 'provedor: automático com WP Consent API', rastro_consent_regra_provedor( 'automatico', array(), true ), 'wp_consent_api' );
confere( 'provedor: próprio vence tudo', rastro_consent_regra_provedor( 'proprio', array( 'site' ), true ), 'proprio' );
confere( 'provedor: externo sem nada, nenhum', rastro_consent_regra_provedor( 'externo', array(), false ), 'nenhum' );
confere( 'provedor: externo com WP Consent API', rastro_consent_regra_provedor( 'externo', array(), true ), 'wp_consent_api' );
confere( 'provedor: básico ignora externos', rastro_consent_regra_provedor( 'externo', array( 'site' ), true, 'basico' ), 'proprio' );
confere( 'provedor: aceite automático segue o aviso', rastro_consent_regra_provedor( 'automatico', array( 'site' ), false, 'aceite_automatico' ), 'site' );

$negado = array( 'analise' => false, 'publicidade' => false, 'decidido' => false );
confere( 'cookie: a1p1', rastro_consent_regra_cookie( 'a1p1' ), array( 'analise' => true, 'publicidade' => true, 'decidido' => true ) );
confere( 'cookie: a1p0', rastro_consent_regra_cookie( 'a1p0' ), array( 'analise' => true, 'publicidade' => false, 'decidido' => true ) );
confere( 'cookie: a0p1', rastro_consent_regra_cookie( 'a0p1' ), array( 'analise' => false, 'publicidade' => true, 'decidido' => true ) );
confere( 'cookie: a0p0 é escolha (recusa)', rastro_consent_regra_cookie( 'a0p0' ), array( 'analise' => false, 'publicidade' => false, 'decidido' => true ) );
foreach ( array( '', 'a2p1', 'a1p1x', "a1p1\n", 'A1P1', 'sim' ) as $ruim ) {
	confere( 'cookie inválido: ' . var_export( $ruim, true ), rastro_consent_regra_cookie( $ruim ), $negado );
}
confere( 'cookie não texto', rastro_consent_regra_cookie( array( 'a1p1' ) ), $negado );

$ponte = array(
	'cookie' => 'site_consentimento',
	'mapa'   => array(
		'analise'     => array( 'analise' => true ),
		'publicidade' => array( 'analise' => true, 'publicidade' => true ),
		'recusado'    => array(),
		'<x>'         => array( 'analise' => true ),
		'solto'       => 'sim',
	),
	'evento' => 'site:consentimento',
	'campo'  => 'escolha',
	'abrir'  => '[data-site-cookies-abrir]',
);
confere(
	'ponte: limpa',
	rastro_consent_regra_ponte_js( $ponte ),
	array(
		'cookie' => 'site_consentimento',
		'mapa'   => array(
			'analise'     => array( 'analise' => true, 'publicidade' => false ),
			'publicidade' => array( 'analise' => true, 'publicidade' => true ),
			'recusado'    => array( 'analise' => false, 'publicidade' => false ),
		),
		'evento' => 'site:consentimento',
		'campo'  => 'escolha',
		'abrir'  => '[data-site-cookies-abrir]',
	)
);
confere( 'ponte: cookie com nome inválido', rastro_consent_regra_ponte_js( array( 'cookie' => 'a b', 'mapa' => array( 'x' => array() ) ) ), null );
confere( 'ponte: sem mapa', rastro_consent_regra_ponte_js( array( 'cookie' => 'c' ) ), null );
confere( 'ponte: mapa sem valor válido', rastro_consent_regra_ponte_js( array( 'cookie' => 'c', 'mapa' => array( '<x>' => array() ) ) ), null );
$suja = rastro_consent_regra_ponte_js( array( 'cookie' => 'c', 'mapa' => array( 'x' => array() ), 'evento' => 'a b<', 'campo' => 'a-b', 'abrir' => '</script>' ) );
confere( 'ponte: evento, campo e seletor inválidos ficam vazios', array( $suja['evento'], $suja['campo'], $suja['abrir'] ), array( '', '', '' ) );
confere( 'ponte: só "true" liga', rastro_consent_regra_ponte_js( array( 'cookie' => 'c', 'mapa' => array( 'x' => array( 'analise' => 1 ) ) ) )['mapa']['x']['analise'], false );

$fila = rastro_consent_regra_fila( 'lgpd' );
contem( 'fila: dataLayer', $fila, 'window.dataLayer=window.dataLayer||[];' );
contem( 'fila: gtag', $fila, 'window.gtag=window.gtag||function(){window.dataLayer.push(arguments);};' );
contem( 'fila: padrão negado nos quatro sinais e espera de 500 ms', $fila, "window.gtag('consent','default',{\"ad_storage\":\"denied\",\"analytics_storage\":\"denied\",\"ad_user_data\":\"denied\",\"ad_personalization\":\"denied\",\"wait_for_update\":500});" );
confere( 'fila: aceite automático também começa negado', rastro_consent_regra_fila( 'aceite_automatico' ), $fila );
$fila_basico = rastro_consent_regra_fila( 'basico' );
contem( 'fila básica: concedido nos quatro', $fila_basico, "window.gtag('consent','default',{\"ad_storage\":\"granted\",\"analytics_storage\":\"granted\",\"ad_user_data\":\"granted\",\"ad_personalization\":\"granted\"});" );
nao_contem( 'fila básica: sem negado', $fila_basico, 'denied' );
nao_contem( 'fila básica: sem espera', $fila_basico, 'wait_for_update' );

/* 2. Provedor e finalidades pela configuração ------------------------------------ */

novo( $com_destino );
confere( 'padrão: provedor próprio', rastro_consent_provedor(), 'proprio' );
confere( 'padrão: modo lgpd', rastro_consent_modo(), 'lgpd' );
confere( 'finalidades da configuração', rastro_consent_finalidades(), array( 'analise', 'publicidade' ) );
novo( array( 'ga4' => 'G-ABC1234' ) );
confere( 'só GA4: só análise', rastro_consent_finalidades(), array( 'analise' ) );
novo( array( 'gtm' => 'GTM-ABCD1234' ) );
confere( 'GTM: só publicidade', rastro_consent_finalidades(), array( 'publicidade' ) );
novo( $com_destino + array( 'aviso' => 'externo' ) );
confere( 'externo sem nada ativo: nenhum', rastro_consent_provedor(), 'nenhum' );

// Ponte registrada pelo site (como a do tema, que tem cookie e evento próprios).
$ponte_ativa = true;
add_filter(
	'rastro_consent_provedores',
	static function ( $p ) use ( $ponte, &$ponte_ativa ) {
		$p['site']           = array( 'ativo' => static function () use ( &$ponte_ativa ) { return $ponte_ativa; }, 'js' => $ponte );
		$p['proprio']        = array( 'ativo' => '__return_true', 'js' => $ponte );
		$p['sem_js']         = array( 'ativo' => '__return_true' );
		$p['Nome Ruim']      = array( 'ativo' => '__return_true', 'js' => $ponte );
		$p['nao_chamavel']   = array( 'ativo' => 'funcao_que_nao_existe', 'js' => $ponte );
		return $p;
	}
);
novo( $com_destino );
confere( 'pontes válidas: só a do site', array_keys( rastro_consent_pontes() ), array( 'site' ) );
confere( 'automático com ponte ativa: a ponte', rastro_consent_provedor(), 'site' );
novo( $com_destino + array( 'aviso' => 'externo' ) );
confere( 'externo com ponte ativa: a ponte', rastro_consent_provedor(), 'site' );
novo( $com_destino + array( 'aviso' => 'proprio' ) );
confere( 'aviso próprio pedido: próprio', rastro_consent_provedor(), 'proprio' );
novo( $com_destino + array( 'modo' => 'basico' ) );
confere( 'básico ignora a ponte', rastro_consent_provedor(), 'proprio' );
novo( $com_destino + array( 'modo' => 'aceite_automatico' ) );
confere( 'aceite automático com ponte: a ponte', rastro_consent_provedor(), 'site' );
$ponte_ativa = false;
novo( $com_destino );
confere( 'ponte inativa: próprio', rastro_consent_provedor(), 'proprio' );
$ponte_ativa = true;

// Ponte que quebra: falha fechada.
add_filter(
	'rastro_consent_provedores',
	static function ( $p ) use ( $ponte ) {
		return array( 'quebrada' => array( 'ativo' => static function () { throw new \RuntimeException( 'x' ); }, 'js' => $ponte ) ) + $p;
	},
	20
);
$falhas_registradas = array();
novo( $com_destino );
confere( 'ponte com erro: nenhum', rastro_consent_provedor(), 'nenhum' );
confere( 'ponte com erro: rastro_falha', $falhas_registradas[0][0] ?? null, 'consentimento' );
remove_all_filters( 'rastro_consent_provedores' );

/* 3. Cookie no servidor ------------------------------------------------------------ */

novo( $com_destino, array( 'cookies' => array( 'rastro_consent' => 'a1p0' ) ) );
confere( 'servidor: a1p0', rastro_consent_servidor(), array( 'analise' => true, 'publicidade' => false, 'decidido' => true ) );
novo( $com_destino, array( 'cookies' => array( 'rastro_consent' => '"><script>' ) ) );
confere( 'servidor: inválido', rastro_consent_servidor(), $negado );
novo( $com_destino, array( 'cookies' => array( 'rastro_consent' => array( 'a1p1' ) ) ) );
confere( 'servidor: cookie em array', rastro_consent_servidor(), $negado );
novo( $com_destino );
confere( 'servidor: sem cookie', rastro_consent_servidor(), $negado );
novo( $com_destino + array( 'modo' => 'basico' ) );
confere( 'servidor: básico concede tudo', rastro_consent_servidor(), array( 'analise' => true, 'publicidade' => true, 'decidido' => true ) );
add_filter( 'rastro_consent_provedores', static function () use ( $ponte ) { return array( 'site' => array( 'ativo' => '__return_true', 'js' => $ponte ) ); } );
novo( $com_destino, array( 'cookies' => array( 'rastro_consent' => 'a1p1' ) ) );
confere( 'servidor: outro provedor, null', rastro_consent_servidor(), array( 'analise' => null, 'publicidade' => null, 'decidido' => null ) );
remove_all_filters( 'rastro_consent_provedores' );

/* 4. Saída: nada sem destino ----------------------------------------------------- */

novo();
confere( 'sem destino: não precisa saída', rastro_precisa_saida(), false );
confere( 'sem destino: config do head vazia', rastro_teste_saida( 'rastro_head_config' ), '' );
confere( 'sem destino: fila vazia', rastro_teste_saida( 'rastro_consent_fila' ), '' );
confere( 'sem destino: aviso vazio', rastro_teste_saida( 'rastro_aviso_imprimir' ), '' );
confere( 'sem destino: botão vazio', rastro_aviso_botao_preferencias(), '' );
confere( 'sem destino: shortcode vazio', do_shortcode( '[rastro_preferencias]' ), '' );
rastro_consent_scripts();
confere( 'sem destino: nenhum script', $GLOBALS['rastro_teste']['scripts'], array() );
confere( 'sem destino: nenhum estilo', $GLOBALS['rastro_teste']['estilos'], array() );
novo( $com_destino, array( 'admin' => true ) );
confere( 'painel: nada sai', rastro_teste_saida( 'rastro_consent_fila' ) . rastro_teste_saida( 'rastro_aviso_imprimir' ), '' );
novo( $com_destino + array( 'aviso' => 'externo' ) );
confere( 'externo sem provedor: nada sai', rastro_teste_saida( 'rastro_consent_fila' ) . rastro_teste_saida( 'rastro_aviso_imprimir' ), '' );
rastro_consent_scripts();
confere( 'externo sem provedor: sem consent.js', isset( $GLOBALS['rastro_teste']['scripts']['rastro-consent'] ), false );

/* 5. Saída com destino ------------------------------------------------------------- */

novo( $com_destino );
confere( 'com destino: precisa saída', rastro_precisa_saida(), true );
$fila = rastro_teste_saida( 'rastro_consent_fila' );
confere( 'fila: script do Rastro com atributos de saída', 1 === preg_match( '#^<script data-no-optimize="1" data-no-defer="1" data-cfasync="false" id="rastro-fila" class="nowprocket">#', $fila ), true );
contem( 'fila: padrão negado', $fila, '"ad_storage":"denied"' );
confere( 'fila: pequena', strlen( rastro_consent_regra_fila( 'lgpd' ) ) < 400, true );

$nav = rastro_config_navegador();
confere(
	'navegador: chave consent',
	$nav['consent'] ?? null,
	array(
		'modo'        => 'lgpd',
		'provedor'    => 'proprio',
		'finalidades' => array( 'analise', 'publicidade' ),
		'cookie'      => 'rastro_consent',
		'visita'      => 'rastro_consent_visita',
		'dias'        => 180,
		'segundos'    => 2,
		'flutuante'   => true,
		'medir'       => true,
		'manterOrigem' => true,
	)
);
nao_contem( 'navegador: sem a escolha do visitante (cache seguro)', wp_json_encode( $nav['consent'] ), 'a1p' );
novo( $com_destino + array( 'modo' => 'aceite_automatico', 'aceite_segundos' => 7, 'aviso_dias' => 30 ) );
$nav = rastro_config_navegador();
confere( 'navegador: modo, segundos e dias', array( $nav['consent']['modo'], $nav['consent']['segundos'], $nav['consent']['dias'] ), array( 'aceite_automatico', 7, 30 ) );

rastro_consent_scripts();
$s = $GLOBALS['rastro_teste']['scripts']['rastro-consent'] ?? array();
confere( 'consent.js registrado e enfileirado', ! empty( $s['enfileirado'] ), true );
confere( 'consent.js no rodapé com defer', $s['args'] ?? null, array( 'in_footer' => true, 'strategy' => 'defer' ) );
confere( 'consent.js pelo RASTRO_URL', $s['src'] ?? null, RASTRO_URL . 'assets/js/consent.js' );
confere( 'aviso.css enfileirado', $GLOBALS['rastro_teste']['estilos']['rastro-aviso']['src'] ?? null, RASTRO_URL . 'assets/css/aviso.css' );

// HTML do aviso.
novo( $com_destino + array( 'aviso_texto' => 'Cookies & "medição" só com permissão.' ), array( 'politica_url' => 'https://loja.exemplo.com.br/privacidade/?a="x"&b=1' ) );
$html = rastro_teste_saida( 'rastro_aviso_imprimir' );
contem( 'aviso: escondido no HTML', $html, '<div class="rastro-aviso" id="rastro-aviso" data-rastro-aviso hidden role="region" aria-label="Aviso de cookies">' );
contem( 'aviso: texto escapado', $html, 'Cookies &amp; &quot;medição&quot; só com permissão.' );
contem( 'aviso: política escapada', $html, 'href="https://loja.exemplo.com.br/privacidade/?a=&quot;x&quot;&amp;b=1">Política de privacidade</a>' );
foreach ( array( 'recusar' => 'Recusar', 'personalizar' => 'Personalizar', 'aceitar' => 'Aceitar' ) as $chave => $rotulo ) {
	confere( "aviso: botão {$rotulo} com a mesma classe", 1 === preg_match( '#<button type="button" class="rastro-aviso__botao" data-rastro-aviso-' . $chave . '[^>]*>' . $rotulo . '</button>#', $html ), true );
}
confere( 'aviso: ordem Recusar, Personalizar, Aceitar', strpos( $html, '>Recusar<' ) < strpos( $html, '>Personalizar<' ) && strpos( $html, '>Personalizar<' ) < strpos( $html, '>Aceitar<' ), true );
contem( 'aviso: Personalizar controla o painel', $html, 'aria-expanded="false" aria-controls="rastro-aviso-preferencias"' );
contem( 'aviso: painel escondido', $html, 'data-rastro-aviso-preferencias hidden>' );
contem( 'aviso: Salvar escolhas', $html, 'data-rastro-aviso-salvar>Salvar escolhas</button>' );
confere( 'aviso: duas finalidades', substr_count( $html, 'data-rastro-finalidade=' ), 2 );
nao_contem( 'aviso: caixas desmarcadas', $html, 'checked' );
contem( 'aviso: rótulo ligado à caixa', $html, '<label for="rastro-aviso-analise">Análise</label>' );
contem( 'aviso: botão fixo escondido', $html, '<button type="button" class="rastro-preferencias rastro-preferencias--flutuante" data-rastro-preferencias data-rastro-flutuante hidden>Preferências de cookies</button>' );
nao_contem( 'aviso: sem script inline', $html, '<script' );
nao_contem( 'aviso: sem travessão', $html, "\u{2014}" );

novo( array( 'ga4' => 'G-ABC1234' ), array( 'politica_url' => '' ) );
$html = rastro_teste_saida( 'rastro_aviso_imprimir' );
contem( 'só análise: caixa de análise', $html, 'data-rastro-finalidade="analise"' );
nao_contem( 'só análise: sem publicidade', $html, 'data-rastro-finalidade="publicidade"' );
nao_contem( 'sem política: sem link', $html, 'rastro-aviso__link' );

// Mesmo HTML para qualquer visitante (cache seguro): a escolha não muda a página.
novo( $com_destino );
$sem_cookie = rastro_teste_saida( 'rastro_aviso_imprimir' );
novo( $com_destino, array( 'cookies' => array( 'rastro_consent' => 'a1p1' ) ) );
confere( 'HTML igual com e sem escolha', rastro_teste_saida( 'rastro_aviso_imprimir' ), $sem_cookie );

// Equipe e cópia local veem o aviso (para conferir); a página não mede.
novo( $com_destino, array( 'logado' => true, 'capacidades' => array( 'edit_posts' => true ) ) );
confere( 'equipe: não mede', rastro_deve_medir(), false );
contem( 'equipe: aviso sai', rastro_teste_saida( 'rastro_aviso_imprimir' ), 'data-rastro-aviso' );
confere( 'equipe: config diz que não mede', rastro_config_navegador()['consent']['medir'], false );
novo( $com_destino, array( 'host' => 'loja.localhost' ) );
contem( 'cópia local: aviso sai', rastro_teste_saida( 'rastro_aviso_imprimir' ), 'data-rastro-aviso' );

// Botão de preferências.
novo( $com_destino );
confere( 'botão: HTML', rastro_aviso_botao_preferencias(), '<button type="button" class="rastro-preferencias" data-rastro-preferencias>Preferências de cookies</button>' );
confere( 'botão: classe e texto limpos', rastro_aviso_botao_preferencias( array( 'classe' => 'rodape"><x', 'texto' => '<b>Cookies</b>' ) ), '<button type="button" class="rastro-preferencias rodapex" data-rastro-preferencias>&lt;b&gt;Cookies&lt;/b&gt;</button>' );
confere( 'shortcode: o botão', do_shortcode( '[rastro_preferencias]' ), rastro_aviso_botao_preferencias() );
add_filter( 'rastro_aviso_botao_flutuante', '__return_false' );
nao_contem( 'flutuante desligado pelo filtro', rastro_teste_saida( 'rastro_aviso_imprimir' ), 'data-rastro-flutuante' );
confere( 'flutuante desligado na config', rastro_config_navegador()['consent']['flutuante'], false );
remove_all_filters( 'rastro_aviso_botao_flutuante' );

/* 6. Modos ------------------------------------------------------------------------- */

novo( $com_destino + array( 'modo' => 'aceite_automatico' ) );
contem( 'aceite automático: aviso sai', rastro_teste_saida( 'rastro_aviso_imprimir' ), 'data-rastro-aviso' );
contem( 'aceite automático: fila negada', rastro_teste_saida( 'rastro_consent_fila' ), '"analytics_storage":"denied"' );
confere( 'aceite automático: botão de preferências', '' !== rastro_aviso_botao_preferencias(), true );

novo( $com_destino + array( 'modo' => 'basico' ) );
confere( 'básico: sem aviso e sem botão fixo', rastro_teste_saida( 'rastro_aviso_imprimir' ), '' );
confere( 'básico: sem botão de preferências', rastro_aviso_botao_preferencias(), '' );
confere( 'básico: shortcode vazio', do_shortcode( '[rastro_preferencias]' ), '' );
$fila = rastro_teste_saida( 'rastro_consent_fila' );
contem( 'básico: fila concedida', $fila, '"analytics_storage":"granted"' );
nao_contem( 'básico: fila sem negado', $fila, 'denied' );
confere( 'básico: config', array( rastro_config_navegador()['consent']['modo'], rastro_config_navegador()['consent']['flutuante'] ), array( 'basico', false ) );
rastro_consent_scripts();
confere( 'básico: consent.js enfileirado', ! empty( $GLOBALS['rastro_teste']['scripts']['rastro-consent']['enfileirado'] ), true );
confere( 'básico: sem estilo do aviso', isset( $GLOBALS['rastro_teste']['estilos']['rastro-aviso'] ), false );

/* 7. Ponte na saída ------------------------------------------------------------------- */

add_filter( 'rastro_consent_provedores', static function () use ( $ponte ) { return array( 'site' => array( 'ativo' => '__return_true', 'js' => $ponte ) ); } );
novo( $com_destino );
$nav = rastro_config_navegador();
confere( 'ponte: provedor na config', $nav['consent']['provedor'], 'site' );
confere( 'ponte: configuração do navegador', $nav['consent']['externo']['cookie'] ?? null, 'site_consentimento' );
confere( 'ponte: flutuante desligado por padrão', $nav['consent']['flutuante'], false );
confere( 'ponte: sem aviso próprio', rastro_teste_saida( 'rastro_aviso_imprimir' ), '' );
confere( 'ponte: botão de preferências reabre o aviso do site', rastro_aviso_botao_preferencias(), '<button type="button" class="rastro-preferencias" data-rastro-preferencias>Preferências de cookies</button>' );
add_filter( 'rastro_aviso_botao_flutuante', '__return_true' );
contem( 'ponte: flutuante pelo filtro', rastro_teste_saida( 'rastro_aviso_imprimir' ), 'data-rastro-flutuante' );
remove_all_filters( 'rastro_aviso_botao_flutuante' );
rastro_consent_scripts();
confere( 'ponte: consent.js sem estilo do aviso', array( ! empty( $GLOBALS['rastro_teste']['scripts']['rastro-consent']['enfileirado'] ), isset( $GLOBALS['rastro_teste']['estilos']['rastro-aviso'] ) ), array( true, false ) );
novo( $com_destino + array( 'modo' => 'basico' ) );
confere( 'ponte no básico: sem externo', isset( rastro_config_navegador()['consent']['externo'] ), false );
// Ponte sem medição (equipe): nada sai, o aviso é do site.
novo( $com_destino, array( 'logado' => true, 'capacidades' => array( 'manage_woocommerce' => true ) ) );
confere( 'ponte com equipe: nada sai', rastro_teste_saida( 'rastro_consent_fila' ), '' );
remove_all_filters( 'rastro_consent_provedores' );

/* 8. Avisos do painel --------------------------------------------------------------- */

$lgpd = 'Este modo não atende a LGPD: ficar parado ou não ser perguntado não é consentimento. A escolha e o risco são do site.';
novo( $com_destino, array( 'admin' => true ) );
confere( 'painel: lgpd sem aviso', rastro_consent_filtro_avisos( array() ), array() );
novo( $com_destino + array( 'modo' => 'aceite_automatico' ), array( 'admin' => true ) );
confere( 'painel: aceite automático avisa', rastro_consent_filtro_avisos( array() ), array( array( 'tipo' => 'warning', 'texto' => $lgpd ) ) );
novo( $com_destino + array( 'modo' => 'basico' ), array( 'admin' => true ) );
confere( 'painel: básico avisa', rastro_consent_filtro_avisos( array() ), array( array( 'tipo' => 'warning', 'texto' => $lgpd ) ) );
novo( $com_destino + array( 'aviso' => 'externo' ), array( 'admin' => true ) );
$avisos = rastro_consent_filtro_avisos( array( array( 'tipo' => 'info', 'texto' => 'outro' ) ) );
confere( 'painel: externo sem provedor avisa', array( count( $avisos ), $avisos[1]['tipo'] ?? null ), array( 2, 'error' ) );
contem( 'painel: texto da falha fechada', $avisos[1]['texto'] ?? '', 'nenhum aviso externo está ativo' );
confere( 'painel: avisos não array passam', rastro_consent_filtro_avisos( 'x' ), 'x' );
foreach ( array( $lgpd, $avisos[1]['texto'] ?? '' ) as $texto ) {
	nao_contem( 'painel: sem travessão', $texto, "\u{2014}" );
}

/* 9. WP Consent API (por último: a função não se desfaz) ----------------------------- */

novo( $com_destino );
confere( 'sem WP Consent API: próprio', rastro_consent_provedor(), 'proprio' );
// Declarada só aqui (dentro do if, para o PHP não a criar antes da hora).
if ( ! function_exists( 'wp_has_consent' ) ) {
	function wp_has_consent( $categoria ) { return false; } // phpcs:ignore
}
novo( $com_destino );
confere( 'WP Consent API ativa: provedor', rastro_consent_provedor(), 'wp_consent_api' );
confere( 'WP Consent API: sem botão de preferências', rastro_aviso_botao_preferencias(), '' );
confere( 'WP Consent API: sem flutuante', rastro_aviso_botao_flutuante_ativo(), false );
confere( 'WP Consent API: servidor null', rastro_consent_servidor()['analise'], null );
novo( $com_destino + array( 'aviso' => 'proprio' ) );
confere( 'WP Consent API com aviso próprio pedido: próprio', rastro_consent_provedor(), 'proprio' );
novo( $com_destino + array( 'modo' => 'basico' ) );
confere( 'WP Consent API no básico: ignorada', rastro_consent_provedor(), 'proprio' );

confere( 'nenhuma falha registrada', array_values( array_filter( $falhas_registradas, static function ( $f ) { return 'consentimento' !== $f[0]; } ) ), array() );

echo "{$total} conferências do consentimento do Rastro, {$falhas} falha(s).\n";
exit( $falhas > 0 ? 1 : 0 );
