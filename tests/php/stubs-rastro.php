<?php
/**
 * Stubs do WordPress (e de um WooCommerce mínimo) para os testes do Rastro,
 * sem WordPress, sem banco e sem rede. Compartilhado por todas as frentes
 * (tests/php/rastro-*.test.php); só a T1 edita este arquivo. Frentes que
 * precisarem de mais stubs os põem no próprio teste.
 *
 * Uso:
 *
 *     define( 'ABSPATH', __DIR__ . '/' );          // opcional
 *     require __DIR__ . '/stubs-rastro.php';
 *     rastro_teste_reset( array( 'logado' => true ) );
 *     require __DIR__ . '/../../rastro/rastro.php';
 *
 * Tudo que muda o resultado de uma pergunta do WordPress fica em
 * $GLOBALS['rastro_teste'] (ver rastro_teste_padrao()). Toda função é
 * declarada só se ainda não existir: um teste pode declarar a sua antes de
 * incluir este arquivo. Sem WooCommerce: defina RASTRO_TESTE_SEM_WOO antes.
 *
 * Ganchos funcionam de verdade (prioridade, número de argumentos,
 * did_action). rastro_teste_reset() mantém os ganchos registrados pelos
 * módulos, a menos que receba $limpar_ganchos = true.
 *
 * @package Rastro
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

foreach (
	array(
		'MINUTE_IN_SECONDS' => 60,
		'HOUR_IN_SECONDS'   => 3600,
		'DAY_IN_SECONDS'    => 86400,
		'WEEK_IN_SECONDS'   => 604800,
		'MONTH_IN_SECONDS'  => 2592000,
		'YEAR_IN_SECONDS'   => 31536000,
	) as $rastro_teste_constante => $rastro_teste_valor
) {
	defined( $rastro_teste_constante ) || define( $rastro_teste_constante, $rastro_teste_valor );
}
unset( $rastro_teste_constante, $rastro_teste_valor );

/** wp_die(), wp_send_json*() e exit simulados viram esta exceção. */
if ( ! class_exists( 'Rastro_Teste_Parada' ) ) {
	class Rastro_Teste_Parada extends \RuntimeException { // phpcs:ignore
		/** @var mixed Dado enviado (wp_send_json) ou argumentos do wp_die. */
		public $dado;

		/** @var int Código HTTP. */
		public $status;

		public function __construct( string $mensagem, $dado = null, int $status = 200 ) {
			parent::__construct( $mensagem );
			$this->dado   = $dado;
			$this->status = $status;
		}
	}
}

/**
 * Estado padrão de uma requisição simulada (loja em produção, página
 * pública, visitante sem login, GET).
 *
 * @return array
 */
function rastro_teste_padrao(): array {
	return array(
		'opcoes'       => array(),
		'autoload'     => array(),
		'transients'   => array(),
		// Ações já disparadas (did_action). O padrão é o momento do wp_head.
		'acoes'        => array(
			'plugins_loaded'    => 1,
			'after_setup_theme' => 1,
			'init'              => 1,
			'wp'                => 1,
		),
		'host'         => 'loja.exemplo.com.br',
		'esquema'      => 'https',
		'caminho_site' => '',
		'url_plugin'   => 'https://loja.exemplo.com.br/wp-content/plugins/rastro',
		'ambiente'     => 'production',
		'admin'        => false,
		'ajax'         => false,
		'cron'         => false,
		'ssl'          => true,
		'logado'       => false,
		'usuario_id'   => 0,
		// Capacidades do usuário atual: nome => bool.
		'capacidades'  => array(),
		// Perguntas da consulta do WordPress: is_feed, is_preview, is_customize_preview, is_404, is_search, is_front_page, is_home, is_singular.
		'wp'           => array(),
		// Perguntas do WooCommerce: is_woocommerce, is_shop, is_product, is_product_category, is_product_tag, is_cart, is_checkout, is_checkout_pay_page, is_order_received_page, is_account_page.
		'woo'          => array(),
		// Retorno de wc_get_order_statuses() (null = lista nativa).
		'woo_status'   => null,
		'moeda'        => 'BRL',
		'query_vars'   => array(),
		'metodo'       => 'GET',
		'uri'          => '/',
		'get'          => array(),
		'post'         => array(),
		'cookies'      => array(),
		'nonce_ok'     => true,
		// Registros do que o código pediu ao WordPress.
		'scripts'      => array(),
		'estilos'      => array(),
		'inline'       => array(),
		'menus'        => array(),
		'shortcodes'   => array(),
		'redirecionou' => array(),
		'locale'       => 'pt_BR',
		'politica_url' => 'https://loja.exemplo.com.br/politica-de-privacidade/',
	);
}

/**
 * Zera o estado da requisição simulada.
 *
 * @param array $sobre          Chaves a trocar no estado padrão.
 * @param bool  $limpar_ganchos Também apaga os ganchos registrados.
 * @return void
 */
function rastro_teste_reset( array $sobre = array(), bool $limpar_ganchos = false ): void {
	$ganchos = ( ! $limpar_ganchos && isset( $GLOBALS['rastro_teste']['ganchos'] ) ) ? $GLOBALS['rastro_teste']['ganchos'] : array();

	$GLOBALS['rastro_teste']            = array_replace( rastro_teste_padrao(), $sobre );
	$GLOBALS['rastro_teste']['ganchos'] = $ganchos;

	$_SERVER['REQUEST_METHOD'] = (string) $GLOBALS['rastro_teste']['metodo'];
	$_SERVER['REQUEST_URI']    = (string) $GLOBALS['rastro_teste']['uri'];
	$_SERVER['HTTP_HOST']      = 'host-falso.invalid';
	$_GET                      = (array) $GLOBALS['rastro_teste']['get'];
	$_POST                     = (array) $GLOBALS['rastro_teste']['post'];
	$_COOKIE                   = (array) $GLOBALS['rastro_teste']['cookies'];
}

/**
 * Lê uma chave do estado (com padrão).
 *
 * @param string $chave  Chave.
 * @param mixed  $padrao Padrão.
 * @return mixed
 */
function rastro_teste( string $chave, $padrao = null ) {
	return array_key_exists( $chave, $GLOBALS['rastro_teste'] ?? array() ) ? $GLOBALS['rastro_teste'][ $chave ] : $padrao;
}

/**
 * Executa um callable e devolve o que ele imprimiu.
 *
 * @param callable $fn   Função.
 * @param array    $args Argumentos.
 * @return string
 */
function rastro_teste_saida( callable $fn, array $args = array() ): string {
	ob_start();
	try {
		call_user_func_array( $fn, $args );
	} finally {
		$saida = (string) ob_get_clean();
	}

	return $saida;
}

if ( ! isset( $GLOBALS['rastro_teste'] ) ) {
	rastro_teste_reset();
}

/* Ganchos ------------------------------------------------------------------ */

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $gancho, $callback, $prioridade = 10, $args = 1 ) { // phpcs:ignore
		$GLOBALS['rastro_teste']['ganchos'][ $gancho ][ (int) $prioridade ][] = array( $callback, (int) $args );
		return true;
	}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $gancho, $callback, $prioridade = 10, $args = 1 ) { // phpcs:ignore
		return add_filter( $gancho, $callback, $prioridade, $args );
	}
}
if ( ! function_exists( 'remove_filter' ) ) {
	function remove_filter( $gancho, $callback, $prioridade = 10 ) { // phpcs:ignore
		foreach ( $GLOBALS['rastro_teste']['ganchos'][ $gancho ][ (int) $prioridade ] ?? array() as $i => $item ) {
			if ( $item[0] === $callback ) {
				unset( $GLOBALS['rastro_teste']['ganchos'][ $gancho ][ (int) $prioridade ][ $i ] );
				return true;
			}
		}
		return false;
	}
}
if ( ! function_exists( 'remove_action' ) ) {
	function remove_action( $gancho, $callback, $prioridade = 10 ) { // phpcs:ignore
		return remove_filter( $gancho, $callback, $prioridade );
	}
}
if ( ! function_exists( 'remove_all_filters' ) ) {
	function remove_all_filters( $gancho ) { // phpcs:ignore
		unset( $GLOBALS['rastro_teste']['ganchos'][ $gancho ] );
		return true;
	}
}
if ( ! function_exists( 'has_filter' ) ) {
	/** Sem callback: há algum? Com callback: a prioridade dele, ou false. */
	function has_filter( $gancho, $callback = false ) { // phpcs:ignore
		$porPrioridade = $GLOBALS['rastro_teste']['ganchos'][ $gancho ] ?? array();
		if ( false === $callback ) {
			foreach ( $porPrioridade as $itens ) {
				if ( array() !== $itens ) {
					return true;
				}
			}
			return false;
		}
		foreach ( $porPrioridade as $prioridade => $itens ) {
			foreach ( $itens as $item ) {
				if ( $item[0] === $callback ) {
					return $prioridade;
				}
			}
		}
		return false;
	}
}
if ( ! function_exists( 'has_action' ) ) {
	function has_action( $gancho, $callback = false ) { // phpcs:ignore
		return has_filter( $gancho, $callback );
	}
}
if ( ! function_exists( 'rastro_teste_callbacks' ) ) {
	/** Callbacks de um gancho, em ordem de prioridade. */
	function rastro_teste_callbacks( string $gancho ): array {
		$porPrioridade = $GLOBALS['rastro_teste']['ganchos'][ $gancho ] ?? array();
		ksort( $porPrioridade );
		$lista = array();
		foreach ( $porPrioridade as $itens ) {
			foreach ( $itens as $item ) {
				$lista[] = $item;
			}
		}
		return $lista;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $gancho, $valor, ...$args ) { // phpcs:ignore
		foreach ( rastro_teste_callbacks( $gancho ) as $item ) {
			$valor = call_user_func_array( $item[0], array_slice( array_merge( array( $valor ), $args ), 0, max( 1, $item[1] ) ) );
		}
		return $valor;
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( $gancho, ...$args ) { // phpcs:ignore
		$GLOBALS['rastro_teste']['acoes'][ $gancho ] = ( $GLOBALS['rastro_teste']['acoes'][ $gancho ] ?? 0 ) + 1;
		foreach ( rastro_teste_callbacks( $gancho ) as $item ) {
			call_user_func_array( $item[0], array_slice( $args, 0, $item[1] ) );
		}
	}
}
if ( ! function_exists( 'did_action' ) ) {
	function did_action( $gancho ) { // phpcs:ignore
		return (int) ( $GLOBALS['rastro_teste']['acoes'][ $gancho ] ?? 0 );
	}
}
if ( ! function_exists( '__return_true' ) ) {
	function __return_true() { return true; } // phpcs:ignore
}
if ( ! function_exists( '__return_false' ) ) {
	function __return_false() { return false; } // phpcs:ignore
}

/* Tradução e escape --------------------------------------------------------- */

if ( ! function_exists( '__' ) ) {
	function __( $texto, $dominio = 'default' ) { return $texto; } // phpcs:ignore
}
if ( ! function_exists( '_x' ) ) {
	function _x( $texto, $contexto, $dominio = 'default' ) { return $texto; } // phpcs:ignore
}
if ( ! function_exists( '_n' ) ) {
	function _n( $um, $varios, $n, $dominio = 'default' ) { return 1 === (int) $n ? $um : $varios; } // phpcs:ignore
}
if ( ! function_exists( '_e' ) ) {
	function _e( $texto, $dominio = 'default' ) { echo $texto; } // phpcs:ignore
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); } // phpcs:ignore
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); } // phpcs:ignore
}
if ( ! function_exists( 'esc_textarea' ) ) {
	function esc_textarea( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); } // phpcs:ignore
}
if ( ! function_exists( 'esc_js' ) ) {
	function esc_js( $t ) { return addslashes( htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ) ); } // phpcs:ignore
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $t, $d = 'default' ) { return esc_html( $t ); } // phpcs:ignore
}
if ( ! function_exists( 'esc_attr__' ) ) {
	function esc_attr__( $t, $d = 'default' ) { return esc_attr( $t ); } // phpcs:ignore
}
if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( $t, $d = 'default' ) { echo esc_html( $t ); } // phpcs:ignore
}
if ( ! function_exists( 'esc_attr_e' ) ) {
	function esc_attr_e( $t, $d = 'default' ) { echo esc_attr( $t ); } // phpcs:ignore
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $u ) { // phpcs:ignore
		$u = (string) $u;
		return preg_match( '#^(?:https?:)?//|^/|^\?|^\##i', $u ) ? htmlspecialchars( $u, ENT_QUOTES, 'UTF-8' ) : '';
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $u ) { return (string) preg_replace( '/[\x00-\x20"<>\\\\^`{|}]/', '', (string) $u ); } // phpcs:ignore
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $k ) { return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); } // phpcs:ignore
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $t ) { return trim( (string) preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $t ) ) ); } // phpcs:ignore
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $t ) { return trim( strip_tags( (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $t ) ) ); } // phpcs:ignore
}
if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $t ) { return strip_tags( (string) $t, '<a><strong><em><p><br>' ); } // phpcs:ignore
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $v ) { return $v; } // phpcs:ignore
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $n ) { return abs( (int) $n ); } // phpcs:ignore
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $dado, $opcoes = 0, $profundidade = 512 ) { return json_encode( $dado, $opcoes, $profundidade ); } // phpcs:ignore
}
if ( ! function_exists( 'checked' ) ) {
	function checked( $a, $b = true, $eco = true ) { // phpcs:ignore
		$r = (string) $a === (string) $b ? " checked='checked'" : '';
		if ( $eco ) { echo $r; } // phpcs:ignore
		return $r;
	}
}
if ( ! function_exists( 'selected' ) ) {
	function selected( $a, $b = true, $eco = true ) { // phpcs:ignore
		$r = (string) $a === (string) $b ? " selected='selected'" : '';
		if ( $eco ) { echo $r; } // phpcs:ignore
		return $r;
	}
}
if ( ! function_exists( 'disabled' ) ) {
	function disabled( $a, $b = true, $eco = true ) { // phpcs:ignore
		$r = (string) $a === (string) $b ? " disabled='disabled'" : '';
		if ( $eco ) { echo $r; } // phpcs:ignore
		return $r;
	}
}

/* Endereços ------------------------------------------------------------------ */

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $componente = -1 ) { return parse_url( (string) $url, $componente ); } // phpcs:ignore
}
if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $v ) { return rtrim( (string) $v, '/\\' ) . '/'; } // phpcs:ignore
}
if ( ! function_exists( 'untrailingslashit' ) ) {
	function untrailingslashit( $v ) { return rtrim( (string) $v, '/\\' ); } // phpcs:ignore
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( $caminho = '' ) { // phpcs:ignore
		return rastro_teste( 'esquema', 'https' ) . '://' . rastro_teste( 'host', 'loja.exemplo.com.br' ) . rastro_teste( 'caminho_site', '' ) . '/' . ltrim( (string) $caminho, '/' );
	}
}
if ( ! function_exists( 'site_url' ) ) {
	function site_url( $caminho = '' ) { return home_url( $caminho ); } // phpcs:ignore
}
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $caminho = '' ) { return home_url( 'wp-admin/' . ltrim( (string) $caminho, '/' ) ); } // phpcs:ignore
}
if ( ! function_exists( 'plugins_url' ) ) {
	function plugins_url( $caminho = '', $arquivo = '' ) { // phpcs:ignore
		$base = rtrim( (string) rastro_teste( 'url_plugin', '' ), '/' );
		return '' === $caminho ? $base : $base . '/' . ltrim( (string) $caminho, '/' );
	}
}
if ( ! function_exists( 'add_query_arg' ) ) {
	/** Só a forma add_query_arg( array $args, string $url ). */
	function add_query_arg( $args, $url = '' ) { // phpcs:ignore
		$partes = explode( '?', (string) $url, 2 );
		$atual  = array();
		if ( isset( $partes[1] ) ) {
			parse_str( $partes[1], $atual );
		}
		$todos = array_merge( $atual, (array) $args );
		return $partes[0] . ( array() === $todos ? '' : '?' . http_build_query( $todos ) );
	}
}
if ( ! function_exists( 'is_ssl' ) ) {
	function is_ssl() { return (bool) rastro_teste( 'ssl', true ); } // phpcs:ignore
}
if ( ! function_exists( 'get_privacy_policy_url' ) ) {
	function get_privacy_policy_url() { return (string) rastro_teste( 'politica_url', '' ); } // phpcs:ignore
}
if ( ! function_exists( 'get_locale' ) ) {
	function get_locale() { return (string) rastro_teste( 'locale', 'pt_BR' ); } // phpcs:ignore
}
if ( ! function_exists( 'determine_locale' ) ) {
	function determine_locale() { return get_locale(); } // phpcs:ignore
}

/* Opções e transients -------------------------------------------------------- */

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $nome, $padrao = false ) { // phpcs:ignore
		return array_key_exists( $nome, $GLOBALS['rastro_teste']['opcoes'] ) ? $GLOBALS['rastro_teste']['opcoes'][ $nome ] : $padrao;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $nome, $valor, $autoload = null ) { // phpcs:ignore
		$GLOBALS['rastro_teste']['opcoes'][ $nome ]   = $valor;
		$GLOBALS['rastro_teste']['autoload'][ $nome ] = $autoload;
		return true;
	}
}
if ( ! function_exists( 'add_option' ) ) {
	function add_option( $nome, $valor = '', $obsoleto = '', $autoload = null ) { // phpcs:ignore
		if ( array_key_exists( $nome, $GLOBALS['rastro_teste']['opcoes'] ) ) {
			return false;
		}
		return update_option( $nome, $valor, $autoload );
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $nome ) { // phpcs:ignore
		unset( $GLOBALS['rastro_teste']['opcoes'][ $nome ] );
		return true;
	}
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $nome ) { return $GLOBALS['rastro_teste']['transients'][ $nome ]['valor'] ?? false; } // phpcs:ignore
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $nome, $valor, $prazo = 0 ) { // phpcs:ignore
		$GLOBALS['rastro_teste']['transients'][ $nome ] = array( 'valor' => $valor, 'prazo' => (int) $prazo );
		return true;
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $nome ) { // phpcs:ignore
		unset( $GLOBALS['rastro_teste']['transients'][ $nome ] );
		return true;
	}
}

/* Requisição, usuário e consulta ---------------------------------------------- */

if ( ! function_exists( 'is_admin' ) ) {
	function is_admin() { return (bool) rastro_teste( 'admin', false ); } // phpcs:ignore
}
if ( ! function_exists( 'wp_doing_ajax' ) ) {
	function wp_doing_ajax() { return (bool) rastro_teste( 'ajax', false ); } // phpcs:ignore
}
if ( ! function_exists( 'wp_doing_cron' ) ) {
	function wp_doing_cron() { return (bool) rastro_teste( 'cron', false ); } // phpcs:ignore
}
if ( ! function_exists( 'wp_get_environment_type' ) ) {
	function wp_get_environment_type() { return (string) rastro_teste( 'ambiente', 'production' ); } // phpcs:ignore
}
if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in() { return (bool) rastro_teste( 'logado', false ); } // phpcs:ignore
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() { return (int) rastro_teste( 'usuario_id', 0 ); } // phpcs:ignore
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $capacidade, ...$args ) { return ! empty( rastro_teste( 'capacidades', array() )[ $capacidade ] ); } // phpcs:ignore
}
if ( ! function_exists( 'get_query_var' ) ) {
	function get_query_var( $nome, $padrao = '' ) { return rastro_teste( 'query_vars', array() )[ $nome ] ?? $padrao; } // phpcs:ignore
}
foreach ( array( 'is_feed', 'is_preview', 'is_customize_preview', 'is_404', 'is_search', 'is_front_page', 'is_home', 'is_singular', 'is_page', 'is_archive' ) as $rastro_teste_pergunta ) {
	if ( ! function_exists( $rastro_teste_pergunta ) ) {
		eval( 'function ' . $rastro_teste_pergunta . '( ...$a ) { return ! empty( rastro_teste( "wp", array() )["' . $rastro_teste_pergunta . '"] ); }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- stub de teste: uma pergunta por nome.
	}
}
unset( $rastro_teste_pergunta );

/* Scripts, estilos e saída ------------------------------------------------------ */

if ( ! function_exists( 'rastro_teste_atributos_html' ) ) {
	/** Atributos como no WordPress: true vira só o nome; false some. */
	function rastro_teste_atributos_html( array $atributos ): string {
		$html = '';
		foreach ( $atributos as $nome => $valor ) {
			if ( false === $valor || null === $valor ) {
				continue;
			}
			$html .= true === $valor ? ' ' . $nome : ' ' . $nome . '="' . esc_attr( (string) $valor ) . '"';
		}
		return $html;
	}
}
if ( ! function_exists( 'wp_get_script_tag' ) ) {
	function wp_get_script_tag( $atributos ) { // phpcs:ignore
		$atributos = apply_filters( 'wp_script_attributes', (array) $atributos );
		return '<script' . rastro_teste_atributos_html( $atributos ) . "></script>\n";
	}
}
if ( ! function_exists( 'wp_get_inline_script_tag' ) ) {
	function wp_get_inline_script_tag( $js, $atributos = array() ) { // phpcs:ignore
		$atributos                         = apply_filters( 'wp_inline_script_attributes', (array) $atributos, $js );
		$GLOBALS['rastro_teste']['inline'][] = array( 'js' => $js, 'atributos' => $atributos );
		return '<script' . rastro_teste_atributos_html( $atributos ) . '>' . "\n" . trim( (string) $js, "\n\r " ) . "\n</script>\n";
	}
}
if ( ! function_exists( 'wp_print_inline_script_tag' ) ) {
	function wp_print_inline_script_tag( $js, $atributos = array() ) { echo wp_get_inline_script_tag( $js, $atributos ); } // phpcs:ignore
}
if ( ! function_exists( 'wp_register_script' ) ) {
	function wp_register_script( $handle, $src, $deps = array(), $ver = false, $args = array() ) { // phpcs:ignore
		if ( isset( $GLOBALS['rastro_teste']['scripts'][ $handle ] ) ) {
			return false;
		}
		$GLOBALS['rastro_teste']['scripts'][ $handle ] = array(
			'src'        => $src,
			'deps'       => (array) $deps,
			'ver'        => $ver,
			'args'       => is_array( $args ) ? $args : array( 'in_footer' => (bool) $args ),
			'enfileirado' => false,
			'inline'     => array(),
		);
		return true;
	}
}
if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $args = array() ) { // phpcs:ignore
		if ( ! isset( $GLOBALS['rastro_teste']['scripts'][ $handle ] ) && '' !== $src ) {
			wp_register_script( $handle, $src, $deps, $ver, $args );
		}
		if ( isset( $GLOBALS['rastro_teste']['scripts'][ $handle ] ) ) {
			$GLOBALS['rastro_teste']['scripts'][ $handle ]['enfileirado'] = true;
		}
	}
}
if ( ! function_exists( 'wp_script_is' ) ) {
	function wp_script_is( $handle, $lista = 'enqueued' ) { // phpcs:ignore
		$s = $GLOBALS['rastro_teste']['scripts'][ $handle ] ?? null;
		if ( null === $s ) {
			return false;
		}
		return 'registered' === $lista ? true : ! empty( $s['enfileirado'] );
	}
}
if ( ! function_exists( 'wp_add_inline_script' ) ) {
	function wp_add_inline_script( $handle, $js, $posicao = 'after' ) { // phpcs:ignore
		if ( ! isset( $GLOBALS['rastro_teste']['scripts'][ $handle ] ) ) {
			return false;
		}
		$GLOBALS['rastro_teste']['scripts'][ $handle ]['inline'][] = array( $posicao, $js );
		return true;
	}
}
if ( ! function_exists( 'wp_localize_script' ) ) {
	function wp_localize_script( $handle, $nome, $dados ) { return wp_add_inline_script( $handle, 'var ' . $nome . ' = ' . wp_json_encode( $dados ) . ';', 'before' ); } // phpcs:ignore
}
if ( ! function_exists( 'wp_register_style' ) ) {
	function wp_register_style( $handle, $src, $deps = array(), $ver = false, $media = 'all' ) { // phpcs:ignore
		$GLOBALS['rastro_teste']['estilos'][ $handle ] = array( 'src' => $src, 'deps' => (array) $deps, 'ver' => $ver, 'media' => $media, 'enfileirado' => false );
		return true;
	}
}
if ( ! function_exists( 'wp_enqueue_style' ) ) {
	function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false, $media = 'all' ) { // phpcs:ignore
		if ( ! isset( $GLOBALS['rastro_teste']['estilos'][ $handle ] ) ) {
			wp_register_style( $handle, $src, $deps, $ver, $media );
		}
		$GLOBALS['rastro_teste']['estilos'][ $handle ]['enfileirado'] = true;
	}
}
if ( ! function_exists( 'add_shortcode' ) ) {
	function add_shortcode( $nome, $callback ) { $GLOBALS['rastro_teste']['shortcodes'][ $nome ] = $callback; } // phpcs:ignore
}
if ( ! function_exists( 'shortcode_exists' ) ) {
	function shortcode_exists( $nome ) { return isset( $GLOBALS['rastro_teste']['shortcodes'][ $nome ] ); } // phpcs:ignore
}
if ( ! function_exists( 'do_shortcode' ) ) {
	/** Só [nome] sem atributos. */
	function do_shortcode( $texto ) { // phpcs:ignore
		return (string) preg_replace_callback(
			'/\[([a-z0-9_-]+)\]/',
			static function ( $m ) {
				$cb = $GLOBALS['rastro_teste']['shortcodes'][ $m[1] ] ?? null;
				return is_callable( $cb ) ? (string) call_user_func( $cb, array(), '', $m[1] ) : $m[0];
			},
			(string) $texto
		);
	}
}

/* Painel ----------------------------------------------------------------------- */

if ( ! function_exists( 'add_menu_page' ) ) {
	function add_menu_page( $titulo, $menu, $capacidade, $slug, $callback = '', $icone = '', $posicao = null ) { // phpcs:ignore
		$GLOBALS['rastro_teste']['menus'][ $slug ] = compact( 'titulo', 'menu', 'capacidade', 'slug', 'callback', 'icone', 'posicao' );
		return 'toplevel_page_' . $slug;
	}
}
if ( ! function_exists( 'add_submenu_page' ) ) {
	function add_submenu_page( $pai, $titulo, $menu, $capacidade, $slug, $callback = '', $posicao = null ) { // phpcs:ignore
		$GLOBALS['rastro_teste']['menus'][ $slug ] = compact( 'pai', 'titulo', 'menu', 'capacidade', 'slug', 'callback', 'posicao' );
		return $pai . '_page_' . $slug;
	}
}
if ( ! function_exists( 'submit_button' ) ) {
	function submit_button( $texto = 'Salvar', $tipo = 'primary', $nome = 'submit' ) { // phpcs:ignore
		echo '<p class="submit"><button type="submit" name="' . esc_attr( $nome ) . '" class="button button-' . esc_attr( $tipo ) . '">' . esc_html( $texto ) . '</button></p>';
	}
}
if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( $acao = -1 ) { return 'nonce-' . substr( md5( (string) $acao ), 0, 10 ); } // phpcs:ignore
}
if ( ! function_exists( 'wp_nonce_field' ) ) {
	function wp_nonce_field( $acao = -1, $nome = '_wpnonce', $referer = true, $eco = true ) { // phpcs:ignore
		$html = '<input type="hidden" id="' . esc_attr( $nome ) . '" name="' . esc_attr( $nome ) . '" value="' . esc_attr( wp_create_nonce( $acao ) ) . '">';
		if ( $eco ) {
			echo $html; // phpcs:ignore
		}
		return $html;
	}
}
if ( ! function_exists( 'wp_verify_nonce' ) ) {
	function wp_verify_nonce( $nonce, $acao = -1 ) { return rastro_teste( 'nonce_ok', true ) && wp_create_nonce( $acao ) === $nonce ? 1 : false; } // phpcs:ignore
}
if ( ! function_exists( 'check_admin_referer' ) ) {
	function check_admin_referer( $acao = -1, $nome = '_wpnonce' ) { // phpcs:ignore
		if ( ! rastro_teste( 'nonce_ok', true ) ) {
			throw new Rastro_Teste_Parada( 'nonce', $acao, 403 );
		}
		return 1;
	}
}
if ( ! function_exists( 'check_ajax_referer' ) ) {
	function check_ajax_referer( $acao = -1, $nome = false, $parar = true ) { // phpcs:ignore
		if ( ! rastro_teste( 'nonce_ok', true ) ) {
			if ( $parar ) {
				throw new Rastro_Teste_Parada( 'nonce', $acao, 403 );
			}
			return false;
		}
		return 1;
	}
}
if ( ! function_exists( 'wp_safe_redirect' ) ) {
	function wp_safe_redirect( $url, $status = 302 ) { // phpcs:ignore
		$GLOBALS['rastro_teste']['redirecionou'][] = array( $url, $status );
		return true;
	}
}
if ( ! function_exists( 'wp_redirect' ) ) {
	function wp_redirect( $url, $status = 302 ) { return wp_safe_redirect( $url, $status ); } // phpcs:ignore
}
if ( ! function_exists( 'wp_die' ) ) {
	function wp_die( $mensagem = '', $titulo = '', $args = array() ) { // phpcs:ignore
		throw new Rastro_Teste_Parada( (string) $mensagem, $args, (int) ( $args['response'] ?? 500 ) );
	}
}
if ( ! function_exists( 'wp_send_json' ) ) {
	function wp_send_json( $dado, $status = 200 ) { throw new Rastro_Teste_Parada( 'json', $dado, (int) $status ); } // phpcs:ignore
}
if ( ! function_exists( 'wp_send_json_success' ) ) {
	function wp_send_json_success( $dado = null, $status = 200 ) { wp_send_json( array( 'success' => true, 'data' => $dado ), $status ); } // phpcs:ignore
}
if ( ! function_exists( 'wp_send_json_error' ) ) {
	function wp_send_json_error( $dado = null, $status = 400 ) { wp_send_json( array( 'success' => false, 'data' => $dado ), $status ); } // phpcs:ignore
}

/* WooCommerce mínimo -------------------------------------------------------------- */

if ( ! defined( 'RASTRO_TESTE_SEM_WOO' ) ) {
	foreach ( array( 'is_woocommerce', 'is_shop', 'is_product', 'is_product_category', 'is_product_tag', 'is_cart', 'is_checkout', 'is_checkout_pay_page', 'is_order_received_page', 'is_account_page' ) as $rastro_teste_pergunta ) {
		if ( ! function_exists( $rastro_teste_pergunta ) ) {
			eval( 'function ' . $rastro_teste_pergunta . '( ...$a ) { return ! empty( rastro_teste( "woo", array() )["' . $rastro_teste_pergunta . '"] ); }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- stub de teste: uma pergunta por nome.
		}
	}
	unset( $rastro_teste_pergunta );

	if ( ! function_exists( 'wc_get_order_statuses' ) ) {
		function wc_get_order_statuses() { // phpcs:ignore
			$lista = rastro_teste( 'woo_status', null );
			if ( is_array( $lista ) ) {
				return $lista;
			}
			return array(
				'wc-pending'    => 'Pagamento pendente',
				'wc-processing' => 'Processando',
				'wc-on-hold'    => 'Aguardando',
				'wc-completed'  => 'Concluído',
				'wc-cancelled'  => 'Cancelado',
				'wc-refunded'   => 'Reembolsado',
				'wc-failed'     => 'Malsucedido',
				'wc-checkout-draft' => 'Rascunho',
			);
		}
	}
	if ( ! function_exists( 'get_woocommerce_currency' ) ) {
		function get_woocommerce_currency() { return (string) rastro_teste( 'moeda', 'BRL' ); } // phpcs:ignore
	}
}
