<?php
/**
 * Testes dos eventos da loja do Rastro (rastro/loja/eventos.php),
 * sem WordPress e sem WooCommerce de verdade: item GA4, modos de valor e de
 * ID, filhos de produto composto fora, compra uma vez por pedido e só com a
 * chave certa, status, liga e desliga, e nenhum dado pessoal na saída.
 *
 * Uso: php tests/php/rastro-eventos.test.php (sai com 1 se algo falhar).
 * Contrato: docs/CONTRATO.md, seção 6.
 * Stubs compartilhados em tests/php/stubs-rastro.php; os do WooCommerce
 * (produto, pedido, carrinho) ficam aqui.
 *
 * @package Rastro
 */

define( 'ABSPATH', __DIR__ . '/' );

$rastro_plugin = realpath( __DIR__ . '/../../rastro' ) . '/rastro.php';

/*
 * Cenário em processo novo: php rastro-eventos.test.php --cenario=sem_woo
 * (sem WooCommerce nenhuma função do WooCommerce existe).
 */
foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( '--cenario=sem_woo' === $arg ) {
		define( 'RASTRO_TESTE_SEM_WOO', true );
		require __DIR__ . '/stubs-rastro.php';
		rastro_teste_reset( array( 'opcoes' => array( 'rastro_config' => array( 'ga4' => 'G-TESTE12345' ) ) ) );
		require $rastro_plugin;
		$config = rastro_config_navegador();
		rastro_eventos_script();
		echo json_encode(
			array(
				'ativo'    => rastro_eventos_ativo(),
				'ligados'  => rastro_eventos_ligados(),
				'eventos'  => isset( $config['eventos'] ),
				'medir'    => rastro_deve_medir(),
				'script'   => isset( $GLOBALS['rastro_teste']['scripts']['rastro-eventos'] ),
			)
		);
		exit( 0 );
	}
}

require __DIR__ . '/stubs-rastro.php';

/*
 * Stubs extras do WooCommerce. Dentro do `if` de propósito: declarados no
 * topo, o PHP os criaria antes de rodar e o cenário sem WooCommerce os veria.
 */
if ( ! defined( 'RASTRO_TESTE_SEM_WOO' ) ) :

$GLOBALS['rastro_teste_produtos']   = array();
$GLOBALS['rastro_teste_pedidos']    = array();
$GLOBALS['rastro_teste_categorias'] = array();
$GLOBALS['rastro_teste_carrinho']   = array();
$GLOBALS['rastro_teste_cupons']     = array();
$GLOBALS['rastro_teste_explode']    = 0;

/** Produto falso: só o que os eventos leem. */
class WC_Product { // phpcs:ignore
	public $d;
	public function __construct( array $d ) {
		$this->d = $d + array(
			'id'       => 0,
			'pai'      => 0,
			'tipo'     => 'simple',
			'nome'     => '',
			'sku'      => '',
			'preco'    => '0',
			'filhos'   => array(),
			'atributos' => '',
		);
	}
	public function get_id() { return $this->d['id']; }
	public function get_parent_id() { return $this->d['pai']; }
	public function get_type() { return $this->d['tipo']; }
	public function is_type( $tipo ) { return in_array( $this->d['tipo'], (array) $tipo, true ); }
	public function get_name() { return $this->d['nome']; }
	public function get_sku() { return $this->d['sku']; }
	public function get_price() { return $this->d['preco']; }
	public function get_children() { return $this->d['filhos']; }
	public function get_attribute_summary() { return $this->d['atributos']; }
}

/** Item de pedido falso. */
class WC_Order_Item_Product { // phpcs:ignore
	public $d;
	public function __construct( array $d ) {
		$this->d = $d + array(
			'produto'  => 0,
			'variacao' => 0,
			'nome'     => '',
			'qtd'      => 1,
			'total'    => '0',
			'meta'     => array(),
		);
	}
	public function get_product() { return $this->d['produto'] ? wc_get_product( $this->d['variacao'] ? $this->d['variacao'] : $this->d['produto'] ) : null; }
	public function get_product_id() { return $this->d['produto']; }
	public function get_variation_id() { return $this->d['variacao']; }
	public function get_name() { return $this->d['nome']; }
	public function get_quantity() { return $this->d['qtd']; }
	public function get_total() { return $this->d['total']; }
	public function get_meta( $chave, $unico = true ) { return $this->d['meta'][ $chave ] ?? ''; }
}

/**
 * Pedido falso. Registra cada método chamado; dado pessoal só existe pelo
 * __call (qualquer leitura dele fica registrada e o teste recusa).
 */
class WC_Order { // phpcs:ignore
	public $d;
	public $chamadas = array();
	public $meta     = array();
	public $salvou   = 0;
	public function __construct( array $d ) {
		$this->d = $d + array(
			'id'      => 0,
			'numero'  => '',
			'chave'   => '',
			'status'  => 'processing',
			'moeda'   => 'BRL',
			'total'   => '0',
			'imposto' => '0',
			'frete'   => '0',
			'cupons'  => array(),
			'itens'   => array(),
		);
	}
	private function anota( $m ) { $this->chamadas[] = $m; }
	public function get_id() { $this->anota( 'get_id' ); return $this->d['id']; }
	public function key_is_valid( $chave ) { $this->anota( 'key_is_valid' ); return hash_equals( $this->d['chave'], (string) $chave ); }
	public function has_status( $status ) { $this->anota( 'has_status' ); return in_array( $this->d['status'], (array) $status, true ); }
	public function get_status() { $this->anota( 'get_status' ); return $this->d['status']; }
	public function get_order_number() { $this->anota( 'get_order_number' ); return $this->d['numero']; }
	public function get_currency() { $this->anota( 'get_currency' ); return $this->d['moeda']; }
	public function get_total() { $this->anota( 'get_total' ); return $this->d['total']; }
	public function get_total_tax() { $this->anota( 'get_total_tax' ); return $this->d['imposto']; }
	public function get_shipping_total() { $this->anota( 'get_shipping_total' ); return $this->d['frete']; }
	public function get_coupon_codes() { $this->anota( 'get_coupon_codes' ); return $this->d['cupons']; }
	public function get_items( $tipo = 'line_item' ) { $this->anota( 'get_items' ); return $this->d['itens']; }
	public function get_meta( $chave, $unico = true ) { $this->anota( 'get_meta' ); return $this->meta[ $chave ] ?? ''; }
	public function update_meta_data( $chave, $valor ) { $this->anota( 'update_meta_data' ); $this->meta[ $chave ] = $valor; }
	public function save_meta_data() { $this->anota( 'save_meta_data' ); ++$this->salvou; }
	/** Metas gravadas por outra requisição (lidas só com read_meta_data( true )). */
	public $banco = array();
	public function read_meta_data( $forcar = false ) { $this->anota( 'read_meta_data' ); if ( $forcar ) { $this->meta = $this->banco + $this->meta; } }
	public function __call( $metodo, $args ) { $this->anota( $metodo ); return 'PESSOAL-' . $metodo; }
}

/** Carrinho falso. */
class Rastro_Teste_Carrinho { // phpcs:ignore
	public function get_cart() { return $GLOBALS['rastro_teste_carrinho']; }
	public function get_applied_coupons() { return $GLOBALS['rastro_teste_cupons']; }
}

function WC() { // phpcs:ignore
	static $woo = null;
	if ( null === $woo ) {
		$woo       = new stdClass();
		$woo->cart = new Rastro_Teste_Carrinho();
	}
	return $woo;
}
function wc_get_product( $produto = false ) { // phpcs:ignore
	$id = is_object( $produto ) ? (int) $produto->ID : (int) $produto;
	if ( $id === $GLOBALS['rastro_teste_explode'] && $id > 0 ) {
		throw new RuntimeException( 'produto quebrado' );
	}
	return $GLOBALS['rastro_teste_produtos'][ $id ] ?? false;
}
function wc_get_order( $id ) { return $GLOBALS['rastro_teste_pedidos'][ (int) $id ] ?? false; } // phpcs:ignore
function wc_get_product_terms( $id, $taxonomia, $args = array() ) { return $GLOBALS['rastro_teste_categorias'][ (int) $id ] ?? array(); } // phpcs:ignore
function wc_get_price_excluding_tax( $produto, $args = array() ) { return (float) $produto->get_price(); } // phpcs:ignore
function wc_get_formatted_variation( $variacao, $plano = false, $nomes = true, $pular = false ) { return $variacao->get_attribute_summary(); } // phpcs:ignore
function get_queried_object_id() { return (int) rastro_teste( 'objeto_id', 0 ); } // phpcs:ignore
function get_queried_object() { return rastro_teste( 'objeto', null ); } // phpcs:ignore
function get_search_query( $escapar = true ) { return (string) rastro_teste( 'busca', '' ); } // phpcs:ignore
endif;

/** Cadastra um produto falso. */
function produto( array $d ): WC_Product { // phpcs:ignore
	$p = new WC_Product( $d );
	$GLOBALS['rastro_teste_produtos'][ $d['id'] ] = $p;
	return $p;
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

const CONFIG_BASE = array( 'ga4' => 'G-TESTE12345' );

/** Zera o estado e as memórias do Rastro (os ganchos dos módulos ficam). */
function novo( array $sobre = array(), array $config = array() ) { // phpcs:ignore
	$sobre['opcoes'] = array( 'rastro_config' => $config + CONFIG_BASE );
	rastro_teste_reset( $sobre );
	rastro_config_redefinir_cache();
	rastro_regras_redefinir_cache();
	rastro_eventos_redefinir_cache();
	$GLOBALS['wp_query'] = (object) array( 'posts' => rastro_teste( 'posts', array() ) );
}

/** Eventos da carga, por nome. */
function carga( array $eventos ): array { // phpcs:ignore
	$saida = array();
	foreach ( $eventos['carga'] ?? array() as $ev ) {
		$saida[ $ev['nome'] ] = $ev;
	}
	return $saida;
}

/** Todas as chaves de um array, em qualquer nível. */
function chaves( $dado ): array { // phpcs:ignore
	$lista = array();
	if ( is_array( $dado ) ) {
		foreach ( $dado as $k => $v ) {
			$lista[] = (string) $k;
			$lista   = array_merge( $lista, chaves( $v ) );
		}
	}
	return $lista;
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

rastro_teste_reset( array( 'opcoes' => array( 'rastro_config' => CONFIG_BASE ) ) );
require $rastro_plugin;
novo();

/* 0. Carga do módulo ---------------------------------------------------------- */

confere( 'módulo carregado', function_exists( 'rastro_eventos_dados' ), true );
confere( 'filtro rastro_config_navegador', has_filter( 'rastro_config_navegador', 'rastro_eventos_config_navegador' ), 20 );
confere( 'script em wp_enqueue_scripts', has_action( 'wp_enqueue_scripts', 'rastro_eventos_script' ), 20 );
confere( 'sem falha ao carregar', $falhas_registradas, array() );

/* 1. Item GA4 ------------------------------------------------------------------ */

$item = rastro_eventos_item_regra(
	array(
		'id'         => 10,
		'sku'        => 'RAC-1',
		'nome'       => ' Ração <b>Premium</b> &amp; Cia ',
		'preco'      => '19.999',
		'quantidade' => '2',
		'categoria'  => 'Cães',
		'variante'   => '',
	)
);
confere(
	'item: formato GA4 (modo id)',
	$item,
	array(
		'item_id'       => '10',
		'item_name'     => 'Ração Premium & Cia',
		'price'         => 20.0,
		'quantity'      => 2,
		'item_category' => 'Cães',
	)
);
confere( 'item: modo sku', rastro_eventos_item_regra( array( 'id' => 10, 'sku' => 'RAC-1', 'nome' => 'X' ), 'sku' )['item_id'], 'RAC-1' );
confere( 'item: modo sku sem SKU cai no ID', rastro_eventos_item_regra( array( 'id' => 10, 'sku' => '', 'nome' => 'X' ), 'sku' )['item_id'], '10' );
confere( 'item: variação', rastro_eventos_item_regra( array( 'id' => 11, 'nome' => 'X', 'variante' => 'Azul, G' ) )['item_variant'], 'Azul, G' );
confere( 'item: quantidade mínima 1', rastro_eventos_item_regra( array( 'id' => 11, 'nome' => 'X', 'quantidade' => 0 ) )['quantity'], 1 );
confere( 'item: sem ID nem nome', rastro_eventos_item_regra( array( 'preco' => 5 ) ), null );
confere( 'item: tag escrita como entidade também sai', rastro_eventos_item_regra( array( 'id' => 1, 'nome' => '&lt;script&gt;x&lt;/script&gt;Bola' ) )['item_name'], 'Bola' );
confere( 'valor: duas casas', rastro_eventos_valor( '10.005' ), 10.01 );
confere( 'valor: negativo vira zero', rastro_eventos_valor( -3 ), 0.0 );
confere( 'valor: vírgula não é número', rastro_eventos_valor( '10,50' ), 0.0 );
confere( 'valor: JSON com ponto', rastro_json( array( 'v' => rastro_eventos_valor( '19.90' ), 'w' => rastro_eventos_valor( 1234.5 ) ) ), '{"v":19.9,"w":1234.5}' );
confere( 'moeda', rastro_eventos_moeda( 'brl' ), 'BRL' );
confere( 'moeda inválida', rastro_eventos_moeda( 'R$' ), '' );
confere( 'soma dos itens', rastro_eventos_soma_itens( array( array( 'price' => 10.1, 'quantity' => 3 ), array( 'price' => 0.2, 'quantity' => 1 ) ) ), 30.5 );

/* 2. Compra: modos, cupom, status ------------------------------------------------ */

$base_compra = array(
	'numero'   => '1001',
	'moeda'    => 'BRL',
	'total'    => '150.40',
	'produtos' => 120,
	'imposto'  => '5.4',
	'frete'    => '25',
	'cupons'   => array(),
	'itens'    => array(),
);
$c = rastro_eventos_compra_regra( $base_compra, 'produtos' );
confere( 'compra: valor só dos produtos', $c['value'], 120.0 );
confere( 'compra: chaves', array_keys( $c ), array( 'transaction_id', 'value', 'currency', 'tax', 'shipping', 'items' ) );
confere( 'compra: transaction_id é o número', $c['transaction_id'], '1001' );
confere( 'compra: frete e imposto', array( $c['shipping'], $c['tax'] ), array( 25.0, 5.4 ) );
$c = rastro_eventos_compra_regra( array( 'cupons' => array( 'PRIMEIRA', 'frete', 'PRIMEIRA' ) ) + $base_compra, 'total' );
confere( 'compra: valor total', $c['value'], 150.4 );
confere( 'compra: cupons sem repetir', $c['coupon'], 'PRIMEIRA,frete' );
foreach ( array( 'processing', 'completed', 'on-hold', 'wc-processing' ) as $s ) {
	confere( "status {$s} gera compra", rastro_eventos_status_compra( $s ), true );
}
foreach ( array( 'pending', 'failed', 'cancelled', 'refunded', 'checkout-draft', '' ) as $s ) {
	confere( "status {$s} não gera compra", rastro_eventos_status_compra( $s ), false );
}
// Filtro rastro_eventos_status_compra: acrescenta (ex.: pending do Pix), nunca falho nem cancelado.
add_filter( 'rastro_eventos_status_compra', static function ( $s ) { return array_merge( $s, array( 'wc-pending', 'failed', 'cancelled', 'refunded', 'checkout-draft', 'trash', 'Ruim!', 5, 'processing' ) ); } );
confere( 'filtro de status: lista limpa', rastro_eventos_status_aceitos(), array( 'processing', 'completed', 'on-hold', 'pending' ) );
confere( 'filtro de status: pending gera compra', rastro_eventos_status_compra( 'pending' ), true );
confere( 'filtro de status: failed nunca', rastro_eventos_status_compra( 'failed' ), false );
remove_all_filters( 'rastro_eventos_status_compra' );
add_filter( 'rastro_eventos_status_compra', '__return_false' );
confere( 'filtro de status que não devolve lista: padrão', rastro_eventos_status_aceitos(), array( 'processing', 'completed', 'on-hold' ) );
remove_all_filters( 'rastro_eventos_status_compra' );
add_filter( 'rastro_eventos_status_compra', static function () { return array( 'completed' ); } );
confere( 'filtro de status pode tirar', rastro_eventos_status_compra( 'processing' ), false );
remove_all_filters( 'rastro_eventos_status_compra' );
confere( 'cupom: padrão não envia', rastro_eventos_enviar_cupom(), false );
add_filter( 'rastro_eventos_enviar_cupom', '__return_true' );
confere( 'cupom: filtro liga', rastro_eventos_enviar_cupom(), true );
remove_all_filters( 'rastro_eventos_enviar_cupom' );
add_filter( 'rastro_eventos_enviar_cupom', static function () { return 1; } );
confere( 'cupom: só true liga', rastro_eventos_enviar_cupom(), false );
remove_all_filters( 'rastro_eventos_enviar_cupom' );

/* 3. Busca --------------------------------------------------------------------- */

confere( 'busca: termo comum', rastro_eventos_termo_busca( ' ração  15kg ' ), 'ração 15kg' );
confere( 'busca: e-mail fora', rastro_eventos_termo_busca( 'fulano@exemplo.com' ), '' );
confere( 'busca: CPF fora', rastro_eventos_termo_busca( '123.456.789-00' ), '' );
confere( 'busca: telefone fora', rastro_eventos_termo_busca( '(21) 99999-0000' ), '' );

/* 4. Página de produto ------------------------------------------------------------ */

produto( array( 'id' => 10, 'nome' => 'Ração Premium', 'sku' => 'RAC-1', 'preco' => '89.9' ) );
$GLOBALS['rastro_teste_categorias'][10] = array( 'Cães', 'Ração' );
novo( array( 'woo' => array( 'is_product' => true ), 'objeto_id' => 10 ) );
$config = rastro_config_navegador();
confere( 'produto: eventos no rastroConfig', isset( $config['eventos'] ), true );
$ev = $config['eventos'];
confere( 'produto: ligados (todos por padrão)', $ev['ligados'], array_keys( rastro_config_eventos() ) );
confere( 'produto: moeda', $ev['moeda'], 'BRL' );
confere( 'produto: ID do produto da página', $ev['produto'], 10 );
confere(
	'produto: view_item',
	carga( $ev )['view_item']['params'],
	array(
		'currency' => 'BRL',
		'value'    => 89.9,
		'items'    => array(
			array(
				'item_id'       => '10',
				'item_name'     => 'Ração Premium',
				'price'         => 89.9,
				'quantity'      => 1,
				'item_category' => 'Cães',
			),
		),
	)
);
confere( 'produto: mapa para o add_to_cart', array_keys( $ev['produtos'] ), array( 10 ) );
confere( 'produto: sem carrinho fora do carrinho e do checkout', isset( $ev['carrinho'] ), false );

novo( array( 'woo' => array( 'is_product' => true ), 'objeto_id' => 10 ), array( 'id_produto' => 'sku' ) );
confere( 'produto: id_produto sku', carga( rastro_config_navegador()['eventos'] )['view_item']['params']['items'][0]['item_id'], 'RAC-1' );

// Variável: variações com item_variant, no mapa da página.
produto( array( 'id' => 20, 'tipo' => 'variable', 'nome' => 'Coleira', 'preco' => '30', 'filhos' => array( 21, 22 ) ) );
produto( array( 'id' => 21, 'pai' => 20, 'tipo' => 'variation', 'nome' => 'Coleira - Azul', 'sku' => 'COL-AZ', 'preco' => '30', 'atributos' => 'Azul, P' ) );
produto( array( 'id' => 22, 'pai' => 20, 'tipo' => 'variation', 'nome' => 'Coleira - Rosa', 'preco' => '35.5', 'atributos' => 'Rosa, G' ) );
$GLOBALS['rastro_teste_categorias'][20] = array( 'Acessórios' );
novo( array( 'woo' => array( 'is_product' => true ), 'objeto_id' => 20 ) );
$ev = rastro_config_navegador()['eventos'];
confere(
	'variável: variação 22',
	$ev['variacoes'][22],
	array(
		'item_id'       => '22',
		'item_name'     => 'Coleira',
		'price'         => 35.5,
		'quantity'      => 1,
		'item_category' => 'Acessórios',
		'item_variant'  => 'Rosa, G',
	)
);
confere( 'variável: duas variações', array_keys( $ev['variacoes'] ), array( 21, 22 ) );

// Agrupado: filhos no mapa de produtos.
produto( array( 'id' => 30, 'tipo' => 'grouped', 'nome' => 'Kit', 'filhos' => array( 10, 21 ) ) );
novo( array( 'woo' => array( 'is_product' => true ), 'objeto_id' => 30 ) );
confere( 'agrupado: filhos no mapa', array_keys( rastro_config_navegador()['eventos']['produtos'] ), array( 30, 10, 21 ) );

// Produto com erro: a página segue sem os eventos e a falha é avisada.
$GLOBALS['rastro_teste_explode'] = 10;
novo( array( 'woo' => array( 'is_product' => true ), 'objeto_id' => 10 ) );
$config = rastro_config_navegador();
confere( 'erro no produto: sem eventos', isset( $config['eventos'] ), false );
confere( 'erro no produto: rastro_falha', end( $falhas_registradas ), array( 'loja/eventos', 'produto quebrado' ) );
confere( 'erro no produto: resto da configuração intacto', isset( $config['pagina'], $config['versao'] ), true );
$GLOBALS['rastro_teste_explode'] = 0;
$falhas_registradas               = array();

/* 5. Listas e busca -------------------------------------------------------------- */

$posts = array( (object) array( 'ID' => 999, 'post_type' => 'post' ) );
for ( $i = 101; $i <= 125; $i++ ) {
	produto( array( 'id' => $i, 'nome' => 'Produto ' . $i, 'preco' => '10' ) );
	$posts[] = (object) array( 'ID' => $i, 'post_type' => 'product' );
}
novo( array( 'woo' => array( 'is_product_category' => true ), 'objeto' => (object) array( 'name' => 'Brinquedos' ), 'posts' => $posts ) );
$ev    = rastro_config_navegador()['eventos'];
$lista = carga( $ev )['view_item_list']['params'];
confere( 'lista: até 20 itens', count( $lista['items'] ), 20 );
confere( 'lista: post que não é produto fica fora', $lista['items'][0]['item_id'], '101' );
confere( 'lista: nome da categoria', array( $lista['item_list_id'], $lista['item_list_name'] ), array( 'categoria', 'Brinquedos' ) );
confere( 'lista: itens no mapa do add_to_cart', count( $ev['produtos'] ), 20 );
confere( 'lista: sem view_item', isset( carga( $ev )['view_item'] ), false );

novo( array( 'woo' => array( 'is_shop' => true ), 'posts' => array_slice( $posts, 1, 3 ) ) );
confere( 'loja: lista da loja', carga( rastro_config_navegador()['eventos'] )['view_item_list']['params']['item_list_id'], 'loja' );

novo( array( 'wp' => array( 'is_search' => true ), 'busca' => 'brinquedo', 'posts' => array_slice( $posts, 0, 3 ) ) );
$cg = carga( rastro_config_navegador()['eventos'] );
confere( 'busca: search_term', $cg['search']['params'], array( 'search_term' => 'brinquedo' ) );
confere( 'busca: lista da busca só com produtos', array( $cg['view_item_list']['params']['item_list_id'], count( $cg['view_item_list']['params']['items'] ) ), array( 'busca', 2 ) );
novo( array( 'wp' => array( 'is_search' => true ), 'busca' => 'meu@email.com' ) );
confere( 'busca: termo pessoal não sai', isset( carga( rastro_config_navegador()['eventos'] )['search'] ), false );

/* 6. Carrinho, produto composto e checkout --------------------------------------------- */

produto( array( 'id' => 40, 'tipo' => 'composite', 'nome' => 'Comedouro composto', 'preco' => '50' ) );
produto( array( 'id' => 41, 'nome' => 'Anel Amarelo', 'preco' => '5' ) );
produto( array( 'id' => 42, 'nome' => 'Tigela Inox', 'preco' => '7' ) );
$GLOBALS['rastro_teste_carrinho'] = array(
	'pai1'   => array( 'data' => $GLOBALS['rastro_teste_produtos'][40], 'quantity' => 2, 'line_total' => 100, 'composite_children' => array( 'filho1', 'filho2' ) ),
	'filho1' => array( 'data' => $GLOBALS['rastro_teste_produtos'][41], 'quantity' => 2, 'line_total' => 10, 'composite_parent' => 'pai1' ),
	'filho2' => array( 'data' => $GLOBALS['rastro_teste_produtos'][42], 'quantity' => 2, 'line_total' => 14, 'composite_parent' => 'pai1' ),
	'solto'  => array( 'data' => $GLOBALS['rastro_teste_produtos'][21], 'quantity' => 1, 'line_total' => 27, 'composite_parent' => '' ),
);
$GLOBALS['rastro_teste_cupons']   = array( 'PRIMEIRA' );

novo( array( 'woo' => array( 'is_cart' => true ) ) );
$ev = rastro_config_navegador()['eventos'];
confere( 'carrinho: filhos do composto fora', array_keys( $ev['carrinho'] ), array( 'pai1', 'solto' ) );
confere( 'carrinho: pai com o valor dos filhos', array( $ev['carrinho']['pai1']['price'], $ev['carrinho']['pai1']['quantity'] ), array( 62.0, 2 ) );
confere( 'carrinho: variação com o pai no nome', array( $ev['carrinho']['solto']['item_name'], $ev['carrinho']['solto']['item_variant'] ), array( 'Coleira', 'Azul, P' ) );
$vc = carga( $ev )['view_cart']['params'];
confere( 'view_cart: valor', $vc['value'], 151.0 );
confere( 'view_cart: itens', count( $vc['items'] ), 2 );
confere( 'view_cart: chaves', array_keys( $vc ), array( 'currency', 'value', 'items' ) );
confere( 'carrinho: sem begin_checkout', isset( carga( $ev )['begin_checkout'] ), false );

novo( array( 'woo' => array( 'is_checkout' => true ) ) );
$ev = rastro_config_navegador()['eventos'];
$bc = carga( $ev )['begin_checkout'];
confere( 'begin_checkout: sem cupom por padrão', array_key_exists( 'coupon', $bc['params'] ), false );
add_filter( 'rastro_eventos_enviar_cupom', '__return_true' );
novo( array( 'woo' => array( 'is_checkout' => true ) ) );
confere( 'begin_checkout: cupom com o filtro', carga( rastro_config_navegador()['eventos'] )['begin_checkout']['params']['coupon'], 'PRIMEIRA' );
remove_all_filters( 'rastro_eventos_enviar_cupom' );
novo( array( 'woo' => array( 'is_checkout' => true ) ) );
$ev = rastro_config_navegador()['eventos'];
$bc = carga( $ev )['begin_checkout'];
confere( 'begin_checkout: guarda por carrinho', 1 === preg_match( '/^begin_checkout:[0-9a-f]{12}$/D', $bc['guarda'] ), true );
confere( 'checkout: dados do add_payment_info', array( $ev['checkout']['params'], 'begin_checkout:' . $ev['checkout']['assinatura'] ), array( $bc['params'], $bc['guarda'] ) );
confere( 'checkout: sem view_cart', isset( carga( $ev )['view_cart'] ), false );
$assinatura = $ev['checkout']['assinatura'];
$GLOBALS['rastro_teste_carrinho']['solto']['quantity'] = 2;
novo( array( 'woo' => array( 'is_checkout' => true ) ) );
confere( 'checkout: assinatura muda com o carrinho', rastro_config_navegador()['eventos']['checkout']['assinatura'] !== $assinatura, true );

novo( array( 'woo' => array( 'is_checkout' => true, 'is_checkout_pay_page' => true ) ) );
confere( 'pagar pedido: sem begin_checkout', isset( carga( rastro_config_navegador()['eventos'] )['begin_checkout'] ), false );

novo( array( 'woo' => array( 'is_product' => true ), 'objeto_id' => 10 ) );
add_filter( 'rastro_eventos_carrinho_em_toda_pagina', '__return_true' );
confere( 'filtro: carrinho em toda página', array_keys( rastro_config_navegador()['eventos']['carrinho'] ), array( 'pai1', 'solto' ) );
remove_all_filters( 'rastro_eventos_carrinho_em_toda_pagina' );

$GLOBALS['rastro_teste_carrinho'] = array();
novo( array( 'woo' => array( 'is_cart' => true ) ) );
$ev = rastro_config_navegador()['eventos'];
confere( 'carrinho vazio: sem view_cart nem itens', array( isset( carga( $ev )['view_cart'] ), isset( $ev['carrinho'] ) ), array( false, false ) );

/* 7. Compra no pedido recebido ------------------------------------------------------- */

/** Pedido de teste com um composto (pai + filho) e um item simples. */
function pedido( array $d = array() ): WC_Order { // phpcs:ignore
	$pedido = new WC_Order(
		$d + array(
			'id'      => 77,
			'numero'  => 'A-1077',
			'chave'   => 'wc_order_Abc123',
			'status'  => 'processing',
			'total'   => '182.90',
			'imposto' => '0',
			'frete'   => '20.90',
			'cupons'  => array( 'PRIMEIRA' ),
			'itens'   => array(
				1 => new WC_Order_Item_Product( array( 'produto' => 40, 'qtd' => 2, 'total' => '100', 'meta' => array( '_composite_cart_key' => 'h1', '_composite_children' => array( 'h2' ) ) ) ),
				2 => new WC_Order_Item_Product( array( 'produto' => 41, 'qtd' => 2, 'total' => '12', 'meta' => array( '_composite_parent' => 'h1', '_composite_cart_key' => 'h2' ) ) ),
				3 => new WC_Order_Item_Product( array( 'produto' => 10, 'qtd' => 1, 'total' => '50' ) ),
				4 => new WC_Order_Item_Product( array( 'produto' => 555, 'nome' => 'Produto apagado', 'qtd' => 1, 'total' => '0' ) ),
			),
		)
	);
	$GLOBALS['rastro_teste_pedidos'][ $pedido->d['id'] ] = $pedido;
	return $pedido;
}

/** Página de pedido recebido do pedido 77 com a chave dada. */
function pagina_recebido( $chave = 'wc_order_Abc123', array $config = array(), $numero = 77, $antes = null ) { // phpcs:ignore
	$get = null === $chave ? array() : array( 'key' => $chave );
	novo(
		array(
			'woo'        => array( 'is_order_received_page' => true, 'is_checkout' => true ),
			'query_vars' => array( 'order-received' => $numero ),
			'get'        => $get,
			'uri'        => '/finalizar-compra/pedido-recebido/' . $numero . '/' . ( $get ? '?key=' . $chave : '' ),
		),
		$config
	);
	if ( is_callable( $antes ) ) {
		$antes();
	}
	return rastro_config_navegador();
}

$pedido = pedido();
$config = pagina_recebido();
$ev     = $config['eventos'];
$compra = carga( $ev )['purchase'] ?? array();
confere( 'compra: sai na primeira impressão', isset( $compra['params'] ), true );
confere( 'compra: transaction_id = número do pedido', $compra['params']['transaction_id'], 'A-1077' );
confere( 'compra: event_id para deduplicar', $compra['opcoes'], array( 'event_id' => 'rastro-77' ) );
confere( 'compra: guarda no navegador', $compra['guarda'], 'purchase:rastro-77' );
confere( 'compra: valor só dos produtos (padrão)', $compra['params']['value'], 162.0 );
confere( 'compra: frete e imposto, sem cupom por padrão', array( $compra['params']['shipping'], $compra['params']['tax'], array_key_exists( 'coupon', $compra['params'] ) ), array( 20.9, 0.0, false ) );
confere( 'compra: cupom nem é lido por padrão', in_array( 'get_coupon_codes', $pedido->chamadas, true ), false );
confere( 'compra: filho do composto fora', array_column( $compra['params']['items'], 'item_id' ), array( '40', '10', '555' ) );
confere( 'compra: pai com o valor do filho', $compra['params']['items'][0]['price'], 56.0 );
confere( 'compra: produto apagado usa o item do pedido', $compra['params']['items'][2]['item_name'], 'Produto apagado' );
confere( 'compra: meta gravada', 1 === preg_match( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/D', (string) ( $pedido->meta['_rastro_compra_enviada'] ?? '' ) ), true );
confere( 'compra: meta pelo método do pedido (HPOS)', array( in_array( 'update_meta_data', $pedido->chamadas, true ), $pedido->salvou ), array( true, 1 ) );
confere( 'compra: endereço da página sem chave nem número', $config['pagina']['url'], 'https://loja.exemplo.com.br/finalizar-compra/pedido-recebido/' );
rastro_config_navegador();
confere( 'compra: mesma requisição não grava de novo', $pedido->salvou, 1 );

$ev = pagina_recebido()['eventos'];
confere( 'compra: recarga não repete', isset( carga( $ev )['purchase'] ), false );
confere( 'compra: recarga não grava de novo', $pedido->salvou, 1 );

// Cupom só com o filtro rastro_eventos_enviar_cupom.
$pedido = pedido();
add_filter( 'rastro_eventos_enviar_cupom', '__return_true' );
$ev = pagina_recebido()['eventos'];
remove_all_filters( 'rastro_eventos_enviar_cupom' );
confere( 'compra: cupom com o filtro', carga( $ev )['purchase']['params']['coupon'], 'PRIMEIRA' );

// Trava da marcação: duas requisições no mesmo pedido, só uma marca.
$pedido = pedido();
$ev = pagina_recebido( 'wc_order_Abc123', array(), 77, static function () { add_option( 'rastro_compra_trava_77', time() - 5 ); } )['eventos'];
confere( 'trava recente de outra requisição: sem compra', isset( carga( $ev )['purchase'] ), false );
confere( 'trava recente: nada gravado', $pedido->salvou, 0 );
confere( 'trava recente: a trava dos outros fica', false !== get_option( 'rastro_compra_trava_77' ), true );
delete_option( 'rastro_compra_trava_77' );

$pedido = pedido();
$ev = pagina_recebido( 'wc_order_Abc123', array(), 77, static function () { add_option( 'rastro_compra_trava_77', time() - 120 ); } )['eventos'];
confere( 'trava velha (mais de 60 s): compra sai', isset( carga( $ev )['purchase'] ), true );
confere( 'trava velha: tirada depois', get_option( 'rastro_compra_trava_77', 'sem' ), 'sem' );

$pedido = pedido();
$ev     = pagina_recebido()['eventos'];
confere( 'trava: tirada depois da compra', get_option( 'rastro_compra_trava_77', 'sem' ), 'sem' );
confere( 'trava: sem carga automática', $GLOBALS['rastro_teste']['autoload']['rastro_compra_trava_77'] ?? null, false );
confere( 'trava: meta lida de novo do banco', in_array( 'read_meta_data', $pedido->chamadas, true ), true );

// Outra requisição marcou entre a primeira leitura e a trava.
$pedido        = pedido();
$pedido->banco = array( '_rastro_compra_enviada' => '2026-11-11T10:00:00+00:00' );
$ev            = pagina_recebido()['eventos'];
confere( 'marcada por outra requisição: sem compra', isset( carga( $ev )['purchase'] ), false );
confere( 'marcada por outra requisição: nada gravado', $pedido->salvou, 0 );
confere( 'marcada por outra requisição: trava tirada', get_option( 'rastro_compra_trava_77', 'sem' ), 'sem' );

// Pix pendente: sem compra pelo navegador, a não ser pelo filtro.
$pedido = pedido( array( 'status' => 'pending' ) );
confere( 'Pix pendente: sem compra (padrão)', isset( carga( pagina_recebido()['eventos'] )['purchase'] ), false );
add_filter( 'rastro_eventos_status_compra', static function ( $s ) { return array_merge( $s, array( 'pending' ) ); } );
$pedido = pedido( array( 'status' => 'pending' ) );
confere( 'Pix pendente com o filtro: compra', isset( carga( pagina_recebido()['eventos'] )['purchase'] ), true );
remove_all_filters( 'rastro_eventos_status_compra' );

$pedido = pedido();
$ev     = pagina_recebido( 'wc_order_Abc123', array( 'valor_compra' => 'total', 'id_produto' => 'sku' ) )['eventos'];
$compra = carga( $ev )['purchase'];
confere( 'compra: valor total', $compra['params']['value'], 182.9 );
confere( 'compra: id_produto sku', $compra['params']['items'][1]['item_id'], 'RAC-1' );

// Chave errada: o pedido não é lido (só a conferência da chave).
$pedido = pedido();
$ev     = pagina_recebido( 'wc_order_ERRADA' )['eventos'];
confere( 'chave errada: sem compra', isset( carga( $ev )['purchase'] ), false );
confere( 'chave errada: só a chave foi conferida', $pedido->chamadas, array( 'key_is_valid' ) );
confere( 'chave errada: nada gravado', $pedido->salvou, 0 );

$pedido = pedido();
pagina_recebido( null );
confere( 'sem chave: o pedido nem é buscado', $pedido->chamadas, array() );
pagina_recebido( '' );
confere( 'chave vazia: o pedido nem é buscado', $pedido->chamadas, array() );

// Pedido de outro número com a mesma chave não passa.
$outro = pedido( array( 'id' => 78 ) );
add_filter( 'woocommerce_thankyou_order_id', static function () { return 78; } );
$ev = pagina_recebido()['eventos'];
remove_all_filters( 'woocommerce_thankyou_order_id' );
confere( 'filtro do WooCommerce para o número vale', isset( carga( $ev )['purchase'] ) && 'rastro-78' === carga( $ev )['purchase']['opcoes']['event_id'], true );

foreach ( array( 'completed' => true, 'on-hold' => true, 'failed' => false, 'cancelled' => false, 'pending' => false, 'refunded' => false ) as $status => $gera ) {
	$pedido = pedido( array( 'status' => $status ) );
	$ev     = pagina_recebido()['eventos'];
	confere( "status {$status}: compra", isset( carga( $ev )['purchase'] ), $gera );
	confere( "status {$status}: meta", $pedido->salvou, $gera ? 1 : 0 );
}

// Nenhum dado pessoal: nenhuma leitura dele e nenhuma chave dele na saída.
$pedido  = pedido();
$config  = pagina_recebido();
$pessoal = array( 'email', 'phone', 'first_name', 'last_name', 'address', 'cpf', 'user_id', 'customer_id', 'billing' );
// Só os métodos do pedido que montam a compra (o resto cairia no __call).
confere(
	'pedido: só métodos sem dado pessoal',
	array_values( array_diff( array_unique( $pedido->chamadas ), array( 'get_id', 'key_is_valid', 'has_status', 'get_meta', 'read_meta_data', 'get_items', 'get_order_number', 'get_currency', 'get_total', 'get_total_tax', 'get_shipping_total', 'get_coupon_codes', 'update_meta_data', 'save_meta_data' ) ) ),
	array()
);
foreach ( chaves( $config['eventos'] ) as $chave ) {
	foreach ( $pessoal as $proibido ) {
		if ( false !== stripos( $chave, $proibido ) || 'ip' === strtolower( $chave ) ) {
			confere( "saída sem a chave {$chave}", $chave, '(sem dado pessoal)' );
		}
	}
}
$json = rastro_json( $config );
confere( 'saída sem dado pessoal do pedido', false === strpos( $json, 'PESSOAL-' ), true );
confere( 'saída sem a chave do pedido', false === strpos( $json, 'wc_order_Abc123' ), true );
confere( 'saída sem nenhuma das palavras de dado pessoal', 0 === preg_match( '/email|phone|first_name|last_name|address|cpf|user_id|customer_id/i', $json ), true );

/* 8. Liga e desliga ------------------------------------------------------------------ */

$pedido = pedido();
$config = pagina_recebido( 'wc_order_Abc123', array( 'evento_purchase' => '0' ) );
confere( 'purchase desligado: sem compra', isset( carga( $config['eventos'] )['purchase'] ), false );
confere( 'purchase desligado: nada gravado', $pedido->salvou, 0 );
confere( 'purchase desligado: fora dos ligados', in_array( 'purchase', $config['eventos']['ligados'], true ), false );

novo( array( 'woo' => array( 'is_product' => true ), 'objeto_id' => 10 ), array( 'evento_view_item' => '0', 'evento_add_to_cart' => '0' ) );
$ev = rastro_config_navegador()['eventos'];
confere( 'view_item desligado', isset( carga( $ev )['view_item'] ), false );
confere( 'add_to_cart desligado: sem mapa de produtos', isset( $ev['produtos'] ), false );

$desligados = array( 'eventos_loja' => '1' );
foreach ( array_keys( rastro_config_eventos() ) as $nome ) {
	$desligados[ 'evento_' . $nome ] = '0';
}
novo( array( 'woo' => array( 'is_product' => true ), 'objeto_id' => 10 ), $desligados );
confere( 'todos desligados: sem eventos', isset( rastro_config_navegador()['eventos'] ), false );
rastro_eventos_script();
confere( 'todos desligados: sem script', isset( $GLOBALS['rastro_teste']['scripts']['rastro-eventos'] ), false );

$pedido = pedido();
$config = pagina_recebido( 'wc_order_Abc123', array( 'eventos_loja' => '0' ) );
confere( 'eventos_loja desligado: sem eventos', isset( $config['eventos'] ), false );
confere( 'eventos_loja desligado: pedido não lido', $pedido->chamadas, array() );
confere( 'eventos_loja desligado: nada ligado', rastro_eventos_ligados(), array() );

novo( array( 'woo' => array( 'is_product' => true ), 'objeto_id' => 10 ) );
$GLOBALS['rastro_teste']['opcoes']['rastro_config'] = array();
rastro_config_redefinir_cache();
rastro_regras_redefinir_cache();
confere( 'sem destino: sem eventos', isset( rastro_config_navegador()['eventos'] ), false );

$pedido = pedido();
pagina_recebido();
novo( array( 'logado' => true, 'capacidades' => array( 'manage_woocommerce' => true ), 'woo' => array( 'is_order_received_page' => true ), 'query_vars' => array( 'order-received' => 77 ), 'get' => array( 'key' => 'wc_order_Abc123' ) ) );
$pedido = pedido();
confere( 'equipe: sem eventos', isset( rastro_config_navegador()['eventos'] ), false );
confere( 'equipe: pedido não marcado', $pedido->salvou, 0 );

/* 9. Script ---------------------------------------------------------------------------- */

novo( array( 'woo' => array( 'is_product' => true ), 'objeto_id' => 10 ) );
rastro_eventos_script();
$s = $GLOBALS['rastro_teste']['scripts']['rastro-eventos'] ?? array();
confere( 'script: registrado e enfileirado', ! empty( $s['enfileirado'] ), true );
confere( 'script: arquivo', $s['src'] ?? '', 'https://loja.exemplo.com.br/wp-content/plugins/rastro/assets/js/eventos.js' );
confere( 'script: rodapé com defer', $s['args'] ?? array(), array( 'in_footer' => true, 'strategy' => 'defer' ) );
confere( 'script: sem dependência', $s['deps'] ?? null, array() );

novo( array( 'admin' => true ) );
rastro_eventos_script();
confere( 'script: nada no painel', isset( $GLOBALS['rastro_teste']['scripts']['rastro-eventos'] ), false );

/* 10. Filhos de outros tipos pelo filtro --------------------------------------------------- */

add_filter( 'rastro_eventos_chaves_filho', static function ( $c ) { $c['bundled_by'] = '_bundle_cart_key'; $c['Ruim'] = 'x'; return $c; } );
confere( 'filtro de filhos: acrescenta e limpa', rastro_eventos_chaves_filho(), array( 'composite_parent' => '_composite_cart_key', 'bundled_by' => '_bundle_cart_key' ) );
confere( 'filtro de filhos: pacote', array_keys( rastro_eventos_itens_carrinho( array( 'b' => array( 'data' => $GLOBALS['rastro_teste_produtos'][10], 'quantity' => 1, 'line_total' => 5 ), 'c' => array( 'data' => $GLOBALS['rastro_teste_produtos'][41], 'quantity' => 1, 'line_total' => 2, 'bundled_by' => 'b' ) ) ) ), array( 'b' ) );
remove_all_filters( 'rastro_eventos_chaves_filho' );
add_filter( 'rastro_eventos_chaves_filho', static function () { return 'quebrado'; } );
confere( 'filtro de filhos inválido: padrão', rastro_eventos_chaves_filho(), array( 'composite_parent' => '_composite_cart_key' ) );
remove_all_filters( 'rastro_eventos_chaves_filho' );

/* 11. Sem WooCommerce (processo novo) ------------------------------------------------------- */

$saida = shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' --cenario=sem_woo' );
confere(
	'sem WooCommerce: nada dos eventos, sem erro',
	json_decode( (string) $saida, true ),
	array(
		'ativo'   => false,
		'ligados' => array(),
		'eventos' => false,
		'medir'   => true,
		'script'  => false,
	)
);

confere( 'nenhuma falha registrada nos módulos', $falhas_registradas, array() );

echo "{$total} conferências dos eventos da loja do Rastro, {$falhas} falha(s).\n";
exit( $falhas > 0 ? 1 : 0 );
