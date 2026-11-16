<?php
/**
 * Testes dos relatórios do Rastro (frente T6: relatorios/dados.php e
 * relatorios/relatorios.php), sem WordPress e sem WooCommerce: fórmulas
 * (desconto, imposto, frete, reembolso parcial e total, centavos),
 * agregação por dimensão e modelo, grupo "Outras", períodos e limites,
 * leitura em páginas de 200, cache e invalidação, CSV (BOM, separador,
 * vírgula decimal, proteção contra fórmula), aba, tela, AJAX e CSV com
 * capacidade e nonce.
 *
 * Uso: php tests/php/rastro-relatorios.test.php (sai com 1 se algo falhar).
 * Contrato: docs/CONTRATO.md, seção 8.
 *
 * Carrega só os arquivos da base e os da T6 (não o rastro.php inteiro): assim
 * o teste da origem de reserva vale mesmo depois que a T5 entrar.
 *
 * @package Rastro
 */

define( 'ABSPATH', __DIR__ . '/' );

/* Stubs extras desta frente (antes dos compartilhados) ------------------------- */

/** Fuso do site nos testes. */
function wp_timezone() { // phpcs:ignore
	return new DateTimeZone( 'America/Sao_Paulo' );
}

/** Pedidos falsos da loja e consultas feitas. */
$GLOBALS['rastro_teste_pedidos']   = array();
$GLOBALS['rastro_teste_consultas'] = array();

/**
 * wc_get_orders() falso: filtra por status e por `date_created` em
 * timestamps (inicio...fim), ordena por data e pagina como o WooCommerce.
 */
function wc_get_orders( $args ) { // phpcs:ignore
	$GLOBALS['rastro_teste_consultas'][] = $args;
	$status = (array) ( $args['status'] ?? array() );
	$ini    = 0;
	$fim    = PHP_INT_MAX;
	if ( isset( $args['date_created'] ) && preg_match( '/^(\d+)\.\.\.(\d+)$/D', (string) $args['date_created'], $m ) ) {
		$ini = (int) $m[1];
		$fim = (int) $m[2];
	}
	$lista = array();
	foreach ( $GLOBALS['rastro_teste_pedidos'] as $p ) {
		if ( ! in_array( $p->status, $status, true ) ) {
			continue;
		}
		$ts = $p->get_date_created()->getTimestamp();
		if ( $ts < $ini || $ts > $fim ) {
			continue;
		}
		$lista[] = $p;
	}
	usort( $lista, function ( $a, $b ) { return $a->get_date_created()->getTimestamp() <=> $b->get_date_created()->getTimestamp(); } );
	$limite = (int) ( $args['limit'] ?? 10 );
	$pagina = (int) ( $args['page'] ?? 1 );
	$fatia  = array_slice( $lista, ( $pagina - 1 ) * $limite, $limite );
	if ( 'ids' === ( $args['return'] ?? 'objects' ) ) {
		$fatia = array_map( function ( $p ) { return $p->get_id(); }, $fatia );
	}
	if ( empty( $args['paginate'] ) ) {
		return $fatia;
	}
	return (object) array(
		'orders'        => $fatia,
		'total'         => count( $lista ),
		'max_num_pages' => (int) ceil( count( $lista ) / max( 1, $limite ) ),
	);
}

/** Pedido por ID (leitura um a um, sem a fábrica do WooCommerce). */
$GLOBALS['rastro_teste_lidos'] = array();
function wc_get_order( $id ) { // phpcs:ignore
	$GLOBALS['rastro_teste_lidos'][] = $id;
	foreach ( $GLOBALS['rastro_teste_pedidos'] as $p ) {
		if ( $p->get_id() === (int) $id ) {
			return $p;
		}
	}
	return false;
}

/** Caches preparados e memória liberada. */
$GLOBALS['rastro_teste_primos']  = array();
$GLOBALS['rastro_teste_flushes'] = 0;
function _prime_post_caches( $ids, $termos = true, $metas = true ) { // phpcs:ignore
	$GLOBALS['rastro_teste_primos'][] = array( $ids, $termos, $metas );
}
function wp_cache_flush_runtime() { // phpcs:ignore
	++$GLOBALS['rastro_teste_flushes'];
	return true;
}

/** WC() falso: com `fabrica`, a leitura usa order_factory->get_orders(). */
$GLOBALS['rastro_teste_wc'] = null;
function WC() { // phpcs:ignore
	return $GLOBALS['rastro_teste_wc'];
}
class Rastro_Teste_Fabrica { // phpcs:ignore
	public $lotes = array();
	public $quebrar = false;
	public function get_orders( $ids = array(), $pular_invalidos = false ) {
		if ( $this->quebrar ) {
			throw new Error( 'classe do pedido não achada' );
		}
		$this->lotes[] = array( $ids, $pular_invalidos );
		$saida = array();
		foreach ( $ids as $id ) {
			foreach ( $GLOBALS['rastro_teste_pedidos'] as $p ) {
				if ( $p->get_id() === (int) $id ) {
					$saida[] = $p;
				}
			}
		}
		return $saida;
	}
}

class Rastro_Teste_Item { // phpcs:ignore
	public $subtotal;
	public function __construct( $subtotal ) { $this->subtotal = $subtotal; }
	public function get_subtotal( $contexto = 'view' ) { return $this->subtotal; }
	public function get_total( $contexto = 'view' ) { return $this->subtotal; }
}

/** Reembolso com linhas de produto (total negativo, sem imposto). */
class Rastro_Teste_Reembolso { // phpcs:ignore
	public $linhas;
	public function __construct( array $linhas ) { $this->linhas = $linhas; }
	public function get_items( $tipo = 'line_item' ) {
		return 'line_item' === $tipo ? array_map( function ( $t ) { return new Rastro_Teste_Item( $t ); }, $this->linhas ) : array();
	}
}

/** Pedido falso com os métodos que os relatórios usam (e dados de cliente que não podem vazar). */
class Rastro_Teste_Pedido { // phpcs:ignore
	public static $proximo = 1000;
	public $id;
	public $status = 'processing';
	public $itens = array();
	public $desconto = 0;
	public $total = 0;
	public $reembolsado = 0;
	public $imposto_reembolsado = 0;
	/** Linhas de produto de cada reembolso: array de arrays de totais (negativos). */
	public $reembolsos = array();
	public $leu_reembolsos = 0;
	public $data;
	public $origem = array();
	public function __construct( array $v = array() ) {
		$this->id = ++self::$proximo;
		foreach ( $v as $k => $valor ) {
			$this->$k = $valor;
		}
		if ( ! $this->data instanceof DateTimeInterface ) {
			$this->data = new DateTimeImmutable( '2026-11-05 12:00:00', new DateTimeZone( 'America/Sao_Paulo' ) );
		}
	}
	public function get_id() { return $this->id; }
	public function get_type() { return 'shop_order'; }
	public function get_status() { return $this->status; }
	public function get_items( $tipo = 'line_item' ) {
		return array_map( function ( $s ) { return new Rastro_Teste_Item( $s ); }, $this->itens );
	}
	public function get_discount_total( $contexto = 'view' ) { return $this->desconto; }
	public function get_total( $contexto = 'view' ) { return $this->total; }
	public function get_total_refunded() { return $this->reembolsado; }
	public function get_total_tax_refunded() { return $this->imposto_reembolsado; }
	public function get_refunds() {
		++$this->leu_reembolsos;
		return array_map( function ( $l ) { return new Rastro_Teste_Reembolso( $l ); }, $this->reembolsos );
	}
	public function get_date_created( $contexto = 'view' ) { return $this->data; }
	public function get_billing_email() { return 'cliente@exemplo.com'; }
	public function get_billing_phone() { return '21999990000'; }
}

/** Reembolso no meio do resultado: tem de ser pulado. */
class WC_Order_Refund extends Rastro_Teste_Pedido { // phpcs:ignore
	public function get_type() { return 'shop_order_refund'; }
}

require __DIR__ . '/stubs-rastro.php';

$rastro_dir = realpath( __DIR__ . '/../../rastro' );
define( 'RASTRO_VERSAO', '0.1.0' );
define( 'RASTRO_ARQUIVO', $rastro_dir . '/rastro.php' );
define( 'RASTRO_DIR', $rastro_dir . '/' );
define( 'RASTRO_URL', 'https://loja.exemplo.com.br/wp-content/plugins/rastro/' );

rastro_teste_reset( array( 'admin' => true ) );
foreach ( array( 'nucleo/config.php', 'nucleo/regras.php', 'nucleo/saida.php', 'admin/admin.php', 'relatorios/dados.php', 'relatorios/relatorios.php' ) as $arquivo ) {
	require $rastro_dir . '/' . $arquivo;
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

/** Roda e devolve a parada (wp_die, wp_send_json, nonce) ou null. */
function parada( callable $fn ) { // phpcs:ignore
	ob_start();
	try {
		$fn();
	} catch ( Rastro_Teste_Parada $p ) {
		ob_end_clean();
		return $p;
	}
	ob_end_clean();
	return null;
}

function sp( string $quando ): DateTimeImmutable { // phpcs:ignore
	return new DateTimeImmutable( $quando, new DateTimeZone( 'America/Sao_Paulo' ) );
}

$cap_loja  = array( 'manage_woocommerce' => true );
$cap_admin = array( 'manage_woocommerce' => true, 'manage_options' => true );

/* 1. Fórmulas (centavos inteiros, negativo vira zero) ------------------------------ */

confere( 'centavos de float', rastro_relatorio_centavos( 19.99 ), 1999 );
confere( 'centavos de texto', rastro_relatorio_centavos( '227.40' ), 22740 );
confere( 'centavos de inteiro', rastro_relatorio_centavos( 10 ), 1000 );
confere( 'centavos de 0,1 + 0,2', rastro_relatorio_centavos( 0.1 + 0.2 ), 30 );
confere( 'centavos de lixo', rastro_relatorio_centavos( 'abc' ), 0 );
confere( 'centavos de null', rastro_relatorio_centavos( null ), 0 );

confere(
	'fórmula pura: a líquida tira só os itens reembolsados',
	rastro_relatorio_formulas( array( 'itens' => array( 10000, 5000 ), 'desconto' => 1500, 'itens_reembolsados' => 2000, 'reembolsado' => 2360, 'total' => 16000 ) ),
	array( 'bruta' => 15000, 'liquida' => 11500, 'total' => 13640 )
);
confere(
	'fórmula pura: reembolso só de frete não muda a líquida',
	rastro_relatorio_formulas( array( 'itens' => array( 10000 ), 'itens_reembolsados' => 0, 'reembolsado' => 1500, 'total' => 11500 ) ),
	array( 'bruta' => 10000, 'liquida' => 10000, 'total' => 10000 )
);
confere(
	'fórmula pura: itens reembolsados negativos valem pelo valor',
	rastro_relatorio_formulas( array( 'itens' => array( 10000 ), 'itens_reembolsados' => -3000, 'reembolsado' => 3000, 'total' => 10000 ) ),
	array( 'bruta' => 10000, 'liquida' => 7000, 'total' => 7000 )
);

$casos = array(
	'sem desconto, com frete' => array(
		array( 'itens' => array( 50.00, 30.00 ), 'total' => 95.00 ),
		array( 'bruta' => 8000, 'liquida' => 8000, 'total' => 9500 ),
	),
	'com desconto e frete'    => array(
		array( 'itens' => array( 100.00 ), 'desconto' => 10.00, 'total' => 110.00 ),
		array( 'bruta' => 10000, 'liquida' => 9000, 'total' => 11000 ),
	),
	'com imposto e frete'     => array(
		array( 'itens' => array( 100.00 ), 'total' => '128.00' ),
		array( 'bruta' => 10000, 'liquida' => 10000, 'total' => 12800 ),
	),
	'reembolso parcial com imposto' => array(
		array( 'itens' => array( 120.00, 80.00 ), 'desconto' => 20.00, 'total' => 227.40, 'reembolsado' => '59.00', 'imposto_reembolsado' => 9.00, 'reembolsos' => array( array( '-50.00' ) ) ),
		array( 'bruta' => 20000, 'liquida' => 13000, 'total' => 16840 ),
	),
	'reembolso total'         => array(
		array( 'itens' => array( 100.00 ), 'total' => 115.00, 'reembolsado' => 115.00, 'reembolsos' => array( array( -100.00 ) ) ),
		array( 'bruta' => 10000, 'liquida' => 0, 'total' => 0 ),
	),
	'reembolso de dois itens em dois reembolsos' => array(
		array( 'itens' => array( 60.00, 40.00 ), 'total' => 120.00, 'reembolsado' => 72.00, 'reembolsos' => array( array( -30.00 ), array( -30.00 ) ) ),
		array( 'bruta' => 10000, 'liquida' => 4000, 'total' => 4800 ),
	),
	'reembolso só do frete (diferente do PixelYourSite)' => array(
		array( 'itens' => array( 100.00 ), 'total' => 120.00, 'reembolsado' => 20.00, 'imposto_reembolsado' => 0, 'reembolsos' => array( array() ) ),
		array( 'bruta' => 10000, 'liquida' => 10000, 'total' => 10000 ),
	),
	'reembolso só de valor, sem linhas' => array(
		array( 'itens' => array( 100.00 ), 'total' => 100.00, 'reembolsado' => 25.00 ),
		array( 'bruta' => 10000, 'liquida' => 10000, 'total' => 7500 ),
	),
	'arredondamento em centavos' => array(
		array( 'itens' => array( 19.99, 19.99, 19.99, 0.1 + 0.2 ), 'total' => '60.27' ),
		array( 'bruta' => 6027, 'liquida' => 6027, 'total' => 6027 ),
	),
	'desconto maior que a bruta' => array(
		array( 'itens' => array( 10.00 ), 'desconto' => 15.00, 'total' => 0 ),
		array( 'bruta' => 1000, 'liquida' => 0, 'total' => 0 ),
	),
	'sem itens'               => array(
		array( 'itens' => array(), 'total' => 0 ),
		array( 'bruta' => 0, 'liquida' => 0, 'total' => 0 ),
	),
);
foreach ( $casos as $nome => $caso ) {
	confere( 'fórmulas: ' . $nome, rastro_relatorio_valores( new Rastro_Teste_Pedido( $caso[0] ) ), $caso[1] );
}
$sem_reembolso = new Rastro_Teste_Pedido( array( 'itens' => array( 10.00 ), 'total' => 10.00, 'reembolsos' => array( array( -10.00 ) ) ) );
rastro_relatorio_valores( $sem_reembolso );
confere( 'fórmulas: sem nada reembolsado, os reembolsos nem são lidos', $sem_reembolso->leu_reembolsos, 0 );

/* 2. Períodos e limites --------------------------------------------------------- */

$agora = sp( '2026-11-11 15:00:00' );
$esperados = array(
	'hoje'        => array( '2026-11-11', '2026-11-11', 1 ),
	'ontem'       => array( '2026-11-10', '2026-11-10', 1 ),
	'7d'          => array( '2026-11-05', '2026-11-11', 7 ),
	'30d'         => array( '2026-10-13', '2026-11-11', 30 ),
	'este_mes'    => array( '2026-11-01', '2026-11-11', 11 ),
	'mes_passado' => array( '2026-10-01', '2026-10-31', 31 ),
	'90d'         => array( '2026-08-14', '2026-11-11', 90 ),
	'12m'         => array( '2025-11-12', '2026-11-11', 365 ),
);
foreach ( $esperados as $periodo => $e ) {
	confere(
		'período ' . $periodo,
		rastro_relatorio_periodo( $periodo, '', '', $agora ),
		array( 'periodo' => (string) $periodo, 'inicio' => $e[0], 'fim' => $e[1], 'dias' => $e[2] )
	);
}
confere( 'lista de períodos', array_keys( rastro_relatorio_periodos() ), array( 'hoje', 'ontem', '7d', '30d', 'este_mes', 'mes_passado', '90d', '12m', 'personalizado' ) );
confere( 'mês passado em janeiro', rastro_relatorio_periodo( 'mes_passado', '', '', sp( '2026-01-15 09:00:00' ) )['inicio'] . '/' . rastro_relatorio_periodo( 'mes_passado', '', '', sp( '2026-01-15 09:00:00' ) )['fim'], '2025-12-01/2025-12-31' );
// 01:30 UTC do dia 12 ainda é dia 11 em São Paulo.
confere( 'hoje no fuso do site', rastro_relatorio_periodo( 'hoje', '', '', new DateTimeImmutable( '2026-11-12 01:30:00', new DateTimeZone( 'UTC' ) ) )['inicio'], '2026-11-11' );
confere( 'personalizado válido', rastro_relatorio_periodo( 'personalizado', '2026-01-01', '2026-12-31', $agora )['dias'], 365 );
confere( 'personalizado de 366 dias (ano bissexto)', rastro_relatorio_periodo( 'personalizado', '2024-01-01', '2024-12-31', $agora )['dias'], 366 );
confere( 'personalizado de 367 dias', rastro_relatorio_periodo( 'personalizado', '2024-01-01', '2025-01-01', $agora ), null );
confere( 'personalizado invertido troca', rastro_relatorio_periodo( 'personalizado', '2026-11-10', '2026-11-01', $agora )['inicio'], '2026-11-01' );
confere( 'personalizado de um dia', rastro_relatorio_periodo( 'personalizado', '2026-11-10', '2026-11-10', $agora )['dias'], 1 );
foreach ( array( array( '2026-02-30', '2026-03-01' ), array( '2026-1-1', '2026-01-02' ), array( "2026-01-01\n", '2026-01-02' ), array( '', '2026-01-02' ), array( '1999-12-31', '2000-01-02' ), array( '01/01/2026', '2026-01-02' ) ) as $ruim ) {
	confere( 'personalizado inválido ' . json_encode( $ruim ), rastro_relatorio_periodo( 'personalizado', $ruim[0], $ruim[1], $agora ), null );
}
confere( 'período desconhecido', rastro_relatorio_periodo( 'semana', '', '', $agora ), null );
confere( 'limites no fuso do site', rastro_relatorio_limites( '2026-11-11', '2026-11-11' ), array( ( new DateTimeImmutable( '2026-11-11 03:00:00 UTC' ) )->getTimestamp(), ( new DateTimeImmutable( '2026-11-12 02:59:59 UTC' ) )->getTimestamp() ) );
confere( 'dias do intervalo', rastro_relatorio_dias( '2026-02-27', '2026-03-01' ), array( '2026-02-27', '2026-02-28', '2026-03-01' ) );
confere( 'dia do pedido no fuso do site', rastro_relatorio_dia_do_pedido( new Rastro_Teste_Pedido( array( 'data' => new DateTimeImmutable( '2026-11-12 01:30:00', new DateTimeZone( 'UTC' ) ) ) ) ), '2026-11-11' );

// Argumentos por lista fechada.
$base_args = array( 'dimensao' => 'fonte', 'inicio' => '2026-11-01', 'fim' => '2026-11-11', 'modelo' => 'primeira' );
confere( 'args: padrão dos status vem da configuração', rastro_relatorio_args( $base_args )['status'], array( 'completed', 'processing' ) );
confere( 'args: dimensão fora da lista', rastro_relatorio_args( array_merge( $base_args, array( 'dimensao' => 'email' ) ) ), null );
confere( 'args: modelo fora da lista', rastro_relatorio_args( array_merge( $base_args, array( 'modelo' => 'linear' ) ) ), null );
confere( 'args: período longo demais', rastro_relatorio_args( array_merge( $base_args, array( 'inicio' => '2024-01-01', 'fim' => '2025-01-01' ) ) ), null );
confere( 'args: status limpos', rastro_relatorio_args( array_merge( $base_args, array( 'status' => array( 'wc-completed', 'XX x', 'inexistente', 'on-hold', 'completed' ) ) ) )['status'], array( 'completed', 'on-hold' ) );
confere( 'args: sem status válido', rastro_relatorio_args( array_merge( $base_args, array( 'status' => array( 'inexistente' ) ) ) ), null );
rastro_teste_reset( array( 'admin' => true, 'opcoes' => array( 'rastro_config' => array( 'status_relatorio' => array( 'completed' ) ) ) ) );
rastro_config_redefinir_cache();
confere( 'args: status da aba WooCommerce', rastro_relatorio_status_config(), array( 'completed' ) );
rastro_teste_reset( array( 'admin' => true ) );
rastro_config_redefinir_cache();

/* 3. Origem de reserva (sem a T5) ------------------------------------------------- */

$sem_t5 = new Rastro_Teste_Pedido( array( 'itens' => array( 10.00 ), 'total' => 10.00 ) );
confere( 'sem rastro_pedido_origem no teste', function_exists( 'rastro_pedido_origem' ), false );
confere( 'reserva: fonte (sem dados)', rastro_relatorio_origem( $sem_t5, 'primeira' )['fonte'], '(sem dados)' );
confere( 'reserva: utm vazio', rastro_relatorio_origem( $sem_t5, 'ultima' )['utm_source'], '' );
confere( 'reserva: chaves da T5', array_keys( rastro_relatorio_origem_reserva() ), array( 'fonte', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'utm_id', 'clique', 'referrer', 'entrada', 'quando', 'origem_dado' ) );
$agg = rastro_relatorio_agregar( array( rastro_relatorio_registro( $sem_t5, 'primeira' ) ), 'fonte' );
confere( 'reserva: uma linha (sem dados)', array_column( $agg['linhas'], 'valor' ), array( '(sem dados)' ) );
confere( 'registro: reembolso pulado', rastro_relatorio_registro( new WC_Order_Refund(), 'primeira' ), null );
confere( 'registro: só dia, valores e origem', array_keys( rastro_relatorio_registro( $sem_t5, 'primeira' ) ), array( 'dia', 'bruta', 'liquida', 'total', 'origem' ) );
confere( 'registro: origem só com as dimensões', array_keys( rastro_relatorio_registro( $sem_t5, 'primeira' )['origem'] ), array_keys( rastro_relatorio_dimensoes() ) );

// Daqui em diante, a leitura da T5 simulada pelo próprio pedido falso.
if ( ! function_exists( 'rastro_pedido_origem' ) ) {
	function rastro_pedido_origem( $pedido, string $modelo = 'primeira' ): array { // phpcs:ignore
		if ( isset( $pedido->origem['erro'] ) ) {
			throw new RuntimeException( 'falha simulada' );
		}
		return array_merge( rastro_relatorio_origem_reserva(), array( 'fonte' => '' ), $pedido->origem[ $modelo ] ?? array() );
	}
}
confere( 'erro na leitura vira reserva', rastro_relatorio_origem( new Rastro_Teste_Pedido( array( 'origem' => array( 'erro' => 1 ) ) ), 'primeira' )['fonte'], '(sem dados)' );
confere( 'valor limpo de controle e cortado em 200', rastro_relatorio_origem( new Rastro_Teste_Pedido( array( 'origem' => array( 'primeira' => array( 'utm_term' => "a\nb" . str_repeat( 'x', 300 ) ) ) ) ), 'primeira' )['utm_term'], 'a b' . str_repeat( 'x', 197 ) );

/* 4. Agregação por dimensão e modelo ------------------------------------------------- */

$p1 = new Rastro_Teste_Pedido(
	array(
		'itens'  => array( 100.00 ),
		'total'  => 100.00,
		'data'   => sp( '2026-11-01 10:00:00' ),
		'origem' => array(
			'primeira' => array( 'fonte' => 'google', 'utm_source' => 'google', 'utm_medium' => 'cpc', 'utm_campaign' => 'natal', 'utm_content' => 'anuncio-a', 'utm_term' => 'racao', 'entrada' => '/produto/comedouro/' ),
			'ultima'   => array( 'fonte' => 'direto' ),
		),
	)
);
$p2 = new Rastro_Teste_Pedido(
	array(
		'itens'  => array( 50.00 ),
		'total'  => 60.00,
		'data'   => sp( '2026-11-01 18:00:00' ),
		'origem' => array(
			'primeira' => array( 'fonte' => 'instagram.com', 'entrada' => '/' ),
			'ultima'   => array( 'fonte' => 'google', 'utm_source' => 'google', 'utm_medium' => 'cpc', 'utm_campaign' => 'natal', 'utm_content' => 'anuncio-b', 'utm_term' => 'racao', 'entrada' => '/produto/comedouro/' ),
		),
	)
);
$p3 = new Rastro_Teste_Pedido(
	array(
		'itens'  => array( 30.00 ),
		'total'  => 30.00,
		'data'   => sp( '2026-11-03 09:00:00' ),
		'origem' => array(),
	)
);
$registros = function ( string $modelo ) use ( $p1, $p2, $p3 ) {
	return array_map( function ( $p ) use ( $modelo ) { return rastro_relatorio_registro( $p, $modelo ); }, array( $p1, $p2, $p3 ) );
};
$valores_por = function ( string $modelo, string $dimensao ) use ( $registros ) {
	$a = rastro_relatorio_agregar( $registros( $modelo ), $dimensao );
	$r = array();
	foreach ( $a['linhas'] as $l ) {
		$r[ $l['valor'] ] = array( $l['pedidos'], $l['total'] );
	}
	return $r;
};
confere( 'primeira visita: fonte', $valores_por( 'primeira', 'fonte' ), array( 'google' => array( 1, 10000 ), 'instagram.com' => array( 1, 6000 ), '(sem dados)' => array( 1, 3000 ) ) );
confere( 'última visita: fonte', $valores_por( 'ultima', 'fonte' ), array( 'direto' => array( 1, 10000 ), 'google' => array( 1, 6000 ), '(sem dados)' => array( 1, 3000 ) ) );
confere( 'primeira visita: página de entrada', $valores_por( 'primeira', 'entrada' ), array( '/produto/comedouro/' => array( 1, 10000 ), '/' => array( 1, 6000 ), '(não definido)' => array( 1, 3000 ) ) );
confere( 'última visita: página de entrada', $valores_por( 'ultima', 'entrada' ), array( '(não definido)' => array( 2, 13000 ), '/produto/comedouro/' => array( 1, 6000 ) ) );
foreach ( array( 'utm_source' => 'google', 'utm_medium' => 'cpc', 'utm_campaign' => 'natal' ) as $dimensao => $valor ) {
	confere( 'primeira visita: ' . $dimensao, $valores_por( 'primeira', $dimensao ), array( $valor => array( 1, 10000 ), '(não definido)' => array( 2, 9000 ) ) );
	confere( 'última visita: ' . $dimensao, $valores_por( 'ultima', $dimensao ), array( '(não definido)' => array( 2, 13000 ), $valor => array( 1, 6000 ) ) );
}
confere( 'primeira visita: utm_content', $valores_por( 'primeira', 'utm_content' ), array( 'anuncio-a' => array( 1, 10000 ), '(não definido)' => array( 2, 9000 ) ) );
confere( 'última visita: utm_content', $valores_por( 'ultima', 'utm_content' ), array( '(não definido)' => array( 2, 13000 ), 'anuncio-b' => array( 1, 6000 ) ) );
confere( 'última visita: utm_term', $valores_por( 'ultima', 'utm_term' ), array( '(não definido)' => array( 2, 13000 ), 'racao' => array( 1, 6000 ) ) );

$agg = rastro_relatorio_agregar( $registros( 'primeira' ), 'fonte', rastro_relatorio_dias( '2026-11-01', '2026-11-03' ) );
confere( 'totais', $agg['totais'], array( 'valores' => 3, 'pedidos' => 3, 'bruta' => 18000, 'liquida' => 18000, 'total' => 19000 ) );
confere( 'série: eixo dos dias com zeros', $agg['serie']['dias'], array( '2026-11-01', '2026-11-02', '2026-11-03' ) );
confere( 'série: google por dia', $agg['serie']['series'][0], array( 'nome' => 'google', 'outras' => false, 'pedidos' => array( 1, 0, 0 ), 'total' => array( 10000, 0, 0 ) ) );
confere( 'série: (sem dados) no dia 3', $agg['serie']['series'][2]['total'], array( 0, 0, 3000 ) );
confere( 'série: sem eixo usa os dias dos pedidos', rastro_relatorio_agregar( $registros( 'primeira' ), 'fonte' )['serie']['dias'], array( '2026-11-01', '2026-11-03' ) );
confere( 'dimensão inválida: vazio', rastro_relatorio_agregar( $registros( 'primeira' ), 'email' )['linhas'], array() );

// Empate: total igual, mais pedidos primeiro; depois o nome.
$empate = rastro_relatorio_agregar(
	array(
		array( 'dia' => '2026-11-01', 'bruta' => 100, 'liquida' => 100, 'total' => 100, 'origem' => array( 'fonte' => 'b' ) ),
		array( 'dia' => '2026-11-01', 'bruta' => 100, 'liquida' => 100, 'total' => 100, 'origem' => array( 'fonte' => 'a' ) ),
		array( 'dia' => '2026-11-01', 'bruta' => 50, 'liquida' => 50, 'total' => 50, 'origem' => array( 'fonte' => 'c' ) ),
		array( 'dia' => '2026-11-01', 'bruta' => 50, 'liquida' => 50, 'total' => 50, 'origem' => array( 'fonte' => 'c' ) ),
	),
	'fonte'
);
confere( 'ordem das linhas', array_column( $empate['linhas'], 'valor' ), array( 'c', 'a', 'b' ) );

/* 5. Grupo "Outras" ----------------------------------------------------------------- */

$muitos = array();
for ( $i = 0; $i < 10; $i++ ) {
	$muitos[] = array( 'dia' => 0 === $i % 2 ? '2026-11-01' : '2026-11-02', 'bruta' => 1000 - $i * 10, 'liquida' => 1000 - $i * 10, 'total' => 1000 - $i * 10, 'origem' => array( 'fonte' => 'f' . $i ) );
}
$agg = rastro_relatorio_agregar( $muitos, 'fonte', array( '2026-11-01', '2026-11-02' ) );
confere( 'outras: 10 linhas na tabela', count( $agg['linhas'] ), 10 );
confere( 'outras: 8 séries', count( $agg['serie']['series'] ), 8 );
confere( 'outras: 7 maiores pelo nome', array_slice( array_column( $agg['serie']['series'], 'nome' ), 0, 7 ), array( 'f0', 'f1', 'f2', 'f3', 'f4', 'f5', 'f6' ) );
confere( 'outras: última série', array( $agg['serie']['series'][7]['nome'], $agg['serie']['series'][7]['outras'] ), array( 'Outras', true ) );
// f7 (dia 2, 930), f8 (dia 1, 920), f9 (dia 2, 910).
confere( 'outras: soma por dia', $agg['serie']['series'][7]['total'], array( 920, 1840 ) );
confere( 'outras: pedidos por dia', $agg['serie']['series'][7]['pedidos'], array( 1, 2 ) );
$oito = rastro_relatorio_agregar( array_slice( $muitos, 0, 8 ), 'fonte' );
confere( 'com 8 valores: 8 séries sem Outras', array( count( $oito['serie']['series'] ), in_array( true, array_column( $oito['serie']['series'], 'outras' ), true ) ), array( 8, false ) );
$um = rastro_relatorio_agregar( array_slice( $muitos, 0, 1 ), 'fonte' );
confere( 'com 1 valor: 1 série', count( $um['serie']['series'] ), 1 );

/* 6. Leitura em páginas de 200, cache e invalidação ----------------------------------- */

rastro_teste_reset( array( 'admin' => true ) );
$GLOBALS['rastro_teste_pedidos'] = array();
for ( $i = 0; $i < 450; $i++ ) {
	$GLOBALS['rastro_teste_pedidos'][] = new Rastro_Teste_Pedido(
		array(
			'status' => 0 === $i % 2 ? 'processing' : 'completed',
			'itens'  => array( 10.00 ),
			'total'  => 12.00,
			'data'   => sp( '2026-11-0' . ( 1 + $i % 9 ) . ' 12:00:00' ),
			'origem' => array( 'primeira' => array( 'fonte' => 0 === $i % 3 ? 'google' : 'direto' ) ),
		)
	);
}
$GLOBALS['rastro_teste_pedidos'][] = new Rastro_Teste_Pedido( array( 'status' => 'cancelled', 'itens' => array( 999.00 ), 'total' => 999.00, 'data' => sp( '2026-11-02 12:00:00' ) ) );
$GLOBALS['rastro_teste_pedidos'][] = new Rastro_Teste_Pedido( array( 'status' => 'completed', 'itens' => array( 999.00 ), 'total' => 999.00, 'data' => sp( '2026-10-31 23:59:00' ) ) );
$GLOBALS['rastro_teste_pedidos'][] = new WC_Order_Refund( array( 'status' => 'completed', 'data' => sp( '2026-11-02 12:00:00' ) ) );
$GLOBALS['rastro_teste_consultas'] = array();

$args_dados = array( 'dimensao' => 'fonte', 'inicio' => '2026-11-01', 'fim' => '2026-11-11', 'modelo' => 'primeira' );
$dados      = rastro_relatorio_dados( $args_dados );
confere( 'leitura: 3 páginas', count( $GLOBALS['rastro_teste_consultas'] ), 3 );
$c = $GLOBALS['rastro_teste_consultas'][0];
confere( 'consulta: tipo, status, página, limite, paginate, ordem, só IDs', array( $c['type'], $c['status'], $c['page'], $c['limit'], $c['paginate'], $c['orderby'], $c['order'], $c['return'] ), array( 'shop_order', array( 'completed', 'processing' ), 1, 200, true, 'date', 'ASC', 'ids' ) );
confere( 'leitura sem a fábrica: caches dos posts preparados por página (sem termos, com metas)', array( count( $GLOBALS['rastro_teste_primos'] ), count( $GLOBALS['rastro_teste_primos'][0][0] ), $GLOBALS['rastro_teste_primos'][0][1], $GLOBALS['rastro_teste_primos'][0][2] ), array( 3, 200, false, true ) );
confere( 'leitura sem a fábrica: pedido a pedido', count( $GLOBALS['rastro_teste_lidos'] ), 451 );
confere( 'leitura: memória liberada a cada página', $GLOBALS['rastro_teste_flushes'], 3 );
confere( 'consulta: date_created em timestamps', $c['date_created'], implode( '...', rastro_relatorio_limites( '2026-11-01', '2026-11-11' ) ) );
confere( 'consulta: páginas 2 e 3', array( $GLOBALS['rastro_teste_consultas'][1]['page'], $GLOBALS['rastro_teste_consultas'][2]['page'] ), array( 2, 3 ) );
confere( 'leitura: 450 pedidos (sem cancelado, fora do período e reembolso)', $dados['totais']['pedidos'], 450 );
confere( 'leitura: venda total', $dados['totais']['total'], 450 * 1200 );
confere( 'leitura: eixo de 11 dias', count( $dados['serie']['dias'] ), 11 );
confere( 'leitura: sem erro', array( $dados['erro'], $dados['truncado'], $dados['moeda'] ), array( '', false, 'BRL' ) );
$transients = $GLOBALS['rastro_teste']['transients'];
confere( 'cache: um transient de 10 minutos', array_values( array_map( function ( $t ) { return $t['prazo']; }, $transients ) ), array( 600 ) );
confere( 'cache: chave com prefixo e hash', 1 === preg_match( '/^rastro_relatorio_[a-f0-9]{32}$/D', (string) array_keys( $transients )[0] ), true );

rastro_relatorio_dados( $args_dados );
confere( 'cache: segunda leitura sem consulta', count( $GLOBALS['rastro_teste_consultas'] ), 3 );
rastro_relatorio_dados( array_merge( $args_dados, array( 'modelo' => 'ultima' ) ) );
confere( 'cache: outro modelo consulta de novo', count( $GLOBALS['rastro_teste_consultas'] ), 6 );

do_action( 'woocommerce_order_status_changed', 1001, 'pending', 'processing', null );
confere( 'invalidação: versão sobe', get_option( RASTRO_RELATORIO_CACHE_OPCAO ), 2 );
confere( 'invalidação: opção sem autoload', $GLOBALS['rastro_teste']['autoload'][ RASTRO_RELATORIO_CACHE_OPCAO ], false );
rastro_relatorio_dados( $args_dados );
confere( 'invalidação: status mudou, lê de novo', count( $GLOBALS['rastro_teste_consultas'] ), 9 );
confere( 'invalidação: nada apagado', count( $GLOBALS['rastro_teste']['transients'] ), 3 );
foreach ( array( 'woocommerce_order_refunded', 'woocommerce_refund_deleted', 'woocommerce_trash_order', 'woocommerce_delete_order' ) as $gancho ) {
	$antes = rastro_relatorio_cache_versao();
	do_action( $gancho, 1001, 2002 );
	confere( 'invalidação: ' . $gancho, rastro_relatorio_cache_versao(), $antes + 1 );
}

// Com a fábrica do WooCommerce: um lote por página, inválidos pulados.
$fabrica                    = new Rastro_Teste_Fabrica();
$GLOBALS['rastro_teste_wc'] = (object) array( 'order_factory' => $fabrica );
$GLOBALS['rastro_teste_primos'] = array();
$GLOBALS['rastro_teste_lidos']  = array();
$com_fabrica = rastro_relatorio_dados( array_merge( $args_dados, array( 'dimensao' => 'utm_medium' ) ) );
confere( 'fábrica: um lote por página, com pular inválidos', array( count( $fabrica->lotes ), count( $fabrica->lotes[0][0] ), $fabrica->lotes[0][1] ), array( 3, 200, true ) );
confere( 'fábrica: sem leitura um a um nem preparo à parte', array( count( $GLOBALS['rastro_teste_lidos'] ), count( $GLOBALS['rastro_teste_primos'] ) ), array( 0, 0 ) );
confere( 'fábrica: mesmos totais', $com_fabrica['totais']['pedidos'], 450 );
// Fábrica com erro: volta para a leitura um a um, sem quebrar.
$fabrica->quebrar = true;
$com_erro         = rastro_relatorio_dados( array_merge( $args_dados, array( 'dimensao' => 'utm_content' ) ) );
confere( 'fábrica com erro: leitura um a um', array( $com_erro['totais']['pedidos'], count( $GLOBALS['rastro_teste_lidos'] ) > 0 ), array( 450, true ) );
$GLOBALS['rastro_teste_wc'] = null;
confere( 'máximo padrão: 50 páginas (10 mil pedidos)', RASTRO_RELATORIO_MAX_PAGINAS * RASTRO_RELATORIO_PAGINA, 10000 );
$consultas_antes = count( $GLOBALS['rastro_teste_consultas'] );

add_filter( 'rastro_relatorio_max_paginas', function () { return 2; } );
$truncado = rastro_relatorio_dados( array_merge( $args_dados, array( 'dimensao' => 'utm_source' ) ) );
// 400 lidos nas 2 páginas; o reembolso falso está entre eles e é pulado.
confere( 'limite de páginas: truncado', array( $truncado['truncado'], $truncado['totais']['pedidos'], count( $GLOBALS['rastro_teste_consultas'] ) - $consultas_antes ), array( true, 399, 2 ) );
remove_all_filters( 'rastro_relatorio_max_paginas' );

$invalido = rastro_relatorio_dados( array( 'dimensao' => 'email' ) );
confere( 'dados: argumento inválido', array( $invalido['erro'], $invalido['linhas'], $invalido['args'] ), array( 'argumentos', array(), null ) );

/* 7. CSV ---------------------------------------------------------------------------- */

confere( 'csv moeda', array( rastro_relatorio_csv_moeda( 123456 ), rastro_relatorio_csv_moeda( 5 ), rastro_relatorio_csv_moeda( 0 ), rastro_relatorio_csv_moeda( -500 ) ), array( '1234,56', '0,05', '0,00', '-5,00' ) );
foreach ( array( '=1+1' => "'=1+1", '+55' => "'+55", '-2' => "'-2", '@SOMA' => "'@SOMA", "\tx" => "'\tx", "\rx" => "'\rx", 'google' => 'google', '' => '' ) as $entrada => $saida ) {
	confere( 'csv célula ' . json_encode( $entrada ), rastro_relatorio_csv_celula( (string) $entrada ), $saida );
}
confere( 'csv célula não escalar', rastro_relatorio_csv_celula( array( 'x' ) ), '' );

$csv_dados = array(
	'linhas' => array(
		array( 'valor' => '=HYPERLINK("x";"y")', 'pedidos' => 2, 'bruta' => 123456, 'liquida' => 100005, 'total' => 130000 ),
		array( 'valor' => 'google', 'pedidos' => 1, 'bruta' => 5, 'liquida' => 0, 'total' => 5 ),
	),
	'totais' => array( 'valores' => 2, 'pedidos' => 3, 'bruta' => 123461, 'liquida' => 100005, 'total' => 130005 ),
);
$texto = rastro_relatorio_csv_texto( rastro_relatorio_csv_linhas( $csv_dados, 'utm_source' ) );
confere( 'csv: BOM UTF-8', substr( $texto, 0, 3 ), "\xEF\xBB\xBF" );
$linhas_csv = explode( "\n", rtrim( substr( $texto, 3 ), "\n" ) );
confere( 'csv: cabeçalho', $linhas_csv[0], 'utm_source;Pedidos;"Venda bruta";"Venda líquida";"Venda total"' );
confere( 'csv: fórmula neutralizada e vírgula decimal', $linhas_csv[1], "\"'=HYPERLINK(\"\"x\"\";\"\"y\"\")\";2;1234,56;1000,05;1300,00" );
confere( 'csv: centavos', $linhas_csv[2], 'google;1;0,05;0,00;0,05' );
confere( 'csv: linha do total', $linhas_csv[3], 'Total;3;1234,61;1000,05;1300,05' );

rastro_teste_reset( array( 'admin' => true ) );
$csv = rastro_relatorio_csv_preparar( array( 'dimensao' => 'utm_source', 'periodo' => 'personalizado', 'de' => '2026-11-01', 'ate' => '2026-11-11', 'modelo' => 'primeira' ) );
confere( 'csv: nome com período', $csv['nome'], 'relatorio-utm-source-2026-11-01-a-2026-11-11-primeira.csv' );
confere( 'csv: sem dado de cliente', false === strpos( $csv['texto'], 'cliente@exemplo.com' ) && false === strpos( $csv['texto'], '21999990000' ), true );
$csv_dia = rastro_relatorio_csv_preparar( array( 'dimensao' => 'fonte', 'periodo' => 'personalizado', 'de' => '2026-11-03', 'ate' => '2026-11-03', 'modelo' => 'ultima' ) );
confere( 'csv: nome de um dia', $csv_dia['nome'], 'relatorio-fonte-2026-11-03-ultima.csv' );
confere( 'csv: filtro inválido', rastro_relatorio_csv_preparar( array( 'dimensao' => '../x' ) ), null );
confere( 'csv: período de 400 dias', rastro_relatorio_csv_preparar( array( 'periodo' => 'personalizado', 'de' => '2025-01-01', 'ate' => '2026-02-05' ) ), null );

/* 7b. Planilha do Excel (.xlsx, 2026-11-13) ---------------------------------------- */

confere( 'xlsx: colunas', array( rastro_xlsx_coluna( 0 ), rastro_xlsx_coluna( 4 ), rastro_xlsx_coluna( 25 ), rastro_xlsx_coluna( 26 ), rastro_xlsx_coluna( 701 ), rastro_xlsx_coluna( 702 ) ), array( 'A', 'E', 'Z', 'AA', 'ZZ', 'AAA' ) );
confere( 'xlsx: XML escapado e sem controle', rastro_xlsx_xml( "<b>&\"x'\x01\x08ç" ), '&lt;b&gt;&amp;&quot;x&apos;ç' );
confere( 'xlsx: não escalar vira vazio', rastro_xlsx_xml( array( 1 ) ), '' );
confere( 'xlsx: emoji fica', rastro_xlsx_xml( "Pet \u{1F436}" ), "Pet \u{1F436}" );
$zip_antigo = rastro_xlsx_zip( array( 'a.txt' => 'x' ), 1000 );
confere( 'xlsx: data antes de 1980 vira 01/01/1980 no ZIP', unpack( 'vhora/vdata', substr( $zip_antigo, 10, 4 ) ), array( 'hora' => 0, 'data' => 33 ) );
$zip_novo = rastro_xlsx_zip( array( 'a.txt' => 'x' ), 1794614400 );
confere( 'xlsx: data em UTC no ZIP', unpack( 'vhora/vdata', substr( $zip_novo, 10, 4 ) ), array( 'hora' => ( (int) gmdate( 'G', 1794614400 ) << 11 ) | ( (int) gmdate( 'i', 1794614400 ) << 5 ), 'data' => ( ( (int) gmdate( 'Y', 1794614400 ) - 1980 ) << 9 ) | ( (int) gmdate( 'n', 1794614400 ) << 5 ) | (int) gmdate( 'j', 1794614400 ) ) );
confere( 'xlsx: nome da aba', array( rastro_xlsx_nome_aba( 'a/b:c*d?[e]' ), rastro_xlsx_nome_aba( '' ), strlen( rastro_xlsx_nome_aba( str_repeat( 'x', 40 ) ) ) ), array( 'a b c d e', 'Planilha', 31 ) );
confere( 'xlsx: célula de texto nunca é fórmula', rastro_xlsx_celula( '=HYPERLINK("x";"y")', 'A2' ), '<c r="A2" t="inlineStr"><is><t xml:space="preserve">=HYPERLINK(&quot;x&quot;;&quot;y&quot;)</t></is></c>' );
confere( 'xlsx: moeda como número', rastro_xlsx_celula( array( 'valor' => 1234.5, 'tipo' => 'moeda' ), 'C2' ), '<c r="C2" s="2"><v>1234.5</v></c>' );
confere( 'xlsx: moeda zero', rastro_xlsx_celula( array( 'valor' => 0, 'tipo' => 'moeda', 'negrito' => true ), 'C3' ), '<c r="C3" s="3"><v>0</v></c>' );
confere( 'xlsx: inteiro', rastro_xlsx_celula( array( 'valor' => 7, 'tipo' => 'inteiro' ), 'B2' ), '<c r="B2" s="4"><v>7</v></c>' );
confere( 'xlsx: moeda não numérica vira texto', rastro_xlsx_celula( array( 'valor' => 'abc', 'tipo' => 'moeda' ), 'C4' ), '<c r="C4" t="inlineStr"><is><t xml:space="preserve">abc</t></is></c>' );
confere( 'xlsx: tipo desconhecido vira texto', false !== strpos( rastro_xlsx_celula( array( 'valor' => 5, 'tipo' => 'formula' ), 'D1' ), 't="inlineStr"' ), true );

$xlsx_linhas = rastro_relatorio_xlsx_linhas( $csv_dados, 'utm_source' );
confere( 'xlsx: cabeçalho em negrito', array( $xlsx_linhas[0][0]['negrito'], $xlsx_linhas[0][2]['valor'] ), array( true, 'Venda bruta' ) );
confere( 'xlsx: valores em reais (centavos / 100)', array( $xlsx_linhas[1][2]['valor'], $xlsx_linhas[2][2]['valor'], $xlsx_linhas[2][3]['valor'] ), array( 1234.56, 0.05, 0 ) );
confere( 'xlsx: total em negrito', array( $xlsx_linhas[3][0]['valor'], $xlsx_linhas[3][1]['valor'], $xlsx_linhas[3][4]['negrito'] ), array( 'Total', 3, true ) );

$xlsx = rastro_relatorio_xlsx_preparar( array( 'dimensao' => 'utm_source', 'periodo' => 'personalizado', 'de' => '2026-11-01', 'ate' => '2026-11-11', 'modelo' => 'primeira' ), 1794614400 );
confere( 'xlsx: nome com período', $xlsx['nome'], 'relatorio-utm-source-2026-11-01-a-2026-11-11-primeira.xlsx' );
confere( 'xlsx: assinatura ZIP', substr( $xlsx['bytes'], 0, 4 ), "PK\x03\x04" );
confere( 'xlsx: filtro inválido', rastro_relatorio_xlsx_preparar( array( 'dimensao' => '../x' ) ), null );
confere( 'xlsx: mesmo conteúdo, mesmos bytes', rastro_relatorio_xlsx_preparar( array( 'dimensao' => 'utm_source', 'periodo' => 'personalizado', 'de' => '2026-11-01', 'ate' => '2026-11-11', 'modelo' => 'primeira' ), 1794614400 )['bytes'], $xlsx['bytes'] );

// Abre o pacote como o Excel abriria: ZIP válido, XML bem formado, células certas.
$arquivo_xlsx = tempnam( sys_get_temp_dir(), 'rastro-xlsx' );
file_put_contents( $arquivo_xlsx, rastro_xlsx( $xlsx_linhas, 'Relatório', array( 34, 12, 16, 16, 16 ), 1794614400 ) );
if ( class_exists( 'ZipArchive' ) ) {
	$zip = new ZipArchive();
	confere( 'xlsx: ZipArchive abre', $zip->open( $arquivo_xlsx ), true );
	$nomes = array();
	for ( $i = 0; $i < $zip->numFiles; $i++ ) {
		$nomes[] = $zip->getNameIndex( $i );
	}
	confere( 'xlsx: partes do pacote', $nomes, array( '[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/worksheets/sheet1.xml', 'xl/styles.xml' ) );
	$bem_formados = true;
	foreach ( $nomes as $nome ) {
		libxml_use_internal_errors( true );
		if ( false === simplexml_load_string( (string) $zip->getFromName( $nome ) ) ) {
			$bem_formados = false;
		}
	}
	confere( 'xlsx: todo XML bem formado', $bem_formados, true );
	$folha = simplexml_load_string( (string) $zip->getFromName( 'xl/worksheets/sheet1.xml' ) );
	$folha->registerXPathNamespace( 'm', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main' );
	confere( 'xlsx: célula A2 com a fórmula como texto', (string) $folha->xpath( '//m:c[@r="A2"]/m:is/m:t' )[0], '=HYPERLINK("x";"y")' );
	confere( 'xlsx: célula C2 com o número', (string) $folha->xpath( '//m:c[@r="C2"]/m:v' )[0], '1234.56' );
	confere( 'xlsx: total na linha 4', (string) $folha->xpath( '//m:c[@r="A4"]/m:is/m:t' )[0], 'Total' );
	confere( 'xlsx: aba com acento', false !== strpos( (string) $zip->getFromName( 'xl/workbook.xml' ), 'name="Relatório"' ), true );
	$zip->close();
}
$validar_xlsx = getenv( 'RASTRO_XLSX_SAIDA' );
if ( $validar_xlsx ) {
	copy( $arquivo_xlsx, $validar_xlsx );
}
unlink( $arquivo_xlsx );

/* 8. Capacidade e nonce --------------------------------------------------------------- */

$filtros_ok = array( 'dimensao' => 'fonte', 'periodo' => 'personalizado', 'de' => '2026-11-01', 'ate' => '2026-11-11', 'modelo' => 'primeira' );

rastro_teste_reset( array( 'admin' => true, 'ajax' => true, 'capacidades' => array(), 'post' => $filtros_ok ) );
$p = parada( 'rastro_relatorios_ajax' );
confere( 'ajax: sem capacidade, 403', array( $p->status, $p->dado['success'] ), array( 403, false ) );

rastro_teste_reset( array( 'admin' => true, 'ajax' => true, 'capacidades' => $cap_loja, 'nonce_ok' => false, 'post' => $filtros_ok ) );
$p = parada( 'rastro_relatorios_ajax' );
confere( 'ajax: nonce recusado, 403', array( $p->status, $p->dado['success'] ), array( 403, false ) );

rastro_teste_reset( array( 'admin' => true, 'ajax' => true, 'capacidades' => $cap_loja, 'post' => array_merge( $filtros_ok, array( 'de' => '2024-01-01' ) ) ) );
$p = parada( 'rastro_relatorios_ajax' );
confere( 'ajax: período longo, 400', array( $p->status, $p->dado['success'] ), array( 400, false ) );

rastro_teste_reset( array( 'admin' => true, 'ajax' => true, 'capacidades' => $cap_loja, 'post' => array_merge( $filtros_ok, array( 'dimensao' => 'billing_email' ) ) ) );
$p = parada( 'rastro_relatorios_ajax' );
confere( 'ajax: dimensão fora da lista, 400', $p->status, 400 );

rastro_teste_reset( array( 'admin' => true, 'ajax' => true, 'capacidades' => $cap_loja, 'post' => $filtros_ok ) );
$p = parada( 'rastro_relatorios_ajax' );
confere( 'ajax: sucesso', array( $p->status, $p->dado['success'] ), array( 200, true ) );
confere( 'ajax: só agregados', array_keys( $p->dado['data'] ), array( 'filtros', 'moeda', 'truncado', 'erro', 'linhas', 'totais', 'serie' ) );
confere( 'ajax: chaves da linha', array_keys( $p->dado['data']['linhas'][0] ), array( 'valor', 'pedidos', 'bruta', 'liquida', 'total' ) );
$json = json_encode( $p->dado );
confere( 'ajax: sem dado de cliente nem número de pedido', false === strpos( $json, 'cliente@exemplo.com' ) && false === strpos( $json, '"id"' ) && false === strpos( $json, '21999990000' ), true );
confere( 'ajax: filtros aplicados', $p->dado['data']['filtros'], array( 'dimensao' => 'fonte', 'modelo' => 'primeira', 'periodo' => 'personalizado', 'inicio' => '2026-11-01', 'fim' => '2026-11-11' ) );
confere( 'ajax: registrado', has_action( 'wp_ajax_rastro_relatorio', 'rastro_relatorios_ajax' ), 10 );
confere( 'ajax: sem rota para visitante', has_action( 'wp_ajax_nopriv_rastro_relatorio' ), false );

rastro_teste_reset( array( 'admin' => true, 'capacidades' => array(), 'get' => $filtros_ok ) );
$p = parada( 'rastro_relatorio_csv' );
confere( 'csv: sem capacidade, 403', $p->status, 403 );
rastro_teste_reset( array( 'admin' => true, 'capacidades' => $cap_loja, 'nonce_ok' => false, 'get' => $filtros_ok ) );
$p = parada( 'rastro_relatorio_csv' );
confere( 'csv: nonce recusado', array( $p->getMessage(), $p->dado, $p->status ), array( 'nonce', 'rastro_relatorio_csv', 403 ) );
rastro_teste_reset( array( 'admin' => true, 'capacidades' => $cap_loja, 'get' => array_merge( $filtros_ok, array( 'periodo' => 'semana' ) ) ) );
$p = parada( 'rastro_relatorio_csv' );
confere( 'csv: filtro inválido, 400', $p->status, 400 );
confere( 'csv: admin-post e AJAX registrados', array( has_action( 'admin_post_rastro_relatorio_csv', 'rastro_relatorio_csv' ), has_action( 'wp_ajax_rastro_relatorio_csv', 'rastro_relatorio_csv' ) ), array( 10, 10 ) );
confere( 'csv: sem rota para visitante', array( has_action( 'admin_post_nopriv_rastro_relatorio_csv' ), has_action( 'wp_ajax_nopriv_rastro_relatorio_csv' ) ), array( false, false ) );
$url = rastro_relatorio_csv_url( rastro_relatorios_filtros( $filtros_ok ) );
confere( 'csv: endereço com nonce e período', 1 === preg_match( '#admin-post\.php\?action=rastro_relatorio_csv&_wpnonce=nonce-[a-f0-9]{10}&dimensao=fonte&periodo=personalizado&modelo=primeira&de=2026-11-01&ate=2026-11-11$#D', $url ), true );

/* 9. Aba, tela e arquivos da tela ------------------------------------------------------- */

$abas = apply_filters( 'rastro_admin_abas', array() );
confere( 'aba registrada', $abas['relatorios'], array( 'titulo' => 'Relatórios', 'capacidade' => 'manage_woocommerce', 'callback' => 'rastro_relatorios_tela', 'ordem' => 50 ) );
rastro_teste_reset( array( 'admin' => true, 'capacidades' => $cap_loja ) );
confere( 'só gerente da loja: vê só os relatórios', array_keys( rastro_admin_abas_visiveis() ), array( 'relatorios' ) );
confere( 'só gerente da loja: capacidade do menu', rastro_admin_capacidade_menu(), 'manage_woocommerce' );
rastro_teste_reset( array( 'admin' => true, 'capacidades' => $cap_admin ) );
confere( 'aba Relatórios por último', array_keys( rastro_admin_abas_visiveis() ), array( 'geral', 'eventos', 'woocommerce', 'consentimento', 'relatorios' ) );

// Arquivos só na tela do Rastro, aba Relatórios.
rastro_admin_tela_id( 'toplevel_page_rastro' );
rastro_teste_reset( array( 'admin' => true, 'capacidades' => $cap_admin, 'get' => array( 'page' => 'rastro', 'aba' => 'geral' ) ) );
rastro_relatorios_assets( 'toplevel_page_rastro' );
confere( 'arquivos: fora na aba Geral', array( isset( $GLOBALS['rastro_teste']['scripts']['rastro-relatorios'] ), isset( $GLOBALS['rastro_teste']['estilos']['rastro-relatorios'] ) ), array( false, false ) );
rastro_teste_reset( array( 'admin' => true, 'capacidades' => $cap_admin, 'get' => array( 'page' => 'rastro', 'aba' => 'relatorios' ) ) );
rastro_relatorios_assets( 'edit-post' );
confere( 'arquivos: fora de outra tela', isset( $GLOBALS['rastro_teste']['scripts']['rastro-relatorios'] ), false );
rastro_relatorios_assets( 'toplevel_page_rastro' );
$script = $GLOBALS['rastro_teste']['scripts']['rastro-relatorios'] ?? array();
confere( 'arquivos: script do Rastro no rodapé com defer', array( $script['enfileirado'] ?? null, $script['args']['in_footer'] ?? null, $script['args']['strategy'] ?? null, $script['src'] ?? null ), array( true, true, 'defer', RASTRO_URL . 'assets/js/relatorios.js' ) );
confere( 'arquivos: estilo', $GLOBALS['rastro_teste']['estilos']['rastro-relatorios']['src'] ?? null, RASTRO_URL . 'assets/css/relatorios.css' );
confere( 'arquivos: prioridade 20', has_action( 'admin_enqueue_scripts', 'rastro_relatorios_assets' ), 20 );

$html = rastro_teste_saida( 'rastro_relatorios_tela', array( 'relatorios' ) );
confere( 'tela: status contados', false !== strpos( $html, 'Status contados: <strong>Processando, Concluído</strong>' ), true );
confere( 'tela: link para a aba WooCommerce', false !== strpos( $html, 'page=rastro&amp;aba=woocommerce' ), true );
foreach ( array( 'totais', 'grafico', 'legenda', 'grafico-tabela', 'tabela', 'paginacao', 'mensagem', 'metrica', 'por-pagina' ) as $parte ) {
	confere( 'tela: parte ' . $parte, false !== strpos( $html, 'data-rastro-rel="' . $parte . '"' ), true );
}
confere( 'tela: sete dimensões', preg_match_all( '/data-dimensao="/', $html ), 7 );
confere( 'tela: nove períodos', preg_match_all( '/<option value="(hoje|ontem|7d|30d|este_mes|mes_passado|90d|12m|personalizado)"/', $html ), 9 );
confere( 'tela: 30 dias por padrão', false !== strpos( $html, '<option value="30d" selected=\'selected\'>' ), true );
confere( 'tela: datas escondidas fora do personalizado', 1 === preg_match( '/class="rastro-rel-campo rastro-rel-datas" hidden>/', $html ), true );
confere( 'tela: 25, 50 e 100 linhas', false !== strpos( $html, '<option value="25">25</option><option value="50">50</option><option value="100">100</option>' ), true );
confere( 'tela: botão do CSV', false !== strpos( $html, 'class="button rastro-rel-csv" href="https://loja.exemplo.com.br/wp-admin/admin-post.php?action=rastro_relatorio_csv&amp;_wpnonce=' ), true );
confere( 'tela: sem script impresso', false === strpos( $html, '<script' ), true );
$inline = $GLOBALS['rastro_teste']['scripts']['rastro-relatorios']['inline'] ?? array();
confere( 'tela: configuração antes do script', array( count( $inline ), $inline[0][0] ?? '', 0 === strpos( $inline[0][1] ?? '', 'window.rastroRelatorio={' ) ), array( 1, 'before', true ) );
$config_js = json_decode( substr( $inline[0][1], strlen( 'window.rastroRelatorio=' ), -1 ), true );
confere( 'tela: configuração em JSON', array_keys( $config_js ), array( 'ajax', 'acao', 'nonce', 'csv', 'csvNonce', 'csvAcao', 'xlsxNonce', 'xlsxAcao', 'inicial', 'dimensoes', 'idioma', 'textos' ) );
confere( 'tela: JSON sem fechar a tag', false === strpos( $inline[0][1], '<' ), true );
confere( 'tela: idioma', $config_js['idioma'], 'pt-BR' );

rastro_teste_reset( array( 'admin' => true, 'capacidades' => $cap_loja, 'get' => array( 'page' => 'rastro', 'aba' => 'relatorios', 'periodo' => 'personalizado', 'de' => '2026-11-01', 'ate' => '2026-11-03', 'dimensao' => 'utm_campaign', 'modelo' => 'ultima' ) ) );
$html = rastro_teste_saida( 'rastro_relatorios_tela', array( 'relatorios' ) );
confere( 'tela: sem manage_options, sem link', false === strpos( $html, 'aba=woocommerce' ), true );
confere( 'tela: dimensão atual', 1 === preg_match( '/class="rastro-rel-dimensao is-atual" data-dimensao="utm_campaign"[^>]*aria-current="true"/', $html ), true );
confere( 'tela: datas visíveis no personalizado', 1 === preg_match( '/class="rastro-rel-campo rastro-rel-datas">/', $html ), true );
confere( 'tela: modelo escolhido', false !== strpos( $html, '<option value="ultima" selected=\'selected\'>' ), true );

rastro_teste_reset( array( 'admin' => true, 'capacidades' => $cap_loja, 'get' => array( 'periodo' => 'personalizado', 'de' => '2026-13-01', 'ate' => '2026-11-03' ) ) );
$html = rastro_teste_saida( 'rastro_relatorios_tela', array( 'relatorios' ) );
confere( 'tela: filtro inválido avisa e volta aos 30 dias', false !== strpos( $html, 'notice-warning' ) && false !== strpos( $html, '<option value="30d" selected=\'selected\'>' ), true );

rastro_teste_reset( array( 'admin' => true, 'capacidades' => array( 'manage_options' => true ) ) );
$html = rastro_teste_saida( 'rastro_relatorios_tela', array( 'relatorios' ) );
confere( 'tela: sem manage_woocommerce', $html, '<p>Sem permissão.</p>' );

/* Resultado ------------------------------------------------------------------------- */

echo "{$total} conferências dos relatórios do Rastro, {$falhas} falha(s).\n";
exit( $falhas > 0 ? 1 : 0 );
