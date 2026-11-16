<?php
/**
 * Rastro: saída para o navegador (contrato, seção 3.3; manual docs/BASE.md).
 *
 * - `<script id="rastro-config">` no wp_head (prioridade 1) com
 *   window.rastroConfig, só quando a página mede ou quando um módulo (o
 *   aviso de cookies) pede a saída.
 * - rastro_registrar_script(): scripts do Rastro no rodapé, com defer e
 *   versão pelo arquivo.
 * - Atributos que tiram os scripts do Rastro do atraso dos otimizadores
 *   (data-no-optimize, data-no-defer, data-cfasync, classe nowprocket) e as
 *   exclusões do LiteSpeed Cache, do WP Rocket e do Autoptimize, só para os
 *   arquivos do Rastro. O atraso é o do próprio Rastro, igual em qualquer
 *   hospedagem.
 *
 * Todo JSON sai por rastro_json() (JSON_HEX_*): texto com </script> não
 * fecha a tag.
 *
 * @package Rastro
 */

defined( 'ABSPATH' ) || exit;

/** Prefixo de todo handle e id de script do Rastro. */
const RASTRO_PREFIXO_SCRIPT = 'rastro-';

/* JSON e atributos ---------------------------------------------------------- */

/**
 * JSON seguro para dentro de <script> ('' quando não dá para codificar).
 *
 * @param mixed $dado Dado.
 * @return string
 */
function rastro_json( $dado ): string {
	$json = wp_json_encode( $dado, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES );

	return is_string( $json ) ? $json : '';
}

/**
 * Atributos que deixam o script fora do atraso e da junção dos otimizadores.
 *
 * @return array<string,string>
 */
function rastro_atributos_saida(): array {
	return array(
		'data-no-optimize' => '1',
		'data-no-defer'    => '1',
		'data-cfasync'     => 'false',
		'class'            => 'nowprocket',
	);
}

/**
 * Acrescenta os atributos de saída a um array de atributos de <script>
 * (sem apagar classes que já existem). Os data-* vêm antes de todos, porque
 * o Rocket Loader da Cloudflare só respeita data-cfasync antes do src.
 *
 * @param array $atributos Atributos recebidos.
 * @return array
 */
function rastro_atributos_mesclar( array $atributos ): array {
	$saida  = rastro_atributos_saida();
	$classe = $saida['class'];
	unset( $saida['class'] );

	$novos = $saida;
	foreach ( $atributos as $nome => $valor ) {
		if ( ! array_key_exists( $nome, $novos ) ) {
			$novos[ $nome ] = $valor;
		}
	}

	if ( isset( $novos['class'] ) && is_string( $novos['class'] ) && '' !== trim( $novos['class'] ) ) {
		$classes = preg_split( '/\s+/', trim( $novos['class'] ) );
		if ( ! in_array( $classe, (array) $classes, true ) ) {
			$novos['class'] = trim( $novos['class'] ) . ' ' . $classe;
		}
	} else {
		$novos['class'] = $classe;
	}

	return $novos;
}

/**
 * O id de <script> é do Rastro?
 *
 * @param mixed $id Atributo id.
 * @return bool
 */
function rastro_id_do_rastro( $id ): bool {
	return is_string( $id ) && 0 === strpos( $id, RASTRO_PREFIXO_SCRIPT );
}

/* Configuração no <head> ---------------------------------------------------- */

/**
 * Configuração para o navegador (window.rastroConfig). Base: versao,
 * pagina (tipo, privada, url), carregamento e debug. Cada módulo acrescenta
 * a sua chave pelo filtro `rastro_config_navegador` (consent, tags,
 * eventos, atribuicao). Nunca dado pessoal.
 *
 * @return array<string,mixed>
 */
function rastro_config_navegador(): array {
	$base = array(
		'versao'       => RASTRO_VERSAO,
		'pagina'       => array(
			'tipo'    => rastro_pagina_tipo(),
			'privada' => rastro_pagina_privada(),
			'url'     => rastro_url_limpa(),
		),
		'carregamento' => (string) rastro_config_campo( 'carregamento' ),
		'debug'        => defined( 'WP_DEBUG' ) && true === WP_DEBUG,
	);

	/**
	 * Filtra a configuração para o navegador. Cada módulo acrescenta só a
	 * própria chave, sem dado pessoal.
	 *
	 * @param array $config Configuração base.
	 */
	$config = apply_filters( 'rastro_config_navegador', $base );

	return is_array( $config ) ? $config : $base;
}

/**
 * A página precisa da saída do Rastro? Sim quando mede, ou quando um módulo
 * pede pelo filtro `rastro_precisa_saida` (o aviso de cookies próprio,
 * mesmo sem medição). Nunca em requisição restrita.
 *
 * @return bool
 */
function rastro_precisa_saida(): bool {
	if ( rastro_deve_medir() ) {
		return true;
	}
	if ( rastro_requisicao_restrita() ) {
		return false;
	}

	/**
	 * Pede a saída do Rastro numa página que não mede.
	 *
	 * @param bool $precisa Padrão false.
	 */
	return true === apply_filters( 'rastro_precisa_saida', false );
}

/**
 * Imprime um script inline do Rastro com os atributos de saída. Usa a
 * função do WordPress, para os filtros de CSP (nonce) valerem.
 *
 * @param string $id Id do script (começa com rastro-).
 * @param string $js Código.
 * @return void
 */
function rastro_script_inline( string $id, string $js ): void {
	$id = strtolower( (string) preg_replace( '/[^A-Za-z0-9_-]/', '', $id ) );
	if ( ! rastro_id_do_rastro( $id ) || '' === trim( $js ) ) {
		return;
	}

	$atributos = rastro_atributos_mesclar( array( 'id' => $id ) );
	if ( function_exists( 'wp_print_inline_script_tag' ) ) {
		wp_print_inline_script_tag( $js, $atributos );
		return;
	}

	// Reserva para WordPress antigo (antes do 5.7).
	$html = '';
	foreach ( $atributos as $nome => $valor ) {
		$html .= ' ' . $nome . '="' . esc_attr( $valor ) . '"';
	}
	echo '<script' . $html . '>' . $js . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- atributos escapados acima; o código é do Rastro, com dados por rastro_json().
}

/**
 * wp_head (prioridade 1): window.rastroConfig e o espaço window.rastro.
 *
 * @return void
 */
function rastro_head_config(): void {
	try {
		if ( ! rastro_precisa_saida() ) {
			return;
		}
		$json = rastro_json( rastro_config_navegador() );
		if ( '' === $json ) {
			return;
		}
		rastro_script_inline( 'rastro-config', 'window.rastroConfig=' . $json . ';window.rastro=window.rastro||{};' );
	} catch ( \Throwable $erro ) {
		do_action( 'rastro_falha', 'saida-head', $erro );
	}
}
add_action( 'wp_head', 'rastro_head_config', 1 );

/* Scripts do Rastro --------------------------------------------------------- */

/**
 * Handles e caminhos registrados por rastro_registrar_script().
 *
 * @return array<string,string> handle => caminho no site (/.../assets/js/x.js).
 */
function &rastro_scripts_registrados(): array {
	static $registrados = array();

	return $registrados;
}

/**
 * Caminho da pasta de scripts do Rastro no site (ex.:
 * /wp-content/plugins/rastro/assets/js/), para as exclusões dos otimizadores.
 *
 * @return string
 */
function rastro_caminho_scripts(): string {
	$caminho = wp_parse_url( RASTRO_URL . 'assets/js/', PHP_URL_PATH );

	return is_string( $caminho ) && '' !== $caminho ? $caminho : '';
}

/**
 * Registra um script do Rastro: rodapé, defer, versão pela data do arquivo
 * (RASTRO_VERSAO sem ela) e fora do atraso dos otimizadores. Falso quando
 * o handle não começa com rastro-, o nome do arquivo não vale ou o arquivo
 * não existe.
 *
 * @param string   $handle           Handle (rastro-...).
 * @param string   $arquivo_relativo Arquivo dentro de assets/js/ (ex.: consent.js).
 * @param string[] $deps             Dependências.
 * @return bool
 */
function rastro_registrar_script( string $handle, string $arquivo_relativo, array $deps = array() ): bool {
	if ( 1 !== preg_match( '/^rastro-[a-z0-9-]{1,60}$/D', $handle ) || 1 !== preg_match( '#^[a-z0-9_-]+(?:/[a-z0-9_-]+)*\.js$#D', $arquivo_relativo ) ) {
		return false;
	}

	$arquivo = RASTRO_DIR . 'assets/js/' . $arquivo_relativo;
	if ( ! is_readable( $arquivo ) || ! function_exists( 'wp_register_script' ) ) {
		return false;
	}

	$data   = @filemtime( $arquivo ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- sem data, vale a versão do plugin.
	$versao = false !== $data ? (string) $data : RASTRO_VERSAO;

	$ok = wp_register_script(
		$handle,
		RASTRO_URL . 'assets/js/' . $arquivo_relativo,
		array_values( array_filter( $deps, 'is_string' ) ),
		$versao,
		array(
			'in_footer' => true,
			'strategy'  => 'defer',
		)
	);
	if ( $ok ) {
		$registrados            = &rastro_scripts_registrados();
		$registrados[ $handle ] = rastro_caminho_scripts() . $arquivo_relativo;
	}

	return (bool) $ok;
}

/**
 * wp_script_attributes (WordPress 5.7 em diante, e o único caminho dos
 * scripts com src no WordPress 7): atributos de saída nos scripts do Rastro.
 *
 * @param mixed $atributos Atributos do <script>.
 * @return mixed
 */
function rastro_filtro_atributos_script( $atributos ) {
	if ( is_array( $atributos ) && isset( $atributos['id'] ) && rastro_id_do_rastro( $atributos['id'] ) ) {
		return rastro_atributos_mesclar( $atributos );
	}

	return $atributos;
}
add_filter( 'wp_script_attributes', 'rastro_filtro_atributos_script', 10, 1 );

/**
 * wp_inline_script_attributes: o mesmo para os inline ligados a um handle
 * do Rastro (-before, -after, -extra, -translations) e os do próprio Rastro.
 *
 * @param mixed $atributos Atributos do <script>.
 * @return mixed
 */
function rastro_filtro_atributos_inline( $atributos ) {
	return rastro_filtro_atributos_script( $atributos );
}
add_filter( 'wp_inline_script_attributes', 'rastro_filtro_atributos_inline', 10, 1 );

/**
 * script_loader_tag (versões em que o HTML do script passa por ele):
 * acrescenta os atributos que faltarem a cada <script> de um handle do
 * Rastro. Não repete atributo que já veio do filtro de atributos.
 *
 * @param mixed  $tag    HTML do script.
 * @param string $handle Handle.
 * @return mixed
 */
function rastro_filtro_tag_script( $tag, $handle = '' ) {
	if ( ! is_string( $tag ) || ! is_string( $handle ) || 0 !== strpos( $handle, RASTRO_PREFIXO_SCRIPT ) ) {
		return $tag;
	}

	$novo = preg_replace_callback(
		'/<script\b([^>]*)>/i',
		static function ( array $m ): string {
			$attrs = $m[1];
			// Os que faltam entram no começo (data-cfasync antes do src).
			$antes = '';
			foreach ( rastro_atributos_saida() as $nome => $valor ) {
				if ( 'class' === $nome ) {
					if ( 1 === preg_match( '/\sclass\s*=\s*(["\'])(.*?)\1/i', $attrs, $c ) ) {
						if ( ! in_array( $valor, (array) preg_split( '/\s+/', trim( $c[2] ) ), true ) ) {
							$attrs = str_replace( $c[0], ' class=' . $c[1] . trim( $c[2] . ' ' . $valor ) . $c[1], $attrs );
						}
						continue;
					}
				} elseif ( 1 === preg_match( '/\s' . preg_quote( $nome, '/' ) . '(?:\s*=|[\s>\/]|$)/i', $attrs ) ) {
					continue;
				}
				$antes .= ' ' . $nome . '="' . $valor . '"';
			}

			return '<script' . $antes . $attrs . '>';
		},
		$tag
	);

	return is_string( $novo ) ? $novo : $tag;
}
add_filter( 'script_loader_tag', 'rastro_filtro_tag_script', 10, 2 );

/* Exclusões dos otimizadores ------------------------------------------------ */

/**
 * Padrões dos arquivos do Rastro para as listas de exclusão (o caminho da
 * pasta de scripts, que cobre todo arquivo registrado). Vazio quando não há
 * caminho.
 *
 * @return string[]
 */
function rastro_otimizadores_padroes(): array {
	$caminho = rastro_caminho_scripts();

	return '' === $caminho ? array() : array( $caminho );
}

/**
 * Acrescenta os padrões do Rastro a uma lista de exclusão (array ou texto
 * separado por vírgula ou linha).
 *
 * @param mixed $lista Lista recebida.
 * @param bool  $regex Os padrões são expressões regulares (WP Rocket)?
 * @return mixed
 */
function rastro_otimizadores_acrescentar( $lista, bool $regex = false ) {
	$padroes = rastro_otimizadores_padroes();
	if ( array() === $padroes ) {
		return $lista;
	}
	if ( $regex ) {
		$padroes = array_map(
			static function ( string $p ): string {
				return preg_quote( $p, '#' );
			},
			$padroes
		);
	}

	if ( is_string( $lista ) ) {
		$lista = '' === trim( $lista ) ? array() : array_map( 'trim', (array) preg_split( '/[,\n]+/', $lista ) );
	}
	if ( ! is_array( $lista ) ) {
		$lista = array();
	}
	foreach ( $padroes as $padrao ) {
		if ( ! in_array( $padrao, $lista, true ) ) {
			$lista[] = $padrao;
		}
	}

	return $lista;
}

/**
 * LiteSpeed Cache: fora do adiamento e da junção (filtros conferidos no
 * código do LiteSpeed Cache 7.9.3, src/optimize.cls.php).
 *
 * @param mixed $lista Lista de exclusão.
 * @return mixed
 */
function rastro_otimizador_litespeed( $lista ) {
	return rastro_otimizadores_acrescentar( $lista );
}

/**
 * WP Rocket: fora do atraso, do adiamento e da minificação (filtros
 * conferidos no código do WP Rocket 3.24). Os padrões são expressões
 * regulares.
 *
 * @param mixed $lista Lista de exclusão.
 * @return mixed
 */
function rastro_otimizador_rocket( $lista ) {
	return rastro_otimizadores_acrescentar( $lista, true );
}

/**
 * Autoptimize: fora da junção (filtro conferido no código do Autoptimize
 * 2.8.4, classes/autoptimizeScripts.php). A lista chega como texto
 * separado por vírgula ou como array em que as chaves são os padrões.
 *
 * @param mixed $lista Lista de exclusão.
 * @return mixed
 */
function rastro_otimizador_autoptimize( $lista ) {
	$padroes = rastro_otimizadores_padroes();
	if ( array() === $padroes ) {
		return $lista;
	}
	if ( is_array( $lista ) ) {
		foreach ( $padroes as $padrao ) {
			if ( ! array_key_exists( $padrao, $lista ) ) {
				$lista[ $padrao ] = '';
			}
		}
		return $lista;
	}

	$texto = is_string( $lista ) ? trim( $lista ) : '';
	foreach ( $padroes as $padrao ) {
		if ( false === strpos( $texto, $padrao ) ) {
			$texto = '' === $texto ? $padrao : $texto . ', ' . $padrao;
		}
	}

	return $texto;
}

/**
 * Liga as exclusões só dos otimizadores instalados.
 *
 * @return void
 */
function rastro_otimizadores_registrar(): void {
	if ( defined( 'LSCWP_V' ) ) {
		add_filter( 'litespeed_optm_js_defer_exc', 'rastro_otimizador_litespeed' );
		add_filter( 'litespeed_optimize_js_excludes', 'rastro_otimizador_litespeed' );
	}
	if ( defined( 'WP_ROCKET_VERSION' ) ) {
		add_filter( 'rocket_delay_js_exclusions', 'rastro_otimizador_rocket' );
		add_filter( 'rocket_exclude_defer_js', 'rastro_otimizador_rocket' );
		add_filter( 'rocket_exclude_js', 'rastro_otimizador_rocket' );
	}
	if ( defined( 'AUTOPTIMIZE_PLUGIN_VERSION' ) ) {
		add_filter( 'autoptimize_filter_js_exclude', 'rastro_otimizador_autoptimize', 10, 1 );
	}
}
// Embutido (after_setup_theme), os plugins já carregaram; como plugin
// próprio, espera todos carregarem.
if ( did_action( 'plugins_loaded' ) ) {
	rastro_otimizadores_registrar();
} else {
	add_action( 'plugins_loaded', 'rastro_otimizadores_registrar', 20 );
}
