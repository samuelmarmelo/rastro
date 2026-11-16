<?php
/**
 * Rastro: onde medir (contrato, seção 3.2; manual docs/BASE.md).
 *
 * Regras puras (rastro_regra_*), testáveis sem WordPress, e as funções que
 * leem a requisição. Falha fechada: sem saber, não mede. O consentimento é
 * decidido depois, no navegador; aqui o servidor só decide se a página pode
 * oferecer a medição.
 *
 * Página privada não impede a medição (decisão D3 do contrato): ela muda o
 * que sai (endereço saneado, sem replay de sessão, sem captura automática).
 *
 * @package Rastro
 */

defined( 'ABSPATH' ) || exit;

/* Regras puras ------------------------------------------------------------- */

/**
 * Regra pura: o site é uma cópia local? (127.0.0.1, localhost, [::1],
 * *.localhost, *.test, ou ambiente local/development). Sem host, vale como
 * local (falha fechada).
 *
 * @param string $host     Host do site (com ou sem porta).
 * @param string $ambiente Tipo de ambiente do WordPress.
 * @return bool
 */
function rastro_regra_local( string $host, string $ambiente ): bool {
	if ( in_array( strtolower( trim( $ambiente ) ), array( 'local', 'development' ), true ) ) {
		return true;
	}

	$host = strtolower( trim( $host ) );
	if ( 1 === preg_match( '/^\[([0-9a-f:.]+)\](?::[0-9]+)?$/D', $host, $partes ) ) {
		// IPv6 entre colchetes, com ou sem porta.
		$host = $partes[1];
	} elseif ( 1 === substr_count( $host, ':' ) ) {
		// host:porta.
		$host = (string) strstr( $host, ':', true );
	}
	$host = rtrim( $host, '.' );
	if ( '' === $host ) {
		return true;
	}

	return in_array( $host, array( '127.0.0.1', 'localhost', '::1' ), true )
		|| '.localhost' === substr( $host, -10 )
		|| '.test' === substr( $host, -5 );
}

/**
 * Regra pura: o servidor pode oferecer a medição nesta página? Falha
 * fechada: chave ausente vale como "não medir".
 *
 * @param array $c {
 *     @type bool $tem_destino Há algum ID de destino válido.
 *     @type bool $local       Cópia local (sem RASTRO_LOCAL).
 *     @type bool $restrita    wp-admin, login, AJAX, REST, cron, feed, prévia, Personalizar ou 404.
 *     @type bool $equipe      Quem está logado é da equipe do site.
 * }
 * @return bool
 */
function rastro_regra_medir( array $c ): bool {
	foreach ( array( 'tem_destino', 'local', 'restrita', 'equipe' ) as $chave ) {
		if ( ! array_key_exists( $chave, $c ) || ! is_bool( $c[ $chave ] ) ) {
			return false;
		}
	}

	return $c['tem_destino'] && ! $c['local'] && ! $c['restrita'] && ! $c['equipe'];
}

/**
 * Tipos de página que o Rastro conhece.
 *
 * @return string[]
 */
function rastro_regra_tipos(): array {
	return array( 'inicio', 'produto', 'categoria', 'loja', 'busca', 'carrinho', 'checkout', 'pedido_recebido', 'conta', 'outra' );
}

/**
 * Regra pura: a página é privada? (conta, finalização da compra, pedido
 * recebido, pagar pedido, resposta a POST, endereço com chave de pedido)
 *
 * @param string $tipo   Tipo da página (rastro_pagina_tipo()).
 * @param array  $sinais pagar, post, key, wc_order, order-pay, order-received (bool).
 * @return bool
 */
function rastro_regra_privada( string $tipo, array $sinais ): bool {
	if ( in_array( $tipo, array( 'conta', 'checkout', 'pedido_recebido' ), true ) ) {
		return true;
	}
	foreach ( array( 'pagar', 'post', 'key', 'wc_order', 'order-pay', 'order-received' ) as $sinal ) {
		if ( ! empty( $sinais[ $sinal ] ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Regra pura: caminho da página pronto para sair. Sempre sem query string
 * e sem âncora. Em página privada (ou de conta, finalização e pedido
 * recebido), o caminho para no primeiro trecho numérico (número do pedido)
 * ou de chave de pedido, e nada depois dele sai. Ex.:
 * `/finalizar-compra/pedido-recebido/123/?key=wc_order_x` vira
 * `/finalizar-compra/pedido-recebido/`.
 *
 * @param string $uri     Endereço pedido (caminho com ou sem query string).
 * @param bool   $privada Página privada?
 * @param string $tipo    Tipo da página.
 * @return string Caminho começando com barra.
 */
function rastro_regra_url_limpa( string $uri, bool $privada, string $tipo ): string {
	$caminho = (string) preg_replace( '/[?#].*$/s', '', $uri );
	// Endereço absoluto: fica só o caminho.
	if ( 1 === preg_match( '#^(?:[a-z][a-z0-9+.-]*:)?//[^/]*(.*)$#isD', $caminho, $partes ) ) {
		$caminho = $partes[1];
	}
	// Sem espaço, caractere de controle, aspas, sinais de tag e barra invertida.
	$caminho = (string) preg_replace( '/[\x00-\x20\x7F<>"\'\\\\]+/', '', $caminho );
	$caminho = '/' . ltrim( (string) preg_replace( '#/{2,}#', '/', $caminho ), '/' );
	if ( strlen( $caminho ) > 500 ) {
		$caminho = substr( $caminho, 0, 500 );
	}

	if ( ! $privada && ! in_array( $tipo, array( 'conta', 'checkout', 'pedido_recebido' ), true ) ) {
		return $caminho;
	}

	$fica = array();
	foreach ( explode( '/', trim( $caminho, '/' ) ) as $trecho ) {
		if ( '' === $trecho ) {
			continue;
		}
		if ( 1 === preg_match( '/^[0-9]+$/D', $trecho ) || 0 === stripos( $trecho, 'wc_order_' ) ) {
			break;
		}
		$fica[] = $trecho;
	}

	return array() === $fica ? '/' : '/' . implode( '/', $fica ) . '/';
}

/**
 * Chaves de consulta de campanha que podem sair no endereço medido de uma
 * página pública (o GA4 usa para atribuir a visita). Lista fechada, nesta
 * ordem; qualquer outra chave é descartada.
 *
 * @return string[]
 */
function rastro_regra_chaves_campanha(): array {
	return array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'utm_id', 'gclid', 'gbraid', 'wbraid', 'fbclid', 'ttclid', 'msclkid' );
}

/**
 * Regra pura: texto de campanha pronto para sair. Vazio quando parece dado
 * pessoal: tem arroba (e-mail) ou oito dígitos seguidos ou mais (CPF,
 * telefone, CEP). Sem caractere de controle, sem espaço nas pontas, até 100
 * caracteres.
 *
 * @param mixed $valor Valor já decodificado.
 * @return string
 */
function rastro_regra_valor_campanha( $valor ): string {
	if ( ! is_string( $valor ) ) {
		return '';
	}
	$valor = trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $valor ) );
	if ( '' === $valor || false !== strpos( $valor, '@' ) || 1 === preg_match( '/[0-9]{8,}/', $valor ) ) {
		return '';
	}

	return function_exists( 'mb_substr' ) ? trim( mb_substr( $valor, 0, 100, 'UTF-8' ) ) : trim( substr( $valor, 0, 100 ) );
}

/**
 * Regra pura: endereço medido (o `page_location` do GA4). Em página pública,
 * o caminho de rastro_regra_url_limpa() mais só as chaves de
 * rastro_regra_chaves_campanha() (a primeira de cada, nessa ordem): utm_*
 * pela rastro_regra_valor_campanha(); ID de clique só com
 * `[A-Za-z0-9._~-]` (até 500). Página privada: o caminho limpo, sem
 * consulta nenhuma.
 *
 * @param string $uri     Endereço pedido (caminho com ou sem query string).
 * @param bool   $privada Página privada?
 * @param string $tipo    Tipo da página.
 * @return string Caminho começando com barra, com a consulta de campanha.
 */
function rastro_regra_url_campanha( string $uri, bool $privada, string $tipo ): string {
	$caminho = rastro_regra_url_limpa( $uri, $privada, $tipo );
	if ( $privada || in_array( $tipo, array( 'conta', 'checkout', 'pedido_recebido' ), true ) ) {
		return $caminho;
	}
	if ( 1 !== preg_match( '/\?([^#]*)/', $uri, $partes ) || '' === $partes[1] ) {
		return $caminho;
	}

	$lidos = array();
	foreach ( explode( '&', $partes[1] ) as $par ) {
		$chave_valor = explode( '=', $par, 2 );
		$chave       = strtolower( urldecode( $chave_valor[0] ) );
		if ( isset( $lidos[ $chave ] ) || ! isset( $chave_valor[1] ) ) {
			continue;
		}
		$lidos[ $chave ] = urldecode( $chave_valor[1] );
	}

	$saida = array();
	$total = strlen( $caminho );
	foreach ( rastro_regra_chaves_campanha() as $chave ) {
		if ( ! isset( $lidos[ $chave ] ) ) {
			continue;
		}
		if ( 0 === strpos( $chave, 'utm_' ) ) {
			$valor = rastro_regra_valor_campanha( $lidos[ $chave ] );
		} else {
			$valor = 1 === preg_match( '/^[A-Za-z0-9._~-]{1,500}$/D', $lidos[ $chave ] ) ? $lidos[ $chave ] : '';
		}
		if ( '' === $valor ) {
			continue;
		}
		$trecho = $chave . '=' . rawurlencode( $valor );
		// O GA4 aceita até 1000 caracteres no page_location (o host vem depois).
		if ( $total + strlen( $trecho ) + 1 > 900 ) {
			continue;
		}
		$total  += strlen( $trecho ) + 1;
		$saida[] = $trecho;
	}

	return array() === $saida ? $caminho : $caminho . '?' . implode( '&', $saida );
}

/* Leitura da requisição ---------------------------------------------------- */

/**
 * Memória da requisição (respostas calculadas uma vez).
 *
 * @return array{local:?bool,medir:?bool}
 */
function &rastro_regras_memoria(): array {
	static $memoria = array(
		'local' => null,
		'medir' => null,
	);

	return $memoria;
}

/**
 * Esquece as respostas desta requisição (testes).
 *
 * @return void
 */
function rastro_regras_redefinir_cache(): void {
	$memoria          = &rastro_regras_memoria();
	$memoria['local'] = null;
	$memoria['medir'] = null;
}

/**
 * Cópia local? (Playground, servidor de desenvolvimento, cópia de teste)
 * A constante RASTRO_LOCAL (true) liga a medição de propósito numa cópia de
 * teste. Nunca no repositório.
 *
 * @return bool
 */
function rastro_ambiente_local(): bool {
	$memoria = &rastro_regras_memoria();
	if ( null !== $memoria['local'] ) {
		return $memoria['local'];
	}

	if ( defined( 'RASTRO_LOCAL' ) && true === RASTRO_LOCAL ) {
		$memoria['local'] = false;
		return false;
	}

	$memoria['local'] = rastro_regra_local(
		(string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ),
		function_exists( 'wp_get_environment_type' ) ? (string) wp_get_environment_type() : 'production'
	);

	return $memoria['local'];
}

/**
 * Quem está logado é da equipe do site? (pode editar conteúdo ou gerenciar
 * a loja) Com `medir_equipe` ligado, devolve false.
 *
 * @return bool
 */
function rastro_e_equipe(): bool {
	if ( rastro_config_ligado( 'medir_equipe' ) ) {
		return false;
	}

	$equipe = is_user_logged_in() && ( current_user_can( 'edit_posts' ) || current_user_can( 'manage_woocommerce' ) );

	/**
	 * Filtra quem conta como equipe (e por isso não é medido).
	 *
	 * @param bool $equipe Resposta padrão.
	 */
	return true === apply_filters( 'rastro_e_equipe', $equipe );
}

/**
 * Requisição onde nada do Rastro sai: wp-admin, login, AJAX, REST, cron,
 * feed, prévia, Personalizar e 404. Antes do gancho `wp` a consulta ainda
 * não existe: vale como restrita (falha fechada).
 *
 * @return bool
 */
function rastro_requisicao_restrita(): bool {
	if ( is_admin() || ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) || ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) ) {
		return true;
	}
	if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
		return true;
	}
	if ( 'wp-login.php' === ( isset( $GLOBALS['pagenow'] ) ? $GLOBALS['pagenow'] : '' ) ) {
		return true;
	}
	if ( ! did_action( 'wp' ) ) {
		return true;
	}

	return is_feed() || is_preview() || is_customize_preview() || is_404();
}

/**
 * Tipo da página: inicio, produto, categoria, loja, busca, carrinho,
 * checkout, pedido_recebido, conta ou outra. As perguntas do WooCommerce só
 * são feitas quando ele existe.
 *
 * @return string
 */
function rastro_pagina_tipo(): string {
	if ( ! did_action( 'wp' ) ) {
		return 'outra';
	}

	$woo = static function ( string $pergunta ): bool {
		return function_exists( $pergunta ) && (bool) call_user_func( $pergunta );
	};

	if ( $woo( 'is_order_received_page' ) ) {
		return 'pedido_recebido';
	}
	if ( $woo( 'is_checkout' ) ) {
		return 'checkout';
	}
	if ( $woo( 'is_cart' ) ) {
		return 'carrinho';
	}
	if ( $woo( 'is_account_page' ) ) {
		return 'conta';
	}
	// Antes da loja: a busca de produtos também é um arquivo da loja.
	if ( is_search() ) {
		return 'busca';
	}
	if ( $woo( 'is_product' ) ) {
		return 'produto';
	}
	if ( $woo( 'is_product_category' ) || $woo( 'is_product_tag' ) ) {
		return 'categoria';
	}
	if ( $woo( 'is_shop' ) ) {
		return 'loja';
	}
	if ( is_front_page() ) {
		return 'inicio';
	}

	return 'outra';
}

/**
 * Método da requisição (GET, POST...).
 *
 * @return string
 */
function rastro_requisicao_metodo(): string {
	return isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
}

/**
 * Página privada? (conta, finalização da compra, pedido recebido, pagar
 * pedido, resposta a POST, endereço com `key` ou `wc_order`) O filtro
 * `rastro_pagina_privada` só acrescenta páginas, nunca tira.
 *
 * @return bool
 */
function rastro_pagina_privada(): bool {
	$sinais = array(
		'pagar' => function_exists( 'is_checkout_pay_page' ) && did_action( 'wp' ) && is_checkout_pay_page(),
		// Qualquer envio de formulário pode devolver dado do visitante na resposta.
		'post'  => 'GET' !== rastro_requisicao_metodo() && 'HEAD' !== rastro_requisicao_metodo(),
	);
	foreach ( array( 'key', 'wc_order', 'order-pay', 'order-received' ) as $variavel ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- só a presença decide.
		$sinais[ $variavel ] = isset( $_GET[ $variavel ] ) || ( function_exists( 'get_query_var' ) && '' !== (string) get_query_var( $variavel, '' ) );
	}

	$privada = rastro_regra_privada( rastro_pagina_tipo(), $sinais );

	/**
	 * Acrescenta páginas privadas (ex.: documento do pedido, rastreio de
	 * pedidos). Devolver true marca a página; false não desmarca.
	 *
	 * @param bool $privada Resposta padrão.
	 */
	return $privada || true === apply_filters( 'rastro_pagina_privada', $privada );
}

/**
 * O servidor oferece a medição nesta requisição? Tem destino, não é cópia
 * local, não é requisição restrita e quem navega não é da equipe.
 * Calculado uma vez por requisição (depois do gancho `wp`). O filtro
 * `rastro_deve_medir` não liga a medição sem destino nem em requisição
 * restrita.
 *
 * @return bool
 */
function rastro_deve_medir(): bool {
	$memoria = &rastro_regras_memoria();
	if ( null !== $memoria['medir'] ) {
		return $memoria['medir'];
	}

	$medir = false;
	try {
		$contexto = array(
			'tem_destino' => rastro_tem_destino(),
			'local'       => rastro_ambiente_local(),
			'restrita'    => rastro_requisicao_restrita(),
			'equipe'      => rastro_e_equipe(),
		);

		/**
		 * Filtra a decisão de medir nesta requisição.
		 *
		 * @param bool  $medir    Resposta da regra.
		 * @param array $contexto tem_destino, local, restrita, equipe.
		 */
		$medir = true === apply_filters( 'rastro_deve_medir', rastro_regra_medir( $contexto ), $contexto );
		$medir = $medir && $contexto['tem_destino'] && ! $contexto['restrita'];
	} catch ( \Throwable $erro ) {
		do_action( 'rastro_falha', 'regras', $erro );
		$medir = false;
	}

	if ( did_action( 'wp' ) ) {
		$memoria['medir'] = $medir;
	}

	return $medir;
}

/**
 * Endereço absoluto da página, pronto para sair: sem query string e, em
 * página privada, só até o endpoint. O host vem do endereço do site
 * (home_url), nunca do cabeçalho da requisição.
 *
 * @return string
 */
function rastro_url_limpa(): string {
	return rastro_url_absoluta( rastro_regra_url_limpa( rastro_requisicao_uri(), rastro_pagina_privada(), rastro_pagina_tipo() ) );
}

/**
 * Endereço medido da página (o `page_location` do GA4): o de
 * rastro_url_limpa() e, em página pública, as chaves de campanha da lista
 * fechada (rastro_regra_url_campanha()).
 *
 * @return string
 */
function rastro_url_campanha(): string {
	return rastro_url_absoluta( rastro_regra_url_campanha( rastro_requisicao_uri(), rastro_pagina_privada(), rastro_pagina_tipo() ) );
}

/**
 * Endereço pedido (REQUEST_URI) sem barra invertida de escape.
 *
 * @return string
 */
function rastro_requisicao_uri(): string {
	return isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? (string) esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
}

/**
 * Caminho já saneado com o esquema, o host e a porta do endereço do site
 * (home_url), nunca do cabeçalho da requisição.
 *
 * @param string $caminho Caminho começando com barra.
 * @return string
 */
function rastro_url_absoluta( string $caminho ): string {
	$site = wp_parse_url( home_url( '/' ) );
	if ( ! is_array( $site ) || empty( $site['host'] ) ) {
		return $caminho;
	}

	$esquema = isset( $site['scheme'] ) && 'http' === strtolower( (string) $site['scheme'] ) ? 'http' : 'https';
	$porta   = isset( $site['port'] ) ? ':' . (int) $site['port'] : '';

	return $esquema . '://' . $site['host'] . $porta . $caminho;
}
