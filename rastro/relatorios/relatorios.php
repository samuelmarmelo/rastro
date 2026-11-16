<?php
/**
 * Rastro: tela de relatórios, AJAX e CSV (contrato, seção 8; manual
 * docs/RELATORIOS.md).
 *
 * Aba "Relatórios" do painel do Rastro (filtro `rastro_admin_abas`, ordem 50,
 * capacidade manage_woocommerce). A tela traz os filtros (dimensão, período,
 * modelo) num formulário GET comum; o `relatorios.js` monta totais, gráfico
 * e tabela a partir do JSON da página e troca os dados por AJAX.
 *
 * AJAX `rastro_relatorio` (JSON), `rastro_relatorio_csv` e
 * `rastro_relatorio_xlsx` (downloads em CSV e em planilha do Excel, também
 * por admin-post): capacidade, nonce, argumentos por lista fechada, datas
 * validadas. A resposta só tem agregados (nada de cliente).
 *
 * @package Rastro
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/xlsx.php';

/** Chave da aba. */
const RASTRO_RELATORIO_ABA = 'relatorios';

/** Capacidade da aba, do AJAX e do CSV. */
const RASTRO_RELATORIO_CAPACIDADE = 'manage_woocommerce';

/** Ação do AJAX e do nonce dos dados. */
const RASTRO_RELATORIO_ACAO = 'rastro_relatorio';

/** Ação do CSV (AJAX e admin-post) e do nonce dele. */
const RASTRO_RELATORIO_ACAO_CSV = 'rastro_relatorio_csv';

/** Ação da planilha do Excel (AJAX e admin-post) e do nonce dela. */
const RASTRO_RELATORIO_ACAO_XLSX = 'rastro_relatorio_xlsx';

/* Aba --------------------------------------------------------------------------- */

/**
 * Registra a aba "Relatórios" (só com o WooCommerce carregado).
 *
 * @param mixed $abas Abas do painel.
 * @return mixed
 */
function rastro_relatorios_aba( $abas ) {
	if ( ! is_array( $abas ) || ! function_exists( 'wc_get_orders' ) ) {
		return $abas;
	}
	$abas[ RASTRO_RELATORIO_ABA ] = array(
		'titulo'     => __( 'Relatórios', 'rastro' ),
		'capacidade' => RASTRO_RELATORIO_CAPACIDADE,
		'callback'   => 'rastro_relatorios_tela',
		'ordem'      => 50,
	);

	return $abas;
}
add_filter( 'rastro_admin_abas', 'rastro_relatorios_aba' );

/* Argumentos da requisição ---------------------------------------------------------- */

/**
 * Texto curto de uma entrada da requisição (só texto simples).
 *
 * @param array  $fonte Entrada ($_GET ou $_POST, já sem barras).
 * @param string $chave Chave.
 * @return string
 */
function rastro_relatorios_entrada( array $fonte, string $chave ): string {
	if ( ! isset( $fonte[ $chave ] ) || ! is_string( $fonte[ $chave ] ) ) {
		return '';
	}

	return substr( trim( $fonte[ $chave ] ), 0, 40 );
}

/**
 * Lê e valida os filtros de uma requisição: dimensão, período (com de/até
 * no personalizado) e modelo. Sem valor, o padrão de cada um.
 *
 * @param array $fonte Entrada ($_GET ou $_POST, já sem barras).
 * @return array{dimensao:string,modelo:string,periodo:string,inicio:string,fim:string,dias:int}|null null para filtro inválido.
 */
function rastro_relatorios_filtros( array $fonte ): ?array {
	$dimensao = sanitize_key( rastro_relatorios_entrada( $fonte, 'dimensao' ) );
	$modelo   = sanitize_key( rastro_relatorios_entrada( $fonte, 'modelo' ) );
	$periodo  = sanitize_key( rastro_relatorios_entrada( $fonte, 'periodo' ) );

	$dimensao = '' === $dimensao ? 'fonte' : $dimensao;
	$modelo   = '' === $modelo ? 'primeira' : $modelo;
	$periodo  = '' === $periodo ? '30d' : $periodo;

	if ( ! isset( rastro_relatorio_dimensoes()[ $dimensao ] ) || ! isset( rastro_relatorio_modelos()[ $modelo ] ) ) {
		return null;
	}

	$intervalo = rastro_relatorio_periodo( $periodo, rastro_relatorios_entrada( $fonte, 'de' ), rastro_relatorios_entrada( $fonte, 'ate' ) );
	if ( null === $intervalo ) {
		return null;
	}

	return array(
		'dimensao' => $dimensao,
		'modelo'   => $modelo,
		'periodo'  => $intervalo['periodo'],
		'inicio'   => $intervalo['inicio'],
		'fim'      => $intervalo['fim'],
		'dias'     => $intervalo['dias'],
	);
}

/**
 * Filtros para rastro_relatorio_dados().
 *
 * @param array $filtros Saída de rastro_relatorios_filtros().
 * @return array
 */
function rastro_relatorios_args_dados( array $filtros ): array {
	return array(
		'dimensao' => $filtros['dimensao'],
		'inicio'   => $filtros['inicio'],
		'fim'      => $filtros['fim'],
		'modelo'   => $filtros['modelo'],
		'status'   => rastro_relatorio_status_config(),
	);
}

/**
 * Resposta para o navegador: só agregados e os filtros aplicados.
 *
 * @param array $dados   Saída de rastro_relatorio_dados().
 * @param array $filtros Saída de rastro_relatorios_filtros().
 * @return array
 */
function rastro_relatorios_resposta( array $dados, array $filtros ): array {
	$linhas = array();
	foreach ( $dados['linhas'] as $linha ) {
		$linhas[] = array(
			'valor'   => (string) $linha['valor'],
			'pedidos' => (int) $linha['pedidos'],
			'bruta'   => (int) $linha['bruta'],
			'liquida' => (int) $linha['liquida'],
			'total'   => (int) $linha['total'],
		);
	}
	$series = array();
	foreach ( $dados['serie']['series'] as $serie ) {
		$series[] = array(
			'nome'    => (string) $serie['nome'],
			'outras'  => ! empty( $serie['outras'] ),
			'pedidos' => array_map( 'intval', $serie['pedidos'] ),
			'total'   => array_map( 'intval', $serie['total'] ),
		);
	}
	$totais = array();
	foreach ( rastro_relatorio_totais_vazios() as $chave => $zero ) {
		$totais[ $chave ] = isset( $dados['totais'][ $chave ] ) ? (int) $dados['totais'][ $chave ] : $zero;
	}

	return array(
		'filtros'  => array(
			'dimensao' => $filtros['dimensao'],
			'modelo'   => $filtros['modelo'],
			'periodo'  => $filtros['periodo'],
			'inicio'   => $filtros['inicio'],
			'fim'      => $filtros['fim'],
		),
		'moeda'    => (string) $dados['moeda'],
		'truncado' => ! empty( $dados['truncado'] ),
		'erro'     => (string) $dados['erro'],
		'linhas'   => $linhas,
		'totais'   => $totais,
		'serie'    => array(
			'dias'   => array_values( array_map( 'strval', $dados['serie']['dias'] ) ),
			'series' => $series,
		),
	);
}

/* AJAX ------------------------------------------------------------------------------ */

/** AJAX `rastro_relatorio`: dados agregados em JSON. */
function rastro_relatorios_ajax(): void {
	if ( ! current_user_can( RASTRO_RELATORIO_CAPACIDADE ) ) {
		wp_send_json_error( array( 'mensagem' => __( 'Sem permissão.', 'rastro' ) ), 403 );
	}
	if ( false === check_ajax_referer( RASTRO_RELATORIO_ACAO, 'nonce', false ) ) {
		wp_send_json_error( array( 'mensagem' => __( 'A página expirou. Recarregue e tente de novo.', 'rastro' ) ), 403 );
	}

	$entrada = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cada filtro passa por lista fechada ou pela validação de data.
	$filtros = rastro_relatorios_filtros( is_array( $entrada ) ? $entrada : array() );
	if ( null === $filtros ) {
		wp_send_json_error( array( 'mensagem' => __( 'Filtro inválido. O período personalizado vai até 366 dias, com datas válidas.', 'rastro' ) ), 400 );
	}

	wp_send_json_success( rastro_relatorios_resposta( rastro_relatorio_dados( rastro_relatorios_args_dados( $filtros ) ), $filtros ) );
}
add_action( 'wp_ajax_' . RASTRO_RELATORIO_ACAO, 'rastro_relatorios_ajax' );

/* CSV ------------------------------------------------------------------------------- */

/**
 * Centavos no formato do CSV: 123456 => "1234,56" (sem milhar, sem moeda).
 *
 * @param int $centavos Valor.
 * @return string
 */
function rastro_relatorio_csv_moeda( int $centavos ): string {
	$negativo = $centavos < 0;
	$absoluto = abs( $centavos );

	return ( $negativo ? '-' : '' ) . intdiv( $absoluto, 100 ) . ',' . str_pad( (string) ( $absoluto % 100 ), 2, '0', STR_PAD_LEFT );
}

/**
 * Célula de texto do CSV, com a proteção contra fórmula do Excel: texto que
 * começa com =, +, -, @, tabulação ou retorno de carro ganha um apóstrofo.
 *
 * @param mixed $valor Valor.
 * @return string
 */
function rastro_relatorio_csv_celula( $valor ): string {
	if ( ! is_scalar( $valor ) ) {
		return '';
	}
	$texto = (string) $valor;
	if ( '' !== $texto && in_array( $texto[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
		$texto = "'" . $texto;
	}

	return $texto;
}

/**
 * Linhas do CSV (já formatadas): cabeçalho, uma linha por valor e o total.
 *
 * @param array  $dados    Saída de rastro_relatorio_dados().
 * @param string $dimensao Chave da dimensão.
 * @return array<int,string[]>
 */
function rastro_relatorio_csv_linhas( array $dados, string $dimensao ): array {
	$rotulos = rastro_relatorio_dimensoes();
	$linhas  = array(
		array(
			rastro_relatorio_csv_celula( $rotulos[ $dimensao ] ?? $dimensao ),
			__( 'Pedidos', 'rastro' ),
			__( 'Venda bruta', 'rastro' ),
			__( 'Venda líquida', 'rastro' ),
			__( 'Venda total', 'rastro' ),
		),
	);
	foreach ( $dados['linhas'] as $linha ) {
		$linhas[] = array(
			rastro_relatorio_csv_celula( $linha['valor'] ),
			(string) (int) $linha['pedidos'],
			rastro_relatorio_csv_moeda( (int) $linha['bruta'] ),
			rastro_relatorio_csv_moeda( (int) $linha['liquida'] ),
			rastro_relatorio_csv_moeda( (int) $linha['total'] ),
		);
	}
	$linhas[] = array(
		__( 'Total', 'rastro' ),
		(string) (int) $dados['totais']['pedidos'],
		rastro_relatorio_csv_moeda( (int) $dados['totais']['bruta'] ),
		rastro_relatorio_csv_moeda( (int) $dados['totais']['liquida'] ),
		rastro_relatorio_csv_moeda( (int) $dados['totais']['total'] ),
	);

	return $linhas;
}

/**
 * Texto do CSV: UTF-8 com BOM, separador ";", aspas só quando precisa.
 *
 * @param array $linhas Linhas de células já formatadas.
 * @return string
 */
function rastro_relatorio_csv_texto( array $linhas ): string {
	$fluxo = fopen( 'php://temp', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- memória temporária.
	if ( false === $fluxo ) {
		return '';
	}
	foreach ( $linhas as $linha ) {
		fputcsv( $fluxo, array_map( 'strval', array_values( (array) $linha ) ), ';', '"', '' );
	}
	rewind( $fluxo );
	$corpo = (string) stream_get_contents( $fluxo );
	fclose( $fluxo ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- memória temporária.

	return "\xEF\xBB\xBF" . $corpo;
}

/**
 * Nome do arquivo, com a dimensão, o período e o modelo (só caracteres
 * seguros: tudo vem de lista fechada ou de data validada).
 *
 * @param array $filtros Saída de rastro_relatorios_filtros().
 * @return string
 */
function rastro_relatorio_csv_nome( array $filtros ): string {
	$dimensao = str_replace( '_', '-', $filtros['dimensao'] );
	$periodo  = $filtros['inicio'] === $filtros['fim'] ? $filtros['inicio'] : $filtros['inicio'] . '-a-' . $filtros['fim'];

	return 'relatorio-' . $dimensao . '-' . $periodo . '-' . $filtros['modelo'] . '.csv';
}

/**
 * Prepara o CSV de uma requisição já autorizada.
 *
 * @param array $fonte Entrada ($_GET, já sem barras).
 * @return array{nome:string,texto:string}|null null para filtro inválido.
 */
function rastro_relatorio_csv_preparar( array $fonte ): ?array {
	$filtros = rastro_relatorios_filtros( $fonte );
	if ( null === $filtros ) {
		return null;
	}
	$dados = rastro_relatorio_dados( rastro_relatorios_args_dados( $filtros ) );

	return array(
		'nome'  => rastro_relatorio_csv_nome( $filtros ),
		'texto' => rastro_relatorio_csv_texto( rastro_relatorio_csv_linhas( $dados, $filtros['dimensao'] ) ),
	);
}

/**
 * Confere capacidade e nonce do CSV. Recusa com wp_die (403).
 *
 * @return void
 */
function rastro_relatorio_csv_autorizar(): void {
	if ( ! current_user_can( RASTRO_RELATORIO_CAPACIDADE ) ) {
		wp_die( esc_html__( 'Sem permissão.', 'rastro' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( RASTRO_RELATORIO_ACAO_CSV );
}

/** Download do CSV (AJAX `rastro_relatorio_csv` e admin-post de mesmo nome). */
function rastro_relatorio_csv(): void {
	rastro_relatorio_csv_autorizar();

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce conferido em rastro_relatorio_csv_autorizar().
	$entrada = wp_unslash( $_GET ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cada filtro passa por lista fechada ou pela validação de data.
	$csv     = rastro_relatorio_csv_preparar( is_array( $entrada ) ? $entrada : array() );
	if ( null === $csv ) {
		wp_die( esc_html__( 'Filtro inválido. O período personalizado vai até 366 dias, com datas válidas.', 'rastro' ), '', array( 'response' => 400 ) );
	}

	while ( ob_get_level() > 0 ) {
		ob_end_clean();
	}
	if ( function_exists( 'nocache_headers' ) ) {
		nocache_headers();
	}
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . $csv['nome'] . '"' );
	header( 'X-Content-Type-Options: nosniff' );
	echo $csv['texto']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- arquivo CSV, células tratadas em rastro_relatorio_csv_celula().
	exit;
}
add_action( 'wp_ajax_' . RASTRO_RELATORIO_ACAO_CSV, 'rastro_relatorio_csv' );
add_action( 'admin_post_' . RASTRO_RELATORIO_ACAO_CSV, 'rastro_relatorio_csv' );

/**
 * Endereço do CSV para os filtros dados (com nonce).
 *
 * @param array $filtros Saída de rastro_relatorios_filtros().
 * @return string
 */
function rastro_relatorio_csv_url( array $filtros ): string {
	return add_query_arg( rastro_relatorio_csv_parametros( $filtros ), admin_url( 'admin-post.php' ) );
}

/**
 * Parâmetros do endereço do CSV.
 *
 * @param array $filtros Saída de rastro_relatorios_filtros().
 * @return array<string,string>
 */
function rastro_relatorio_csv_parametros( array $filtros ): array {
	return rastro_relatorio_baixar_parametros( $filtros, RASTRO_RELATORIO_ACAO_CSV );
}

/**
 * Parâmetros do endereço de um download (CSV ou Excel), com o nonce da ação.
 *
 * @param array  $filtros Saída de rastro_relatorios_filtros().
 * @param string $acao    RASTRO_RELATORIO_ACAO_CSV ou RASTRO_RELATORIO_ACAO_XLSX.
 * @return array<string,string>
 */
function rastro_relatorio_baixar_parametros( array $filtros, string $acao ): array {
	$parametros = array(
		'action'   => $acao,
		'_wpnonce' => wp_create_nonce( $acao ),
		'dimensao' => $filtros['dimensao'],
		'periodo'  => $filtros['periodo'],
		'modelo'   => $filtros['modelo'],
	);
	if ( 'personalizado' === $filtros['periodo'] ) {
		$parametros['de']  = $filtros['inicio'];
		$parametros['ate'] = $filtros['fim'];
	}

	return $parametros;
}

/* Excel ----------------------------------------------------------------------------- */

/**
 * Linhas da planilha do Excel: as mesmas colunas do CSV, com pedidos e
 * valores como números (moeda em reais), cabeçalho e total em negrito.
 *
 * @param array  $dados    Saída de rastro_relatorio_dados().
 * @param string $dimensao Chave da dimensão.
 * @return array<int,array>
 */
function rastro_relatorio_xlsx_linhas( array $dados, string $dimensao ): array {
	$rotulos = rastro_relatorio_dimensoes();
	$titulo  = static function ( $texto ) {
		return array( 'valor' => (string) $texto, 'negrito' => true );
	};
	$moeda   = static function ( $centavos, $negrito = false ) {
		return array( 'valor' => (int) $centavos / 100, 'tipo' => 'moeda', 'negrito' => $negrito );
	};
	$linhas  = array(
		array(
			$titulo( $rotulos[ $dimensao ] ?? $dimensao ),
			$titulo( __( 'Pedidos', 'rastro' ) ),
			$titulo( __( 'Venda bruta', 'rastro' ) ),
			$titulo( __( 'Venda líquida', 'rastro' ) ),
			$titulo( __( 'Venda total', 'rastro' ) ),
		),
	);
	foreach ( $dados['linhas'] as $linha ) {
		$linhas[] = array(
			array( 'valor' => is_scalar( $linha['valor'] ) ? (string) $linha['valor'] : '' ),
			array( 'valor' => (int) $linha['pedidos'], 'tipo' => 'inteiro' ),
			$moeda( $linha['bruta'] ),
			$moeda( $linha['liquida'] ),
			$moeda( $linha['total'] ),
		);
	}
	$linhas[] = array(
		$titulo( __( 'Total', 'rastro' ) ),
		array( 'valor' => (int) $dados['totais']['pedidos'], 'tipo' => 'inteiro', 'negrito' => true ),
		$moeda( $dados['totais']['bruta'], true ),
		$moeda( $dados['totais']['liquida'], true ),
		$moeda( $dados['totais']['total'], true ),
	);

	return $linhas;
}

/**
 * Nome do arquivo do Excel: o mesmo do CSV, com .xlsx.
 *
 * @param array $filtros Saída de rastro_relatorios_filtros().
 * @return string
 */
function rastro_relatorio_xlsx_nome( array $filtros ): string {
	return substr( rastro_relatorio_csv_nome( $filtros ), 0, -4 ) . '.xlsx';
}

/**
 * Prepara a planilha de uma requisição já autorizada.
 *
 * @param array $fonte  Entrada ($_GET, já sem barras).
 * @param int   $quando Data dos arquivos dentro do pacote (testes).
 * @return array{nome:string,bytes:string}|null null para filtro inválido.
 */
function rastro_relatorio_xlsx_preparar( array $fonte, int $quando = 0 ): ?array {
	$filtros = rastro_relatorios_filtros( $fonte );
	if ( null === $filtros ) {
		return null;
	}
	$dados = rastro_relatorio_dados( rastro_relatorios_args_dados( $filtros ) );

	return array(
		'nome'  => rastro_relatorio_xlsx_nome( $filtros ),
		'bytes' => rastro_xlsx( rastro_relatorio_xlsx_linhas( $dados, $filtros['dimensao'] ), __( 'Relatório', 'rastro' ), array( 34, 12, 16, 16, 16 ), $quando ),
	);
}

/**
 * Confere capacidade e nonce da planilha. Recusa com wp_die (403).
 *
 * @return void
 */
function rastro_relatorio_xlsx_autorizar(): void {
	if ( ! current_user_can( RASTRO_RELATORIO_CAPACIDADE ) ) {
		wp_die( esc_html__( 'Sem permissão.', 'rastro' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( RASTRO_RELATORIO_ACAO_XLSX );
}

/** Download da planilha (AJAX `rastro_relatorio_xlsx` e admin-post de mesmo nome). */
function rastro_relatorio_xlsx(): void {
	rastro_relatorio_xlsx_autorizar();

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce conferido em rastro_relatorio_xlsx_autorizar().
	$entrada = wp_unslash( $_GET ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cada filtro passa por lista fechada ou pela validação de data.
	$planilha = rastro_relatorio_xlsx_preparar( is_array( $entrada ) ? $entrada : array() );
	if ( null === $planilha ) {
		wp_die( esc_html__( 'Filtro inválido. O período personalizado vai até 366 dias, com datas válidas.', 'rastro' ), '', array( 'response' => 400 ) );
	}

	while ( ob_get_level() > 0 ) {
		ob_end_clean();
	}
	if ( function_exists( 'nocache_headers' ) ) {
		nocache_headers();
	}
	header( 'Content-Type: ' . RASTRO_XLSX_MIME );
	header( 'Content-Disposition: attachment; filename="' . $planilha['nome'] . '"' );
	header( 'Content-Length: ' . strlen( $planilha['bytes'] ) );
	header( 'X-Content-Type-Options: nosniff' );
	echo $planilha['bytes']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- arquivo binário .xlsx, texto escapado em rastro_xlsx_xml().
	exit;
}
add_action( 'wp_ajax_' . RASTRO_RELATORIO_ACAO_XLSX, 'rastro_relatorio_xlsx' );
add_action( 'admin_post_' . RASTRO_RELATORIO_ACAO_XLSX, 'rastro_relatorio_xlsx' );

/**
 * Endereço da planilha para os filtros dados (com nonce).
 *
 * @param array $filtros Saída de rastro_relatorios_filtros().
 * @return string
 */
function rastro_relatorio_xlsx_url( array $filtros ): string {
	return add_query_arg( rastro_relatorio_baixar_parametros( $filtros, RASTRO_RELATORIO_ACAO_XLSX ), admin_url( 'admin-post.php' ) );
}

/* Tela ---------------------------------------------------------------------------------- */

/**
 * Endereço de uma aba do painel (sem depender da carga do admin.php).
 *
 * @param string $aba  Aba.
 * @param array  $args Parâmetros extras.
 * @return string
 */
function rastro_relatorios_url( string $aba, array $args = array() ): string {
	if ( function_exists( 'rastro_admin_url' ) ) {
		return rastro_admin_url( $aba, $args );
	}

	return add_query_arg( array_merge( array( 'page' => 'rastro', 'aba' => $aba ), $args ), admin_url( 'admin.php' ) );
}

/**
 * Linha de informação dos status contados, com o link para a aba
 * WooCommerce quando o usuário pode mudar a configuração.
 *
 * @return void
 */
function rastro_relatorios_status_imprimir(): void {
	$opcoes  = function_exists( 'rastro_config_status_opcoes' ) ? rastro_config_status_opcoes() : array();
	$status  = rastro_relatorio_status_config();
	$rotulos = array();
	// Na ordem da lista de status da loja.
	foreach ( $opcoes as $codigo => $rotulo ) {
		if ( in_array( (string) $codigo, $status, true ) ) {
			$rotulos[] = (string) $rotulo;
		}
	}
	if ( array() === $rotulos ) {
		$rotulos = $status;
	}

	echo '<p class="rastro-rel-status">' . esc_html__( 'Status contados:', 'rastro' ) . ' <strong>' . esc_html( implode( ', ', $rotulos ) ) . '</strong>.';
	if ( current_user_can( 'manage_options' ) ) {
		echo ' <a href="' . esc_url( rastro_relatorios_url( 'woocommerce' ) ) . '">' . esc_html__( 'Mudar na aba WooCommerce', 'rastro' ) . '</a>';
	}
	echo '</p>';
}

/**
 * Configuração do relatorios.js (sem dado de cliente).
 *
 * @param array $filtros Saída de rastro_relatorios_filtros().
 * @param array $inicial Saída de rastro_relatorios_resposta().
 * @return array
 */
function rastro_relatorios_config_js( array $filtros, array $inicial ): array {
	return array(
		'ajax'      => admin_url( 'admin-ajax.php' ),
		'acao'      => RASTRO_RELATORIO_ACAO,
		'nonce'     => wp_create_nonce( RASTRO_RELATORIO_ACAO ),
		'csv'       => admin_url( 'admin-post.php' ),
		'csvNonce'  => wp_create_nonce( RASTRO_RELATORIO_ACAO_CSV ),
		'csvAcao'   => RASTRO_RELATORIO_ACAO_CSV,
		'xlsxNonce' => wp_create_nonce( RASTRO_RELATORIO_ACAO_XLSX ),
		'xlsxAcao'  => RASTRO_RELATORIO_ACAO_XLSX,
		'inicial'   => $inicial,
		'dimensoes' => rastro_relatorio_dimensoes(),
		'idioma'    => str_replace( '_', '-', function_exists( 'get_locale' ) ? (string) get_locale() : 'pt-BR' ),
		'textos'    => array(
			'valores'      => __( 'Valores', 'rastro' ),
			'pedidos'      => __( 'Pedidos', 'rastro' ),
			'bruta'        => __( 'Venda bruta', 'rastro' ),
			'liquida'      => __( 'Venda líquida', 'rastro' ),
			'total'        => __( 'Venda total', 'rastro' ),
			'dia'          => __( 'Dia', 'rastro' ),
			'carregando'   => __( 'Carregando os dados...', 'rastro' ),
			'pronto'       => __( 'Relatório atualizado.', 'rastro' ),
			'erro'         => __( 'Não foi possível carregar o relatório. Tente de novo.', 'rastro' ),
			'vazio'        => __( 'Nenhum pedido neste período, nos status contados.', 'rastro' ),
			'semSerie'     => __( 'Todas as séries estão ocultas. Use a legenda para mostrar.', 'rastro' ),
			'grafico'      => __( 'Gráfico de linhas por dia. Use as setas para percorrer os dias; a tabela abaixo traz os mesmos números.', 'rastro' ),
			'ordenar'      => __( 'Ordenar por', 'rastro' ),
			'anterior'     => __( 'Anterior', 'rastro' ),
			'proxima'      => __( 'Próxima', 'rastro' ),
			'pagina'       => __( 'Página', 'rastro' ),
			'de'           => __( 'de', 'rastro' ),
			'truncado'     => __( 'O período tem pedidos demais para uma leitura; o relatório mostra só a primeira parte. Escolha um período menor.', 'rastro' ),
			'woocommerce'  => __( 'O WooCommerce não está ativo.', 'rastro' ),
			'mostrarSerie' => __( 'Mostrar ou ocultar a série', 'rastro' ),
		),
	);
}

/**
 * Conteúdo da aba "Relatórios".
 *
 * @param string $aba Chave da aba.
 * @return void
 */
function rastro_relatorios_tela( string $aba = RASTRO_RELATORIO_ABA ): void {
	if ( ! current_user_can( RASTRO_RELATORIO_CAPACIDADE ) ) {
		echo '<p>' . esc_html__( 'Sem permissão.', 'rastro' ) . '</p>';
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filtros de leitura, por lista fechada.
	$entrada = wp_unslash( $_GET ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cada filtro passa por lista fechada ou pela validação de data.
	$entrada = is_array( $entrada ) ? $entrada : array();
	$filtros = rastro_relatorios_filtros( $entrada );
	if ( null === $filtros ) {
		if ( function_exists( 'rastro_admin_aviso' ) ) {
			rastro_admin_aviso( 'warning', __( 'Filtro inválido: o relatório voltou para os últimos 30 dias. O período personalizado vai até 366 dias, com datas válidas.', 'rastro' ) );
		}
		$filtros = rastro_relatorios_filtros( array() );
	}
	if ( null === $filtros ) {
		return;
	}

	$inicial = rastro_relatorios_resposta( rastro_relatorio_dados( rastro_relatorios_args_dados( $filtros ) ), $filtros );
	if ( function_exists( 'wp_add_inline_script' ) && function_exists( 'rastro_json' ) ) {
		wp_add_inline_script( 'rastro-relatorios', 'window.rastroRelatorio=' . rastro_json( rastro_relatorios_config_js( $filtros, $inicial ) ) . ';', 'before' );
	}

	echo '<div class="rastro-relatorios" id="rastro-relatorios">';
	rastro_relatorios_status_imprimir();

	// Abas de dimensão (links comuns; o JS troca por AJAX).
	echo '<nav class="rastro-rel-dimensoes" aria-label="' . esc_attr__( 'Dimensão do relatório', 'rastro' ) . '"><ul>';
	foreach ( rastro_relatorio_dimensoes() as $chave => $rotulo ) {
		$parametros = array(
			'dimensao' => $chave,
			'periodo'  => $filtros['periodo'],
			'modelo'   => $filtros['modelo'],
		);
		if ( 'personalizado' === $filtros['periodo'] ) {
			$parametros['de']  = $filtros['inicio'];
			$parametros['ate'] = $filtros['fim'];
		}
		$atual = $chave === $filtros['dimensao'];
		echo '<li><a class="rastro-rel-dimensao' . ( $atual ? ' is-atual' : '' ) . '" data-dimensao="' . esc_attr( $chave ) . '" href="' . esc_url( rastro_relatorios_url( RASTRO_RELATORIO_ABA, $parametros ) ) . '"' . ( $atual ? ' aria-current="true"' : '' ) . '>' . esc_html( $rotulo ) . '</a></li>';
	}
	echo '</ul></nav>';

	// Filtros.
	$personalizado = 'personalizado' === $filtros['periodo'];
	echo '<form class="rastro-rel-filtros" method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
	echo '<input type="hidden" name="page" value="rastro"><input type="hidden" name="aba" value="' . esc_attr( RASTRO_RELATORIO_ABA ) . '">';
	echo '<input type="hidden" name="dimensao" value="' . esc_attr( $filtros['dimensao'] ) . '">';

	echo '<p class="rastro-rel-campo"><label for="rastro-rel-periodo">' . esc_html__( 'Período', 'rastro' ) . '</label> <select id="rastro-rel-periodo" name="periodo">';
	foreach ( rastro_relatorio_periodos() as $chave => $rotulo ) {
		echo '<option value="' . esc_attr( $chave ) . '"' . selected( $filtros['periodo'], $chave, false ) . '>' . esc_html( $rotulo ) . '</option>';
	}
	echo '</select></p>';

	echo '<p class="rastro-rel-campo rastro-rel-datas"' . ( $personalizado ? '' : ' hidden' ) . '>';
	echo '<label for="rastro-rel-de">' . esc_html__( 'De', 'rastro' ) . '</label> <input type="date" id="rastro-rel-de" name="de" value="' . esc_attr( $filtros['inicio'] ) . '" min="2000-01-01" max="2100-12-31"> ';
	echo '<label for="rastro-rel-ate">' . esc_html__( 'Até', 'rastro' ) . '</label> <input type="date" id="rastro-rel-ate" name="ate" value="' . esc_attr( $filtros['fim'] ) . '" min="2000-01-01" max="2100-12-31">';
	echo '</p>';

	echo '<p class="rastro-rel-campo"><label for="rastro-rel-modelo">' . esc_html__( 'Atribuição', 'rastro' ) . '</label> <select id="rastro-rel-modelo" name="modelo">';
	foreach ( rastro_relatorio_modelos() as $chave => $rotulo ) {
		echo '<option value="' . esc_attr( $chave ) . '"' . selected( $filtros['modelo'], $chave, false ) . '>' . esc_html( $rotulo ) . '</option>';
	}
	echo '</select></p>';

	echo '<p class="rastro-rel-campo rastro-rel-acoes"><button type="submit" class="button">' . esc_html__( 'Aplicar', 'rastro' ) . '</button> ';
	echo '<a class="button rastro-rel-xlsx" href="' . esc_url( rastro_relatorio_xlsx_url( $filtros ) ) . '">' . esc_html__( 'Baixar Excel', 'rastro' ) . '</a> ';
	echo '<a class="button rastro-rel-csv" href="' . esc_url( rastro_relatorio_csv_url( $filtros ) ) . '">' . esc_html__( 'Baixar CSV', 'rastro' ) . '</a></p>';
	echo '</form>';

	echo '<p class="rastro-rel-periodo-texto" data-rastro-rel="periodo"></p>';
	echo '<p class="rastro-rel-mensagem" data-rastro-rel="mensagem" role="status" aria-live="polite"></p>';
	echo '<noscript><p>' . esc_html__( 'Os totais, o gráfico e a tabela precisam de JavaScript. O Excel e o CSV funcionam sem ele.', 'rastro' ) . '</p></noscript>';

	echo '<div class="rastro-rel-totais" data-rastro-rel="totais"></div>';

	echo '<section class="rastro-rel-bloco" aria-labelledby="rastro-rel-grafico-titulo">';
	echo '<div class="rastro-rel-cabeca"><h2 id="rastro-rel-grafico-titulo">' . esc_html__( 'Por dia', 'rastro' ) . '</h2>';
	echo '<p class="rastro-rel-campo"><label for="rastro-rel-metrica">' . esc_html__( 'Mostrar', 'rastro' ) . '</label> <select id="rastro-rel-metrica" data-rastro-rel="metrica"><option value="total">' . esc_html__( 'Venda total', 'rastro' ) . '</option><option value="pedidos">' . esc_html__( 'Pedidos', 'rastro' ) . '</option></select></p></div>';
	echo '<div class="rastro-rel-grafico" data-rastro-rel="grafico"></div>';
	echo '<div class="rastro-rel-legenda" data-rastro-rel="legenda"></div>';
	echo '<details class="rastro-rel-detalhes"><summary>' . esc_html__( 'Ver os números do gráfico em tabela', 'rastro' ) . '</summary><div class="rastro-rel-rolagem" data-rastro-rel="grafico-tabela"></div></details>';
	echo '</section>';

	echo '<section class="rastro-rel-bloco" aria-labelledby="rastro-rel-tabela-titulo">';
	echo '<div class="rastro-rel-cabeca"><h2 id="rastro-rel-tabela-titulo">' . esc_html__( 'Por valor da dimensão', 'rastro' ) . '</h2>';
	echo '<p class="rastro-rel-campo"><label for="rastro-rel-por-pagina">' . esc_html__( 'Linhas por página', 'rastro' ) . '</label> <select id="rastro-rel-por-pagina" data-rastro-rel="por-pagina"><option value="25">25</option><option value="50">50</option><option value="100">100</option></select></p></div>';
	echo '<div class="rastro-rel-rolagem" data-rastro-rel="tabela"></div>';
	echo '<div class="rastro-rel-paginacao" data-rastro-rel="paginacao"></div>';
	echo '</section>';

	echo '</div>';
}

/**
 * Script e estilo da aba, só na tela do Rastro, na aba Relatórios, para
 * quem pode ver. Prioridade 20: depois do admin.css da base.
 *
 * @param string $sufixo Sufixo da tela atual.
 * @return void
 */
function rastro_relatorios_assets( $sufixo = '' ): void {
	if ( ! function_exists( 'rastro_admin_tela_id' ) || ! function_exists( 'rastro_admin_aba_atual' ) ) {
		return;
	}
	$tela = rastro_admin_tela_id();
	if ( '' === $tela || $tela !== $sufixo || RASTRO_RELATORIO_ABA !== rastro_admin_aba_atual() || ! current_user_can( RASTRO_RELATORIO_CAPACIDADE ) ) {
		return;
	}

	if ( function_exists( 'rastro_registrar_script' ) && rastro_registrar_script( 'rastro-relatorios', 'relatorios.js' ) ) {
		wp_enqueue_script( 'rastro-relatorios' );
	}

	$arquivo = RASTRO_DIR . 'assets/css/relatorios.css';
	if ( is_readable( $arquivo ) ) {
		$data = @filemtime( $arquivo ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- sem data, vale a versão do plugin.
		wp_enqueue_style( 'rastro-relatorios', RASTRO_URL . 'assets/css/relatorios.css', array(), false !== $data ? (string) $data : RASTRO_VERSAO );
	}
}
add_action( 'admin_enqueue_scripts', 'rastro_relatorios_assets', 20 );
