<?php
/**
 * Rastro: dados dos relatórios de venda por origem (contrato, seção 8;
 * manual docs/RELATORIOS.md).
 *
 * Calcula a partir dos pedidos do WooCommerce (D7), sem tabela nova e sem
 * SQL próprio: lê em páginas de 200 com `wc_get_orders` (vale para HPOS e
 * para a tabela de posts), pega a origem de cada pedido por
 * `rastro_pedido_origem()` (frente T5) e agrega por uma dimensão.
 *
 * Fórmulas por pedido, em centavos inteiros, valor negativo vira zero:
 * - Venda bruta: soma do subtotal dos itens (sem imposto, antes do desconto);
 * - Venda líquida: bruta menos desconto menos os itens reembolsados (total
 *   sem imposto das linhas de produto dos reembolsos; frete, taxa e
 *   reembolso só de valor ficam fora);
 * - Venda total: total do pedido (com frete e impostos) menos reembolsado.
 *
 * Leitura: IDs por página (`return => ids`), pedidos carregados em lote
 * (`WC()->order_factory->get_orders()`, que prepara os caches e lê as metas
 * de uma vez nos dois armazenamentos; sem ela, `_prime_post_caches()` na
 * tabela de posts e `wc_get_order()` um a um) e memória da página liberada
 * (`wp_cache_flush_runtime()`, quando existe). Até 50 páginas (10 mil
 * pedidos) por relatório; passou disso, o resultado sai marcado como
 * truncado.
 *
 * Nada de dado de cliente sai daqui: os registros guardam só o dia, os três
 * valores e os campos de origem da dimensão.
 *
 * Cache: transient de 10 minutos com o hash dos argumentos e de uma versão
 * guardada em opção. Mudança de status, reembolso, lixeira e exclusão de
 * pedido só sobem a versão (as entradas antigas expiram sozinhas).
 *
 * @package Rastro
 */

defined( 'ABSPATH' ) || exit;

/** Pedidos por página na leitura. */
const RASTRO_RELATORIO_PAGINA = 200;

/** Máximo padrão de páginas por relatório (50 x 200 = 10 mil pedidos). */
const RASTRO_RELATORIO_MAX_PAGINAS = 50;

/** Maior período aceito, em dias (contando o primeiro e o último). */
const RASTRO_RELATORIO_MAX_DIAS = 366;

/** Máximo de séries do gráfico (as 7 maiores e "Outras"). */
const RASTRO_RELATORIO_SERIES = 8;

/** Opção com a versão do cache dos relatórios. */
const RASTRO_RELATORIO_CACHE_OPCAO = 'rastro_relatorio_cache_versao';

/** Validade do cache, em segundos (10 minutos). */
const RASTRO_RELATORIO_CACHE_PRAZO = 600;

/* Listas fechadas ------------------------------------------------------------ */

/**
 * Dimensões do relatório: chave => rótulo. A chave é o campo devolvido por
 * `rastro_pedido_origem()`.
 *
 * @return array<string,string>
 */
function rastro_relatorio_dimensoes(): array {
	return array(
		'fonte'        => __( 'Fonte de tráfego', 'rastro' ),
		'entrada'      => __( 'Página de entrada', 'rastro' ),
		'utm_source'   => 'utm_source',
		'utm_medium'   => 'utm_medium',
		'utm_campaign' => 'utm_campaign',
		'utm_content'  => 'utm_content',
		'utm_term'     => 'utm_term',
	);
}

/**
 * Modelos de atribuição: chave => rótulo.
 *
 * @return array<string,string>
 */
function rastro_relatorio_modelos(): array {
	return array(
		'primeira' => __( 'Primeira visita', 'rastro' ),
		'ultima'   => __( 'Última visita', 'rastro' ),
	);
}

/**
 * Períodos: chave => rótulo. O padrão é `30d`.
 *
 * @return array<string,string>
 */
function rastro_relatorio_periodos(): array {
	return array(
		'hoje'          => __( 'Hoje', 'rastro' ),
		'ontem'         => __( 'Ontem', 'rastro' ),
		'7d'            => __( 'Últimos 7 dias', 'rastro' ),
		'30d'           => __( 'Últimos 30 dias', 'rastro' ),
		'este_mes'      => __( 'Este mês', 'rastro' ),
		'mes_passado'   => __( 'Mês passado', 'rastro' ),
		'90d'           => __( 'Últimos 90 dias', 'rastro' ),
		'12m'           => __( 'Últimos 12 meses', 'rastro' ),
		'personalizado' => __( 'Personalizado', 'rastro' ),
	);
}

/**
 * Texto da linha quando o pedido não tem valor na dimensão.
 *
 * @param string $dimensao Chave da dimensão.
 * @return string
 */
function rastro_relatorio_vazio( string $dimensao ): string {
	return 'fonte' === $dimensao ? __( '(sem dados)', 'rastro' ) : __( '(não definido)', 'rastro' );
}

/* Período --------------------------------------------------------------------- */

/**
 * Fuso do site (as datas do relatório são do calendário da loja).
 *
 * @return \DateTimeZone
 */
function rastro_relatorio_fuso(): \DateTimeZone {
	if ( function_exists( 'wp_timezone' ) ) {
		$fuso = wp_timezone();
		if ( $fuso instanceof \DateTimeZone ) {
			return $fuso;
		}
	}

	return new \DateTimeZone( 'UTC' );
}

/**
 * Data AAAA-MM-DD válida (calendário de verdade, anos 2000 a 2100), à
 * meia-noite no fuso do site.
 *
 * @param mixed $data Texto da data.
 * @return \DateTimeImmutable|null
 */
function rastro_relatorio_data( $data ): ?\DateTimeImmutable {
	if ( ! is_string( $data ) || 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/D', $data, $m ) ) {
		return null;
	}
	$ano = (int) $m[1];
	if ( $ano < 2000 || $ano > 2100 || ! checkdate( (int) $m[2], (int) $m[3], $ano ) ) {
		return null;
	}
	$dia = \DateTimeImmutable::createFromFormat( '!Y-m-d', $data, rastro_relatorio_fuso() );

	return $dia instanceof \DateTimeImmutable ? $dia : null;
}

/**
 * Resolve um período em datas de início e fim (inclusivas, no fuso do site).
 *
 * @param string                  $periodo Chave de rastro_relatorio_periodos().
 * @param string                  $de      Início do personalizado (AAAA-MM-DD).
 * @param string                  $ate     Fim do personalizado (AAAA-MM-DD).
 * @param \DateTimeInterface|null $agora   Momento de referência (testes).
 * @return array{periodo:string,inicio:string,fim:string,dias:int}|null null para período inválido.
 */
function rastro_relatorio_periodo( string $periodo, string $de = '', string $ate = '', ?\DateTimeInterface $agora = null ): ?array {
	if ( ! isset( rastro_relatorio_periodos()[ $periodo ] ) ) {
		return null;
	}

	$fuso = rastro_relatorio_fuso();
	$base = null === $agora ? new \DateTimeImmutable( 'now', $fuso ) : \DateTimeImmutable::createFromFormat( 'U', (string) $agora->getTimestamp() );
	$hoje = $base->setTimezone( $fuso )->setTime( 0, 0, 0 );

	switch ( $periodo ) {
		case 'hoje':
			$inicio = $hoje;
			$fim    = $hoje;
			break;
		case 'ontem':
			$inicio = $hoje->modify( '-1 day' );
			$fim    = $inicio;
			break;
		case '7d':
			$inicio = $hoje->modify( '-6 days' );
			$fim    = $hoje;
			break;
		case 'este_mes':
			$inicio = $hoje->modify( 'first day of this month' );
			$fim    = $hoje;
			break;
		case 'mes_passado':
			$inicio = $hoje->modify( 'first day of last month' );
			$fim    = $hoje->modify( 'last day of last month' );
			break;
		case '90d':
			$inicio = $hoje->modify( '-89 days' );
			$fim    = $hoje;
			break;
		case '12m':
			$inicio = $hoje->modify( '-12 months' )->modify( '+1 day' );
			$fim    = $hoje;
			break;
		case 'personalizado':
			$inicio = rastro_relatorio_data( $de );
			$fim    = rastro_relatorio_data( $ate );
			if ( null === $inicio || null === $fim ) {
				return null;
			}
			if ( $inicio > $fim ) {
				$troca  = $inicio;
				$inicio = $fim;
				$fim    = $troca;
			}
			break;
		default: // 30d.
			$inicio = $hoje->modify( '-29 days' );
			$fim    = $hoje;
	}

	$dias = count( rastro_relatorio_dias( $inicio->format( 'Y-m-d' ), $fim->format( 'Y-m-d' ) ) );
	if ( $dias < 1 || $dias > RASTRO_RELATORIO_MAX_DIAS ) {
		return null;
	}

	return array(
		'periodo' => $periodo,
		'inicio'  => $inicio->format( 'Y-m-d' ),
		'fim'     => $fim->format( 'Y-m-d' ),
		'dias'    => $dias,
	);
}

/**
 * Dias de um intervalo, inclusivos (AAAA-MM-DD). Para no limite de dias
 * mais um, para quem chama perceber o excesso.
 *
 * @param string $inicio Primeiro dia.
 * @param string $fim    Último dia.
 * @return string[]
 */
function rastro_relatorio_dias( string $inicio, string $fim ): array {
	$dia    = rastro_relatorio_data( $inicio );
	$ultimo = rastro_relatorio_data( $fim );
	if ( null === $dia || null === $ultimo || $dia > $ultimo ) {
		return array();
	}

	$dias = array();
	while ( $dia <= $ultimo && count( $dias ) <= RASTRO_RELATORIO_MAX_DIAS ) {
		$dias[] = $dia->format( 'Y-m-d' );
		$dia    = $dia->modify( '+1 day' );
	}

	return $dias;
}

/**
 * Limites do intervalo em timestamps UTC: meia-noite do primeiro dia e
 * 23:59:59 do último, no fuso do site.
 *
 * @param string $inicio Primeiro dia.
 * @param string $fim    Último dia.
 * @return array{0:int,1:int}|null
 */
function rastro_relatorio_limites( string $inicio, string $fim ): ?array {
	$a = rastro_relatorio_data( $inicio );
	$b = rastro_relatorio_data( $fim );
	if ( null === $a || null === $b ) {
		return null;
	}

	return array( $a->getTimestamp(), $b->setTime( 23, 59, 59 )->getTimestamp() );
}

/* Argumentos ------------------------------------------------------------------ */

/**
 * Status contados (aba WooCommerce, `status_relatorio`), sem o prefixo wc-.
 *
 * @return string[]
 */
function rastro_relatorio_status_config(): array {
	$padrao = array( 'processing', 'completed' );
	$valor  = function_exists( 'rastro_config_campo' ) ? rastro_config_campo( 'status_relatorio' ) : null;

	$status = rastro_relatorio_status_limpar( is_array( $valor ) ? $valor : array() );

	return array() !== $status ? $status : $padrao;
}

/**
 * Limpa uma lista de status: só códigos simples e, quando a lista da loja
 * existe, só os que ela conhece. Sem repetição, em ordem.
 *
 * @param array $lista Status pedidos.
 * @return string[]
 */
function rastro_relatorio_status_limpar( array $lista ): array {
	$conhecidos = function_exists( 'rastro_config_status_opcoes' ) ? rastro_config_status_opcoes() : array();
	$limpos     = array();
	foreach ( $lista as $status ) {
		if ( ! is_string( $status ) ) {
			continue;
		}
		$status = (string) preg_replace( '/^wc-/', '', strtolower( $status ) );
		if ( 1 !== preg_match( '/^[a-z0-9_-]{1,40}$/D', $status ) ) {
			continue;
		}
		if ( array() !== $conhecidos && ! isset( $conhecidos[ $status ] ) ) {
			continue;
		}
		$limpos[ $status ] = $status;
	}
	sort( $limpos );

	return array_values( $limpos );
}

/**
 * Valida os argumentos de rastro_relatorio_dados() por lista fechada.
 *
 * @param array $args dimensao, inicio, fim, modelo, status.
 * @return array{dimensao:string,inicio:string,fim:string,modelo:string,status:string[]}|null
 */
function rastro_relatorio_args( array $args ): ?array {
	$dimensao = isset( $args['dimensao'] ) && is_string( $args['dimensao'] ) ? $args['dimensao'] : 'fonte';
	$modelo   = isset( $args['modelo'] ) && is_string( $args['modelo'] ) ? $args['modelo'] : 'primeira';
	if ( ! isset( rastro_relatorio_dimensoes()[ $dimensao ] ) || ! isset( rastro_relatorio_modelos()[ $modelo ] ) ) {
		return null;
	}

	$inicio = isset( $args['inicio'] ) && is_string( $args['inicio'] ) ? $args['inicio'] : '';
	$fim    = isset( $args['fim'] ) && is_string( $args['fim'] ) ? $args['fim'] : '';
	$dias   = rastro_relatorio_dias( $inicio, $fim );
	if ( array() === $dias || count( $dias ) > RASTRO_RELATORIO_MAX_DIAS ) {
		return null;
	}

	$status = isset( $args['status'] ) && is_array( $args['status'] ) ? rastro_relatorio_status_limpar( $args['status'] ) : rastro_relatorio_status_config();
	if ( array() === $status ) {
		return null;
	}

	return array(
		'dimensao' => $dimensao,
		'inicio'   => $inicio,
		'fim'      => $fim,
		'modelo'   => $modelo,
		'status'   => $status,
	);
}

/* Valores de um pedido --------------------------------------------------------- */

/**
 * Valor em dinheiro (número ou texto numérico com ponto) em centavos.
 *
 * @param mixed $valor Valor do WooCommerce.
 * @return int
 */
function rastro_relatorio_centavos( $valor ): int {
	if ( is_int( $valor ) ) {
		return $valor * 100;
	}
	if ( ! is_numeric( $valor ) ) {
		return 0;
	}

	return (int) round( (float) $valor * 100 );
}

/**
 * Fórmulas da seção 8, em centavos (função pura). Valor negativo vira zero.
 * A líquida tira só os itens reembolsados (sem imposto, como a bruta):
 * reembolso de frete, de taxa ou só de valor não muda a líquida, só a total.
 *
 * @param array $v itens (int[] subtotais), desconto, itens_reembolsados (total
 *                 sem imposto das linhas de produto dos reembolsos, positivo),
 *                 reembolsado (tudo), total.
 * @return array{bruta:int,liquida:int,total:int}
 */
function rastro_relatorio_formulas( array $v ): array {
	$bruta = 0;
	foreach ( isset( $v['itens'] ) && is_array( $v['itens'] ) ? $v['itens'] : array() as $subtotal ) {
		$bruta += (int) $subtotal;
	}
	$desconto           = (int) ( $v['desconto'] ?? 0 );
	$itens_reembolsados = abs( (int) ( $v['itens_reembolsados'] ?? 0 ) );
	$reembolsado        = (int) ( $v['reembolsado'] ?? 0 );
	$total              = (int) ( $v['total'] ?? 0 );

	return array(
		'bruta'   => max( 0, $bruta ),
		'liquida' => max( 0, $bruta - $desconto - $itens_reembolsados ),
		'total'   => max( 0, $total - $reembolsado ),
	);
}

/**
 * Itens reembolsados de um pedido, em centavos (positivo): soma do total sem
 * imposto das linhas de produto (`line_item`) de cada reembolso. Frete,
 * taxas e reembolso só de valor não entram. Só lê os reembolsos quando o
 * pedido tem algo reembolsado.
 *
 * @param object $pedido      WC_Order.
 * @param int    $reembolsado Total reembolsado do pedido, em centavos.
 * @return int
 */
function rastro_relatorio_itens_reembolsados( $pedido, int $reembolsado ): int {
	if ( $reembolsado <= 0 || ! method_exists( $pedido, 'get_refunds' ) ) {
		return 0;
	}
	$soma = 0;
	foreach ( (array) $pedido->get_refunds() as $reembolso ) {
		if ( ! is_object( $reembolso ) || ! method_exists( $reembolso, 'get_items' ) ) {
			continue;
		}
		foreach ( (array) $reembolso->get_items( 'line_item' ) as $item ) {
			if ( is_object( $item ) && method_exists( $item, 'get_total' ) ) {
				// As linhas do reembolso têm valor negativo.
				$soma += abs( rastro_relatorio_centavos( $item->get_total() ) );
			}
		}
	}

	return $soma;
}

/**
 * Valores de um pedido do WooCommerce (bruta, líquida, total) em centavos.
 *
 * @param object $pedido WC_Order.
 * @return array{bruta:int,liquida:int,total:int}
 */
function rastro_relatorio_valores( $pedido ): array {
	$itens = array();
	if ( method_exists( $pedido, 'get_items' ) ) {
		foreach ( (array) $pedido->get_items( 'line_item' ) as $item ) {
			if ( is_object( $item ) && method_exists( $item, 'get_subtotal' ) ) {
				$itens[] = rastro_relatorio_centavos( $item->get_subtotal() );
			}
		}
	}
	$reembolsado = method_exists( $pedido, 'get_total_refunded' ) ? rastro_relatorio_centavos( $pedido->get_total_refunded() ) : 0;

	return rastro_relatorio_formulas(
		array(
			'itens'              => $itens,
			'desconto'           => method_exists( $pedido, 'get_discount_total' ) ? rastro_relatorio_centavos( $pedido->get_discount_total() ) : 0,
			'itens_reembolsados' => rastro_relatorio_itens_reembolsados( $pedido, $reembolsado ),
			'reembolsado'        => $reembolsado,
			'total'              => method_exists( $pedido, 'get_total' ) ? rastro_relatorio_centavos( $pedido->get_total() ) : 0,
		)
	);
}

/**
 * Dia do pedido (AAAA-MM-DD) no fuso do site; '' sem data.
 *
 * @param object $pedido WC_Order.
 * @return string
 */
function rastro_relatorio_dia_do_pedido( $pedido ): string {
	$data = method_exists( $pedido, 'get_date_created' ) ? $pedido->get_date_created() : null;
	if ( ! $data instanceof \DateTimeInterface ) {
		return '';
	}
	$utc = \DateTimeImmutable::createFromFormat( 'U', (string) $data->getTimestamp() );

	return $utc instanceof \DateTimeImmutable ? $utc->setTimezone( rastro_relatorio_fuso() )->format( 'Y-m-d' ) : '';
}

/* Origem ---------------------------------------------------------------------- */

/**
 * Origem de reserva, quando a leitura da T5 (`rastro_pedido_origem`) não
 * está carregada: tudo vazio e a fonte "(sem dados)".
 *
 * @return array<string,string>
 */
function rastro_relatorio_origem_reserva(): array {
	return array(
		'fonte'        => '(sem dados)',
		'utm_source'   => '',
		'utm_medium'   => '',
		'utm_campaign' => '',
		'utm_content'  => '',
		'utm_term'     => '',
		'utm_id'       => '',
		'clique'       => '',
		'referrer'     => '',
		'entrada'      => '',
		'quando'       => '',
		'origem_dado'  => 'nenhum',
	);
}

/**
 * Origem de um pedido no modelo pedido, só com os campos das dimensões.
 * Usa `rastro_pedido_origem()` quando existe; erro nela vira a reserva.
 *
 * @param object $pedido WC_Order.
 * @param string $modelo primeira ou ultima.
 * @return array<string,string> dimensão => valor (texto limpo, até 200 caracteres).
 */
function rastro_relatorio_origem( $pedido, string $modelo ): array {
	$origem = null;
	if ( function_exists( 'rastro_pedido_origem' ) ) {
		try {
			$origem = rastro_pedido_origem( $pedido, $modelo );
		} catch ( \Throwable $erro ) {
			do_action( 'rastro_falha', 'relatorios-origem', $erro );
			$origem = null;
		}
	}
	if ( ! is_array( $origem ) ) {
		$origem = rastro_relatorio_origem_reserva();
	}

	$limpa = array();
	foreach ( array_keys( rastro_relatorio_dimensoes() ) as $dimensao ) {
		$valor = isset( $origem[ $dimensao ] ) && is_scalar( $origem[ $dimensao ] ) ? trim( (string) $origem[ $dimensao ] ) : '';
		// Sem caracteres de controle; limite de tamanho por segurança.
		$valor              = (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $valor );
		$limpa[ $dimensao ] = function_exists( 'mb_substr' ) ? mb_substr( $valor, 0, 200 ) : substr( $valor, 0, 200 );
	}

	return $limpa;
}

/**
 * Registro de um pedido para a agregação (sem dado de cliente).
 *
 * @param object $pedido WC_Order.
 * @param string $modelo primeira ou ultima.
 * @return array{dia:string,bruta:int,liquida:int,total:int,origem:array<string,string>}|null
 */
function rastro_relatorio_registro( $pedido, string $modelo ): ?array {
	if ( ! is_object( $pedido ) || is_a( $pedido, 'WC_Order_Refund' ) ) {
		return null;
	}
	if ( method_exists( $pedido, 'get_type' ) && 'shop_order' !== $pedido->get_type() ) {
		return null;
	}

	return array_merge(
		array( 'dia' => rastro_relatorio_dia_do_pedido( $pedido ) ),
		rastro_relatorio_valores( $pedido ),
		array( 'origem' => rastro_relatorio_origem( $pedido, $modelo ) )
	);
}

/* Agregação -------------------------------------------------------------------- */

/**
 * Totais vazios.
 *
 * @return array{valores:int,pedidos:int,bruta:int,liquida:int,total:int}
 */
function rastro_relatorio_totais_vazios(): array {
	return array(
		'valores' => 0,
		'pedidos' => 0,
		'bruta'   => 0,
		'liquida' => 0,
		'total'   => 0,
	);
}

/**
 * Agrega registros por dimensão (função pura).
 *
 * Linhas em ordem de venda total, depois pedidos, depois o valor. Série por
 * dia com até 8 séries: com mais de 8 valores, as 7 maiores e "Outras".
 *
 * @param array    $pedidos  Registros de rastro_relatorio_registro().
 * @param string   $dimensao Chave da dimensão.
 * @param string[] $dias     Eixo dos dias (vazio: os dias que aparecem nos registros).
 * @return array{linhas:array,totais:array,serie:array{dias:string[],series:array}}
 */
function rastro_relatorio_agregar( array $pedidos, string $dimensao, array $dias = array() ): array {
	$vazio = array(
		'linhas' => array(),
		'totais' => rastro_relatorio_totais_vazios(),
		'serie'  => array(
			'dias'   => array_values( $dias ),
			'series' => array(),
		),
	);
	if ( ! isset( rastro_relatorio_dimensoes()[ $dimensao ] ) ) {
		return $vazio;
	}

	$linhas = array();
	$totais = rastro_relatorio_totais_vazios();
	$porDia = array(); // valor => dia => array( pedidos, total ).
	$vistos = array();

	foreach ( $pedidos as $p ) {
		if ( ! is_array( $p ) ) {
			continue;
		}
		$origem = isset( $p['origem'] ) && is_array( $p['origem'] ) ? $p['origem'] : array();
		$valor  = isset( $origem[ $dimensao ] ) && is_scalar( $origem[ $dimensao ] ) ? trim( (string) $origem[ $dimensao ] ) : '';
		if ( '' === $valor ) {
			$valor = rastro_relatorio_vazio( $dimensao );
		}
		$bruta   = max( 0, (int) ( $p['bruta'] ?? 0 ) );
		$liquida = max( 0, (int) ( $p['liquida'] ?? 0 ) );
		$total   = max( 0, (int) ( $p['total'] ?? 0 ) );

		if ( ! isset( $linhas[ $valor ] ) ) {
			$linhas[ $valor ] = array(
				'valor'   => $valor,
				'pedidos' => 0,
				'bruta'   => 0,
				'liquida' => 0,
				'total'   => 0,
			);
		}
		++$linhas[ $valor ]['pedidos'];
		$linhas[ $valor ]['bruta']   += $bruta;
		$linhas[ $valor ]['liquida'] += $liquida;
		$linhas[ $valor ]['total']   += $total;

		++$totais['pedidos'];
		$totais['bruta']   += $bruta;
		$totais['liquida'] += $liquida;
		$totais['total']   += $total;

		$dia = isset( $p['dia'] ) && is_string( $p['dia'] ) ? $p['dia'] : '';
		if ( '' !== $dia ) {
			$vistos[ $dia ] = true;
			if ( ! isset( $porDia[ $valor ][ $dia ] ) ) {
				$porDia[ $valor ][ $dia ] = array( 0, 0 );
			}
			++$porDia[ $valor ][ $dia ][0];
			$porDia[ $valor ][ $dia ][1] += $total;
		}
	}

	$linhas = array_values( $linhas );
	usort(
		$linhas,
		static function ( array $a, array $b ): int {
			return array( $b['total'], $b['pedidos'], $a['valor'] ) <=> array( $a['total'], $a['pedidos'], $b['valor'] );
		}
	);
	$totais['valores'] = count( $linhas );

	if ( array() === $dias ) {
		$dias = array_keys( $vistos );
		sort( $dias );
	}
	$dias = array_values( $dias );

	// Séries: com mais de 8 valores, as 7 maiores e "Outras" com o resto.
	$nomes  = array_column( $linhas, 'valor' );
	$juntar = count( $nomes ) > RASTRO_RELATORIO_SERIES;
	$grupos = array();
	foreach ( $nomes as $i => $nome ) {
		$grupo              = $juntar && $i >= RASTRO_RELATORIO_SERIES - 1 ? '' : $nome;
		$grupos[ $grupo ][] = $nome;
	}

	$series = array();
	foreach ( $grupos as $grupo => $membros ) {
		$pedidosDia = array();
		$totalDia   = array();
		foreach ( $dias as $dia ) {
			$n = 0;
			$t = 0;
			foreach ( $membros as $membro ) {
				if ( isset( $porDia[ $membro ][ $dia ] ) ) {
					$n += $porDia[ $membro ][ $dia ][0];
					$t += $porDia[ $membro ][ $dia ][1];
				}
			}
			$pedidosDia[] = $n;
			$totalDia[]   = $t;
		}
		$series[] = array(
			'nome'    => '' === $grupo ? __( 'Outras', 'rastro' ) : (string) $grupo,
			'outras'  => '' === $grupo,
			'pedidos' => $pedidosDia,
			'total'   => $totalDia,
		);
	}

	return array(
		'linhas' => $linhas,
		'totais' => $totais,
		'serie'  => array(
			'dias'   => $dias,
			'series' => $series,
		),
	);
}

/* Leitura e cache ---------------------------------------------------------------- */

/**
 * Versão atual do cache dos relatórios.
 *
 * @return int
 */
function rastro_relatorio_cache_versao(): int {
	$versao = get_option( RASTRO_RELATORIO_CACHE_OPCAO, 1 );

	return is_numeric( $versao ) && (int) $versao > 0 ? (int) $versao : 1;
}

/**
 * Invalida o cache dos relatórios: sobe a versão (nada é apagado; as
 * entradas antigas expiram em 10 minutos).
 *
 * @return void
 */
function rastro_relatorio_cache_invalidar(): void {
	update_option( RASTRO_RELATORIO_CACHE_OPCAO, rastro_relatorio_cache_versao() + 1, false );
}
add_action( 'woocommerce_order_status_changed', 'rastro_relatorio_cache_invalidar', 10, 0 );
add_action( 'woocommerce_order_refunded', 'rastro_relatorio_cache_invalidar', 10, 0 );
add_action( 'woocommerce_refund_deleted', 'rastro_relatorio_cache_invalidar', 10, 0 );
add_action( 'woocommerce_trash_order', 'rastro_relatorio_cache_invalidar', 10, 0 );
add_action( 'woocommerce_delete_order', 'rastro_relatorio_cache_invalidar', 10, 0 );

/**
 * Chave do transient para argumentos já validados.
 *
 * @param array $args Saída de rastro_relatorio_args().
 * @return string
 */
function rastro_relatorio_cache_chave( array $args ): string {
	$versao_plugin = defined( 'RASTRO_VERSAO' ) ? RASTRO_VERSAO : '';

	return 'rastro_relatorio_' . md5( (string) wp_json_encode( array( $args, rastro_relatorio_cache_versao(), $versao_plugin ) ) );
}

/**
 * Argumentos de wc_get_orders() para uma página.
 *
 * Conferidos no código do WooCommerce 11.3 (WC_Order_Query, data store de
 * posts e OrdersTableQuery do HPOS): `status` sem o prefixo wc- (os dois
 * data stores acrescentam), `date_created` como `inicio...fim` em
 * timestamps (UTC, precisão de segundo, inclusivo), `paginate` devolve um
 * objeto com `orders`, `total` e `max_num_pages`.
 *
 * @param array $args   Saída de rastro_relatorio_args().
 * @param int   $pagina Página (a partir de 1).
 * @return array
 */
function rastro_relatorio_consulta( array $args, int $pagina ): array {
	$limites = rastro_relatorio_limites( $args['inicio'], $args['fim'] );

	return array(
		'type'         => 'shop_order',
		'status'       => $args['status'],
		'date_created' => null === $limites ? '' : $limites[0] . '...' . $limites[1],
		'limit'        => RASTRO_RELATORIO_PAGINA,
		'page'         => max( 1, $pagina ),
		'paginate'     => true,
		'orderby'      => 'date',
		'order'        => 'ASC',
		'return'       => 'ids',
	);
}

/**
 * Pedidos de uma página a partir dos IDs, em lote. Com o
 * `WC()->order_factory->get_orders( $ids, true )` do WooCommerce (prepara
 * os caches e lê os pedidos de uma vez, posts ou HPOS; inválidos são
 * pulados); sem ele, `_prime_post_caches()` (tabela de posts) e
 * `wc_get_order()` um a um.
 *
 * @param int[] $ids IDs dos pedidos.
 * @return array Pedidos.
 */
function rastro_relatorio_carregar_pedidos( array $ids ): array {
	$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
	if ( array() === $ids ) {
		return array();
	}

	$woo = function_exists( 'WC' ) ? WC() : null;
	if ( is_object( $woo ) && isset( $woo->order_factory ) && is_object( $woo->order_factory ) && method_exists( $woo->order_factory, 'get_orders' ) ) {
		try {
			$pedidos = $woo->order_factory->get_orders( $ids, true );
			if ( is_array( $pedidos ) ) {
				return array_values( $pedidos );
			}
		} catch ( \Throwable $erro ) {
			do_action( 'rastro_falha', 'relatorios-leitura', $erro );
		}
	}

	$hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && method_exists( '\Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	if ( ! $hpos && function_exists( '_prime_post_caches' ) ) {
		_prime_post_caches( $ids, false, true );
	}

	$pedidos = array();
	foreach ( $ids as $id ) {
		$pedido = function_exists( 'wc_get_order' ) ? wc_get_order( $id ) : null;
		if ( is_object( $pedido ) ) {
			$pedidos[] = $pedido;
		}
	}

	return $pedidos;
}

/**
 * Registros dos pedidos do período, lidos em páginas de 200.
 *
 * @param array $args Saída de rastro_relatorio_args().
 * @return array{registros:array,truncado:bool}
 */
function rastro_relatorio_pedidos( array $args ): array {
	$registros = array();
	$truncado  = false;
	if ( ! function_exists( 'wc_get_orders' ) ) {
		return array(
			'registros' => $registros,
			'truncado'  => $truncado,
		);
	}

	/**
	 * Máximo de páginas lidas por relatório (200 pedidos cada). Passou disso,
	 * o relatório sai com `truncado` (e o aviso na tela).
	 *
	 * @param int $paginas Padrão 50 (10 mil pedidos).
	 */
	$max_paginas = (int) apply_filters( 'rastro_relatorio_max_paginas', RASTRO_RELATORIO_MAX_PAGINAS );
	$max_paginas = max( 1, $max_paginas );

	$pagina = 1;
	do {
		$resultado = wc_get_orders( rastro_relatorio_consulta( $args, $pagina ) );
		$ids       = is_object( $resultado ) && isset( $resultado->orders ) && is_array( $resultado->orders ) ? $resultado->orders : array();
		$paginas   = is_object( $resultado ) && isset( $resultado->max_num_pages ) ? (int) $resultado->max_num_pages : 0;

		foreach ( rastro_relatorio_carregar_pedidos( $ids ) as $pedido ) {
			$registro = rastro_relatorio_registro( $pedido, $args['modelo'] );
			if ( null !== $registro ) {
				$registros[] = $registro;
			}
		}
		// Os pedidos da página saem da memória (o registro guarda só o dia, os valores e a origem).
		if ( function_exists( 'wp_cache_flush_runtime' ) ) {
			wp_cache_flush_runtime();
		}

		if ( $pagina >= $max_paginas && $pagina < $paginas ) {
			$truncado = true;
			break;
		}
		++$pagina;
	} while ( array() !== $ids && $pagina <= $paginas );

	return array(
		'registros' => $registros,
		'truncado'  => $truncado,
	);
}

/**
 * Dados do relatório: linhas por valor da dimensão, totais e série por dia.
 *
 * @param array $args dimensao, inicio, fim (AAAA-MM-DD), modelo, status (opcional; padrão da aba WooCommerce).
 * @return array{linhas:array,totais:array,serie:array,args:array|null,moeda:string,truncado:bool,erro:string}
 */
function rastro_relatorio_dados( array $args ): array {
	$validos = rastro_relatorio_args( $args );
	$moeda   = function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '';

	if ( null === $validos ) {
		return array_merge(
			rastro_relatorio_agregar( array(), '' ),
			array(
				'args'     => null,
				'moeda'    => $moeda,
				'truncado' => false,
				'erro'     => 'argumentos',
			)
		);
	}

	$chave  = rastro_relatorio_cache_chave( $validos );
	$cached = get_transient( $chave );
	if ( is_array( $cached ) && isset( $cached['linhas'], $cached['totais'], $cached['serie'] ) ) {
		return $cached;
	}

	$dias = rastro_relatorio_dias( $validos['inicio'], $validos['fim'] );

	if ( ! function_exists( 'wc_get_orders' ) ) {
		return array_merge(
			rastro_relatorio_agregar( array(), $validos['dimensao'], $dias ),
			array(
				'args'     => $validos,
				'moeda'    => $moeda,
				'truncado' => false,
				'erro'     => 'woocommerce',
			)
		);
	}

	$lidos  = rastro_relatorio_pedidos( $validos );
	$result = array_merge(
		rastro_relatorio_agregar( $lidos['registros'], $validos['dimensao'], $dias ),
		array(
			'args'     => $validos,
			'moeda'    => $moeda,
			'truncado' => $lidos['truncado'],
			'erro'     => '',
		)
	);

	set_transient( $chave, $result, RASTRO_RELATORIO_CACHE_PRAZO );

	return $result;
}
