<?php
/**
 * Rastro: origem da venda no painel do pedido (contrato, seção 7; manual
 * docs/ATRIBUICAO.md).
 *
 * - Caixa "Origem da venda" (primeira e última visita) na tela do pedido,
 *   clássica (shop_order) e HPOS (woocommerce_page_wc-orders).
 * - Coluna "Origem" na lista de pedidos, clássica e HPOS (os mesmos ganchos
 *   que o WooCommerce usa para a coluna dele).
 *
 * Só para quem tem manage_woocommerce. Tudo escapado. Nada disso aparece
 * para o cliente nem nos e-mails (a meta é protegida e só é lida aqui).
 *
 * @package Rastro
 */

defined( 'ABSPATH' ) || exit;

/** Capacidade para ver a origem do pedido. */
const RASTRO_PEDIDO_CAPACIDADE = 'manage_woocommerce';

/** Chave da coluna na lista de pedidos. */
const RASTRO_PEDIDO_COLUNA = 'rastro_origem';

/**
 * Telas do pedido: a clássica e a do HPOS.
 *
 * @return string[]
 */
function rastro_pedido_telas(): array {
	$hpos = function_exists( 'wc_get_page_screen_id' ) ? (string) wc_get_page_screen_id( 'shop-order' ) : '';
	if ( '' === $hpos || 'shop_order' === $hpos ) {
		$hpos = 'woocommerce_page_wc-orders';
	}

	return array( 'shop_order', $hpos );
}

/**
 * Pedido a partir do que a tela entrega (WP_Post, WC_Order ou ID).
 *
 * @param mixed $item Item.
 * @return WC_Order|null
 */
function rastro_pedido_obter( $item ) {
	if ( is_object( $item ) && $item instanceof WC_Order ) {
		return $item;
	}
	$id = 0;
	if ( is_object( $item ) && isset( $item->ID ) ) {
		$id = (int) $item->ID;
	} elseif ( is_numeric( $item ) ) {
		$id = (int) $item;
	}
	if ( $id <= 0 || ! function_exists( 'wc_get_order' ) ) {
		return null;
	}
	$pedido = wc_get_order( $id );

	return is_object( $pedido ) && $pedido instanceof WC_Order ? $pedido : null;
}

/**
 * Nome legível da fonte do dado.
 *
 * @param string $origem rastro, pys, woocommerce ou nenhum.
 * @return string
 */
function rastro_pedido_rotulo_dado( string $origem ): string {
	$rotulos = array(
		'rastro'      => __( 'Rastro', 'rastro' ),
		'pys'         => __( 'PixelYourSite (pedido antigo)', 'rastro' ),
		'woocommerce' => __( 'Atribuição nativa do WooCommerce', 'rastro' ),
	);

	return $rotulos[ $origem ] ?? __( 'Nenhum registro', 'rastro' );
}

/**
 * Nome legível do ID de clique (só o nome é guardado, nunca o valor).
 *
 * @param string $clique Nome do parâmetro.
 * @return string
 */
function rastro_pedido_rotulo_clique( string $clique ): string {
	$rotulos = array(
		'gclid'   => 'Google Ads',
		'gbraid'  => 'Google Ads',
		'wbraid'  => 'Google Ads',
		'fbclid'  => 'Meta',
		'ttclid'  => 'TikTok',
		'msclkid' => 'Microsoft Ads',
	);

	return isset( $rotulos[ $clique ] ) ? $rotulos[ $clique ] . ' (' . $clique . ')' : $clique;
}

/**
 * Data ISO em UTC formatada no fuso do site.
 *
 * @param string $iso Data ISO.
 * @return string
 */
function rastro_pedido_data( string $iso ): string {
	$momento = '' === $iso ? false : strtotime( $iso );
	if ( false === $momento ) {
		return '';
	}
	if ( function_exists( 'wp_date' ) ) {
		$formato = trim( (string) get_option( 'date_format', 'd/m/Y' ) . ' ' . (string) get_option( 'time_format', 'H:i' ) );
		return (string) wp_date( $formato, $momento );
	}

	return gmdate( 'd/m/Y H:i', $momento );
}

/**
 * Texto curto da origem para a coluna: fonte e meio (ex.: google / cpc).
 *
 * @param array $origem Resultado de rastro_pedido_origem().
 * @return string
 */
function rastro_pedido_resumo( array $origem ): string {
	$fonte = (string) ( $origem['fonte'] ?? '' );
	$meio  = (string) ( $origem['utm_medium'] ?? '' );

	return '' !== $meio ? $fonte . ' / ' . $meio : $fonte;
}

/* Caixa no pedido ------------------------------------------------------------- */

/**
 * add_meta_boxes: a caixa "Origem da venda" nas duas telas do pedido.
 *
 * @return void
 */
function rastro_pedido_caixa_registrar(): void {
	if ( ! current_user_can( RASTRO_PEDIDO_CAPACIDADE ) || ! function_exists( 'add_meta_box' ) ) {
		return;
	}
	foreach ( rastro_pedido_telas() as $tela ) {
		add_meta_box( 'rastro-origem', __( 'Origem da venda', 'rastro' ), 'rastro_pedido_caixa', $tela, 'side', 'default' );
	}
}
add_action( 'add_meta_boxes', 'rastro_pedido_caixa_registrar', 40 );

/**
 * Linhas de um toque na caixa.
 *
 * @param array $toque Toque (rastro_pedido_origem()).
 * @return array<int,array{0:string,1:string}>
 */
function rastro_pedido_linhas( array $toque ): array {
	$linhas = array(
		array( __( 'Fonte', 'rastro' ), (string) $toque['fonte'] ),
		array( __( 'Meio', 'rastro' ), (string) $toque['utm_medium'] ),
		array( __( 'Campanha', 'rastro' ), (string) $toque['utm_campaign'] ),
		array( __( 'Conteúdo', 'rastro' ), (string) $toque['utm_content'] ),
		array( __( 'Termo', 'rastro' ), (string) $toque['utm_term'] ),
		array( __( 'ID da campanha', 'rastro' ), (string) $toque['utm_id'] ),
		array( __( 'Clique de anúncio', 'rastro' ), '' === $toque['clique'] ? '' : rastro_pedido_rotulo_clique( (string) $toque['clique'] ) ),
		array( __( 'Site de origem', 'rastro' ), (string) $toque['referrer'] ),
		array( __( 'Página de entrada', 'rastro' ), (string) $toque['entrada'] ),
		array( __( 'Quando', 'rastro' ), rastro_pedido_data( (string) $toque['quando'] ) ),
	);

	return array_values(
		array_filter(
			$linhas,
			static function ( array $linha ): bool {
				return '' !== $linha[1];
			}
		)
	);
}

/**
 * Conteúdo da caixa "Origem da venda".
 *
 * @param mixed $item WP_Post (tela clássica) ou WC_Order (HPOS).
 * @return void
 */
function rastro_pedido_caixa( $item ): void {
	if ( ! current_user_can( RASTRO_PEDIDO_CAPACIDADE ) ) {
		return;
	}
	$pedido = rastro_pedido_obter( $item );
	if ( null === $pedido ) {
		echo '<p>' . esc_html__( 'Pedido não encontrado.', 'rastro' ) . '</p>';
		return;
	}

	try {
		$primeira = rastro_pedido_origem( $pedido, 'primeira' );
		$ultima   = rastro_pedido_origem( $pedido, 'ultima' );
	} catch ( \Throwable $erro ) {
		do_action( 'rastro_falha', 'atribuicao-caixa', $erro );
		echo '<p>' . esc_html__( 'Não foi possível ler a origem deste pedido.', 'rastro' ) . '</p>';
		return;
	}

	if ( 'nenhum' === $primeira['origem_dado'] ) {
		echo '<p>' . esc_html( (string) $primeira['fonte'] ) . '</p>';
		return;
	}

	foreach (
		array(
			array( __( 'Primeira visita', 'rastro' ), $primeira ),
			array( __( 'Última visita', 'rastro' ), $ultima ),
		) as $bloco
	) {
		echo '<p><strong>' . esc_html( $bloco[0] ) . '</strong></p>';
		echo '<table class="widefat striped rastro-origem"><tbody>';
		foreach ( rastro_pedido_linhas( $bloco[1] ) as $linha ) {
			echo '<tr><th scope="row">' . esc_html( $linha[0] ) . '</th><td>' . esc_html( $linha[1] ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	echo '<p class="description">' . esc_html(
		sprintf(
			/* translators: %s: de onde veio o dado (Rastro, PixelYourSite, WooCommerce). */
			__( 'Dado de: %s', 'rastro' ),
			rastro_pedido_rotulo_dado( (string) $primeira['origem_dado'] )
		)
	) . '</p>';
}

/* Coluna na lista ------------------------------------------------------------- */

/**
 * Acrescenta a coluna "Origem" (depois do total, quando existe).
 *
 * @param mixed $colunas Colunas.
 * @return mixed
 */
function rastro_pedido_colunas( $colunas ) {
	if ( ! is_array( $colunas ) || ! current_user_can( RASTRO_PEDIDO_CAPACIDADE ) ) {
		return $colunas;
	}

	$novas = array();
	foreach ( $colunas as $chave => $titulo ) {
		$novas[ $chave ] = $titulo;
		if ( 'order_total' === $chave ) {
			$novas[ RASTRO_PEDIDO_COLUNA ] = __( 'Origem', 'rastro' );
		}
	}
	if ( ! isset( $novas[ RASTRO_PEDIDO_COLUNA ] ) ) {
		$novas[ RASTRO_PEDIDO_COLUNA ] = __( 'Origem', 'rastro' );
	}

	return $novas;
}

/**
 * Conteúdo da coluna "Origem".
 *
 * @param mixed $coluna Chave da coluna.
 * @param mixed $item   ID do pedido (tela clássica) ou WC_Order (HPOS).
 * @return void
 */
function rastro_pedido_coluna( $coluna, $item = null ): void {
	if ( RASTRO_PEDIDO_COLUNA !== $coluna || ! current_user_can( RASTRO_PEDIDO_CAPACIDADE ) ) {
		return;
	}
	$pedido = rastro_pedido_obter( $item );
	if ( null === $pedido ) {
		return;
	}

	try {
		$origem = rastro_pedido_origem( $pedido, 'primeira' );
	} catch ( \Throwable $erro ) {
		do_action( 'rastro_falha', 'atribuicao-coluna', $erro );
		return;
	}

	echo esc_html( rastro_pedido_resumo( $origem ) );
}

/**
 * admin_init: liga a coluna nas duas listas de pedidos (clássica e HPOS).
 *
 * @return void
 */
function rastro_pedido_registrar_lista(): void {
	foreach ( rastro_pedido_telas() as $tela ) {
		if ( false !== has_action( "manage_{$tela}_custom_column", 'rastro_pedido_coluna' ) ) {
			continue;
		}
		add_filter( "manage_{$tela}_columns", 'rastro_pedido_colunas', 20, 1 );
		add_filter( "manage_edit-{$tela}_columns", 'rastro_pedido_colunas', 20, 1 );
		add_action( "manage_{$tela}_custom_column", 'rastro_pedido_coluna', 20, 2 );
		add_action( "manage_{$tela}_posts_custom_column", 'rastro_pedido_coluna', 20, 2 );
	}
}
add_action( 'admin_init', 'rastro_pedido_registrar_lista' );
