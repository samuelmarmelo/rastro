<?php
/**
 * Testes das tags do Rastro (rastro/tags/tags.php),
 * sem WordPress: destinos e finalidades, filtro `rastro_destinos`, chave
 * `tags` da configuração do navegador, tags.js só quando a página mede,
 * nada de terceiro no HTML antes do aceite, meta de verificação do Google e
 * avisos de duplicidade no painel.
 *
 * Uso: php tests/php/rastro-tags.test.php (sai com 1 se algo falhar).
 * Contrato: docs/CONTRATO.md, seções 5 e 12.
 * Manual: docs/TAGS.md. IDs fictícios, só com o formato de cada serviço.
 *
 * @package Rastro
 */

define( 'ABSPATH', __DIR__ . '/' );

$rastro_dir = realpath( __DIR__ . '/../../rastro' );

require __DIR__ . '/stubs-rastro.php';

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

/** Zera o estado e as memórias do Rastro (os ganchos dos módulos ficam). */
function novo( array $sobre = array() ) { // phpcs:ignore
	rastro_teste_reset( $sobre );
	rastro_config_redefinir_cache();
	rastro_regras_redefinir_cache();
}

/** Configuração com todos os destinos (IDs fictícios). */
function config_cheia( array $extra = array() ) { // phpcs:ignore
	return array_merge(
		array(
			'gtm'                => 'GTM-ABC1234',
			'ga4'                => 'G-ABC1234',
			'ads'                => 'AW-123456789',
			'ads_rotulo_compra'  => 'AbC-123_xy',
			'meta'               => '123456789012345',
			'tiktok'             => 'ABCDEFGHIJ12345678',
			'openai'             => 'oai_pixel_123',
			'posthog'            => 'phc_ABCDEFGHIJKLMNOPQRSTUVWXYZ0123',
			'google_verificacao' => 'abcdefghijklmnopqrstuvwxyz_-123',
		),
		$extra
	);
}

function com_config( array $config, array $sobre = array() ) { // phpcs:ignore
	novo( array_replace( array( 'opcoes' => array( 'rastro_config' => $config ) ), $sobre ) );
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
require $rastro_dir . '/rastro.php';

/* 0. Carga e ganchos ------------------------------------------------------------ */

confere( 'módulo tags carregado', function_exists( 'rastro_destinos' ) && function_exists( 'rastro_tags_meta_verificacao' ), true );
confere( 'filtro rastro_config_navegador', has_filter( 'rastro_config_navegador', 'rastro_tags_navegador' ), 10 );
confere( 'tags.js no wp_enqueue_scripts, prioridade 20', has_action( 'wp_enqueue_scripts', 'rastro_tags_enfileirar' ), 20 );
confere( 'verificação no wp_head, prioridade 1', has_action( 'wp_head', 'rastro_tags_meta_verificacao' ), 1 );
confere( 'avisos do painel', has_filter( 'rastro_admin_avisos', 'rastro_tags_avisos' ), 10 );

/* 1. Destinos e finalidades -------------------------------------------------------- */

novo();
$destinos = rastro_destinos();
confere( 'sete destinos, na ordem', array_keys( $destinos ), array( 'gtm', 'ga4', 'ads', 'meta', 'tiktok', 'openai', 'posthog' ) );
$ativos = array();
foreach ( $destinos as $d ) {
	$ativos[] = $d['ativo'];
}
confere( 'sem configuração, nenhum ativo', array_unique( $ativos ), array( false ) );
confere( 'sem configuração, sem ativos', rastro_destinos_ativos(), array() );

$esperado = array(
	'gtm'     => array( 'publicidade', array( 'publicidade' ) ),
	'ga4'     => array( 'analise', array( 'analise' ) ),
	'ads'     => array( 'publicidade', array( 'publicidade' ) ),
	'meta'    => array( 'publicidade', array( 'publicidade' ) ),
	'tiktok'  => array( 'publicidade', array( 'publicidade' ) ),
	'openai'  => array( 'publicidade', array( 'publicidade' ) ),
	'posthog' => array( 'analise', array( 'analise' ) ),
);
foreach ( $esperado as $chave => $par ) {
	confere( "finalidade de {$chave}", $destinos[ $chave ]['finalidade'], $par[0] );
	confere( "carrega com ({$chave})", $destinos[ $chave ]['carrega_com'], $par[1] );
}

com_config( config_cheia(), array( 'uri' => '/produto/racao/?gclid=abc&utm_source=x&cor=azul', 'woo' => array( 'is_product' => true ) ) );
$destinos = rastro_destinos();
confere( 'configuração cheia, todos ativos', rastro_destinos_ativos(), array( 'gtm', 'ga4', 'ads', 'meta', 'tiktok', 'openai', 'posthog' ) );
confere(
	'GA4: page_location com as chaves de campanha (atribuição) e send_page_view',
	$destinos['ga4']['navegador'],
	array(
		'id'             => 'G-ABC1234',
		'send_page_view' => true,
		'page_location'  => 'https://loja.exemplo.com.br/produto/racao/?utm_source=x&gclid=abc',
	)
);
$montados = rastro_destinos_montar(
	array( 'ga4' => 'G-ABC1234' ),
	array(
		'privada'  => true,
		'url'      => 'https://loja.exemplo.com.br/finalizar-compra/',
		'campanha' => 'https://loja.exemplo.com.br/finalizar-compra/?utm_source=x',
	)
);
confere( 'GA4 em página privada: nunca a consulta de campanha', $montados['ga4']['navegador']['page_location'], 'https://loja.exemplo.com.br/finalizar-compra/' );
confere( 'GTM só com o ID', $destinos['gtm']['navegador'], array( 'id' => 'GTM-ABC1234' ) );
confere( 'Ads com o rótulo da compra', $destinos['ads']['navegador'], array( 'id' => 'AW-123456789', 'rotulo_compra' => 'AbC-123_xy' ) );
confere( 'Meta com o código base na fila do cabeçalho', $destinos['meta']['navegador'], array( 'id' => '123456789012345', 'cabecalho' => 'fila' ) );
confere( 'TikTok só com o ID', $destinos['tiktok']['navegador'], array( 'id' => 'ABCDEFGHIJ12345678' ) );
confere( 'OpenAI sem depuração', $destinos['openai']['navegador'], array( 'id' => 'oai_pixel_123', 'debug' => false ) );
confere(
	'PostHog em página pública',
	$destinos['posthog']['navegador'],
	array(
		'id'     => 'phc_ABCDEFGHIJKLMNOPQRSTUVWXYZ0123',
		'config' => array(
			'api_host'          => 'https://us.i.posthog.com',
			'defaults'          => '2026-08-30',
			'person_profiles'   => 'identified_only',
			'session_recording' => array( 'maskAllInputs' => true ),
			'logs'              => array(
				'serviceName'        => 'rastro',
				'environment'        => 'production',
				'serviceVersion'     => '1.0.0',
				'captureConsoleLogs' => false,
			),
		),
	)
);

com_config( config_cheia( array( 'posthog_logs' => '0' ) ) );
$destinos = rastro_destinos();
confere( 'PostHog sem logs com o campo desligado', isset( $destinos['posthog']['navegador']['config']['logs'] ), false );
com_config( config_cheia( array( 'posthog_logs_console' => '1' ) ) );
$destinos = rastro_destinos();
confere( 'PostHog com o console pela opção', $destinos['posthog']['navegador']['config']['logs']['captureConsoleLogs'], true );

com_config( config_cheia( array( 'posthog_regiao' => 'eu', 'openai_debug' => '1', 'ads_rotulo_compra' => 'ruim!' ) ) );
$destinos = rastro_destinos();
confere( 'PostHog na região eu', $destinos['posthog']['navegador']['config']['api_host'], 'https://eu.i.posthog.com' );
confere( 'OpenAI com depuração', $destinos['openai']['navegador']['debug'], true );
confere( 'rótulo inválido fica vazio', $destinos['ads']['navegador']['rotulo_compra'], '' );
confere( 'host do PostHog: fora da lista é us', rastro_tags_posthog_host( 'xx' ), 'https://us.i.posthog.com' );

// Página privada: endereço até o endpoint, sem replay e sem captura automática.
com_config(
	config_cheia(),
	array(
		'uri'  => '/finalizar-compra/pedido-recebido/123/?key=wc_order_abc',
		'get'  => array( 'key' => 'wc_order_abc' ),
		'woo'  => array( 'is_order_received_page' => true, 'is_checkout' => true ),
	)
);
$destinos = rastro_destinos();
confere( 'pedido recebido: page_location saneado', $destinos['ga4']['navegador']['page_location'], 'https://loja.exemplo.com.br/finalizar-compra/pedido-recebido/' );
confere( 'pedido recebido: PostHog sem replay', $destinos['posthog']['navegador']['config']['disable_session_recording'], true );
confere( 'pedido recebido: PostHog sem autocapture', $destinos['posthog']['navegador']['config']['autocapture'], false );
$json = rastro_json( rastro_tags_config_navegador() );
confere( 'pedido recebido: sem chave nem número do pedido no JSON', false !== strpos( $json, 'wc_order' ) || false !== strpos( $json, '123/' ), false );

// Regras puras.
$montados = rastro_destinos_montar( array( 'ga4' => 'G-<SCRIPT>', 'meta' => '12' ), array() );
confere( 'ID inválido não liga (GA4)', $montados['ga4']['ativo'], false );
confere( 'ID inválido não liga (Meta)', $montados['meta']['ativo'], false );
$montados = rastro_destinos_montar( array( 'ga4' => 'G-ABC1234' ), array( 'url' => '/relativo/' ) );
confere( 'page_location só com endereço completo', array_key_exists( 'page_location', $montados['ga4']['navegador'] ), false );

/* 2. Filtro rastro_destinos ------------------------------------------------------- */

com_config( config_cheia() );
add_filter(
	'rastro_destinos',
	static function ( $d ) {
		$d['posthog']['ativo']      = false;
		$d['meta']['finalidade']    = 'analise';
		$d['meta']['carrega_com']   = array( 'analise' );
		$d['ga4']['id']             = 'G-<script>';
		$d['tiktok']['navegador']   = array( 'id' => 'X', 'extra' => '</script>' );
		$d['openai']['id']          = 'outro_pixel_456';
		return $d;
	}
);
$destinos = rastro_destinos();
confere( 'filtro desliga o PostHog', $destinos['posthog']['ativo'], false );
confere( 'filtro não muda a finalidade', array( $destinos['meta']['finalidade'], $destinos['meta']['carrega_com'] ), array( 'publicidade', array( 'publicidade' ) ) );
confere( 'filtro com ID inválido desliga', array( $destinos['ga4']['ativo'], $destinos['ga4']['id'] ), array( false, '' ) );
confere( 'filtro não troca a configuração do navegador', $destinos['tiktok']['navegador'], array( 'id' => 'ABCDEFGHIJ12345678' ) );
confere( 'filtro troca o ID por outro válido', $destinos['openai']['navegador']['id'], 'outro_pixel_456' );
confere( 'ativos depois do filtro', rastro_destinos_ativos(), array( 'gtm', 'ads', 'meta', 'tiktok', 'openai' ) );
remove_all_filters( 'rastro_destinos' );

com_config( array( 'ga4' => 'G-ABC1234' ) );
add_filter(
	'rastro_destinos',
	static function ( $d ) {
		$d['meta']['ativo'] = true;
		return $d;
	}
);
confere( 'filtro não liga destino sem ID', rastro_destinos()['meta']['ativo'], false );
remove_all_filters( 'rastro_destinos' );
add_filter( 'rastro_destinos', '__return_false' );
confere( 'filtro que não devolve lista: destinos do Rastro', rastro_destinos_ativos(), array( 'ga4' ) );
remove_all_filters( 'rastro_destinos' );

/* 3. tags.js e chave tags ------------------------------------------------------------ */

com_config( config_cheia() );
$nav = rastro_config_navegador();
confere( 'sem tags.js na página, sem chave tags', array_key_exists( 'tags', $nav ), false );
do_action( 'wp_enqueue_scripts' );
confere( 'tags.js registrado e na fila', wp_script_is( 'rastro-tags', 'enqueued' ), true );
$script = rastro_teste( 'scripts' )['rastro-tags'];
confere( 'tags.js do Rastro', substr( $script['src'], -strlen( 'rastro/assets/js/tags.js' ) ), 'rastro/assets/js/tags.js' );
confere( 'tags.js no rodapé com defer', $script['args'], array( 'in_footer' => true, 'strategy' => 'defer' ) );
confere( 'tags.js sem dependência sem consentimento registrado', $script['deps'], array() );
$nav = rastro_config_navegador();
confere( 'chave tags com o script na página', array_key_exists( 'tags', $nav ), true );
$tags = json_decode( rastro_json( $nav['tags'] ), true );
confere( 'tags: destinos ativos', array_keys( $tags['destinos'] ), array( 'gtm', 'ga4', 'ads', 'meta', 'tiktok', 'openai', 'posthog' ) );
confere( 'tags: GTM só com publicidade (LGPD)', $tags['destinos']['gtm'], array( 'carrega_com' => array( 'publicidade' ), 'id' => 'GTM-ABC1234' ) );
confere( 'tags: Meta só com publicidade', $tags['destinos']['meta']['carrega_com'], array( 'publicidade' ) );
confere( 'tags: sem a verificação do Google', false === strpos( rastro_json( $nav['tags'] ), 'abcdefghijklmnopqrstuvwxyz' ), true );

// Com o consentimento registrado, depende dele.
com_config( config_cheia() );
wp_register_script( 'rastro-consent', 'https://loja.exemplo.com.br/consent.js' );
do_action( 'wp_enqueue_scripts' );
confere( 'tags.js depois do consent.js', rastro_teste( 'scripts' )['rastro-tags']['deps'], array( 'rastro-consent' ) );

// Equipe, cópia local, painel e destinos desligados: nada.
$casos = array(
	'equipe'          => array( 'logado' => true, 'capacidades' => array( 'edit_posts' => true ) ),
	'cópia local'     => array( 'host' => 'loja.localhost' ),
	'painel'          => array( 'admin' => true ),
	'AJAX'            => array( 'ajax' => true ),
	'antes do wp'     => array( 'acoes' => array( 'init' => 1 ) ),
);
foreach ( $casos as $nome => $sobre ) {
	com_config( config_cheia(), $sobre );
	do_action( 'wp_enqueue_scripts' );
	confere( "{$nome}: sem tags.js", wp_script_is( 'rastro-tags', 'registered' ), false );
	confere( "{$nome}: sem chave tags", array_key_exists( 'tags', rastro_config_navegador() ), false );
}
com_config( array( 'posthog' => 'phc_ABCDEFGHIJKLMNOPQRSTUVWXYZ0123' ) );
add_filter(
	'rastro_destinos',
	static function ( $d ) {
		$d['posthog']['ativo'] = false;
		return $d;
	}
);
do_action( 'wp_enqueue_scripts' );
confere( 'todos os destinos desligados pelo filtro: sem tags.js', wp_script_is( 'rastro-tags', 'registered' ), false );
remove_all_filters( 'rastro_destinos' );

// Cliente logado (não é equipe) é medido (D3).
com_config( config_cheia(), array( 'logado' => true, 'capacidades' => array( 'read' => true ) ) );
do_action( 'wp_enqueue_scripts' );
confere( 'cliente logado: tags.js', wp_script_is( 'rastro-tags', 'enqueued' ), true );

/* 4. Nada de terceiro no HTML antes do aceite ----------------------------------------- */

$terceiros = '#gtm\.js|gtag/js|fbevents\.js|i18n/pixel|oaiq\.min\.js|array\.js|connect\.facebook\.net|analytics\.tiktok\.com|bzrcdn\.openai\.com|posthog\.com/static#';
foreach ( array( 'sem cookie' => array(), 'com cookie de aceite' => array( 'rastro_consent' => 'a1p1' ) ) as $nome => $cookies ) {
	com_config( config_cheia(), array( 'cookies' => $cookies, 'uri' => '/produto/racao/', 'woo' => array( 'is_product' => true ) ) );
	do_action( 'wp_enqueue_scripts' );
	$head = rastro_teste_saida( 'do_action', array( 'wp_head' ) );
	confere( "{$nome}: config no head", false !== strpos( $head, 'id="rastro-config"' ), true );
	confere( "{$nome}: nenhum carregador de terceiro no head", preg_match( $terceiros, $head ), 0 );
	confere( "{$nome}: nenhum script com src no head", preg_match( '#<script[^>]+\ssrc=#i', $head ), 0 );
	confere( "{$nome}: nenhuma imagem ou iframe de terceiro", preg_match( '#<(img|iframe|noscript)\b#i', $head ), 0 );
	$heads[ $nome ] = $head;
}
confere( 'HTML igual com e sem cookie (cache de página seguro)', $heads['sem cookie'], $heads['com cookie de aceite'] );
confere( 'IDs só dentro do JSON da configuração', substr_count( $heads['sem cookie'], 'GTM-ABC1234' ), 1 );

$fonte = file_get_contents( $rastro_dir . '/tags/tags.php' );
// Exceção única: o código base do Meta Pixel (seção 5.1), numa função própria.
$sem_meta = (string) preg_replace( '#\nfunction rastro_tags_meta_cabecalho_js\(.*?\n}\n#s', "\n", $fonte );
$sem_meta = (string) preg_replace( '#\nfunction rastro_tags_meta_cabecalho\(\).*?\n}\n#s', "\n", $sem_meta );
$sem_meta = (string) preg_replace( '#/\*.*?\*/#s', '', $sem_meta );
confere( 'tags.php não imprime script (fora do Meta Pixel)', preg_match( '#[\'"]<script|wp_print_inline_script_tag|rastro_script_inline\(|wp_add_inline_script\(#', $sem_meta ), 0 );
confere( 'tags.php sem endereço de carregador (fora do Meta Pixel)', preg_match( $terceiros, $sem_meta ), 0 );

/* 4b. Meta Pixel no cabeçalho e no rodapé (seção 5.1) ------------------------------- */

$cabecalho_meta = static function ( array $config, array $sobre = array() ) {
	com_config( $config, array_replace( array( 'uri' => '/produto/racao/', 'woo' => array( 'is_product' => true ) ), $sobre ) );
	do_action( 'wp_enqueue_scripts' );
	return array(
		'head'   => rastro_teste_saida( 'do_action', array( 'wp_head' ) ),
		'rodape' => rastro_teste_saida( 'do_action', array( 'wp_footer' ) ),
	);
};
confere( 'Meta: código base no wp_head, prioridade 3', has_action( 'wp_head', 'rastro_tags_meta_cabecalho' ), 3 );
confere( 'Meta: noscript no wp_footer', has_action( 'wp_footer', 'rastro_tags_meta_rodape' ), 20 );

$s = $cabecalho_meta( config_cheia() );
confere( 'LGPD: fila fbq no head', false !== strpos( $s['head'], 'id="rastro-meta-pixel"' ) && false !== strpos( $s['head'], "fbq('init',\"123456789012345\")" ), true );
confere( 'LGPD: autoConfig desligado antes do init', strpos( $s['head'], "fbq('set','autoConfig',false" ) < strpos( $s['head'], "fbq('init'" ), true );
confere( 'LGPD: PageView na fila', false !== strpos( $s['head'], "fbq('track','PageView')" ), true );
confere( 'LGPD: nenhum carregador de terceiro', preg_match( $terceiros, $s['head'] . $s['rodape'] ), 0 );
confere( 'LGPD: sem noscript', false !== strpos( $s['rodape'], '<noscript' ), false );
confere( 'LGPD: fila depois da configuração', strpos( $s['head'], 'id="rastro-config"' ) < strpos( $s['head'], 'id="rastro-meta-pixel"' ), true );

$s = $cabecalho_meta( config_cheia( array( 'modo' => 'basico' ) ) );
confere( 'básico: código oficial completo no head', false !== strpos( $s['head'], "'https://connect.facebook.net/en_US/fbevents.js'" ), true );
confere( 'básico: noscript no rodapé', str_replace( '&#038;', '&amp;', $s['rodape'] ), '<noscript><img height="1" width="1" style="display:none" alt="" src="https://www.facebook.com/tr?id=123456789012345&amp;ev=PageView&amp;noscript=1" /></noscript>' . "\n" );

$s = $cabecalho_meta( config_cheia( array( 'modo' => 'basico' ) ), array( 'uri' => '/finalizar-compra/pedido-recebido/123/?key=wc_order_abc', 'get' => array( 'key' => 'wc_order_abc' ), 'woo' => array( 'is_order_received_page' => true, 'is_checkout' => true ) ) );
confere( 'básico em página privada: só a fila', preg_match( $terceiros, $s['head'] . $s['rodape'] ), 0 );
confere( 'básico em página privada: fila presente', false !== strpos( $s['head'], 'id="rastro-meta-pixel"' ), true );

$s = $cabecalho_meta( config_cheia( array( 'meta_cabecalho' => '0', 'modo' => 'basico' ) ) );
confere( 'campo desligado: nada do Meta no head', false !== strpos( $s['head'], 'rastro-meta-pixel' ), false );
confere( 'campo desligado: nada no rodapé', $s['rodape'], '' );

add_filter( 'rastro_destinos', static function ( $d ) { $d['meta']['ativo'] = false; return $d; } );
$s = $cabecalho_meta( config_cheia( array( 'modo' => 'basico' ) ) );
confere( 'Meta desligado pela ponte: nada no head', false !== strpos( $s['head'], 'rastro-meta-pixel' ), false );
remove_all_filters( 'rastro_destinos' );

$s = $cabecalho_meta( config_cheia(), array( 'logado' => true, 'capacidades' => array( 'edit_posts' => true ) ) );
confere( 'equipe: nada do Meta', false !== strpos( $s['head'], 'rastro-meta-pixel' ), false );

confere( 'regra: ID inválido não gera código', rastro_tags_meta_cabecalho_js( '12</script>', 'fila' ), '' );
confere( 'regra: tipo inválido não gera código', rastro_tags_meta_cabecalho_js( '123456789012345', 'outro' ), '' );
confere( 'regra: noscript só no completo', rastro_tags_meta_noscript_html( '123456789012345', 'fila' ), '' );
confere( 'regra: tipo pela configuração', array( rastro_tags_meta_tipo( array(), false ), rastro_tags_meta_tipo( array( 'meta_cabecalho' => '1' ), false ), rastro_tags_meta_tipo( array( 'meta_cabecalho' => '1', 'modo' => 'basico' ), false ), rastro_tags_meta_tipo( array( 'meta_cabecalho' => '1', 'modo' => 'basico' ), true ) ), array( '', 'fila', 'completo', 'fila' ) );

/* 5. Verificação do Google --------------------------------------------------------------- */

$meta_ok = '<meta name="google-site-verification" content="abcdefghijklmnopqrstuvwxyz_-123" />' . "\n";
confere( 'meta: HTML exato', rastro_tags_meta_html( 'abcdefghijklmnopqrstuvwxyz_-123' ), $meta_ok );
confere( 'meta: valor inválido', rastro_tags_meta_html( '"><script>alert(1)</script>' ), '' );
confere( 'meta: curto demais', rastro_tags_meta_html( 'abc' ), '' );
confere( 'meta: tag colada vira o content', rastro_tags_meta_html( '<meta name="google-site-verification" content="abcdefghijklmnopqrstuvwxyz_-123" />' ), $meta_ok );
confere( 'meta: não texto', rastro_tags_meta_html( array( 'x' ) ), '' );

com_config( array( 'google_verificacao' => 'abcdefghijklmnopqrstuvwxyz_-123' ) );
confere( 'meta na página pública, sem consentimento', rastro_teste_saida( 'rastro_tags_meta_verificacao' ), $meta_ok );
com_config( array( 'google_verificacao' => '<meta name="google-site-verification" content="abcdefghijklmnopqrstuvwxyz_-123">' ) );
confere( 'meta com a tag colada no campo', rastro_teste_saida( 'rastro_tags_meta_verificacao' ), $meta_ok );
com_config( array( 'google_verificacao' => 'abc"def<ghijklmnopqrstuvwxyz' ) );
confere( 'meta com valor guardado inválido: nada', rastro_teste_saida( 'rastro_tags_meta_verificacao' ), '' );
com_config( array() );
confere( 'sem valor: nada', rastro_teste_saida( 'rastro_tags_meta_verificacao' ), '' );

$meta_casos = array(
	'equipe logada (não depende da medição)' => array( array( 'logado' => true, 'capacidades' => array( 'edit_posts' => true ) ), $meta_ok ),
	'página privada'                         => array( array( 'woo' => array( 'is_checkout' => true ) ), $meta_ok ),
	'painel'                                 => array( array( 'admin' => true ), '' ),
	'AJAX'                                   => array( array( 'ajax' => true ), '' ),
	'feed'                                   => array( array( 'wp' => array( 'is_feed' => true ) ), '' ),
	'404'                                    => array( array( 'wp' => array( 'is_404' => true ) ), '' ),
);
foreach ( $meta_casos as $nome => $par ) {
	com_config( array( 'google_verificacao' => 'abcdefghijklmnopqrstuvwxyz_-123' ), $par[0] );
	confere( "meta: {$nome}", rastro_teste_saida( 'rastro_tags_meta_verificacao' ), $par[1] );
}
// Política de referrer das páginas privadas (a próxima página não recebe o caminho com o pedido).
$referrer_ok = '<meta name="referrer" content="strict-origin" />' . "\n";
confere( 'referrer: no wp_head, prioridade 1', has_action( 'wp_head', 'rastro_tags_meta_referrer' ), 1 );
confere( 'referrer: regra pura, privada com destino', rastro_tags_meta_referrer_html( true, true ), $referrer_ok );
confere( 'referrer: regra pura, pública', rastro_tags_meta_referrer_html( false, true ), '' );
confere( 'referrer: regra pura, sem destino', rastro_tags_meta_referrer_html( true, false ), '' );
$referrer_casos = array(
	'pedido recebido'          => array( array( 'uri' => '/finalizar-compra/pedido-recebido/123/?key=wc_order_x', 'get' => array( 'key' => 'wc_order_x' ), 'woo' => array( 'is_order_received_page' => true, 'is_checkout' => true ) ), $referrer_ok ),
	'checkout'                 => array( array( 'woo' => array( 'is_checkout' => true ) ), $referrer_ok ),
	'conta'                    => array( array( 'woo' => array( 'is_account_page' => true ) ), $referrer_ok ),
	'equipe no checkout'       => array( array( 'logado' => true, 'capacidades' => array( 'edit_posts' => true ), 'woo' => array( 'is_checkout' => true ) ), $referrer_ok ),
	'produto (pública)'        => array( array( 'woo' => array( 'is_product' => true ) ), '' ),
	'painel'                   => array( array( 'admin' => true, 'woo' => array( 'is_checkout' => true ) ), '' ),
);
foreach ( $referrer_casos as $nome => $par ) {
	com_config( config_cheia(), $par[0] );
	confere( "referrer: {$nome}", rastro_teste_saida( 'rastro_tags_meta_referrer' ), $par[1] );
}
com_config( array(), array( 'woo' => array( 'is_checkout' => true ) ) );
confere( 'referrer: sem destino, nada', rastro_teste_saida( 'rastro_tags_meta_referrer' ), '' );

com_config( array( 'google_verificacao' => 'abcdefghijklmnopqrstuvwxyz_-123' ) );
$GLOBALS['pagenow'] = 'wp-login.php';
confere( 'meta: tela de login', rastro_teste_saida( 'rastro_tags_meta_verificacao' ), '' );
unset( $GLOBALS['pagenow'] );
// Só a verificação configurada: a página não mede, mas a meta sai.
com_config( array( 'google_verificacao' => 'abcdefghijklmnopqrstuvwxyz_-123' ) );
confere( 'só a verificação: não mede', rastro_deve_medir(), false );
$head = rastro_teste_saida( 'do_action', array( 'wp_head' ) );
confere( 'só a verificação: meta no head', false !== strpos( $head, $meta_ok ), true );
confere( 'só a verificação: sem config', false !== strpos( $head, 'rastro-config' ), false );

/* 6. Avisos do painel ---------------------------------------------------------------------- */

$nomes = array( 'ga4' => 'Google Analytics 4', 'meta' => 'Meta Pixel', 'tiktok' => 'TikTok Pixel', 'ads' => 'Google Ads' );
confere( 'aviso: GTM sozinho', rastro_tags_regra_avisos( array( 'gtm' ), array(), $nomes ), array() );
confere( 'aviso: GA4 sozinho', rastro_tags_regra_avisos( array( 'ga4', 'posthog' ), array(), $nomes ), array() );
confere( 'aviso: GTM com OpenAI e PostHog (não são tags do contêiner)', rastro_tags_regra_avisos( array( 'gtm', 'openai', 'posthog' ), array(), $nomes ), array() );
$avisos = rastro_tags_regra_avisos( array( 'gtm', 'ga4', 'meta', 'tiktok' ), array(), $nomes );
confere( 'aviso: GTM com diretos, um aviso', count( $avisos ), 1 );
confere( 'aviso: tipo warning', $avisos[0]['tipo'], 'warning' );
confere( 'aviso: cita as tags diretas', false !== strpos( $avisos[0]['texto'], 'Google Analytics 4, Meta Pixel, TikTok Pixel' ), true );
$avisos = rastro_tags_regra_avisos( array( 'ga4' ), array( 'PixelYourSite' ), $nomes );
confere( 'aviso: plugin ativo com destino', array( $avisos[0]['tipo'], false !== strpos( $avisos[0]['texto'], '"PixelYourSite"' ) ), array( 'warning', true ) );
$avisos = rastro_tags_regra_avisos( array(), array( 'PixelYourSite' ), $nomes );
confere( 'aviso: plugin ativo sem destino é informação', $avisos[0]['tipo'], 'info' );

com_config( array( 'gtm' => 'GTM-ABC1234', 'ga4' => 'G-ABC1234' ), array( 'admin' => true ) );
$avisos = apply_filters( 'rastro_admin_avisos', array() );
confere( 'painel: GTM com GA4 avisa', count( $avisos ), 1 );
confere( 'painel: aviso existente fica', count( apply_filters( 'rastro_admin_avisos', array( array( 'tipo' => 'info', 'texto' => 'x' ) ) ) ), 2 );
confere( 'painel: lista que não é array passa igual', apply_filters( 'rastro_admin_avisos', 'x' ), 'x' );

com_config(
	array( 'ga4' => 'G-ABC1234' ),
	array(
		'admin'  => true,
		'opcoes' => array(
			'rastro_config'  => array( 'ga4' => 'G-ABC1234' ),
			'active_plugins' => array( 'pixelyoursite/facebook-pixel-master.php', 'woocommerce/woocommerce.php', 'pixelyoursite-pro/pixelyoursite-pro.php' ),
		),
	)
);
confere( 'plugins ativos pelo arquivo principal', rastro_tags_plugins_ativos(), array( 'PixelYourSite', 'PixelYourSite Pro' ) );
$avisos = apply_filters( 'rastro_admin_avisos', array() );
confere( 'painel: um aviso por plugin', count( $avisos ), 2 );

com_config( array( 'ga4' => 'G-ABC1234' ), array( 'admin' => true ) );
confere( 'nenhum plugin de medição', rastro_tags_plugins_ativos(), array() );
define( 'GOOGLESITEKIT_VERSION', '1.0.0' );
eval( 'class WC_Google_Analytics_Integration {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- classe falsa só para o teste.
confere( 'plugins ativos pela constante e pela classe', rastro_tags_plugins_ativos(), array( 'Google Analytics for WooCommerce', 'Site Kit by Google' ) );
add_filter(
	'rastro_tags_plugins_conhecidos',
	static function ( $lista ) {
		$lista['Outro medidor'] = array( 'arquivos' => array( 'outro/outro.php' ) );
		return $lista;
	}
);
com_config( array(), array( 'admin' => true, 'opcoes' => array( 'active_plugins' => array( 'outro/outro.php' ) ) ) );
confere( 'filtro rastro_tags_plugins_conhecidos', in_array( 'Outro medidor', rastro_tags_plugins_ativos(), true ), true );
remove_all_filters( 'rastro_tags_plugins_conhecidos' );

foreach ( rastro_tags_regra_avisos( array( 'gtm', 'ga4' ), array( 'X' ), $nomes ) as $aviso ) {
	confere( 'aviso sem travessão', preg_match( '/\x{2014}/u', $aviso['texto'] ), 0 );
}

/* 7. Sem falha registrada --------------------------------------------------------------------- */

confere( 'nenhuma falha registrada', $falhas_registradas, array() );

echo "{$total} conferências das tags do Rastro, {$falhas} falha(s).\n";
exit( $falhas > 0 ? 1 : 0 );
