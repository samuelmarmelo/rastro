<?php
/**
 * Testes da atribuição de origem do Rastro (frente T5:
 * rastro/atribuicao/*), sem WordPress:
 * leitura e validação do cookie rastro_origem (tamanho, base64url, chaves,
 * caracteres), estrutura da meta _rastro_origem, uma vez por pedido,
 * leitura unificada (Rastro, PixelYourSite, WooCommerce, nada), atribuição
 * nativa do WooCommerce esperando o aceite, capacidade e escape na caixa e
 * na coluna do pedido.
 *
 * Uso: php tests/php/rastro-atribuicao.test.php (sai com 1 se algo falhar).
 * Contrato: docs/CONTRATO.md, seções 2.1 e 7.
 *
 * @package Rastro
 */

define( 'ABSPATH', __DIR__ . '/' );

$rastro_plugin = realpath( __DIR__ . '/../../rastro' ) . '/rastro.php';

/*
 * Cenário em processo novo (constantes não se desfazem):
 * php rastro-atribuicao.test.php --cenario=woo_antigo
 */
foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( '--cenario=woo_antigo' === $arg ) {
		require __DIR__ . '/stubs-rastro.php';
		define( 'WC_VERSION', '8.5.0' );
		require $rastro_plugin;
		echo json_encode(
			array(
				'disponivel' => rastro_atribuicao_woo_disponivel(),
				'permitir'   => apply_filters( 'wc_order_attribution_allow_tracking', true ),
				'avisos'     => count( apply_filters( 'rastro_admin_avisos', array() ) ),
			)
		);
		exit( 0 );
	}
}

/* Stubs desta frente (WooCommerce e painel) ------------------------------------ */

define( 'WC_VERSION', '11.2.2' );

class WooCommerce {} // phpcs:ignore

/** Pedido falso com metas em memória (como no HPOS: tudo pelo objeto). */
class WC_Order { // phpcs:ignore
	public $id;
	public $metas  = array();
	public $salvou = 0;

	public function __construct( int $id = 1, array $metas = array() ) {
		$this->id    = $id;
		$this->metas = $metas;
	}
	public function get_id() {
		return $this->id;
	}
	public function get_meta( $chave, $unico = true ) {
		return $this->metas[ $chave ] ?? '';
	}
	public function update_meta_data( $chave, $valor ) {
		$this->metas[ $chave ] = $valor;
	}
	public function meta_exists( $chave ) {
		return isset( $this->metas[ $chave ] );
	}
	public function save_meta_data() {
		++$this->salvou;
	}
	public function save() {
		++$this->salvou;
		return $this->id;
	}
}

class WP_Post { // phpcs:ignore
	public $ID;
	public function __construct( int $id ) {
		$this->ID = $id;
	}
}

$GLOBALS['pedidos_falsos'] = array();
function wc_get_order( $id ) { // phpcs:ignore
	return $GLOBALS['pedidos_falsos'][ (int) $id ] ?? false;
}
function wc_get_page_screen_id( $tela ) { // phpcs:ignore
	return 'shop-order' === $tela ? 'woocommerce_page_wc-orders' : $tela;
}
$GLOBALS['caixas'] = array();
function add_meta_box( $id, $titulo, $callback, $tela = null, $contexto = 'advanced', $prioridade = 'default' ) { // phpcs:ignore
	$GLOBALS['caixas'][] = compact( 'id', 'titulo', 'callback', 'tela', 'contexto' );
}
$GLOBALS['scripts_falsos'] = (object) array(
	'registered' => array( 'wc-order-attribution' => (object) array( 'deps' => array( 'sourcebuster-js' ) ) ),
);
function wp_scripts() { // phpcs:ignore
	return $GLOBALS['scripts_falsos'];
}

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

/** Zera o estado (os ganchos dos módulos ficam) e aplica a configuração. */
function novo( array $config = array(), array $sobre = array() ) { // phpcs:ignore
	rastro_teste_reset( $sobre );
	if ( array() !== $config ) {
		$GLOBALS['rastro_teste']['opcoes']['rastro_config'] = $config;
	}
	rastro_config_redefinir_cache();
	rastro_regras_redefinir_cache();
}

/** Codifica como o atribuicao.js (JSON em base64url, sem =). */
function cookie( array $dado ): string { // phpcs:ignore
	return rtrim( strtr( base64_encode( json_encode( $dado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ), '+/', '-_' ), '=' );
}

$falhas_registradas = array();
add_action(
	'rastro_falha',
	static function ( $modulo, $erro ) use ( &$falhas_registradas ) {
		$falhas_registradas[] = array( $modulo, $erro->getMessage() );
	},
	10,
	2
);

rastro_teste_reset();
require $rastro_plugin;
confere( 'módulos da T5 carregados', function_exists( 'rastro_pedido_origem' ) && function_exists( 'rastro_pedido_caixa' ), true );

$chaves = array( 'fonte', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'utm_id', 'clique', 'referrer', 'entrada', 'quando' );
$agora  = time() - 120;
$iso    = gmdate( 'Y-m-d\TH:i:s\Z', $agora );

/* 1. base64url sem base64_decode ------------------------------------------------ */

foreach ( array( 'a', 'ab', 'abc', 'abcd', "\x00\xff\xfe", 'promoção de verão', str_repeat( 'x', 300 ) ) as $amostra ) {
	$codificado = rtrim( strtr( base64_encode( $amostra ), '+/', '-_' ), '=' );
	confere( 'base64url ida e volta: ' . strlen( $amostra ), rastro_atribuicao_base64url( $codificado ), $amostra );
}
confere( 'base64url: = recusado', rastro_atribuicao_base64url( 'YWJj=' ), null );
confere( 'base64url: + e / recusados', rastro_atribuicao_base64url( 'ab+/' ), null );
confere( 'base64url: tamanho impossível', rastro_atribuicao_base64url( 'abcde' ), null );
confere( 'base64url: vazio', rastro_atribuicao_base64url( '' ), null );
confere( 'base64url: acima de 1,5 KB', rastro_atribuicao_base64url( str_repeat( 'A', 1540 ) ), null );

/* 2. Cookie: leitura e validação -------------------------------------------------- */

$primeira = array( 'f' => 'google', 's' => 'google', 'm' => 'cpc', 'c' => 'promoção natal', 'k' => 'gclid', 'e' => '/produto/racao-premium/', 'q' => $agora );
$ultima   = array( 'f' => 'instagram.com', 'r' => 'l.instagram.com', 'e' => '/', 'q' => $agora + 60 );
$valido   = array( 'v' => 1, 'p' => $primeira, 'u' => $ultima, 'a' => $agora + 60, 'o' => 'aceite' );
$lido     = rastro_atribuicao_ler_cookie( cookie( $valido ) );
confere( 'cookie válido: base', $lido['consentimento'] ?? null, 'aceite' );
confere( 'cookie válido: chaves longas na ordem', array_keys( $lido['primeira'] ?? array() ), $chaves );
confere(
	'cookie válido: primeira visita',
	$lido['primeira'] ?? null,
	array(
		'fonte'        => 'google',
		'utm_source'   => 'google',
		'utm_medium'   => 'cpc',
		'utm_campaign' => 'promoção natal',
		'utm_content'  => '',
		'utm_term'     => '',
		'utm_id'       => '',
		'clique'       => 'gclid',
		'referrer'     => '',
		'entrada'      => '/produto/racao-premium/',
		'quando'       => $iso,
	)
);
confere( 'cookie válido: última visita', array( $lido['ultima']['fonte'] ?? '', $lido['ultima']['referrer'] ?? '' ), array( 'instagram.com', 'l.instagram.com' ) );
$sem_ultima = $valido;
unset( $sem_ultima['u'] );
confere( 'sem última: vale a primeira', ( rastro_atribuicao_ler_cookie( cookie( $sem_ultima ) )['ultima']['fonte'] ?? '' ), 'google' );
foreach ( array( 'aceite', 'sessao', 'campanha', 'basico' ) as $base ) {
	confere( "base {$base} aceita", rastro_atribuicao_ler_cookie( cookie( array( 'o' => $base ) + $valido ) )['consentimento'] ?? null, $base );
}

$grande = $valido;
$grande['p']['c'] = str_repeat( 'a', 100 );
$grande['p']['n'] = str_repeat( 'b', 100 );
$grande['p']['t'] = str_repeat( 'c', 100 );
$grande['u']      = $grande['p'];
$grande['u']['m'] = str_repeat( 'd', 100 );
$grande['u']['i'] = str_repeat( 'e', 100 );
$grande['u']['s'] = str_repeat( 'f', 100 );
$grande['p']['s'] = str_repeat( 'g', 100 );
$grande['p']['m'] = str_repeat( 'h', 100 );
$grande['p']['i'] = str_repeat( 'i', 100 );
confere( 'cookie acima de 1,5 KB recusado', strlen( cookie( $grande ) ) > 1536 && null === rastro_atribuicao_ler_cookie( cookie( $grande ) ), true );

$invalidos = array(
	'não é texto'               => array( 'x' ),
	'vazio'                     => '',
	'base64 com =='             => cookie( $valido ) . '==',
	'fora do alfabeto'          => 'eyJ2IjoxfQ%3D%3D',
	'não é JSON'                => rtrim( strtr( base64_encode( 'nada aqui' ), '+/', '-_' ), '=' ),
	'JSON fundo demais'         => cookie( array( 'v' => 1, 'p' => array( 'f' => array( array( array( 'x' ) ) ) ), 'o' => 'aceite' ) ),
	'versão 2'                  => cookie( array( 'v' => 2 ) + $valido ),
	'versão em texto'           => cookie( array( 'v' => '1' ) + $valido ),
	'sem primeira'              => cookie( array( 'v' => 1, 'o' => 'aceite' ) ),
	'sem base'                  => cookie( array( 'v' => 1, 'p' => $primeira ) ),
	'base desconhecida'         => cookie( array( 'o' => 'sim' ) + $valido ),
	'chave de topo estranha'    => cookie( $valido + array( 'email' => 'x' ) ),
	'chave de toque estranha'   => cookie( array( 'p' => $primeira + array( 'cpf' => '1' ) ) + $valido ),
	'valor do clique guardado'  => cookie( array( 'p' => array( 'k' => 'gclid=Cj0KCQ' ) + $primeira ) + $valido ),
	'clique desconhecido'       => cookie( array( 'p' => array( 'k' => 'dclid' ) + $primeira ) + $valido ),
	'script na campanha'        => cookie( array( 'p' => array( 'c' => '<script>alert(1)</script>' ) + $primeira ) + $valido ),
	'aspas na fonte'            => cookie( array( 'p' => array( 'f' => 'x" onmouseover="y' ) + $primeira ) + $valido ),
	'aspas simples no meio'     => cookie( array( 'p' => array( 'm' => "cpc' OR 1=1" ) + $primeira ) + $valido ),
	'& e = no termo'            => cookie( array( 'p' => array( 't' => 'a=b&c=d' ) + $primeira ) + $valido ),
	'barra invertida'           => cookie( array( 'p' => array( 'n' => 'a' . chr( 92 ) . 'b' ) + $primeira ) + $valido ),
	'quebra de linha'           => cookie( array( 'p' => array( 'c' => "a\nb" ) + $primeira ) + $valido ),
	'espaço nas pontas'         => cookie( array( 'p' => array( 'c' => ' natal' ) + $primeira ) + $valido ),
	'campanha longa'            => cookie( array( 'p' => array( 'c' => str_repeat( 'x', 101 ) ) + $primeira ) + $valido ),
	'host com caminho'          => cookie( array( 'p' => array( 'r' => 'evil.com/caminho' ) + $primeira ) + $valido ),
	'host em maiúsculas'        => cookie( array( 'p' => array( 'r' => 'Evil.com' ) + $primeira ) + $valido ),
	'entrada com query'         => cookie( array( 'p' => array( 'e' => '/?utm_source=x' ) + $primeira ) + $valido ),
	'entrada sem barra'         => cookie( array( 'p' => array( 'e' => 'javascript:alert(1)' ) + $primeira ) + $valido ),
	'quando em texto'           => cookie( array( 'p' => array( 'q' => '2026-11-11' ) + $primeira ) + $valido ),
	'quando antigo'             => cookie( array( 'p' => array( 'q' => 1000 ) + $primeira ) + $valido ),
	'quando no futuro'          => cookie( array( 'p' => array( 'q' => time() + 3 * DAY_IN_SECONDS ) + $primeira ) + $valido ),
	'fonte vazia'               => cookie( array( 'p' => array( 'f' => '' ) + $primeira ) + $valido ),
	'número no lugar de texto'  => cookie( array( 'p' => array( 's' => 123 ) + $primeira ) + $valido ),
	'atividade em texto'        => cookie( array( 'a' => 'ontem' ) + $valido ),
	'arroba (e-mail) na campanha' => cookie( array( 'p' => array( 's' => 'cliente@exemplo.com' ) + $primeira ) + $valido ),
	'arroba na fonte'           => cookie( array( 'p' => array( 'f' => 'a@b' ) + $primeira ) + $valido ),
	'UTF-8 quebrado'            => rtrim( strtr( base64_encode( '{"v":1,"p":{"f":"' . "\xc3\x28" . '"},"o":"aceite"}' ), '+/', '-_' ), '=' ),
);
foreach ( $invalidos as $nome => $bruto ) {
	confere( "cookie recusado: {$nome}", rastro_atribuicao_ler_cookie( $bruto ), null );
}

// Campanha com oito dígitos seguidos (CPF, telefone, CEP): o campo sai vazio, o cookie vale.
$com_digitos = rastro_atribuicao_ler_cookie( cookie( array( 'p' => array( 'c' => 'cpf12345678901', 't' => 'tel 21 9876' ) + $primeira ) + $valido ) );
confere( 'campanha com 8 dígitos seguidos: vazia', array( $com_digitos['primeira']['utm_campaign'] ?? null, $com_digitos['primeira']['utm_term'] ?? null ), array( '', 'tel 21 9876' ) );
confere( 'valor: campanha com arroba recusada', rastro_atribuicao_validar_valor( 'campanha', 'a@b.co', 100 ), null );
confere( 'valor: texto com arroba recusado', rastro_atribuicao_validar_valor( 'texto', 'a@b', 100 ), null );
confere( 'valor: campanha com 8 dígitos vazia', rastro_atribuicao_validar_valor( 'campanha', '21987654321', 100 ), '' );
confere( 'valor: fonte com dígitos fica (não é campanha)', rastro_atribuicao_validar_valor( 'texto', '12345678.com.br', 100 ), '12345678.com.br' );
confere( 'externo (PixelYourSite, WooCommerce): e-mail vira vazio', rastro_atribuicao_texto_externo( 'cliente@exemplo.com' ), '' );
confere( 'externo: 8 dígitos vira vazio', rastro_atribuicao_texto_externo( '12345678' ), '' );
confere( 'externo: campanha comum fica', rastro_atribuicao_texto_externo( 'black-friday' ), 'black-friday' );

/* 3. Meta no pedido ------------------------------------------------------------- */

novo( array(), array( 'cookies' => array( 'rastro_origem' => cookie( $valido ) ) ) );
$pedido = new WC_Order( 10 );
do_action( 'woocommerce_checkout_order_created', $pedido );
$meta = $pedido->get_meta( '_rastro_origem' );
confere( 'meta: chaves', array_keys( (array) $meta ), array( 'v', 'primeira', 'ultima', 'consentimento' ) );
confere( 'meta: versão 1', $meta['v'] ?? null, 1 );
confere( 'meta: toque com as chaves do contrato', array_keys( $meta['primeira'] ?? array() ), $chaves );
confere( 'meta: última com as mesmas chaves', array_keys( $meta['ultima'] ?? array() ), $chaves );
confere( 'meta: clique só pelo nome', $meta['primeira']['clique'] ?? null, 'gclid' );
confere( 'meta: quando em ISO', $meta['primeira']['quando'] ?? null, $iso );
confere( 'meta: salva pelo objeto (HPOS)', $pedido->salvou, 1 );

$GLOBALS['rastro_teste']['cookies']['rastro_origem'] = cookie( array( 'p' => array( 'f' => 'outra' ) + $primeira ) + $valido );
$_COOKIE = $GLOBALS['rastro_teste']['cookies'];
do_action( 'woocommerce_store_api_checkout_order_processed', $pedido );
do_action( 'woocommerce_checkout_order_created', $pedido );
confere( 'meta: uma vez por pedido', array( $pedido->get_meta( '_rastro_origem' )['primeira']['fonte'], $pedido->salvou ), array( 'google', 1 ) );

novo( array(), array( 'cookies' => array( 'rastro_origem' => cookie( $valido ) ) ) );
$blocos = new WC_Order( 11 );
do_action( 'woocommerce_store_api_checkout_order_processed', $blocos );
confere( 'checkout de blocos também grava', $blocos->get_meta( '_rastro_origem' )['consentimento'] ?? null, 'aceite' );

novo();
$sem = new WC_Order( 12 );
do_action( 'woocommerce_checkout_order_created', $sem );
confere( 'sem cookie no modo aceite: sem consentimento', $sem->get_meta( '_rastro_origem' ), array( 'v' => 1, 'sem' => 'consentimento' ) );

novo( array( 'atribuicao' => 'sessao' ) );
$sem = new WC_Order( 13 );
do_action( 'woocommerce_checkout_order_created', $sem );
confere( 'sem cookie no modo sessão: sem dados', $sem->get_meta( '_rastro_origem' ), array( 'v' => 1, 'sem' => 'dados' ) );

novo( array( 'modo' => 'basico' ) );
$sem = new WC_Order( 14 );
do_action( 'woocommerce_checkout_order_created', $sem );
confere( 'sem cookie no modo básico: sem dados', $sem->get_meta( '_rastro_origem' ), array( 'v' => 1, 'sem' => 'dados' ) );

novo( array(), array( 'cookies' => array( 'rastro_origem' => 'lixo<script>' ) ) );
$sem = new WC_Order( 15 );
do_action( 'woocommerce_checkout_order_created', $sem );
confere( 'cookie adulterado: nada dele entra', $sem->get_meta( '_rastro_origem' ), array( 'v' => 1, 'sem' => 'consentimento' ) );

novo( array( 'atribuicao' => 'desligada' ), array( 'cookies' => array( 'rastro_origem' => cookie( $valido ) ) ) );
$deslig = new WC_Order( 16 );
do_action( 'woocommerce_checkout_order_created', $deslig );
confere( 'desligada: nenhuma meta', array( $deslig->metas, $deslig->salvou ), array( array(), 0 ) );
do_action( 'woocommerce_checkout_order_created', 'não é pedido' );

novo( array(), array( 'cookies' => array( 'rastro_origem' => cookie( array( 'o' => 'campanha' ) + $valido ) ) ) );
$camp = new WC_Order( 17 );
do_action( 'woocommerce_checkout_order_created', $camp );
confere( 'visita de campanha antes do aceite: base campanha', $camp->get_meta( '_rastro_origem' )['consentimento'] ?? null, 'campanha' );

/* 4. Leitura unificada ----------------------------------------------------------- */

$todas = array_merge( $chaves, array( 'origem_dado' ) );
novo();

$o = rastro_pedido_origem( $pedido );
confere( 'leitura rastro: chaves', array_keys( $o ), $todas );
confere( 'leitura rastro: primeira', array( $o['fonte'], $o['utm_campaign'], $o['origem_dado'] ), array( 'google', 'promoção natal', 'rastro' ) );
$o = rastro_pedido_origem( $pedido, 'ultima' );
confere( 'leitura rastro: última', array( $o['fonte'], $o['referrer'], $o['entrada'] ), array( 'instagram.com', 'l.instagram.com', '/' ) );
confere( 'modelo desconhecido vira primeira', rastro_pedido_origem( $pedido, 'outro' )['fonte'], 'google' );

// PixelYourSite (amostras no formato dos pedidos antigos da produção).
$pys_campanha = new WC_Order(
	20,
	array(
		'pys_enrich_data' => array(
			'pys_landing'      => 'https://www.loja.exemplo.com.br/produto/racao-premium/',
			'pys_source'       => 'google.com',
			'pys_utm'          => 'utm_source:google|utm_medium:cpc|utm_campaign:black-friday|utm_term:undefined|utm_content:undefined',
			'pys_browser_time' => '13-14|Thursday|November',
			'last_pys_landing' => 'https://www.loja.exemplo.com.br/',
			'last_pys_source'  => 'direct',
			'last_pys_utm'     => 'utm_source:undefined|utm_medium:undefined|utm_campaign:undefined|utm_term:undefined|utm_content:undefined',
		),
	)
);
$o = rastro_pedido_origem( $pys_campanha );
confere( 'pys: chaves', array_keys( $o ), $todas );
confere(
	'pys: primeira visita',
	$o,
	array(
		'fonte'        => 'google',
		'utm_source'   => 'google',
		'utm_medium'   => 'cpc',
		'utm_campaign' => 'black-friday',
		'utm_content'  => '',
		'utm_term'     => '',
		'utm_id'       => '',
		'clique'       => '',
		'referrer'     => 'google.com',
		'entrada'      => '/produto/racao-premium/',
		'quando'       => '',
		'origem_dado'  => 'pys',
	)
);
$o = rastro_pedido_origem( $pys_campanha, 'ultima' );
confere( 'pys: última direct vira direto, undefined vira vazio', array( $o['fonte'], $o['utm_source'], $o['referrer'], $o['entrada'] ), array( 'direto', '', '', '/' ) );

$pys_ref = new WC_Order(
	21,
	array(
		'pys_enrich_data' => array(
			'pys_landing' => 'https://loja.exemplo.com.br/categoria/gatos/?add-to-cart=1',
			'pys_source'  => 'l.facebook.com',
			'pys_utm'     => 'utm_source:undefined|utm_medium:undefined|utm_campaign:undefined|utm_term:undefined|utm_content:undefined',
		),
	)
);
$o = rastro_pedido_origem( $pys_ref, 'ultima' );
confere( 'pys: sem last_pys usa pys_, referrer vira fonte, entrada sem query', array( $o['fonte'], $o['referrer'], $o['entrada'], $o['origem_dado'] ), array( 'l.facebook.com', 'l.facebook.com', '/categoria/gatos/', 'pys' ) );

$pys_mal = new WC_Order(
	22,
	array(
		'pys_enrich_data' => array(
			'pys_landing' => 'https://loja.exemplo.com.br/<script>',
			'pys_source'  => 'direct',
			'pys_utm'     => 'utm_source:<img src=x onerror=alert(1)>|utm_medium:cpc',
		),
	)
);
$o = rastro_pedido_origem( $pys_mal );
confere( 'pys: valor perigoso vira vazio', array( $o['fonte'], $o['utm_source'], $o['utm_medium'], $o['entrada'] ), array( 'direto', '', 'cpc', '' ) );

// Atribuição nativa do WooCommerce (_wc_order_attribution_*).
$woo_utm = new WC_Order(
	30,
	array(
		'_wc_order_attribution_source_type'        => 'utm',
		'_wc_order_attribution_referrer'           => 'https://www.instagram.com/',
		'_wc_order_attribution_utm_source'         => 'Instagram',
		'_wc_order_attribution_utm_medium'         => 'social',
		'_wc_order_attribution_utm_campaign'       => 'lancamento',
		'_wc_order_attribution_utm_content'        => '(none)',
		'_wc_order_attribution_utm_term'           => '(none)',
		'_wc_order_attribution_utm_id'             => '(none)',
		'_wc_order_attribution_session_entry'      => 'https://loja.exemplo.com.br/produto/coleira/?utm_source=Instagram',
		'_wc_order_attribution_session_start_time' => '2026-11-10 14:05:00',
		'_wc_order_attribution_user_agent'         => 'Mozilla/5.0',
	)
);
$o = rastro_pedido_origem( $woo_utm );
confere( 'woo: chaves', array_keys( $o ), $todas );
confere(
	'woo: campanha',
	array( $o['fonte'], $o['utm_source'], $o['utm_medium'], $o['utm_content'], $o['referrer'], $o['entrada'], $o['quando'], $o['origem_dado'] ),
	array( 'instagram', 'Instagram', 'social', '', 'www.instagram.com', '/produto/coleira/', '2026-11-10T14:05:00Z', 'woocommerce' )
);
confere( 'woo: mesma visita nos dois modelos', rastro_pedido_origem( $woo_utm, 'ultima' ), $o );
$woo_direto = new WC_Order(
	31,
	array(
		'_wc_order_attribution_source_type' => 'typein',
		'_wc_order_attribution_utm_source'  => '(direct)',
		'_wc_order_attribution_utm_medium'  => '(none)',
	)
);
$o = rastro_pedido_origem( $woo_direto );
confere( 'woo: digitado vira direto', array( $o['fonte'], $o['utm_source'], $o['utm_medium'] ), array( 'direto', '', '' ) );
$woo_ref = new WC_Order(
	32,
	array(
		'_wc_order_attribution_source_type' => 'referral',
		'_wc_order_attribution_utm_source'  => 'www.blogdepet.com.br',
		'_wc_order_attribution_utm_medium'  => 'referral',
		'_wc_order_attribution_referrer'    => 'https://www.blogdepet.com.br/post/',
	)
);
confere( 'woo: indicação sem www', rastro_pedido_origem( $woo_ref )['fonte'], 'blogdepet.com.br' );

// Ordem: Rastro antes do PixelYourSite antes do WooCommerce.
$tudo = new WC_Order( 40, $pys_campanha->metas + $woo_utm->metas + array( '_rastro_origem' => $pedido->get_meta( '_rastro_origem' ) ) );
confere( 'ordem: Rastro primeiro', rastro_pedido_origem( $tudo )['origem_dado'], 'rastro' );
$tudo->metas['_rastro_origem'] = array( 'v' => 1, 'sem' => 'consentimento' );
confere( 'ordem: sem do Rastro passa para o PixelYourSite', rastro_pedido_origem( $tudo )['origem_dado'], 'pys' );
unset( $tudo->metas['pys_enrich_data'] );
confere( 'ordem: depois o WooCommerce', rastro_pedido_origem( $tudo )['origem_dado'], 'woocommerce' );

$adulterada = new WC_Order( 41, array( '_rastro_origem' => array( 'v' => 1, 'primeira' => array( 'fonte' => '<b>x</b>' ) + $meta['primeira'], 'ultima' => $meta['ultima'], 'consentimento' => 'aceite' ) ) );
$o          = rastro_pedido_origem( $adulterada );
confere( 'meta adulterada na primeira: não é usada', $o['origem_dado'], 'nenhum' );
confere( 'meta adulterada na primeira: a última ainda vale', rastro_pedido_origem( $adulterada, 'ultima' )['fonte'], 'instagram.com' );

$vazio = rastro_pedido_origem( new WC_Order( 42 ) );
confere( 'nada: chaves', array_keys( $vazio ), $todas );
confere( 'nada: sem dados', array( $vazio['fonte'], $vazio['origem_dado'], $vazio['utm_source'] ), array( '(sem dados)', 'nenhum', '' ) );
confere( 'nada por falta de aceite', rastro_pedido_origem( new WC_Order( 43, array( '_rastro_origem' => array( 'v' => 1, 'sem' => 'consentimento' ) ) ) )['fonte'], '(sem consentimento)' );
foreach ( array( $pedido, $pys_campanha, $woo_utm, new WC_Order( 44 ) ) as $p ) {
	foreach ( rastro_pedido_origem( $p, 'ultima' ) as $valor ) {
		if ( ! is_string( $valor ) ) {
			confere( 'todo valor é texto', gettype( $valor ), 'string' );
		}
	}
}

/* 5. Atribuição nativa do WooCommerce ------------------------------------------------ */

confere( 'versão conferida: 11.2.2', rastro_atribuicao_regra_woo( '11.2.2' ), true );
confere( 'versão conferida: 8.6.0', rastro_atribuicao_regra_woo( '8.6.0' ), true );
confere( 'versão sem a função: 8.5.0', rastro_atribuicao_regra_woo( '8.5.0' ), false );
confere( 'sem versão', rastro_atribuicao_regra_woo( '' ), false );

novo();
confere( 'woo espera o aceite (padrão)', apply_filters( 'wc_order_attribution_allow_tracking', true ), false );
novo( array( 'atribuicao_woo_consentimento' => '0' ) );
confere( 'opção desligada: não mexe', apply_filters( 'wc_order_attribution_allow_tracking', true ), true );
novo( array( 'modo' => 'basico' ) );
confere( 'modo básico: não mexe', apply_filters( 'wc_order_attribution_allow_tracking', true ), true );
novo();
confere( 'nenhum aviso no painel com a versão conferida', apply_filters( 'rastro_admin_avisos', array() ), array() );

$saida = rodar_cenario( __FILE__, '--cenario=woo_antigo' );
confere( 'WooCommerce 8.5: indisponível, nada muda, painel avisa', $saida, array( 'disponivel' => false, 'permitir' => true, 'avisos' => 1 ) );

/** Roda este arquivo num processo novo e devolve o JSON. */
function rodar_cenario( string $arquivo, string $arg ) { // phpcs:ignore
	$saida = shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $arquivo ) . ' ' . escapeshellarg( $arg ) );
	return json_decode( (string) $saida, true );
}

/* 6. Navegador ---------------------------------------------------------------------- */

novo();
$cfg = rastro_config_navegador();
confere(
	'rastroConfig.atribuicao',
	$cfg['atribuicao'] ?? null,
	array(
		'modo'      => 'aceite',
		'dias'      => 90,
		'utmSempre' => true,
		'basico'    => false,
		'woo'       => true,
		'cookie'    => 'rastro_origem',
		'max'       => 1536,
	)
);
confere( 'a configuração sai mesmo sem tag', rastro_precisa_saida(), true );
novo( array( 'atribuicao' => 'sessao', 'atribuicao_dias' => 30, 'atribuicao_utm_sempre' => '0', 'modo' => 'basico' ) );
$cfg = rastro_config_navegador()['atribuicao'] ?? array();
confere( 'configuração segue o painel', array( $cfg['modo'], $cfg['dias'], $cfg['utmSempre'], $cfg['basico'], $cfg['woo'] ), array( 'sessao', 30, false, true, false ) );
novo( array( 'atribuicao' => 'desligada', 'atribuicao_woo_consentimento' => '0' ) );
confere( 'desligada e sem a nativa: nada no navegador', isset( rastro_config_navegador()['atribuicao'] ), false );
novo( array( 'atribuicao' => 'desligada' ) );
confere( 'desligada com a nativa esperando: o script segue para liberar o WooCommerce', rastro_config_navegador()['atribuicao']['modo'] ?? null, 'desligada' );
novo( array(), array( 'logado' => true, 'capacidades' => array( 'edit_posts' => true ) ) );
confere( 'equipe: nada no navegador', isset( rastro_config_navegador()['atribuicao'] ), false );
novo( array(), array( 'host' => 'loja.localhost' ) );
confere( 'cópia local: nada no navegador', isset( rastro_config_navegador()['atribuicao'] ), false );

novo();
$GLOBALS['rastro_teste']['scripts']['rastro-consent'] = array( 'src' => 'x', 'deps' => array(), 'ver' => '1', 'args' => array(), 'enfileirado' => false, 'inline' => array() );
do_action( 'wp_enqueue_scripts' );
$script = $GLOBALS['rastro_teste']['scripts']['rastro-atribuicao'] ?? array();
confere( 'script registrado e enfileirado', array( $script['enfileirado'] ?? null, $script['args']['in_footer'] ?? null, $script['args']['strategy'] ?? null ), array( true, true, 'defer' ) );
confere( 'script depois do consentimento', $script['deps'] ?? null, array( 'rastro-consent' ) );
confere( 'wc-order-attribution roda depois do Rastro', $GLOBALS['scripts_falsos']->registered['wc-order-attribution']->deps, array( 'sourcebuster-js', 'rastro-atribuicao' ) );

/* 7. Painel do pedido: capacidade e escape ----------------------------------------- */

$GLOBALS['pedidos_falsos'] = array( 10 => $pedido, 20 => $pys_campanha, 42 => new WC_Order( 42 ) );

novo( array(), array( 'admin' => true, 'logado' => true ) );
$GLOBALS['caixas'] = array();
do_action( 'add_meta_boxes' );
confere( 'caixa sem manage_woocommerce: não aparece', $GLOBALS['caixas'], array() );
confere( 'caixa sem capacidade: nada impresso', rastro_teste_saida( 'rastro_pedido_caixa', array( $pedido ) ), '' );
do_action( 'admin_init' );
$colunas = apply_filters( 'manage_edit-shop_order_columns', array( 'order_number' => 'Pedido', 'order_total' => 'Total' ) );
confere( 'coluna sem capacidade: lista igual', $colunas, array( 'order_number' => 'Pedido', 'order_total' => 'Total' ) );
confere( 'coluna sem capacidade: nada impresso', rastro_teste_saida( 'rastro_pedido_coluna', array( 'rastro_origem', 10 ) ), '' );

$gerente = array( 'admin' => true, 'logado' => true, 'capacidades' => array( 'manage_woocommerce' => true ) );
novo( array(), $gerente );
$GLOBALS['caixas'] = array();
do_action( 'add_meta_boxes' );
confere( 'caixa nas telas clássica e HPOS', array_column( $GLOBALS['caixas'], 'tela' ), array( 'shop_order', 'woocommerce_page_wc-orders' ) );
confere( 'caixa: título', $GLOBALS['caixas'][0]['titulo'] ?? null, 'Origem da venda' );

$html = rastro_teste_saida( 'rastro_pedido_caixa', array( new WP_Post( 10 ) ) );
confere( 'caixa clássica (WP_Post): primeira e última', false !== strpos( $html, 'Primeira visita' ) && false !== strpos( $html, 'Última visita' ), true );
confere( 'caixa: valores', false !== strpos( $html, 'promoção natal' ) && false !== strpos( $html, 'Google Ads (gclid)' ) && false !== strpos( $html, '/produto/racao-premium/' ) && false !== strpos( $html, 'l.instagram.com' ), true );
confere( 'caixa: de onde veio o dado', false !== strpos( $html, 'Dado de: Rastro' ), true );
$html = rastro_teste_saida( 'rastro_pedido_caixa', array( $pys_campanha ) );
confere( 'caixa HPOS (WC_Order): pedido antigo', false !== strpos( $html, 'black-friday' ) && false !== strpos( $html, 'PixelYourSite' ), true );
confere( 'caixa sem dados', rastro_teste_saida( 'rastro_pedido_caixa', array( 42 ) ), '<p>(sem dados)</p>' );
confere( 'caixa de pedido que não existe', rastro_teste_saida( 'rastro_pedido_caixa', array( 999 ) ), '<p>Pedido não encontrado.</p>' );
$html = rastro_teste_saida( 'rastro_pedido_caixa', array( $pys_mal ) );
confere( 'caixa: nada de HTML vindo do pedido', false === strpos( $html, '<script' ) && false === strpos( $html, '<img' ) && false === strpos( $html, 'onerror' ), true );

do_action( 'admin_init' );
$colunas = apply_filters( 'manage_edit-shop_order_columns', array( 'order_number' => 'Pedido', 'order_total' => 'Total', 'wc_actions' => 'Ações' ) );
confere( 'coluna clássica depois do total', array_keys( $colunas ), array( 'order_number', 'order_total', 'rastro_origem', 'wc_actions' ) );
$colunas = apply_filters( 'manage_woocommerce_page_wc-orders_columns', array( 'order_number' => 'Pedido' ) );
confere( 'coluna HPOS', $colunas, array( 'order_number' => 'Pedido', 'rastro_origem' => 'Origem' ) );
confere( 'coluna clássica (ID do pedido)', rastro_teste_saida( 'do_action', array( 'manage_shop_order_posts_custom_column', 'rastro_origem', 10 ) ), 'google / cpc' );
confere( 'coluna HPOS (objeto do pedido)', rastro_teste_saida( 'do_action', array( 'manage_woocommerce_page_wc-orders_custom_column', 'rastro_origem', $pys_campanha ) ), 'google / cpc' );
confere( 'coluna de outro nome: nada', rastro_teste_saida( 'rastro_pedido_coluna', array( 'order_total', 10 ) ), '' );

// Escape: o rótulo de dado de uma origem desconhecida e o resumo passam por esc_html.
confere( 'resumo sem meio', rastro_pedido_resumo( array( 'fonte' => 'direto', 'utm_medium' => '' ) ), 'direto' );
confere( 'rótulo de origem desconhecida', rastro_pedido_rotulo_dado( '<x>' ), 'Nenhum registro' );

/* 8. Sem falhas -------------------------------------------------------------------- */

confere( 'nenhuma falha registrada nos módulos', $falhas_registradas, array() );

echo "{$total} conferências da atribuição do Rastro, {$falhas} falha(s).\n";
exit( $falhas > 0 ? 1 : 0 );
