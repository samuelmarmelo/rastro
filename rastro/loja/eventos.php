<?php
/**
 * Rastro: eventos da loja (contrato, seção 6; manual docs/EVENTOS.md).
 *
 * Só com WooCommerce ativo, `eventos_loja` ligado e a página medida
 * (rastro_deve_medir()). Os dados saem montados do PHP em
 * window.rastroConfig.eventos (filtro `rastro_config_navegador`), sem dado
 * pessoal: só catálogo, quantidades, valores, moeda, cupom (só com o filtro
 * `rastro_eventos_enviar_cupom`) e, na compra, o
 * número do pedido como `transaction_id`. O assets/js/eventos.js dispara
 * cada evento com window.rastro.event( nome, params, opcoes ) ou, antes de
 * ela existir, na fila window.rastroFilaEventos.
 *
 * Item no formato GA4: item_id (ID ou SKU, conforme `id_produto`),
 * item_name, price, quantity, item_category (primeira categoria) e
 * item_variant (variação, quando houver). Valores em número com ponto,
 * arredondados em duas casas, sem imposto (como o WooCommerce guarda os
 * itens).
 *
 * Produto composto: o item pai sai como um item só, com o valor dos filhos
 * somado; os filhos (chaves `composite_parent` no carrinho e
 * `_composite_parent` no pedido, do Composite Products) nunca saem. O
 * filtro `rastro_eventos_chaves_filho` acrescenta outros tipos de filho.
 *
 * Compra: só na página de pedido recebido, depois de conferir a chave do
 * pedido da URL ($order->key_is_valid()), só com status processing,
 * completed ou on-hold (filtro `rastro_eventos_status_compra`; Pix
 * `pending` fica para a T7), uma vez por pedido (trava curta e meta
 * `_rastro_compra_enviada`, gravada com update_meta_data() e
 * save_meta_data(), compatível com HPOS).
 *
 * SafeWidget: qualquer erro vira a ação `rastro_falha` e a página segue sem
 * os eventos.
 *
 * @package Rastro
 */

defined( 'ABSPATH' ) || exit;

/** Meta do pedido que marca a compra já impressa (data ISO 8601). */
const RASTRO_META_COMPRA = '_rastro_compra_enviada';

/** Máximo de itens do view_item_list. */
const RASTRO_EVENTOS_MAX_LISTA = 20;

/** Máximo de variações levadas para a página do produto. */
const RASTRO_EVENTOS_MAX_VARIACOES = 50;

/** Prefixo da opção que trava a marcação da compra (mais o ID do pedido). */
const RASTRO_EVENTOS_TRAVA = 'rastro_compra_trava_';

/** Trava mais velha que isso (segundos) é de uma requisição que morreu. */
const RASTRO_EVENTOS_TRAVA_PRAZO = 60;

/* Liga e desliga ---------------------------------------------------------- */

/**
 * O WooCommerce está ativo (com as funções que os eventos usam)?
 *
 * @return bool
 */
function rastro_eventos_woo_ativo(): bool {
	return function_exists( 'WC' ) && function_exists( 'wc_get_product' );
}

/**
 * Os eventos da loja estão ligados? (WooCommerce ativo e `eventos_loja`)
 *
 * @return bool
 */
function rastro_eventos_ativo(): bool {
	return rastro_eventos_woo_ativo() && rastro_config_ligado( 'eventos_loja' );
}

/**
 * Um evento canônico está ligado? (chave `evento_<nome>` do esquema)
 *
 * @param string $evento Nome canônico (ex.: purchase).
 * @return bool
 */
function rastro_eventos_ligado( string $evento ): bool {
	return rastro_eventos_ativo()
		&& array_key_exists( $evento, rastro_config_eventos() )
		&& rastro_config_ligado( 'evento_' . $evento );
}

/**
 * Eventos ligados, na ordem do esquema.
 *
 * @return string[]
 */
function rastro_eventos_ligados(): array {
	if ( ! rastro_eventos_ativo() ) {
		return array();
	}

	return array_values( array_filter( array_keys( rastro_config_eventos() ), 'rastro_eventos_ligado' ) );
}

/**
 * Modo do item_id: `id` ou `sku`.
 *
 * @return string
 */
function rastro_eventos_modo_id(): string {
	return 'sku' === rastro_config_campo( 'id_produto' ) ? 'sku' : 'id';
}

/**
 * Modo do valor da compra: `produtos` (só os itens, com desconto) ou `total`.
 *
 * @return string
 */
function rastro_eventos_modo_valor(): string {
	return 'total' === rastro_config_campo( 'valor_compra' ) ? 'total' : 'produtos';
}

/* Regras puras ------------------------------------------------------------ */

/**
 * Valor em número com ponto e duas casas (negativo e inválido viram zero).
 *
 * @param mixed $valor Valor.
 * @return float
 */
function rastro_eventos_valor( $valor ): float {
	$numero = is_numeric( $valor ) ? (float) $valor : 0.0;
	if ( ! is_finite( $numero ) || $numero < 0 ) {
		$numero = 0.0;
	}

	return round( $numero, 2 );
}

/**
 * Texto de catálogo pronto para sair: sem HTML, sem entidades, sem espaço
 * repetido, com tamanho máximo.
 *
 * @param mixed $texto  Texto.
 * @param int   $maximo Tamanho máximo.
 * @return string
 */
function rastro_eventos_texto( $texto, int $maximo = 100 ): string {
	if ( ! is_scalar( $texto ) ) {
		return '';
	}
	// Entidades primeiro: "&lt;b&gt;" decodificado também perde a tag.
	$texto = wp_strip_all_tags( html_entity_decode( wp_strip_all_tags( (string) $texto ), ENT_QUOTES, 'UTF-8' ) );
	$texto = trim( (string) preg_replace( '/[\x00-\x1F\x7F\s]+/u', ' ', $texto ) );

	return function_exists( 'mb_substr' ) ? mb_substr( $texto, 0, $maximo, 'UTF-8' ) : substr( $texto, 0, $maximo );
}

/**
 * Moeda em três letras maiúsculas (vazio quando inválida).
 *
 * @param mixed $moeda Moeda.
 * @return string
 */
function rastro_eventos_moeda( $moeda ): string {
	$moeda = strtoupper( (string) preg_replace( '/[^A-Za-z]/', '', is_scalar( $moeda ) ? (string) $moeda : '' ) );

	return 3 === strlen( $moeda ) ? $moeda : '';
}

/**
 * Regra pura: item no formato GA4 a partir dos dados do catálogo.
 *
 * @param array  $d {
 *     @type int    $id         ID do produto ou da variação.
 *     @type string $sku        SKU.
 *     @type string $nome       Nome.
 *     @type mixed  $preco      Preço unitário.
 *     @type mixed  $quantidade Quantidade.
 *     @type string $categoria  Primeira categoria.
 *     @type string $variante   Variação.
 * }
 * @param string $modo `id` ou `sku` (SKU vazio cai no ID).
 * @return array|null Null sem ID nem nome.
 */
function rastro_eventos_item_regra( array $d, string $modo = 'id' ): ?array {
	$id   = isset( $d['id'] ) && is_numeric( $d['id'] ) ? (int) $d['id'] : 0;
	$sku  = rastro_eventos_texto( isset( $d['sku'] ) ? $d['sku'] : '', 64 );
	$nome = rastro_eventos_texto( isset( $d['nome'] ) ? $d['nome'] : '' );
	if ( $id <= 0 && '' === $nome ) {
		return null;
	}

	$item_id = ( 'sku' === $modo && '' !== $sku ) ? $sku : ( $id > 0 ? (string) $id : $sku );
	$qtd     = isset( $d['quantidade'] ) && is_numeric( $d['quantidade'] ) ? (int) round( (float) $d['quantidade'] ) : 1;

	$item = array(
		'item_id'   => $item_id,
		'item_name' => $nome,
		'price'     => rastro_eventos_valor( isset( $d['preco'] ) ? $d['preco'] : 0 ),
		'quantity'  => max( 1, $qtd ),
	);
	$categoria = rastro_eventos_texto( isset( $d['categoria'] ) ? $d['categoria'] : '' );
	if ( '' !== $categoria ) {
		$item['item_category'] = $categoria;
	}
	$variante = rastro_eventos_texto( isset( $d['variante'] ) ? $d['variante'] : '' );
	if ( '' !== $variante ) {
		$item['item_variant'] = $variante;
	}

	return $item;
}

/**
 * Regra pura: soma dos itens (preço vezes quantidade), em duas casas.
 *
 * @param array $itens Itens GA4.
 * @return float
 */
function rastro_eventos_soma_itens( array $itens ): float {
	$centavos = 0;
	foreach ( $itens as $item ) {
		if ( is_array( $item ) && isset( $item['price'], $item['quantity'] ) ) {
			$centavos += (int) round( (float) $item['price'] * 100 ) * (int) $item['quantity'];
		}
	}

	return rastro_eventos_valor( $centavos / 100 );
}

/**
 * Regra pura: parâmetros do purchase.
 *
 * @param array  $d {
 *     @type string   $numero   Número do pedido (transaction_id).
 *     @type string   $moeda    Moeda.
 *     @type mixed    $total    Total do pedido (com frete e impostos).
 *     @type mixed    $produtos Soma dos itens, com desconto e sem imposto.
 *     @type mixed    $imposto  Imposto total.
 *     @type mixed    $frete    Frete (sem imposto).
 *     @type string[] $cupons   Códigos de cupom.
 *     @type array    $itens    Itens GA4.
 * }
 * @param string $modo `produtos` ou `total`.
 * @return array
 */
function rastro_eventos_compra_regra( array $d, string $modo = 'produtos' ): array {
	$params = array(
		'transaction_id' => rastro_eventos_texto( isset( $d['numero'] ) ? $d['numero'] : '', 64 ),
		'value'          => rastro_eventos_valor( 'total' === $modo ? ( $d['total'] ?? 0 ) : ( $d['produtos'] ?? 0 ) ),
		'currency'       => rastro_eventos_moeda( $d['moeda'] ?? '' ),
		'tax'            => rastro_eventos_valor( $d['imposto'] ?? 0 ),
		'shipping'       => rastro_eventos_valor( $d['frete'] ?? 0 ),
	);
	$cupom = rastro_eventos_cupons( isset( $d['cupons'] ) && is_array( $d['cupons'] ) ? $d['cupons'] : array() );
	if ( '' !== $cupom ) {
		$params['coupon'] = $cupom;
	}
	$params['items'] = isset( $d['itens'] ) && is_array( $d['itens'] ) ? array_values( $d['itens'] ) : array();

	return $params;
}

/**
 * Códigos de cupom num texto só, separados por vírgula.
 *
 * @param array $cupons Códigos.
 * @return string
 */
function rastro_eventos_cupons( array $cupons ): string {
	$limpos = array();
	foreach ( $cupons as $cupom ) {
		$cupom = rastro_eventos_texto( $cupom, 50 );
		if ( '' !== $cupom && ! in_array( $cupom, $limpos, true ) ) {
			$limpos[] = $cupom;
		}
	}

	return implode( ',', $limpos );
}

/**
 * Regra pura: o status do pedido gera compra? (processing, completed,
 * on-hold; com ou sem o prefixo wc-)
 *
 * @param string $status Status.
 * @return bool
 */
function rastro_eventos_status_compra( string $status ): bool {
	$status = 0 === strpos( $status, 'wc-' ) ? substr( $status, 3 ) : $status;

	return in_array( $status, rastro_eventos_status_aceitos(), true );
}

/**
 * Status que geram compra. Padrão: processing, completed e on-hold. Pedido
 * Pix que fica `pending` no pedido recebido não gera compra pelo navegador
 * (a T7, compra pelo servidor, cobre); o filtro `rastro_eventos_status_compra`
 * acrescenta ou tira status. Falho, cancelado, reembolsado, rascunho e
 * lixeira nunca valem.
 *
 * @return string[]
 */
function rastro_eventos_status_aceitos(): array {
	$padrao = array( 'processing', 'completed', 'on-hold' );

	/**
	 * Status do pedido que geram a compra no pedido recebido.
	 *
	 * @param string[] $status Padrão processing, completed, on-hold (sem o prefixo wc-).
	 */
	$lista = apply_filters( 'rastro_eventos_status_compra', $padrao );
	if ( ! is_array( $lista ) ) {
		return $padrao;
	}

	$limpos = array();
	foreach ( $lista as $status ) {
		if ( ! is_string( $status ) ) {
			continue;
		}
		$status = 0 === strpos( $status, 'wc-' ) ? substr( $status, 3 ) : $status;
		if ( 1 === preg_match( '/^[a-z0-9_-]{1,40}$/D', $status ) && ! in_array( $status, array( 'failed', 'cancelled', 'refunded', 'checkout-draft', 'trash' ), true ) && ! in_array( $status, $limpos, true ) ) {
			$limpos[] = $status;
		}
	}

	return $limpos;
}

/**
 * Códigos de cupom saem nos eventos? Padrão não: um cupom pode ser pessoal
 * (ex.: o nome ou o e-mail de quem ganhou). O filtro
 * `rastro_eventos_enviar_cupom` devolvendo true liga.
 *
 * @return bool
 */
function rastro_eventos_enviar_cupom(): bool {
	/**
	 * Envia os códigos de cupom (`coupon`) no begin_checkout,
	 * add_payment_info e purchase.
	 *
	 * @param bool $enviar Padrão false.
	 */
	return true === apply_filters( 'rastro_eventos_enviar_cupom', false );
}

/**
 * Regra pura: termo de busca pronto para sair. Vazio quando pode ser dado
 * pessoal (tem arroba ou oito dígitos ou mais, como CPF, telefone ou CEP).
 *
 * @param mixed $termo Termo digitado.
 * @return string
 */
function rastro_eventos_termo_busca( $termo ): string {
	$termo = rastro_eventos_texto( $termo, 100 );
	if ( '' === $termo || false !== strpos( $termo, '@' ) ) {
		return '';
	}
	if ( strlen( (string) preg_replace( '/\D+/', '', $termo ) ) >= 8 ) {
		return '';
	}

	return $termo;
}

/**
 * Chaves de item filho (o filho nunca sai; o valor dele soma no pai):
 * chave do item no carrinho => meta do pai no pedido com a chave do pai.
 * Padrão: Composite Products (`composite_parent` aponta para a chave do
 * pai; no pedido, `_composite_parent` aponta para `_composite_cart_key`).
 *
 * @return array<string,string>
 */
function rastro_eventos_chaves_filho(): array {
	$padrao = array( 'composite_parent' => '_composite_cart_key' );

	/**
	 * Acrescenta tipos de item filho (produto composto, pacote...).
	 *
	 * @param array $chaves chave do filho no carrinho => meta da chave do pai no pedido.
	 */
	$chaves = apply_filters( 'rastro_eventos_chaves_filho', $padrao );
	if ( ! is_array( $chaves ) ) {
		return $padrao;
	}
	$limpas = array();
	foreach ( $chaves as $filho => $meta_pai ) {
		if ( is_string( $filho ) && is_string( $meta_pai ) && 1 === preg_match( '/^[a-z0-9_]{1,40}$/D', $filho ) && 1 === preg_match( '/^_[a-z0-9_]{1,40}$/D', $meta_pai ) ) {
			$limpas[ $filho ] = $meta_pai;
		}
	}

	return $limpas;
}

/**
 * Regra pura: chave do pai de um item do carrinho ('' quando não é filho).
 *
 * @param array $item Item do carrinho.
 * @return string
 */
function rastro_eventos_pai_carrinho( array $item ): string {
	foreach ( array_keys( rastro_eventos_chaves_filho() ) as $chave ) {
		if ( ! empty( $item[ $chave ] ) && is_scalar( $item[ $chave ] ) ) {
			return (string) $item[ $chave ];
		}
	}

	return '';
}

/* Catálogo ---------------------------------------------------------------- */

/**
 * Primeira categoria do produto ('' sem categoria).
 *
 * @param int $produto_id ID do produto (o pai, numa variação).
 * @return string
 */
function rastro_eventos_categoria( int $produto_id ): string {
	if ( $produto_id <= 0 ) {
		return '';
	}
	$nomes = function_exists( 'wc_get_product_terms' ) ? wc_get_product_terms( $produto_id, 'product_cat', array( 'fields' => 'names' ) ) : array();

	return is_array( $nomes ) && array() !== $nomes ? rastro_eventos_texto( reset( $nomes ) ) : '';
}

/**
 * Texto da variação (ex.: "Azul, G").
 *
 * @param object $variacao Variação.
 * @return string
 */
function rastro_eventos_variante( $variacao ): string {
	if ( function_exists( 'wc_get_formatted_variation' ) ) {
		$texto = wc_get_formatted_variation( $variacao, true, false, false );
		if ( is_string( $texto ) && '' !== trim( $texto ) ) {
			return rastro_eventos_texto( $texto );
		}
	}
	if ( is_object( $variacao ) && method_exists( $variacao, 'get_attribute_summary' ) ) {
		return rastro_eventos_texto( $variacao->get_attribute_summary() );
	}

	return '';
}

/**
 * Preço unitário do produto, sem imposto.
 *
 * @param object $produto Produto.
 * @return float
 */
function rastro_eventos_preco_produto( $produto ): float {
	if ( function_exists( 'wc_get_price_excluding_tax' ) ) {
		return rastro_eventos_valor( wc_get_price_excluding_tax( $produto ) );
	}

	return rastro_eventos_valor( method_exists( $produto, 'get_price' ) ? $produto->get_price() : 0 );
}

/**
 * É um produto do WooCommerce? (objeto com os métodos que os eventos usam)
 *
 * @param mixed $produto Produto.
 * @return bool
 */
function rastro_eventos_e_produto( $produto ): bool {
	return is_object( $produto ) && method_exists( $produto, 'get_id' ) && method_exists( $produto, 'get_name' ) && method_exists( $produto, 'get_sku' );
}

/**
 * Item GA4 de um produto (ou variação) do WooCommerce.
 *
 * @param mixed      $produto    Produto.
 * @param int        $quantidade Quantidade.
 * @param float|null $preco      Preço unitário (null: o do catálogo, sem imposto).
 * @return array|null
 */
function rastro_eventos_item_produto( $produto, int $quantidade = 1, ?float $preco = null ): ?array {
	if ( ! rastro_eventos_e_produto( $produto ) ) {
		return null;
	}

	$id       = (int) $produto->get_id();
	$pai_id   = method_exists( $produto, 'get_parent_id' ) ? (int) $produto->get_parent_id() : 0;
	$variacao = $pai_id > 0 && method_exists( $produto, 'is_type' ) && $produto->is_type( 'variation' );
	$pai      = $variacao ? wc_get_product( $pai_id ) : null;

	return rastro_eventos_item_regra(
		array(
			'id'         => $id,
			'sku'        => (string) $produto->get_sku(),
			'nome'       => rastro_eventos_e_produto( $pai ) ? $pai->get_name() : $produto->get_name(),
			'preco'      => null === $preco ? rastro_eventos_preco_produto( $produto ) : $preco,
			'quantidade' => $quantidade,
			'categoria'  => rastro_eventos_categoria( $variacao ? $pai_id : $id ),
			'variante'   => $variacao ? rastro_eventos_variante( $produto ) : '',
		),
		rastro_eventos_modo_id()
	);
}

/* Carrinho ---------------------------------------------------------------- */

/**
 * Itens do carrinho no formato GA4, por chave do item. Filhos de produto
 * composto não saem; o valor deles soma no pai. Preço unitário = total da
 * linha (com desconto, sem imposto) dividido pela quantidade.
 *
 * @param array $conteudo Itens de WC()->cart->get_cart().
 * @return array<string,array>
 */
function rastro_eventos_itens_carrinho( array $conteudo ): array {
	$extra = array();
	foreach ( $conteudo as $item ) {
		if ( ! is_array( $item ) ) {
			continue;
		}
		$pai = rastro_eventos_pai_carrinho( $item );
		if ( '' !== $pai ) {
			$extra[ $pai ] = ( $extra[ $pai ] ?? 0.0 ) + (float) ( $item['line_total'] ?? 0 );
		}
	}

	$itens = array();
	foreach ( $conteudo as $chave => $item ) {
		if ( ! is_array( $item ) || '' !== rastro_eventos_pai_carrinho( $item ) || empty( $item['data'] ) ) {
			continue;
		}
		$qtd   = max( 1, (int) ( $item['quantity'] ?? 1 ) );
		$total = (float) ( $item['line_total'] ?? 0 ) + ( $extra[ (string) $chave ] ?? 0.0 );
		$dado  = rastro_eventos_item_produto( $item['data'], $qtd, $total / $qtd );
		if ( null !== $dado ) {
			$itens[ (string) $chave ] = $dado;
		}
	}

	return $itens;
}

/**
 * Carrinho atual: itens por chave, valor, moeda, cupom e assinatura (muda
 * quando o conteúdo muda). Null com o carrinho vazio ou indisponível.
 *
 * @return array|null
 */
function rastro_eventos_carrinho(): ?array {
	$woo = WC();
	if ( ! is_object( $woo ) || ! isset( $woo->cart ) || ! is_object( $woo->cart ) || ! method_exists( $woo->cart, 'get_cart' ) ) {
		return null;
	}

	$conteudo = $woo->cart->get_cart();
	$itens    = is_array( $conteudo ) ? rastro_eventos_itens_carrinho( $conteudo ) : array();
	if ( array() === $itens ) {
		return null;
	}

	$assinatura = array();
	foreach ( $itens as $chave => $item ) {
		$assinatura[] = $chave . ':' . $item['quantity'];
	}
	sort( $assinatura );

	$params = array(
		'currency' => rastro_eventos_moeda( get_woocommerce_currency() ),
		'value'    => rastro_eventos_soma_itens( $itens ),
	);
	$cupom  = rastro_eventos_enviar_cupom() && method_exists( $woo->cart, 'get_applied_coupons' ) ? rastro_eventos_cupons( (array) $woo->cart->get_applied_coupons() ) : '';
	if ( '' !== $cupom ) {
		$params['coupon'] = $cupom;
	}
	$params['items'] = array_values( $itens );

	return array(
		'itens'      => $itens,
		'params'     => $params,
		'assinatura' => substr( md5( implode( '|', $assinatura ) ), 0, 12 ),
	);
}

/**
 * Leva o carrinho para toda página medida? Padrão: só no carrinho e no
 * checkout (fora deles a página pode estar em cache e o HTML precisa ser
 * igual para todo visitante). Um site cujo minicart já varia por visitante
 * liga o filtro para o remove_from_cart do minicart ter os dados do item.
 *
 * @return bool
 */
function rastro_eventos_carrinho_em_toda_pagina(): bool {
	/**
	 * Leva os itens do carrinho para toda página medida.
	 *
	 * @param bool $toda Padrão false.
	 */
	return true === apply_filters( 'rastro_eventos_carrinho_em_toda_pagina', false );
}

/* Listas, produto e busca ---------------------------------------------------- */

/**
 * Produtos da consulta principal (arquivo da loja, categoria, busca), até
 * o máximo do view_item_list.
 *
 * @return array Produtos.
 */
function rastro_eventos_produtos_consulta(): array {
	$consulta = isset( $GLOBALS['wp_query'] ) && is_object( $GLOBALS['wp_query'] ) ? $GLOBALS['wp_query'] : null;
	$posts    = $consulta && isset( $consulta->posts ) && is_array( $consulta->posts ) ? $consulta->posts : array();

	$produtos = array();
	foreach ( $posts as $post ) {
		if ( count( $produtos ) >= RASTRO_EVENTOS_MAX_LISTA ) {
			break;
		}
		if ( is_object( $post ) && isset( $post->post_type ) && 'product' !== $post->post_type ) {
			continue;
		}
		$produto = wc_get_product( $post );
		if ( rastro_eventos_e_produto( $produto ) ) {
			$produtos[] = $produto;
		}
	}

	return $produtos;
}

/**
 * Identificação da lista da página: item_list_id e item_list_name.
 *
 * @param string $tipo Tipo da página (loja, categoria, busca).
 * @return array
 */
function rastro_eventos_lista_nome( string $tipo ): array {
	if ( 'categoria' === $tipo ) {
		$termo = function_exists( 'get_queried_object' ) ? get_queried_object() : null;
		$nome  = is_object( $termo ) && isset( $termo->name ) ? rastro_eventos_texto( $termo->name ) : '';

		return array(
			'item_list_id'   => 'categoria',
			'item_list_name' => '' !== $nome ? $nome : 'Categoria',
		);
	}

	return 'busca' === $tipo
		? array(
			'item_list_id'   => 'busca',
			'item_list_name' => 'Busca',
		)
		: array(
			'item_list_id'   => 'loja',
			'item_list_name' => 'Loja',
		);
}

/**
 * Produto da página de produto, com as variações (até o máximo) e os
 * filhos de um produto agrupado.
 *
 * @return array|null { produto: item, id: int, variacoes: array, filhos: array }
 */
function rastro_eventos_produto_pagina(): ?array {
	$id      = function_exists( 'get_queried_object_id' ) ? (int) get_queried_object_id() : 0;
	$produto = $id > 0 ? wc_get_product( $id ) : null;
	$item    = rastro_eventos_item_produto( $produto );
	if ( null === $item ) {
		return null;
	}

	$variacoes = array();
	$filhos    = array();
	$tipo      = method_exists( $produto, 'get_type' ) ? (string) $produto->get_type() : '';
	if ( in_array( $tipo, array( 'variable', 'grouped' ), true ) && method_exists( $produto, 'get_children' ) ) {
		foreach ( array_slice( (array) $produto->get_children(), 0, RASTRO_EVENTOS_MAX_VARIACOES ) as $filho_id ) {
			$filho      = wc_get_product( (int) $filho_id );
			$filho_item = rastro_eventos_item_produto( $filho );
			if ( null === $filho_item ) {
				continue;
			}
			if ( 'variable' === $tipo ) {
				$variacoes[ (string) (int) $filho_id ] = $filho_item;
			} else {
				$filhos[ (string) (int) $filho_id ] = $filho_item;
			}
		}
	}

	return array(
		'id'        => (int) $produto->get_id(),
		'produto'   => $item,
		'variacoes' => $variacoes,
		'filhos'    => $filhos,
	);
}

/* Compra -------------------------------------------------------------------- */

/**
 * Itens GA4 de um pedido. Filhos de produto composto não saem; o valor
 * deles soma no pai. Preço unitário = total da linha (com desconto, sem
 * imposto) dividido pela quantidade.
 *
 * @param object $pedido Pedido.
 * @return array{itens:array,produtos:float}
 */
function rastro_eventos_itens_pedido( $pedido ): array {
	$linhas = method_exists( $pedido, 'get_items' ) ? $pedido->get_items() : array();
	$linhas = is_array( $linhas ) ? $linhas : array();
	$chaves = rastro_eventos_chaves_filho();

	$extra    = array();
	$soma     = 0.0;
	$filho_de = array();
	foreach ( $linhas as $id_linha => $linha ) {
		if ( ! is_object( $linha ) || ! method_exists( $linha, 'get_total' ) ) {
			continue;
		}
		$soma += (float) $linha->get_total();
		foreach ( $chaves as $filho => $meta_pai ) {
			$pai = method_exists( $linha, 'get_meta' ) ? $linha->get_meta( '_' . $filho, true ) : '';
			if ( is_scalar( $pai ) && '' !== (string) $pai ) {
				$filho_de[ $id_linha ]               = true;
				$extra[ $meta_pai . '|' . $pai ] = ( $extra[ $meta_pai . '|' . $pai ] ?? 0.0 ) + (float) $linha->get_total();
				break;
			}
		}
	}

	$itens = array();
	foreach ( $linhas as $id_linha => $linha ) {
		if ( ! is_object( $linha ) || ! method_exists( $linha, 'get_total' ) || isset( $filho_de[ $id_linha ] ) ) {
			continue;
		}
		$qtd   = max( 1, (int) ( method_exists( $linha, 'get_quantity' ) ? $linha->get_quantity() : 1 ) );
		$total = (float) $linha->get_total();
		foreach ( $chaves as $meta_pai ) {
			$minha = method_exists( $linha, 'get_meta' ) ? $linha->get_meta( $meta_pai, true ) : '';
			if ( is_scalar( $minha ) && '' !== (string) $minha ) {
				$total += $extra[ $meta_pai . '|' . $minha ] ?? 0.0;
			}
		}

		$produto = method_exists( $linha, 'get_product' ) ? $linha->get_product() : null;
		$item    = rastro_eventos_item_produto( $produto, $qtd, $total / $qtd );
		if ( null === $item ) {
			// Produto apagado: o que o próprio item do pedido guarda.
			$variacao_id = method_exists( $linha, 'get_variation_id' ) ? (int) $linha->get_variation_id() : 0;
			$item        = rastro_eventos_item_regra(
				array(
					'id'         => $variacao_id > 0 ? $variacao_id : ( method_exists( $linha, 'get_product_id' ) ? (int) $linha->get_product_id() : 0 ),
					'nome'       => method_exists( $linha, 'get_name' ) ? $linha->get_name() : '',
					'preco'      => $total / $qtd,
					'quantidade' => $qtd,
				),
				'id'
			);
		}
		if ( null !== $item ) {
			$itens[] = $item;
		}
	}

	return array(
		'itens'    => $itens,
		'produtos' => rastro_eventos_valor( $soma ),
	);
}

/**
 * Pedido da página de pedido recebido, só depois de conferir a chave da
 * URL (como o WooCommerce faz). Null sem número, sem chave, com chave
 * errada ou quando não é um pedido.
 *
 * @return object|null
 */
function rastro_eventos_pedido_da_url() {
	$numero = function_exists( 'get_query_var' ) ? absint( get_query_var( 'order-received', 0 ) ) : 0;
	/** Mesmo filtro do WooCommerce para o número do pedido recebido. */
	$numero = absint( apply_filters( 'woocommerce_thankyou_order_id', $numero ) );
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- chave do pedido conferida com key_is_valid() logo abaixo.
	$chave = isset( $_GET['key'] ) && is_string( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
	/** Mesmo filtro do WooCommerce para a chave do pedido recebido. */
	$chave = (string) apply_filters( 'woocommerce_thankyou_order_key', $chave );
	if ( $numero <= 0 || '' === $chave || ! function_exists( 'wc_get_order' ) ) {
		return null;
	}

	$pedido = wc_get_order( $numero );
	if ( ! class_exists( 'WC_Order' ) || ! $pedido instanceof WC_Order || ! $pedido->key_is_valid( $chave ) ) {
		return null;
	}
	if ( (int) $pedido->get_id() !== $numero ) {
		return null;
	}

	return $pedido;
}

/**
 * Compra do pedido recebido: parâmetros e opções (event_id), uma vez por
 * pedido. Na primeira impressão grava a meta `_rastro_compra_enviada`; na
 * recarga devolve null. Só com status processing, completed ou on-hold.
 *
 * @return array|null { params: array, opcoes: array }
 */
function rastro_eventos_compra(): ?array {
	$pedido = rastro_eventos_pedido_da_url();
	if ( null === $pedido ) {
		return null;
	}
	if ( ! $pedido->has_status( rastro_eventos_status_aceitos() ) ) {
		return null;
	}
	if ( '' !== (string) $pedido->get_meta( RASTRO_META_COMPRA, true ) ) {
		return null;
	}

	// Duas abas (ou uma recarga rápida) no mesmo pedido: só uma marca a compra.
	$trava = RASTRO_EVENTOS_TRAVA . (int) $pedido->get_id();
	if ( ! rastro_eventos_travar( $trava ) ) {
		return null;
	}
	try {
		// Lida de novo do banco, sem o cache: outra requisição pode ter marcado.
		if ( method_exists( $pedido, 'read_meta_data' ) ) {
			$pedido->read_meta_data( true );
		}
		if ( '' !== (string) $pedido->get_meta( RASTRO_META_COMPRA, true ) ) {
			return null;
		}

		return rastro_eventos_marcar_compra( $pedido );
	} finally {
		delete_option( $trava );
	}
}

/**
 * Trava curta de uma compra: add_option() sem carga automática com a hora.
 * Trava de mais de 60 segundos (requisição que morreu) é trocada. Reduz a
 * janela de duas marcações, mas não é atômica: o add_option() do WordPress
 * usa INSERT ... ON DUPLICATE KEY UPDATE depois de conferir a opção.
 *
 * @param string $nome Nome da opção.
 * @return bool A trava é desta requisição.
 */
function rastro_eventos_travar( string $nome ): bool {
	if ( add_option( $nome, time(), '', false ) ) {
		return true;
	}
	$desde = (int) get_option( $nome, 0 );
	if ( $desde > 0 && time() - $desde < RASTRO_EVENTOS_TRAVA_PRAZO ) {
		return false;
	}
	delete_option( $nome );

	return add_option( $nome, time(), '', false );
}

/**
 * Monta a compra do pedido e grava a meta `_rastro_compra_enviada` (com a
 * trava já tomada).
 *
 * @param object $pedido Pedido.
 * @return array { params: array, opcoes: array }
 */
function rastro_eventos_marcar_compra( $pedido ): array {
	$itens  = rastro_eventos_itens_pedido( $pedido );
	$params = rastro_eventos_compra_regra(
		array(
			'numero'   => (string) $pedido->get_order_number(),
			'moeda'    => $pedido->get_currency(),
			'total'    => $pedido->get_total(),
			'produtos' => $itens['produtos'],
			'imposto'  => $pedido->get_total_tax(),
			'frete'    => $pedido->get_shipping_total(),
			'cupons'   => rastro_eventos_enviar_cupom() && method_exists( $pedido, 'get_coupon_codes' ) ? (array) $pedido->get_coupon_codes() : array(),
			'itens'    => $itens['itens'],
		),
		rastro_eventos_modo_valor()
	);

	// Marca antes de imprimir: a recarga da página nunca repete a compra.
	$pedido->update_meta_data( RASTRO_META_COMPRA, gmdate( 'c' ) );
	$pedido->save_meta_data();

	return array(
		'params' => $params,
		'opcoes' => array( 'event_id' => 'rastro-' . (int) $pedido->get_id() ),
	);
}

/* Montagem para o navegador --------------------------------------------------- */

/**
 * Memória da requisição (a compra é lida e marcada uma vez só, mesmo que a
 * configuração do navegador seja montada mais de uma vez).
 *
 * @return array{dados:?array}
 */
function &rastro_eventos_memoria(): array {
	static $memoria = array( 'dados' => null );

	return $memoria;
}

/**
 * Esquece os dados desta requisição (testes).
 *
 * @return void
 */
function rastro_eventos_redefinir_cache(): void {
	$memoria          = &rastro_eventos_memoria();
	$memoria['dados'] = null;
}

/**
 * Dados dos eventos desta página (rastroConfig.eventos). Vazio quando os
 * eventos estão desligados.
 *
 * Chaves: `moeda`, `id_produto`, `ligados` (eventos ligados), `carga`
 * (eventos da carga da página: nome, params, opcoes e guarda, a marca de
 * uma vez por sessão), `produto` (ID do produto da página), `produtos` e
 * `variacoes` (item por ID, para o add_to_cart), `carrinho` (item por
 * chave do carrinho, para o remove_from_cart) e `checkout` (params e
 * assinatura, para o add_payment_info).
 *
 * @return array
 */
function rastro_eventos_dados(): array {
	$memoria = &rastro_eventos_memoria();
	if ( is_array( $memoria['dados'] ) ) {
		return $memoria['dados'];
	}

	$ligados = rastro_eventos_ligados();
	if ( array() === $ligados ) {
		return array();
	}
	$ligado = static function ( string $evento ) use ( $ligados ): bool {
		return in_array( $evento, $ligados, true );
	};

	$moeda = rastro_eventos_moeda( get_woocommerce_currency() );
	$tipo  = rastro_pagina_tipo();
	$dados = array(
		'moeda'      => $moeda,
		'id_produto' => rastro_eventos_modo_id(),
		'ligados'    => $ligados,
		'carga'      => array(),
	);
	$produtos = array();

	if ( in_array( $tipo, array( 'loja', 'categoria', 'busca' ), true ) ) {
		$itens = array();
		foreach ( rastro_eventos_produtos_consulta() as $produto ) {
			$item = rastro_eventos_item_produto( $produto );
			if ( null !== $item ) {
				$itens[]                                         = $item;
				$produtos[ (string) (int) $produto->get_id() ] = $item;
			}
		}
		if ( array() !== $itens && $ligado( 'view_item_list' ) ) {
			$dados['carga'][] = array(
				'nome'   => 'view_item_list',
				'params' => rastro_eventos_lista_nome( $tipo ) + array( 'items' => $itens ),
			);
		}
	}

	if ( 'busca' === $tipo && $ligado( 'search' ) ) {
		$termo = rastro_eventos_termo_busca( function_exists( 'get_search_query' ) ? get_search_query( false ) : '' );
		if ( '' !== $termo ) {
			$dados['carga'][] = array(
				'nome'   => 'search',
				'params' => array( 'search_term' => $termo ),
			);
		}
	}

	if ( 'produto' === $tipo ) {
		$pagina = rastro_eventos_produto_pagina();
		if ( null !== $pagina ) {
			$dados['produto']                       = $pagina['id'];
			$produtos[ (string) $pagina['id'] ] = $pagina['produto'];
			$produtos                              += $pagina['filhos'];
			if ( array() !== $pagina['variacoes'] ) {
				$dados['variacoes'] = $pagina['variacoes'];
			}
			if ( $ligado( 'view_item' ) ) {
				$dados['carga'][] = array(
					'nome'   => 'view_item',
					'params' => array(
						'currency' => $moeda,
						'value'    => $pagina['produto']['price'],
						'items'    => array( $pagina['produto'] ),
					),
				);
			}
		}
	}

	$no_carrinho = in_array( $tipo, array( 'carrinho', 'checkout' ), true );
	$pagar       = function_exists( 'is_checkout_pay_page' ) && is_checkout_pay_page();
	if ( ( $no_carrinho || rastro_eventos_carrinho_em_toda_pagina() ) && ! $pagar ) {
		$carrinho = rastro_eventos_carrinho();
		if ( null !== $carrinho ) {
			if ( $ligado( 'remove_from_cart' ) ) {
				$dados['carrinho'] = $carrinho['itens'];
			}
			if ( 'carrinho' === $tipo && $ligado( 'view_cart' ) ) {
				$dados['carga'][] = array(
					'nome'   => 'view_cart',
					'params' => array_diff_key( $carrinho['params'], array( 'coupon' => true ) ),
				);
			}
			if ( 'checkout' === $tipo ) {
				if ( $ligado( 'begin_checkout' ) ) {
					$dados['carga'][] = array(
						'nome'   => 'begin_checkout',
						'params' => $carrinho['params'],
						'guarda' => 'begin_checkout:' . $carrinho['assinatura'],
					);
				}
				if ( $ligado( 'add_payment_info' ) ) {
					$dados['checkout'] = array(
						'params'     => $carrinho['params'],
						'assinatura' => $carrinho['assinatura'],
					);
				}
			}
		}
	}

	if ( 'pedido_recebido' === $tipo && $ligado( 'purchase' ) ) {
		$compra = rastro_eventos_compra();
		if ( null !== $compra ) {
			$dados['carga'][] = array(
				'nome'   => 'purchase',
				'params' => $compra['params'],
				'opcoes' => $compra['opcoes'],
				'guarda' => 'purchase:' . $compra['opcoes']['event_id'],
			);
		}
	}

	if ( array() !== $produtos && $ligado( 'add_to_cart' ) ) {
		$dados['produtos'] = $produtos;
	}

	if ( did_action( 'wp' ) ) {
		$memoria['dados'] = $dados;
	}

	return $dados;
}

/**
 * Filtro `rastro_config_navegador`: acrescenta a chave `eventos`, só
 * quando a página mede e os eventos estão ligados.
 *
 * @param mixed $config Configuração do navegador.
 * @return mixed
 */
function rastro_eventos_config_navegador( $config ) {
	if ( ! is_array( $config ) ) {
		return $config;
	}
	try {
		if ( ! rastro_deve_medir() || ! rastro_eventos_ativo() ) {
			return $config;
		}
		$dados = rastro_eventos_dados();
		if ( array() !== $dados ) {
			$config['eventos'] = $dados;
		}
	} catch ( \Throwable $erro ) {
		do_action( 'rastro_falha', 'loja/eventos', $erro );
	}

	return $config;
}
add_filter( 'rastro_config_navegador', 'rastro_eventos_config_navegador', 20 );

/**
 * Carrega o assets/js/eventos.js nas páginas medidas com algum evento
 * ligado.
 *
 * @return void
 */
function rastro_eventos_script(): void {
	try {
		if ( ! rastro_deve_medir() || array() === rastro_eventos_ligados() ) {
			return;
		}
		if ( rastro_registrar_script( 'rastro-eventos', 'eventos.js' ) ) {
			wp_enqueue_script( 'rastro-eventos' );
		}
	} catch ( \Throwable $erro ) {
		do_action( 'rastro_falha', 'loja/eventos', $erro );
	}
}
add_action( 'wp_enqueue_scripts', 'rastro_eventos_script', 20 );
