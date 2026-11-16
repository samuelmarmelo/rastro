<?php
/**
 * Rastro: atribuição de origem (contrato, seções 2.1 e 7; manual
 * docs/ATRIBUICAO.md).
 *
 * - Liga o assets/js/atribuicao.js nas páginas da loja (a captura e o cookie
 *   rastro_origem são do navegador: HTML igual para todo visitante).
 * - No pedido (checkout clássico e de blocos), lê o cookie, valida cada
 *   campo (lista fechada de chaves, tamanho máximo, só caracteres seguros) e
 *   grava a meta protegida _rastro_origem uma vez por pedido.
 * - Atribuição nativa do WooCommerce: com `atribuicao_woo_consentimento`,
 *   só depois do aceite de análise, pelo filtro wc_order_attribution_allow_tracking
 *   e por window.wc_order_attribution.setOrderTracking() (conferidos no
 *   código do WooCommerce 8.6.0, 9.0.0 e 11.2.2; antes da 8.6 nada muda).
 * - Leitura unificada rastro_pedido_origem(): meta do Rastro, metas do
 *   PixelYourSite (só leitura), atribuição nativa do WooCommerce, nada.
 *
 * Nunca guarda o valor de um ID de clique (só qual veio) nem dado pessoal.
 *
 * @package Rastro
 */

defined( 'ABSPATH' ) || exit;

/** Nome do cookie de origem (o mesmo do atribuicao.js). */
const RASTRO_ORIGEM_COOKIE = 'rastro_origem';

/** Meta protegida do pedido. */
const RASTRO_ORIGEM_META = '_rastro_origem';

/** Tamanho máximo do valor do cookie (1,5 KB). */
const RASTRO_ORIGEM_MAX = 1536;

/** Versão mínima do WooCommerce com o filtro e a função de navegador conferidos. */
const RASTRO_ORIGEM_WOO_MINIMA = '8.6.0';

/* Regras puras --------------------------------------------------------------- */

/**
 * Campos de um toque: chave curta do cookie => array( chave longa, tamanho
 * máximo, tipo ). A lista é fechada: chave fora dela invalida o cookie.
 *
 * @return array<string,array{0:string,1:int,2:string}>
 */
function rastro_atribuicao_campos(): array {
	return array(
		'f' => array( 'fonte', 100, 'texto' ),
		's' => array( 'utm_source', 100, 'campanha' ),
		'm' => array( 'utm_medium', 100, 'campanha' ),
		'c' => array( 'utm_campaign', 100, 'campanha' ),
		'n' => array( 'utm_content', 100, 'campanha' ),
		't' => array( 'utm_term', 100, 'campanha' ),
		'i' => array( 'utm_id', 100, 'campanha' ),
		'k' => array( 'clique', 10, 'clique' ),
		'r' => array( 'referrer', 100, 'host' ),
		'e' => array( 'entrada', 200, 'caminho' ),
		'q' => array( 'quando', 0, 'tempo' ),
	);
}

/**
 * IDs de clique aceitos (só o nome é guardado) e a fonte de cada um.
 *
 * @return array<string,string>
 */
function rastro_atribuicao_cliques(): array {
	return array(
		'gclid'   => 'google',
		'gbraid'  => 'google',
		'wbraid'  => 'google',
		'fbclid'  => 'facebook',
		'ttclid'  => 'tiktok',
		'msclkid' => 'bing',
	);
}

/**
 * Bases legais aceitas no campo `consentimento` (seção 2.1): aceite de
 * análise, modo sessão antes do aceite, visita de campanha antes do aceite,
 * modo básico.
 *
 * @return string[]
 */
function rastro_atribuicao_bases(): array {
	return array( 'aceite', 'sessao', 'campanha', 'basico' );
}

/**
 * Toque vazio, com as chaves longas na ordem do contrato.
 *
 * @return array<string,string>
 */
function rastro_atribuicao_toque_vazio(): array {
	$toque = array();
	foreach ( rastro_atribuicao_campos() as $campo ) {
		$toque[ $campo[0] ] = '';
	}

	return $toque;
}

/**
 * Tamanho em caracteres (UTF-8).
 *
 * @param string $texto Texto.
 * @return int
 */
function rastro_atribuicao_tamanho( string $texto ): int {
	if ( function_exists( 'mb_strlen' ) ) {
		return (int) mb_strlen( $texto, 'UTF-8' );
	}

	return (int) preg_match_all( '/./us', $texto );
}

/**
 * Valida um valor de um campo. Devolve o valor limpo, '' para vazio e null
 * para inválido (caractere fora da lista, tamanho, tipo errado).
 *
 * @param string $tipo  texto, campanha, clique, host, caminho ou tempo.
 * @param mixed  $valor Valor bruto.
 * @param int    $max   Tamanho máximo (texto, host, caminho).
 * @return string|null
 */
function rastro_atribuicao_validar_valor( string $tipo, $valor, int $max ): ?string {
	if ( 'tempo' === $tipo ) {
		if ( is_int( $valor ) ) {
			// Segundos (cookie): de 2020 até um dia à frente do relógio do servidor.
			return ( $valor >= 1577836800 && $valor <= time() + DAY_IN_SECONDS ) ? gmdate( 'Y-m-d\TH:i:s\Z', $valor ) : null;
		}
		if ( ! is_string( $valor ) ) {
			return null;
		}
		if ( '' === $valor ) {
			return '';
		}
		// Data ISO já gravada na meta.
		return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $valor ) ? $valor : null;
	}

	if ( ! is_string( $valor ) ) {
		return null;
	}
	if ( '' === $valor ) {
		return '';
	}
	if ( rastro_atribuicao_tamanho( $valor ) > $max ) {
		return null;
	}

	switch ( $tipo ) {
		case 'clique':
			return array_key_exists( $valor, rastro_atribuicao_cliques() ) ? $valor : null;
		case 'host':
			return 1 === preg_match( '/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/D', $valor ) ? $valor : null;
		case 'caminho':
			return 1 === preg_match( '#^/[A-Za-z0-9._~%/+:@!,-]*$#D', $valor ) ? $valor : null;
		case 'texto':
		case 'campanha':
			// Letras e números de qualquer idioma, espaço e poucos sinais. Sem
			// aspas, sem <, >, &, =, ;, @ (e-mail), barra invertida ou controle.
			if ( 1 !== preg_match( '/^[\p{L}\p{N} ._~:\/()+,!|%-]+$/uD', $valor ) || trim( $valor ) !== $valor ) {
				return null;
			}
			// Campanha com oito dígitos seguidos (CPF, telefone, CEP) sai vazia.
			return 'campanha' === $tipo && 1 === preg_match( '/[0-9]{8,}/', $valor ) ? '' : $valor;
	}

	return null;
}

/**
 * Decodifica base64url (alfabeto - e _, sem =) sem base64_decode. null
 * para texto vazio, longo demais ou fora do alfabeto.
 *
 * @param string $texto Texto codificado.
 * @return string|null
 */
function rastro_atribuicao_base64url( string $texto ): ?string {
	$n = strlen( $texto );
	if ( 0 === $n || $n > RASTRO_ORIGEM_MAX || 1 === $n % 4 || 1 !== preg_match( '/^[A-Za-z0-9_-]+$/D', $texto ) ) {
		return null;
	}

	$alfabeto = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
	$saida    = '';
	$buffer   = 0;
	$bits     = 0;
	for ( $i = 0; $i < $n; $i++ ) {
		$buffer = ( $buffer << 6 ) | (int) strpos( $alfabeto, $texto[ $i ] );
		$bits  += 6;
		if ( $bits >= 8 ) {
			$bits  -= 8;
			$saida .= chr( ( $buffer >> $bits ) & 0xFF );
		}
		$buffer &= ( 1 << $bits ) - 1;
	}

	return $saida;
}

/**
 * Valida um toque do cookie (chaves curtas). null quando há chave fora da
 * lista, campo inválido ou falta a fonte.
 *
 * @param mixed $toque Toque decodificado.
 * @return array<string,string>|null Toque com as chaves longas.
 */
function rastro_atribuicao_validar_toque( $toque ): ?array {
	if ( ! is_array( $toque ) ) {
		return null;
	}

	$campos = rastro_atribuicao_campos();
	$limpo  = rastro_atribuicao_toque_vazio();
	foreach ( $toque as $chave => $valor ) {
		if ( ! is_string( $chave ) || ! isset( $campos[ $chave ] ) ) {
			return null;
		}
		$campo = $campos[ $chave ];
		$valor = rastro_atribuicao_validar_valor( $campo[2], $valor, $campo[1] );
		if ( null === $valor ) {
			return null;
		}
		$limpo[ $campo[0] ] = $valor;
	}

	return '' === $limpo['fonte'] ? null : $limpo;
}

/**
 * Lê e valida o valor do cookie rastro_origem. null para qualquer coisa
 * fora do formato (tamanho, base64url, JSON, chaves, valores).
 *
 * @param mixed $bruto Valor do cookie.
 * @return array{primeira:array,ultima:array,consentimento:string}|null
 */
function rastro_atribuicao_ler_cookie( $bruto ): ?array {
	if ( ! is_string( $bruto ) || '' === $bruto || strlen( $bruto ) > RASTRO_ORIGEM_MAX ) {
		return null;
	}

	$json = rastro_atribuicao_base64url( $bruto );
	if ( null === $json ) {
		return null;
	}
	$dado = json_decode( $json, true, 4 );
	if ( ! is_array( $dado ) ) {
		return null;
	}

	foreach ( array_keys( $dado ) as $chave ) {
		if ( ! in_array( $chave, array( 'v', 'p', 'u', 'a', 'o' ), true ) ) {
			return null;
		}
	}
	if ( ! isset( $dado['v'], $dado['p'], $dado['o'] ) || 1 !== $dado['v'] || ! is_string( $dado['o'] ) || ! in_array( $dado['o'], rastro_atribuicao_bases(), true ) ) {
		return null;
	}
	if ( isset( $dado['a'] ) && ! is_int( $dado['a'] ) ) {
		return null;
	}

	$primeira = rastro_atribuicao_validar_toque( $dado['p'] );
	if ( null === $primeira ) {
		return null;
	}
	$ultima = isset( $dado['u'] ) ? rastro_atribuicao_validar_toque( $dado['u'] ) : $primeira;
	if ( null === $ultima ) {
		return null;
	}

	return array(
		'primeira'      => $primeira,
		'ultima'        => $ultima,
		'consentimento' => $dado['o'],
	);
}

/**
 * Valida um toque já gravado na meta (chaves longas). null quando inválido.
 *
 * @param mixed $toque Toque da meta.
 * @return array<string,string>|null
 */
function rastro_atribuicao_validar_toque_meta( $toque ): ?array {
	if ( ! is_array( $toque ) ) {
		return null;
	}

	$limpo = rastro_atribuicao_toque_vazio();
	foreach ( rastro_atribuicao_campos() as $campo ) {
		$valor = rastro_atribuicao_validar_valor( $campo[2], $toque[ $campo[0] ] ?? '', $campo[1] );
		if ( null === $valor ) {
			return null;
		}
		$limpo[ $campo[0] ] = $valor;
	}

	return '' === $limpo['fonte'] ? null : $limpo;
}

/**
 * Motivo da falta de origem no pedido: 'consentimento' quando o modo pede o
 * aceite e o servidor não sabe de aceite de análise; senão 'dados'.
 *
 * @return string
 */
function rastro_atribuicao_motivo_sem(): string {
	if ( 'aceite' !== rastro_config_campo( 'atribuicao' ) || 'basico' === rastro_config_campo( 'modo' ) ) {
		return 'dados';
	}
	if ( function_exists( 'rastro_consent_servidor' ) ) {
		$estado = rastro_consent_servidor();
		if ( is_array( $estado ) && true === ( $estado['analise'] ?? null ) ) {
			return 'dados';
		}
	}

	return 'consentimento';
}

/**
 * Estrutura da meta _rastro_origem (seção 7).
 *
 * @param array|null $origem Resultado de rastro_atribuicao_ler_cookie().
 * @param string     $sem    Motivo quando não há origem (consentimento ou dados).
 * @return array
 */
function rastro_atribuicao_meta( ?array $origem, string $sem = 'dados' ): array {
	if ( null === $origem ) {
		return array(
			'v'   => 1,
			'sem' => 'consentimento' === $sem ? 'consentimento' : 'dados',
		);
	}

	return array(
		'v'             => 1,
		'primeira'      => $origem['primeira'],
		'ultima'        => $origem['ultima'],
		'consentimento' => $origem['consentimento'],
	);
}

/* No pedido ---------------------------------------------------------------- */

/**
 * Grava _rastro_origem no pedido recém-criado (uma vez por pedido). Ligado a
 * woocommerce_checkout_order_created (checkout clássico) e
 * woocommerce_store_api_checkout_order_processed (checkout de blocos).
 * Compatível com HPOS (metas pelo objeto do pedido).
 *
 * @param mixed $pedido Pedido.
 * @return void
 */
function rastro_atribuicao_gravar_pedido( $pedido ): void {
	try {
		if ( ! is_object( $pedido ) || ! ( $pedido instanceof WC_Order ) ) {
			return;
		}
		if ( 'desligada' === rastro_config_campo( 'atribuicao' ) ) {
			return;
		}
		$atual = $pedido->get_meta( RASTRO_ORIGEM_META, true );
		if ( ! empty( $atual ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validado campo a campo em rastro_atribuicao_ler_cookie().
		$bruto  = isset( $_COOKIE[ RASTRO_ORIGEM_COOKIE ] ) ? wp_unslash( $_COOKIE[ RASTRO_ORIGEM_COOKIE ] ) : null;
		$origem = rastro_atribuicao_ler_cookie( $bruto );
		$meta   = rastro_atribuicao_meta( $origem, null === $origem ? rastro_atribuicao_motivo_sem() : 'dados' );

		$pedido->update_meta_data( RASTRO_ORIGEM_META, $meta );
		if ( method_exists( $pedido, 'save_meta_data' ) ) {
			$pedido->save_meta_data();
		} else {
			$pedido->save();
		}
	} catch ( \Throwable $erro ) {
		do_action( 'rastro_falha', 'atribuicao-pedido', $erro );
	}
}
add_action( 'woocommerce_checkout_order_created', 'rastro_atribuicao_gravar_pedido', 20, 1 );
add_action( 'woocommerce_store_api_checkout_order_processed', 'rastro_atribuicao_gravar_pedido', 20, 1 );

/* Leitura unificada -------------------------------------------------------- */

/**
 * Host sem "www." no começo.
 *
 * @param string $host Host.
 * @return string
 */
function rastro_atribuicao_sem_www( string $host ): string {
	return 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
}

/**
 * Host de um endereço (ou de um host solto), validado; '' quando não vale.
 *
 * @param mixed $endereco Endereço ou host.
 * @return string
 */
function rastro_atribuicao_host( $endereco ): string {
	if ( ! is_string( $endereco ) || '' === trim( $endereco ) ) {
		return '';
	}
	$endereco = trim( $endereco );
	$host     = false !== strpos( $endereco, '//' ) ? wp_parse_url( $endereco, PHP_URL_HOST ) : $endereco;
	$host     = is_string( $host ) ? strtolower( $host ) : '';

	return (string) rastro_atribuicao_validar_valor( 'host', $host, 100 );
}

/**
 * Caminho de um endereço (sem query string), validado; '' quando não vale.
 *
 * @param mixed $endereco Endereço completo ou caminho.
 * @return string
 */
function rastro_atribuicao_caminho( $endereco ): string {
	if ( ! is_string( $endereco ) || '' === trim( $endereco ) ) {
		return '';
	}
	$caminho = wp_parse_url( trim( $endereco ), PHP_URL_PATH );
	$caminho = is_string( $caminho ) && '' !== $caminho ? $caminho : '/';

	return (string) rastro_atribuicao_validar_valor( 'caminho', $caminho, 200 );
}

/**
 * Texto de um campo de campanha de outra fonte: valores de "vazio" dos
 * outros plugins viram '', o resto é validado (inválido vira '').
 *
 * @param mixed $valor Valor.
 * @return string
 */
function rastro_atribuicao_texto_externo( $valor ): string {
	if ( ! is_string( $valor ) ) {
		return '';
	}
	$valor = trim( $valor );
	if ( in_array( strtolower( $valor ), array( '', 'undefined', '(none)', '(not set)', 'null' ), true ) ) {
		return '';
	}

	return (string) rastro_atribuicao_validar_valor( 'campanha', $valor, 100 );
}

/**
 * Lê o texto pys_utm do PixelYourSite (utm_source:x|utm_medium:y|...).
 *
 * @param mixed $texto Texto.
 * @return array<string,string> utm_* => valor.
 */
function rastro_atribuicao_pys_utm( $texto ): array {
	$saida = array();
	if ( ! is_string( $texto ) || '' === $texto ) {
		return $saida;
	}
	foreach ( explode( '|', $texto ) as $par ) {
		$partes = explode( ':', $par, 2 );
		if ( 2 !== count( $partes ) ) {
			continue;
		}
		$chave = strtolower( trim( $partes[0] ) );
		if ( in_array( $chave, array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'utm_id' ), true ) ) {
			$saida[ $chave ] = rastro_atribuicao_texto_externo( $partes[1] );
		}
	}

	return $saida;
}

/**
 * Toque a partir da meta pys_enrich_data do PixelYourSite (só leitura).
 * Primeira visita: pys_*; última: last_pys_* (sem eles, os pys_*).
 *
 * @param mixed  $dado   Meta pys_enrich_data.
 * @param string $modelo primeira ou ultima.
 * @return array<string,string>|null
 */
function rastro_atribuicao_de_pys( $dado, string $modelo ): ?array {
	if ( is_string( $dado ) && '' !== $dado ) {
		$dado = json_decode( $dado, true, 4 );
	}
	if ( ! is_array( $dado ) ) {
		return null;
	}

	$prefixo = 'pys_';
	if ( 'ultima' === $modelo && ( ! empty( $dado['last_pys_source'] ) || ! empty( $dado['last_pys_landing'] ) || ! empty( $dado['last_pys_utm'] ) ) ) {
		$prefixo = 'last_pys_';
	}
	$fonte_bruta = isset( $dado[ $prefixo . 'source' ] ) && is_string( $dado[ $prefixo . 'source' ] ) ? strtolower( trim( $dado[ $prefixo . 'source' ] ) ) : '';
	$entrada     = rastro_atribuicao_caminho( $dado[ $prefixo . 'landing' ] ?? '' );
	$utm         = rastro_atribuicao_pys_utm( $dado[ $prefixo . 'utm' ] ?? '' );
	if ( '' === $fonte_bruta && '' === $entrada && array() === array_filter( $utm ) ) {
		return null;
	}

	$toque             = array_merge( rastro_atribuicao_toque_vazio(), $utm );
	$toque['entrada']  = $entrada;
	$toque['referrer'] = 'direct' === $fonte_bruta || 'undefined' === $fonte_bruta ? '' : rastro_atribuicao_host( $fonte_bruta );
	if ( '' !== $toque['utm_source'] ) {
		$toque['fonte'] = strtolower( $toque['utm_source'] );
	} elseif ( '' !== $toque['referrer'] ) {
		$toque['fonte'] = rastro_atribuicao_sem_www( $toque['referrer'] );
	} else {
		$toque['fonte'] = 'direto';
	}

	return $toque;
}

/**
 * Prefixo das metas da atribuição nativa do WooCommerce, com o mesmo filtro
 * que ele usa (src/Internal/Traits/OrderAttributionMeta.php).
 *
 * @return string Ex.: _wc_order_attribution_
 */
function rastro_atribuicao_woo_prefixo(): string {
	$prefixo = (string) apply_filters( 'wc_order_attribution_tracking_field_prefix', 'wc_order_attribution_' );
	$prefixo = trim( $prefixo, '_' );

	return '_' . $prefixo . '_';
}

/**
 * Toque a partir das metas _wc_order_attribution_* (a mesma visita vale
 * para os dois modelos: o WooCommerce guarda só a sessão atual).
 *
 * @param WC_Order $pedido Pedido.
 * @return array<string,string>|null
 */
function rastro_atribuicao_de_woo( $pedido ): ?array {
	$prefixo = rastro_atribuicao_woo_prefixo();
	$ler     = static function ( string $campo ) use ( $pedido, $prefixo ) {
		$valor = $pedido->get_meta( $prefixo . $campo, true );
		return is_string( $valor ) ? trim( $valor ) : '';
	};

	$tipo   = strtolower( $ler( 'source_type' ) );
	$source = $ler( 'utm_source' );
	if ( '' === $tipo && '' === $source ) {
		return null;
	}

	$toque = rastro_atribuicao_toque_vazio();
	foreach ( array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'utm_id' ) as $campo ) {
		$toque[ $campo ] = rastro_atribuicao_texto_externo( $ler( $campo ) );
	}
	if ( '(direct)' === strtolower( $source ) ) {
		$toque['utm_source'] = '';
	}
	$toque['referrer'] = rastro_atribuicao_host( $ler( 'referrer' ) );
	$toque['entrada']  = rastro_atribuicao_caminho( $ler( 'session_entry' ) );

	$inicio = $ler( 'session_start_time' );
	if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $inicio ) ) {
		$momento = strtotime( $inicio . ' UTC' );
		if ( false !== $momento ) {
			$toque['quando'] = gmdate( 'Y-m-d\TH:i:s\Z', $momento );
		}
	}

	if ( 'typein' === $tipo || '(direct)' === strtolower( $source ) ) {
		$toque['fonte'] = 'direto';
	} elseif ( 'admin' === $tipo ) {
		$toque['fonte'] = '(admin)';
	} elseif ( '' !== $toque['utm_source'] ) {
		$toque['fonte'] = rastro_atribuicao_sem_www( strtolower( $toque['utm_source'] ) );
	} elseif ( '' !== $toque['referrer'] ) {
		$toque['fonte'] = rastro_atribuicao_sem_www( $toque['referrer'] );
	} else {
		return null;
	}

	return $toque;
}

/**
 * Origem de um pedido, de onde houver: meta do Rastro, metas do
 * PixelYourSite (pys_enrich_data, só leitura), atribuição nativa do
 * WooCommerce (_wc_order_attribution_*), senão fonte '(sem dados)' (ou
 * '(sem consentimento)' quando o Rastro registrou que faltou o aceite).
 *
 * Chaves, sempre nesta ordem e sempre texto: fonte, utm_source, utm_medium,
 * utm_campaign, utm_content, utm_term, utm_id, clique, referrer, entrada,
 * quando (ISO 8601 em UTC ou ''), origem_dado (rastro, pys, woocommerce ou
 * nenhum).
 *
 * @param WC_Order $pedido Pedido.
 * @param string   $modelo primeira (padrão) ou ultima.
 * @return array<string,string>
 */
function rastro_pedido_origem( WC_Order $pedido, string $modelo = 'primeira' ): array {
	$modelo = 'ultima' === $modelo ? 'ultima' : 'primeira';
	$sem    = 'dados';

	$meta = $pedido->get_meta( RASTRO_ORIGEM_META, true );
	if ( is_array( $meta ) && isset( $meta['v'] ) && 1 === (int) $meta['v'] ) {
		$toque = rastro_atribuicao_validar_toque_meta( $meta[ $modelo ] ?? null );
		if ( null === $toque && 'ultima' === $modelo ) {
			$toque = rastro_atribuicao_validar_toque_meta( $meta['primeira'] ?? null );
		}
		if ( null !== $toque ) {
			return $toque + array( 'origem_dado' => 'rastro' );
		}
		if ( isset( $meta['sem'] ) && 'consentimento' === $meta['sem'] ) {
			$sem = 'consentimento';
		}
	}

	$toque = rastro_atribuicao_de_pys( $pedido->get_meta( 'pys_enrich_data', true ), $modelo );
	if ( null !== $toque ) {
		return $toque + array( 'origem_dado' => 'pys' );
	}

	$toque = rastro_atribuicao_de_woo( $pedido );
	if ( null !== $toque ) {
		return $toque + array( 'origem_dado' => 'woocommerce' );
	}

	$vazio          = rastro_atribuicao_toque_vazio();
	$vazio['fonte'] = 'consentimento' === $sem ? '(sem consentimento)' : '(sem dados)';

	return $vazio + array( 'origem_dado' => 'nenhum' );
}

/* Atribuição nativa do WooCommerce ------------------------------------------- */

/**
 * O WooCommerce instalado tem o controle de consentimento conferido
 * (filtro wc_order_attribution_allow_tracking e
 * window.wc_order_attribution.setOrderTracking, da 8.6.0 em diante)?
 *
 * @return bool
 */
function rastro_atribuicao_woo_disponivel(): bool {
	return rastro_atribuicao_regra_woo( defined( 'WC_VERSION' ) ? (string) WC_VERSION : '' );
}

/**
 * Regra pura: a versão do WooCommerce tem o controle conferido?
 *
 * @param string $versao Versão (ex.: 11.2.2); '' sem WooCommerce.
 * @return bool
 */
function rastro_atribuicao_regra_woo( string $versao ): bool {
	return 1 === preg_match( '/^\d+\.\d+(?:\.\d+)?/', $versao ) && version_compare( $versao, RASTRO_ORIGEM_WOO_MINIMA, '>=' );
}

/**
 * A atribuição nativa espera o aceite de análise? (opção ligada, versão
 * conferida e modo diferente de básico, em que o aceite vale desde a carga)
 *
 * @return bool
 */
function rastro_atribuicao_woo_espera(): bool {
	return rastro_config_ligado( 'atribuicao_woo_consentimento' ) && rastro_atribuicao_woo_disponivel() && 'basico' !== rastro_config_campo( 'modo' );
}

/**
 * wc_order_attribution_allow_tracking: começa negado (HTML igual para
 * todos, cache de página seguro); o atribuicao.js libera no navegador com o
 * aceite de análise.
 *
 * @param mixed $permitir Valor recebido.
 * @return mixed
 */
function rastro_atribuicao_woo_permitir( $permitir ) {
	return rastro_atribuicao_woo_espera() ? false : $permitir;
}
add_filter( 'wc_order_attribution_allow_tracking', 'rastro_atribuicao_woo_permitir', 20, 1 );

/**
 * O wc-order-attribution passa a depender do rastro-atribuicao: o Rastro
 * roda antes e acerta params.allowTracking conforme o aceite já dado, para
 * o WooCommerce não apagar os cookies dele a cada página.
 *
 * @return void
 */
function rastro_atribuicao_woo_dependencia(): void {
	if ( ! function_exists( 'wp_scripts' ) ) {
		return;
	}
	$scripts = wp_scripts();
	if ( ! is_object( $scripts ) || ! isset( $scripts->registered['wc-order-attribution'] ) ) {
		return;
	}
	$item = $scripts->registered['wc-order-attribution'];
	if ( is_object( $item ) && isset( $item->deps ) && is_array( $item->deps ) && ! in_array( 'rastro-atribuicao', $item->deps, true ) ) {
		$item->deps[] = 'rastro-atribuicao';
	}
}

/**
 * Aviso no painel do Rastro quando a opção da atribuição nativa não tem
 * efeito nesta versão do WooCommerce (Protocolo da verdade).
 *
 * @param mixed $avisos Avisos.
 * @return mixed
 */
function rastro_atribuicao_admin_avisos( $avisos ) {
	if ( ! is_array( $avisos ) || ! defined( 'WC_VERSION' ) || rastro_atribuicao_woo_disponivel() || ! rastro_config_ligado( 'atribuicao_woo_consentimento' ) ) {
		return $avisos;
	}
	$avisos[] = array(
		'tipo'  => 'info',
		'texto' => __( 'Atribuição nativa do WooCommerce: indisponível nesta versão do WooCommerce (o controle de consentimento conferido existe a partir da 8.6). A opção fica sem efeito e nada muda na loja.', 'rastro' ),
	);

	return $avisos;
}
add_filter( 'rastro_admin_avisos', 'rastro_atribuicao_admin_avisos', 10, 1 );

/* Navegador ---------------------------------------------------------------- */

/**
 * A loja tem WooCommerce?
 *
 * @return bool
 */
function rastro_atribuicao_tem_woo(): bool {
	return class_exists( 'WooCommerce' ) || function_exists( 'WC' );
}

/**
 * A página leva o atribuicao.js? Loja com WooCommerce, fora de cópia local,
 * de requisição restrita e da equipe, com a atribuição ligada ou a
 * atribuição nativa esperando o aceite.
 *
 * @return bool
 */
function rastro_atribuicao_pagina_ativa(): bool {
	if ( ! rastro_atribuicao_tem_woo() || rastro_ambiente_local() || rastro_requisicao_restrita() || rastro_e_equipe() ) {
		return false;
	}

	return 'desligada' !== rastro_config_campo( 'atribuicao' ) || rastro_atribuicao_woo_espera();
}

/**
 * Configuração da atribuição para o navegador (rastroConfig.atribuicao).
 * Nenhum dado do visitante: só as regras.
 *
 * @return array
 */
function rastro_atribuicao_config_js(): array {
	$modo = (string) rastro_config_campo( 'atribuicao' );

	return array(
		'modo'      => in_array( $modo, array( 'aceite', 'sessao', 'desligada' ), true ) ? $modo : 'aceite',
		'dias'      => max( 1, min( 365, (int) rastro_config_campo( 'atribuicao_dias' ) ) ),
		'utmSempre' => rastro_config_ligado( 'atribuicao_utm_sempre' ),
		'basico'    => 'basico' === rastro_config_campo( 'modo' ),
		'woo'       => rastro_atribuicao_woo_espera(),
		'cookie'    => RASTRO_ORIGEM_COOKIE,
		'max'       => RASTRO_ORIGEM_MAX,
	);
}

/**
 * rastro_config_navegador: acrescenta a chave `atribuicao`.
 *
 * @param mixed $config Configuração.
 * @return mixed
 */
function rastro_atribuicao_navegador( $config ) {
	if ( is_array( $config ) && rastro_atribuicao_pagina_ativa() ) {
		$config['atribuicao'] = rastro_atribuicao_config_js();
	}

	return $config;
}
add_filter( 'rastro_config_navegador', 'rastro_atribuicao_navegador', 10, 1 );

/**
 * rastro_precisa_saida: a configuração sai também quando só a atribuição
 * precisa dela (loja sem tag configurada).
 *
 * @param mixed $precisa Valor recebido.
 * @return mixed
 */
function rastro_atribuicao_precisa_saida( $precisa ) {
	return true === $precisa || rastro_atribuicao_pagina_ativa() ? true : $precisa;
}
add_filter( 'rastro_precisa_saida', 'rastro_atribuicao_precisa_saida', 10, 1 );

/**
 * wp_enqueue_scripts (prioridade 20, depois do WooCommerce e do
 * consentimento): registra e enfileira o atribuicao.js.
 *
 * @return void
 */
function rastro_atribuicao_scripts(): void {
	try {
		if ( ! rastro_atribuicao_pagina_ativa() ) {
			return;
		}
		$deps = array();
		if ( function_exists( 'wp_script_is' ) && wp_script_is( 'rastro-consent', 'registered' ) ) {
			$deps[] = 'rastro-consent';
		}
		if ( rastro_registrar_script( 'rastro-atribuicao', 'atribuicao.js', $deps ) ) {
			wp_enqueue_script( 'rastro-atribuicao' );
			if ( rastro_atribuicao_woo_espera() ) {
				rastro_atribuicao_woo_dependencia();
			}
		}
	} catch ( \Throwable $erro ) {
		do_action( 'rastro_falha', 'atribuicao-scripts', $erro );
	}
}
add_action( 'wp_enqueue_scripts', 'rastro_atribuicao_scripts', 20 );
