<?php
/**
 * Testes da base do Rastro (rastro/: rastro.php,
 * nucleo/*, admin/admin.php), sem WordPress: esquema e validação de cada
 * campo, gravação que mantém o valor anterior, regras de onde medir,
 * endereço saneado, JSON do <head> sem escapar da tag, atributos dos
 * scripts, exclusões dos otimizadores, painel, carregador e guarda do
 * plugin próprio.
 *
 * Uso: php tests/php/rastro-base.test.php (sai com 1 se algo falhar).
 * Contrato: docs/CONTRATO.md, seções 3 e 12.
 * IDs fictícios, só com o formato de cada serviço.
 *
 * @package Rastro
 */

define( 'ABSPATH', __DIR__ . '/' );

$rastro_dir    = realpath( __DIR__ . '/../../rastro' );
$rastro_plugin = $rastro_dir . '/rastro.php';

/*
 * Cenário em processo novo (constantes não se desfazem):
 * php rastro-base.test.php --cenario=guarda|duas_copias|local_proposital
 */
$cenario_pedido = '';
foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( 0 === strpos( $arg, '--cenario=' ) ) {
		$cenario_pedido = substr( $arg, 10 );
	}
}
if ( '' !== $cenario_pedido ) {
	require __DIR__ . '/stubs-rastro.php';
	if ( 'guarda' === $cenario_pedido ) {
		// Plugin próprio já ativo: a cópia embutida não carrega nada.
		define( 'RASTRO_VERSAO', '9.9.9' );
		require $rastro_plugin;
		echo json_encode(
			array(
				'versao'    => RASTRO_VERSAO,
				'carregar'  => function_exists( 'rastro_carregar' ),
				'esquema'   => function_exists( 'rastro_config_esquema' ),
				'carregado' => did_action( 'rastro_carregado' ),
			)
		);
	} elseif ( 'duas_copias' === $cenario_pedido ) {
		// A mesma pasta incluída duas vezes (plugin próprio + embutido): sem erro fatal.
		require $rastro_plugin;
		require $rastro_plugin;
		echo json_encode(
			array(
				'versao'    => RASTRO_VERSAO,
				'carregado' => did_action( 'rastro_carregado' ),
			)
		);
	} elseif ( 'local_proposital' === $cenario_pedido ) {
		rastro_teste_reset( array( 'host' => 'loja.localhost' ) );
		define( 'RASTRO_LOCAL', true );
		require $rastro_plugin;
		echo json_encode( array( 'local' => rastro_ambiente_local() ) );
	}
	exit( 0 );
}

require __DIR__ . '/stubs-rastro.php';

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

/** Escape de JSON (barra invertida, u e o código), sem escrever a barra no fonte. */
function u( $hex ) { return chr( 92 ) . 'u' . $hex; } // phpcs:ignore

/** Zera o estado e as memórias do Rastro (os ganchos dos módulos ficam). */
function novo( array $sobre = array() ) { // phpcs:ignore
	rastro_teste_reset( $sobre );
	rastro_config_redefinir_cache();
	rastro_regras_redefinir_cache();
}

/* 0. Carregador -------------------------------------------------------------- */

$falhas_registradas = array();
add_action(
	'rastro_falha',
	static function ( $modulo, $erro ) use ( &$falhas_registradas ) {
		$falhas_registradas[] = array( $modulo, get_class( $erro ) );
	},
	10,
	2
);

// No painel, para o carregador incluir admin/admin.php.
rastro_teste_reset( array( 'admin' => true ) );
require $rastro_plugin;

$versao_cabecalho = preg_match( '/^ \* Version:\s+(\S+)$/m', (string) file_get_contents( __DIR__ . '/../../rastro/rastro.php' ), $m_versao ) ? $m_versao[1] : '';
confere( 'versão', RASTRO_VERSAO, $versao_cabecalho );
confere( 'RASTRO_DIR com barra final', RASTRO_DIR, $rastro_dir . '/' );
confere( 'RASTRO_URL por plugins_url, com barra final', RASTRO_URL, 'https://loja.exemplo.com.br/wp-content/plugins/rastro/' );
confere( 'RASTRO_ARQUIVO', realpath( RASTRO_ARQUIVO ), realpath( $rastro_plugin ) );
confere( 'rastro_carregado uma vez', did_action( 'rastro_carregado' ), 1 );
confere( 'núcleo carregado', function_exists( 'rastro_config_esquema' ) && function_exists( 'rastro_deve_medir' ) && function_exists( 'rastro_head_config' ), true );
confere( 'painel carregado no painel', function_exists( 'rastro_admin_tela' ), true );
confere( 'sem falha ao carregar a base', $falhas_registradas, array() );
confere(
	'lista completa no painel',
	rastro_modulos(),
	array(
		'nucleo/config.php',
		'nucleo/regras.php',
		'nucleo/saida.php',
		'logs/logs.php',
		'consentimento/consentimento.php',
		'consentimento/aviso.php',
		'tags/tags.php',
		'loja/eventos.php',
		'atribuicao/atribuicao.php',
		'atribuicao/pedido.php',
		'relatorios/dados.php',
		'relatorios/relatorios.php',
		'atualizacao/chaves.php',
		'atualizacao/atualizador.php',
		'admin/admin.php',
		'atualizacao/instalador.php',
	)
);
novo();
confere( 'fora do painel, sem admin/admin.php', in_array( 'admin/admin.php', rastro_modulos(), true ), false );
add_filter( 'rastro_modulos', static function () { return 'quebrado'; } );
confere( 'filtro rastro_modulos que não devolve lista: lista padrão', count( rastro_modulos() ), 14 );
remove_all_filters( 'rastro_modulos' );
add_filter( 'rastro_modulos', static function ( $m ) { $m[] = 'extra/novo.php'; $m[] = 7; return $m; } );
confere( 'filtro rastro_modulos acrescenta (só textos)', array_slice( rastro_modulos(), -1 ), array( 'extra/novo.php' ) );
remove_all_filters( 'rastro_modulos' );

confere( 'módulo ausente é pulado', rastro_carregar_arquivo( 'consentimento/nao-existe.php' ), false );
confere( 'caminho com .. recusado', rastro_carregar_arquivo( '../rastro.php' ), false );
confere( 'caminho absoluto recusado', rastro_carregar_arquivo( '/etc/passwd' ), false );
$temporaria = sys_get_temp_dir() . '/rastro-teste-' . getmypid() . '/';
@mkdir( $temporaria . 'quebra', 0777, true ); // phpcs:ignore
file_put_contents( $temporaria . 'quebra/modulo.php', "<?php\nthrow new \\RuntimeException( 'falha de teste' );\n" ); // phpcs:ignore
file_put_contents( $temporaria . 'quebra/bom.php', "<?php\n\$GLOBALS['rastro_teste_bom'] = true;\n" ); // phpcs:ignore
$falhas_registradas = array();
confere( 'módulo com erro devolve false', rastro_carregar_arquivo( 'quebra/modulo.php', $temporaria ), false );
confere( 'módulo com erro vira rastro_falha', $falhas_registradas, array( array( 'quebra/modulo.php', 'RuntimeException' ) ) );
confere( 'módulo bom carrega', rastro_carregar_arquivo( 'quebra/bom.php', $temporaria ) && ! empty( $GLOBALS['rastro_teste_bom'] ), true );
@unlink( $temporaria . 'quebra/modulo.php' ); // phpcs:ignore
@unlink( $temporaria . 'quebra/bom.php' ); // phpcs:ignore
@rmdir( $temporaria . 'quebra' ); // phpcs:ignore
@rmdir( $temporaria ); // phpcs:ignore
$falhas_registradas = array();

$php      = escapeshellarg( PHP_BINARY );
$cenario  = static function ( $nome ) use ( $php ) {
	return json_decode( (string) shell_exec( $php . ' ' . escapeshellarg( __FILE__ ) . ' --cenario=' . $nome ), true );
};
$c = $cenario( 'guarda' );
confere( 'guarda: versão do plugin próprio', $c['versao'] ?? null, '9.9.9' );
confere( 'guarda: carregador não declarado', $c['carregar'] ?? null, false );
confere( 'guarda: módulos não carregados', $c['esquema'] ?? null, false );
confere( 'guarda: nada carregado', $c['carregado'] ?? null, 0 );
$c = $cenario( 'duas_copias' );
confere( 'duas cópias: sem erro fatal', $c['versao'] ?? null, $versao_cabecalho );
confere( 'duas cópias: carrega uma vez', $c['carregado'] ?? null, 1 );
$c = $cenario( 'local_proposital' );
confere( 'RASTRO_LOCAL liga a medição numa cópia local', $c['local'] ?? null, false );

/* 1. Esquema ------------------------------------------------------------------ */

novo();
$esquema = rastro_config_esquema();
$eventos = array( 'view_item_list', 'view_item', 'add_to_cart', 'remove_from_cart', 'view_cart', 'begin_checkout', 'add_payment_info', 'purchase', 'search' );
$chaves  = array( 'gtm', 'ga4', 'ads', 'ads_rotulo_compra', 'meta', 'meta_cabecalho', 'tiktok', 'openai', 'openai_debug', 'posthog', 'posthog_regiao', 'posthog_logs', 'posthog_logs_console', 'google_verificacao', 'facebook_verificacao', 'carregamento', 'eventos_loja' );
foreach ( $eventos as $evento ) {
	$chaves[] = 'evento_' . $evento;
}
$chaves = array_merge( $chaves, array( 'valor_compra', 'id_produto', 'status_relatorio', 'atribuicao', 'atribuicao_utm_sempre', 'atribuicao_dias', 'atribuicao_woo_consentimento', 'modo', 'aceite_segundos', 'aviso', 'aviso_texto', 'aviso_dias', 'medir_equipe' ) );
confere( 'esquema com as chaves do contrato, na ordem', array_keys( $esquema ), $chaves );
$tipos = array( 'id', 'texto', 'selecao', 'booleano', 'inteiro', 'lista' );
$abas  = array( 'geral', 'eventos', 'woocommerce', 'consentimento', 'verificacao' );
foreach ( $esquema as $chave => $campo ) {
	confere( "{$chave}: aba válida", in_array( $campo['aba'], $abas, true ), true );
	confere( "{$chave}: tipo válido", in_array( $campo['tipo'], $tipos, true ), true );
	confere( "{$chave}: rótulo", is_string( $campo['rotulo'] ) && '' !== $campo['rotulo'], true );
	confere( "{$chave}: ajuda", array_key_exists( 'ajuda', $campo ) && is_string( $campo['ajuda'] ), true );
	confere( "{$chave}: padrão existe", array_key_exists( 'padrao', $campo ), true );
	if ( 'id' === $campo['tipo'] ) {
		confere( "{$chave}: regex com /D", is_string( $campo['regex'] ) && '/D' === substr( $campo['regex'], -2 ), true );
	}
	confere( "{$chave}: padrão passa na própria regra", rastro_config_normalizar( $campo, rastro_config_validar_campo( $chave, $campo['padrao'] ) ), $campo['padrao'] );
}
$padrao = rastro_config_padrao();
confere( 'padrão carregamento', $padrao['carregamento'], 'ocioso' );
confere( 'padrão posthog_regiao', $padrao['posthog_regiao'], 'us' );
confere( 'padrão eventos_loja', $padrao['eventos_loja'], '1' );
confere( 'padrão evento_purchase', $padrao['evento_purchase'], '1' );
confere( 'padrão valor_compra', $padrao['valor_compra'], 'produtos' );
confere( 'padrão id_produto', $padrao['id_produto'], 'id' );
confere( 'padrão status_relatorio', $padrao['status_relatorio'], array( 'processing', 'completed' ) );
confere( 'padrão atribuicao', $padrao['atribuicao'], 'aceite' );
confere( 'padrão atribuicao_dias', $padrao['atribuicao_dias'], 90 );
confere( 'padrão modo', $padrao['modo'], 'lgpd' );
confere( 'padrão aceite_segundos', $padrao['aceite_segundos'], 2 );
confere( 'padrão atribuicao_utm_sempre', $padrao['atribuicao_utm_sempre'], '1' );
confere( 'modo inválido', rastro_config_validar_campo( 'modo', 'livre' ), null );
confere( 'modo básico', rastro_config_validar_campo( 'modo', 'basico' ), 'basico' );
confere( 'aceite acima do máximo', rastro_config_validar_campo( 'aceite_segundos', '99' ), null );
confere( 'padrão atribuicao_woo_consentimento', $padrao['atribuicao_woo_consentimento'], '1' );
confere( 'padrão aviso', $padrao['aviso'], 'automatico' );
confere( 'padrão aviso_texto', $padrao['aviso_texto'], 'Usamos cookies para medir o uso do site e os nossos anúncios, só com a sua permissão.' );
confere( 'padrão aviso_dias', $padrao['aviso_dias'], 180 );
confere( 'padrão medir_equipe', $padrao['medir_equipe'], '0' );
confere( 'padrão openai_debug', $padrao['openai_debug'], '0' );
foreach ( array( 'gtm', 'ga4', 'ads', 'ads_rotulo_compra', 'meta', 'tiktok', 'openai', 'posthog', 'google_verificacao' ) as $chave ) {
	confere( "padrão {$chave} vazio", $padrao[ $chave ], '' );
}

/* 2. Regras de cada campo ---------------------------------------------------------- */

$v = 'rastro_config_validar_campo';
$casos = array(
	// chave, bruto, esperado.
	array( 'gtm', 'GTM-ABCD123', 'GTM-ABCD123' ),
	array( 'gtm', ' gtm-abcd123 ', 'GTM-ABCD123' ),
	array( 'gtm', '', '' ),
	array( 'gtm', '   ', '' ),
	array( 'gtm', 'GTM-ABC', null ),
	array( 'gtm', 'GTM-ABCDEFGHIJKLM', null ),
	array( 'gtm', "GTM-ABCD\nX", null ),
	array( 'gtm', 'GTM-</script>', null ),
	array( 'gtm', array( 'GTM-ABCD123' ), null ),
	array( 'ga4', 'G-ABC1234', 'G-ABC1234' ),
	array( 'ga4', 'g-abc1234', 'G-ABC1234' ),
	array( 'ga4', 'G-ABC12', null ),
	array( 'ga4', 'UA-1234-1', null ),
	array( 'ads', 'AW-123456', 'AW-123456' ),
	array( 'ads', 'AW-12345', null ),
	array( 'ads', 'AW-12ABC345', null ),
	array( 'ads', 'aw-1234567', null ),
	array( 'ads_rotulo_compra', 'AbC-12_xyZ', 'AbC-12_xyZ' ),
	array( 'ads_rotulo_compra', 'abc', null ),
	array( 'ads_rotulo_compra', 'abc/def123', null ),
	array( 'meta', '1111222233334444', '1111222233334444' ),
	array( 'meta', '1234567', null ),
	array( 'meta', '12345678a', null ),
	array( 'tiktok', 'c1abcdefghij2345678', 'C1ABCDEFGHIJ2345678' ),
	array( 'tiktok', 'ABC123', null ),
	array( 'tiktok', 'ABCDEFGHIJ-123456', null ),
	array( 'openai', 'PixelFicticio1234567', 'PixelFicticio1234567' ),
	array( 'openai', 'curto', null ),
	array( 'posthog', 'phc_' . str_repeat( 'a1', 15 ), 'phc_' . str_repeat( 'a1', 15 ) ),
	array( 'posthog', 'phc_curta', null ),
	array( 'posthog', 'PHC_' . str_repeat( 'a', 30 ), null ),
	array( 'google_verificacao', 'AbCdEfGhIjKlMnOpQrStUv-_123', 'AbCdEfGhIjKlMnOpQrStUv-_123' ),
	array( 'google_verificacao', '<meta name="google-site-verification" content="AbCdEfGhIjKlMnOpQrStUv-_123" />', 'AbCdEfGhIjKlMnOpQrStUv-_123' ),
	array( 'google_verificacao', "<meta name='google-site-verification' content='AbCdEfGhIjKlMnOpQrStUv-_123'>", 'AbCdEfGhIjKlMnOpQrStUv-_123' ),
	array( 'google_verificacao', '<meta name="google-site-verification" content="curto" />', null ),
	array( 'google_verificacao', '<meta name="google-site-verification" content="x"><script>', null ),
	array( 'google_verificacao', 'curto', null ),
	array( 'openai_debug', '1', '1' ),
	array( 'openai_debug', '0', '0' ),
	array( 'openai_debug', true, '1' ),
	array( 'openai_debug', 0, '0' ),
	array( 'openai_debug', 'sim', null ),
	array( 'openai_debug', '', '' ),
	array( 'evento_purchase', '0', '0' ),
	array( 'evento_purchase', '2', null ),
	array( 'posthog_regiao', 'eu', 'eu' ),
	array( 'posthog_regiao', 'EU', 'eu' ),
	array( 'posthog_regiao', 'br', null ),
	array( 'carregamento', 'interacao', 'interacao' ),
	array( 'carregamento', 'imediato', 'imediato' ),
	array( 'carregamento', 'depois', null ),
	array( 'valor_compra', 'total', 'total' ),
	array( 'valor_compra', 'bruto', null ),
	array( 'id_produto', 'sku', 'sku' ),
	array( 'id_produto', 'ean', null ),
	array( 'atribuicao', 'sessao', 'sessao' ),
	array( 'atribuicao', 'desligada', 'desligada' ),
	array( 'atribuicao', 'sempre', null ),
	array( 'aviso', 'externo', 'externo' ),
	array( 'aviso', 'nenhum', null ),
	array( 'atribuicao_dias', '30', 30 ),
	array( 'atribuicao_dias', ' 365 ', 365 ),
	array( 'atribuicao_dias', 1, 1 ),
	array( 'atribuicao_dias', '0', null ),
	array( 'atribuicao_dias', '366', null ),
	array( 'atribuicao_dias', '9x', null ),
	array( 'atribuicao_dias', '-5', null ),
	array( 'atribuicao_dias', '10.5', null ),
	array( 'atribuicao_dias', 10.5, null ),
	array( 'atribuicao_dias', '', '' ),
	array( 'aviso_dias', '30', 30 ),
	array( 'aviso_dias', '29', null ),
	array( 'aviso_dias', '395', 395 ),
	array( 'aviso_dias', '396', null ),
	array( 'status_relatorio', array( 'completed', 'processing' ), array( 'processing', 'completed' ) ),
	array( 'status_relatorio', array( 'wc-completed', 'completed', 'inventado' ), array( 'completed' ) ),
	array( 'status_relatorio', 'wc-on-hold,processing', array( 'processing', 'on-hold' ) ),
	array( 'status_relatorio', array( 'inventado' ), null ),
	array( 'status_relatorio', array(), '' ),
	array( 'status_relatorio', 7, null ),
	array( 'aviso_texto', 'Usamos cookies, com a sua permissão.', 'Usamos cookies, com a sua permissão.' ),
	array( 'aviso_texto', "  linha um\n\tlinha dois  ", 'linha um linha dois' ),
	array( 'aviso_texto', 'Texto <b>com</b> negrito', 'Texto com negrito' ),
	array( 'aviso_texto', '</script><script>alert(1)</script>Oi', 'Oi' ),
	array( 'aviso_texto', str_repeat( 'á', 400 ), str_repeat( 'á', 400 ) ),
	array( 'aviso_texto', str_repeat( 'á', 401 ), null ),
	array( 'aviso_texto', '', '' ),
	array( 'nao_existe', 'x', null ),
);
foreach ( $casos as $caso ) {
	confere( $caso[0] . ' ' . var_export( $caso[1], true ), $v( $caso[0], $caso[1] ), $caso[2] );
}

// Status do WooCommerce instalado (com status próprio da loja).
novo( array( 'woo_status' => array( 'wc-processing' => 'Processando', 'wc-enviado' => 'Enviado', 'wc-completed' => 'Concluído' ) ) );
confere( 'status próprio do WooCommerce aceito', $v( 'status_relatorio', array( 'enviado', 'completed' ) ), array( 'enviado', 'completed' ) );
confere( 'status nativo ausente da loja recusado', $v( 'status_relatorio', array( 'refunded' ) ), null );
// Antes do init, a lista fechada (sem chamar o WooCommerce cedo demais).
novo( array( 'acoes' => array(), 'woo_status' => array( 'wc-enviado' => 'Enviado' ) ) );
confere( 'antes do init: lista fechada', array_keys( rastro_config_status_opcoes() ), array( 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed' ) );

/* 3. Leitura: nada do banco sem revalidar ----------------------------------------- */

novo();
confere( 'ler: não array vira padrão', rastro_config_ler( 'lixo' ), rastro_config_padrao() );
$lido = rastro_config_ler(
	array(
		'gtm'              => 'gtm-abcd123',
		'ga4'              => '<script>',
		'carregamento'     => 'nunca',
		'atribuicao_dias'  => '1000',
		'status_relatorio' => array( 'inventado' ),
		'aviso_texto'      => '',
		'eventos_loja'     => '0',
		'extra'            => 'ignorado',
	)
);
confere( 'ler: id normalizado', $lido['gtm'], 'GTM-ABCD123' );
confere( 'ler: id inválido vira vazio', $lido['ga4'], '' );
confere( 'ler: seleção inválida vira padrão', $lido['carregamento'], 'ocioso' );
confere( 'ler: inteiro fora do limite vira padrão', $lido['atribuicao_dias'], 90 );
confere( 'ler: lista inválida vira padrão', $lido['status_relatorio'], array( 'processing', 'completed' ) );
confere( 'ler: texto vazio vira padrão', $lido['aviso_texto'], rastro_config_padrao()['aviso_texto'] );
confere( 'ler: desligado fica desligado', $lido['eventos_loja'], '0' );
confere( 'ler: chave desconhecida some', array_key_exists( 'extra', $lido ), false );
confere( 'ler: sempre todas as chaves', array_keys( $lido ), array_keys( rastro_config_esquema() ) );

novo( array( 'opcoes' => array( 'rastro_config' => array( 'gtm' => 'GTM-</script>', 'meta' => '1111222233334444', 'carregamento' => 'x' ) ) ) );
$c = rastro_config();
confere( 'config: banco com script vira vazio', $c['gtm'], '' );
confere( 'config: banco válido fica', $c['meta'], '1111222233334444' );
confere( 'config: banco com seleção inválida vira padrão', $c['carregamento'], 'ocioso' );
confere( 'config_campo', rastro_config_campo( 'meta' ), '1111222233334444' );
confere( 'config_campo de chave inexistente', rastro_config_campo( 'inexistente' ), null );
confere( 'tem destino com Meta', rastro_tem_destino(), true );
novo( array( 'opcoes' => array( 'rastro_config' => 'texto no lugar do array' ) ) );
confere( 'config: opção corrompida vira padrão', rastro_config(), rastro_config_padrao() );
confere( 'sem destino', rastro_tem_destino(), false );
foreach ( array( 'gtm' => 'GTM-ABCD123', 'ga4' => 'G-ABC1234', 'ads' => 'AW-1234567', 'tiktok' => 'C1ABCDEFGHIJ2345678', 'openai' => 'PixelFicticio1234567', 'posthog' => 'phc_' . str_repeat( 'b', 25 ) ) as $chave => $id ) {
	novo( array( 'opcoes' => array( 'rastro_config' => array( $chave => $id ) ) ) );
	confere( "tem destino com {$chave}", rastro_tem_destino(), true );
}
novo( array( 'opcoes' => array( 'rastro_config' => array( 'google_verificacao' => 'AbCdEfGhIjKlMnOpQrStUv', 'ads_rotulo_compra' => 'rotulo123' ) ) ) );
confere( 'verificação e rótulo não são destino', rastro_tem_destino(), false );

// Filtro rastro_config passa pela validação de novo.
novo();
add_filter( 'rastro_config', static function ( $c ) { $c['gtm'] = '"><script>'; $c['ga4'] = 'g-filtro123'; return $c; } );
confere( 'filtro rastro_config revalidado (inválido some)', rastro_config()['gtm'], '' );
confere( 'filtro rastro_config revalidado (normaliza)', rastro_config()['ga4'], 'G-FILTRO123' );
remove_all_filters( 'rastro_config' );
novo();
confere( 'sem filtro, sem GA4', rastro_config()['ga4'], '' );

/* 4. Gravação ----------------------------------------------------------------------- */

novo( array( 'opcoes' => array( 'rastro_config' => array( 'gtm' => 'GTM-AAAA1111', 'meta' => '1111222233334444', 'aviso_dias' => 200 ) ) ) );
$r = rastro_config_salvar(
	array(
		'gtm'        => 'GTM-errado!',
		'ga4'        => 'g-abc1234',
		'aviso_dias' => '10',
		'meta'       => '',
		'chave_nova' => 'x',
		5            => 'y',
	)
);
confere( 'salvar: inválido mantém o anterior', $r['config']['gtm'], 'GTM-AAAA1111' );
confere( 'salvar: válido normalizado', $r['config']['ga4'], 'G-ABC1234' );
confere( 'salvar: vazio apaga o ID', $r['config']['meta'], '' );
confere( 'salvar: inteiro inválido mantém o anterior', $r['config']['aviso_dias'], 200 );
confere( 'salvar: erros por campo', array_keys( $r['erros'] ), array( 'gtm', 'aviso_dias' ) );
confere( 'salvar: erro em pt-BR, valor mantido', $r['erros']['gtm'], 'O ID do Google Tag Manager começa com GTM- e tem de 4 a 12 letras ou números depois do hífen. O valor anterior foi mantido.' );
confere( 'salvar: erro do inteiro cita o limite', $r['erros']['aviso_dias'], 'O campo "Dias de validade da escolha" aceita um número inteiro de 30 a 395. O valor anterior foi mantido.' );
confere( 'salvar: chave desconhecida não entra', array_key_exists( 'chave_nova', $r['config'] ), false );
confere( 'salvar: gravado no banco', $GLOBALS['rastro_teste']['opcoes']['rastro_config'], $r['config'] );
confere( 'salvar: autoload ligado', $GLOBALS['rastro_teste']['autoload']['rastro_config'], true );
confere( 'salvar: leitura depois do salvar vê o novo', rastro_config()['ga4'], 'G-ABC1234' );
confere( 'salvar: campo não enviado fica', rastro_config()['aviso_dias'], 200 );

/* 5. Onde medir: regras puras ------------------------------------------------------- */

$locais = array(
	array( 'localhost', 'production', true ),
	array( 'LOCALHOST', 'production', true ),
	array( 'localhost:8080', 'production', true ),
	array( '127.0.0.1', 'production', true ),
	array( '127.0.0.1:9400', 'production', true ),
	array( '[::1]', 'production', true ),
	array( '[::1]:8080', 'production', true ),
	array( '::1', 'production', true ),
	array( 'loja.localhost', 'production', true ),
	array( 'loja.test', 'production', true ),
	array( 'loja.test.', 'production', true ),
	array( '', 'production', true ),
	array( 'loja.com.br', 'local', true ),
	array( 'loja.com.br', 'development', true ),
	array( 'loja.com.br', 'Development', true ),
	array( 'loja.com.br', 'staging', false ),
	array( 'loja.com.br', 'production', false ),
	array( 'testes.loja.com.br', 'production', false ),
	array( 'localhost.loja.com.br', 'production', false ),
	array( 'loja.testing', 'production', false ),
);
foreach ( $locais as $caso ) {
	confere( "local {$caso[0]} / {$caso[1]}", rastro_regra_local( $caso[0], $caso[1] ), $caso[2] );
}

$tudo_certo = array( 'tem_destino' => true, 'local' => false, 'restrita' => false, 'equipe' => false );
confere( 'medir: tudo certo', rastro_regra_medir( $tudo_certo ), true );
confere( 'medir: vazio não mede', rastro_regra_medir( array() ), false );
foreach ( array_keys( $tudo_certo ) as $chave ) {
	$sem = $tudo_certo;
	unset( $sem[ $chave ] );
	confere( "medir: sem {$chave} não mede (falha fechada)", rastro_regra_medir( $sem ), false );
	$texto            = $tudo_certo;
	$texto[ $chave ]  = 'tem_destino' === $chave ? 'sim' : 0;
	confere( "medir: {$chave} que não é bool não mede", rastro_regra_medir( $texto ), false );
}
confere( 'medir: sem destino', rastro_regra_medir( array( 'tem_destino' => false ) + $tudo_certo ), false );
confere( 'medir: cópia local', rastro_regra_medir( array( 'local' => true ) + $tudo_certo ), false );
confere( 'medir: restrita', rastro_regra_medir( array( 'restrita' => true ) + $tudo_certo ), false );
confere( 'medir: equipe', rastro_regra_medir( array( 'equipe' => true ) + $tudo_certo ), false );

$urls = array(
	array( '/finalizar-compra/pedido-recebido/123/?key=wc_order_abc', true, 'pedido_recebido', '/finalizar-compra/pedido-recebido/' ),
	array( '/minha-conta/view-order/55/', true, 'conta', '/minha-conta/view-order/' ),
	array( '/minha-conta/view-order/55/extra/coisa', true, 'conta', '/minha-conta/view-order/' ),
	array( '/finalizar-compra/order-pay/77/?pay_for_order=true&key=wc_order_x', true, 'checkout', '/finalizar-compra/order-pay/' ),
	array( '/checkout/order-received/wc_order_abc/', true, 'pedido_recebido', '/checkout/order-received/' ),
	array( '/minha-conta/', true, 'conta', '/minha-conta/' ),
	array( '/minha-conta', true, 'conta', '/minha-conta/' ),
	array( '/123/', true, 'outra', '/' ),
	array( '/produto/racao-10kg/?utm_source=x&gclid=abc#avaliacoes', false, 'produto', '/produto/racao-10kg/' ),
	array( '/produto/racao-10kg', false, 'produto', '/produto/racao-10kg' ),
	array( '/?s=racao&post_type=product', false, 'busca', '/' ),
	array( '', false, 'outra', '/' ),
	array( '//outro.site/caminho?x=1', false, 'outra', '/caminho' ),
	array( 'https://outro.site/caminho/?x=1', false, 'outra', '/caminho/' ),
	array( '/a//b///c/', false, 'outra', '/a/b/c/' ),
	array( '/produto/"><script>alert(1)</script>', false, 'produto', '/produto/scriptalert(1)/script' ),
	array( '/pagina/2/', false, 'categoria', '/pagina/2/' ),
	// Tipo privado, mesmo com a bandeira falsa.
	array( '/minha-conta/view-order/55/', false, 'conta', '/minha-conta/view-order/' ),
	// Página privada pela ponte (ex.: documento do pedido).
	array( '/documento/88/?documento_privado=1', true, 'outra', '/documento/' ),
);
foreach ( $urls as $caso ) {
	confere( 'url limpa ' . $caso[0], rastro_regra_url_limpa( $caso[0], $caso[1], $caso[2] ), $caso[3] );
}
confere( 'url limpa: tamanho máximo', strlen( rastro_regra_url_limpa( '/' . str_repeat( 'a', 900 ), false, 'outra' ) ), 500 );

// Endereço medido (page_location do GA4): só as chaves de campanha da lista fechada.
$campanhas = array(
	array( '/produto/racao/?gclid=abc&utm_source=x&cor=azul#avaliacoes', false, 'produto', '/produto/racao/?utm_source=x&gclid=abc' ),
	array( '/?utm_source=Instagram&utm_medium=social&utm_campaign=Black+Friday&utm_content=a&utm_term=b&utm_id=7&gbraid=g1&wbraid=w1&fbclid=IwAR_x-1&ttclid=t.1&msclkid=m1&s=racao', false, 'inicio', '/?utm_source=Instagram&utm_medium=social&utm_campaign=Black%20Friday&utm_content=a&utm_term=b&utm_id=7&gbraid=g1&wbraid=w1&fbclid=IwAR_x-1&ttclid=t.1&msclkid=m1' ),
	array( '/?UTM_SOURCE=x&utm_source=y', false, 'inicio', '/?utm_source=x' ),
	array( '/?utm_source=cliente%40exemplo.com&utm_medium=email', false, 'inicio', '/?utm_medium=email' ),
	array( '/?utm_campaign=cpf12345678901&utm_term=1234567', false, 'inicio', '/?utm_term=1234567' ),
	array( '/?gclid=%3Cscript%3E&fbclid=ok', false, 'inicio', '/?fbclid=ok' ),
	array( '/?utm_source=&utm_medium=%20', false, 'inicio', '/' ),
	array( '/?cor=azul', false, 'produto', '/' ),
	array( '/produto/racao/', false, 'produto', '/produto/racao/' ),
	// Página privada: nenhuma consulta, nem de campanha.
	array( '/finalizar-compra/pedido-recebido/123/?key=wc_order_x&utm_source=x', true, 'pedido_recebido', '/finalizar-compra/pedido-recebido/' ),
	array( '/finalizar-compra/?utm_source=x', false, 'checkout', '/finalizar-compra/' ),
	array( '/qualquer/?utm_source=x', true, 'outra', '/qualquer/' ),
);
foreach ( $campanhas as $caso ) {
	confere( 'url de campanha ' . $caso[0], rastro_regra_url_campanha( $caso[0], $caso[1], $caso[2] ), $caso[3] );
}
confere( 'url de campanha: valor até 100 caracteres', rastro_regra_url_campanha( '/?utm_source=' . str_repeat( 'a', 150 ), false, 'inicio' ), '/?utm_source=' . str_repeat( 'a', 100 ) );
confere( 'url de campanha: consulta até 900 caracteres', strlen( rastro_regra_url_campanha( '/?gclid=' . str_repeat( 'a', 500 ) . '&fbclid=' . str_repeat( 'b', 500 ), false, 'inicio' ) ) <= 900, true );
confere( 'valor de campanha: e-mail vira vazio', rastro_regra_valor_campanha( 'a@b.co' ), '' );
confere( 'valor de campanha: 8 dígitos seguidos vira vazio', rastro_regra_valor_campanha( 'tel 21987654321' ), '' );
confere( 'valor de campanha: dígitos separados ficam', rastro_regra_valor_campanha( 'promo 2026 11' ), 'promo 2026 11' );
confere( 'valor de campanha: não texto', rastro_regra_valor_campanha( array( 'x' ) ), '' );

confere( 'privada: conta', rastro_regra_privada( 'conta', array() ), true );
confere( 'privada: checkout', rastro_regra_privada( 'checkout', array() ), true );
confere( 'privada: pedido recebido', rastro_regra_privada( 'pedido_recebido', array() ), true );
confere( 'privada: carrinho não', rastro_regra_privada( 'carrinho', array() ), false );
confere( 'privada: produto não', rastro_regra_privada( 'produto', array() ), false );
foreach ( array( 'pagar', 'post', 'key', 'wc_order', 'order-pay', 'order-received' ) as $sinal ) {
	confere( "privada: sinal {$sinal}", rastro_regra_privada( 'outra', array( $sinal => true ) ), true );
}
confere( 'tipos conhecidos', rastro_regra_tipos(), array( 'inicio', 'produto', 'categoria', 'loja', 'busca', 'carrinho', 'checkout', 'pedido_recebido', 'conta', 'outra' ) );

/* 6. Onde medir: leitura da requisição ------------------------------------------------ */

$com_destino = array( 'opcoes' => array( 'rastro_config' => array( 'ga4' => 'G-ABC1234' ) ) );
novo( $com_destino );
confere( 'requisição pública em produção mede', rastro_deve_medir(), true );
novo();
confere( 'sem destino não mede', rastro_deve_medir(), false );
novo( array( 'host' => 'loja.localhost' ) + $com_destino );
confere( 'cópia local não mede', rastro_deve_medir(), false );
confere( 'cópia local reconhecida', rastro_ambiente_local(), true );
novo( array( 'ambiente' => 'development' ) + $com_destino );
confere( 'ambiente development não mede', rastro_deve_medir(), false );
foreach (
	array(
		'painel'       => array( 'admin' => true ),
		'ajax'         => array( 'ajax' => true ),
		'cron'         => array( 'cron' => true ),
		'feed'         => array( 'wp' => array( 'is_feed' => true ) ),
		'prévia'       => array( 'wp' => array( 'is_preview' => true ) ),
		'personalizar' => array( 'wp' => array( 'is_customize_preview' => true ) ),
		'404'          => array( 'wp' => array( 'is_404' => true ) ),
		'antes do wp'  => array( 'acoes' => array( 'init' => 1 ) ),
	) as $nome => $sobre
) {
	novo( $sobre + $com_destino );
	confere( "restrita: {$nome}", rastro_requisicao_restrita(), true );
	confere( "não mede: {$nome}", rastro_deve_medir(), false );
}
novo( $com_destino );
$GLOBALS['pagenow'] = 'wp-login.php';
confere( 'restrita: login', rastro_requisicao_restrita(), true );
unset( $GLOBALS['pagenow'] );
novo( $com_destino );
confere( 'pública não é restrita', rastro_requisicao_restrita(), false );

$equipe = array( 'logado' => true, 'capacidades' => array( 'edit_posts' => true ) );
novo( $equipe + $com_destino );
confere( 'equipe (edit_posts)', rastro_e_equipe(), true );
confere( 'equipe não é medida', rastro_deve_medir(), false );
novo( array( 'logado' => true, 'capacidades' => array( 'manage_woocommerce' => true ) ) + $com_destino );
confere( 'equipe (manage_woocommerce)', rastro_e_equipe(), true );
novo( array( 'logado' => true, 'capacidades' => array( 'read' => true ) ) + $com_destino );
confere( 'cliente logado não é equipe', rastro_e_equipe(), false );
confere( 'cliente logado é medido (D3)', rastro_deve_medir(), true );
novo( $equipe + array( 'opcoes' => array( 'rastro_config' => array( 'ga4' => 'G-ABC1234', 'medir_equipe' => '1' ) ) ) );
confere( 'medir_equipe ligado: equipe medida', rastro_deve_medir(), true );
novo( $com_destino );
add_filter( 'rastro_e_equipe', '__return_true' );
confere( 'filtro rastro_e_equipe', rastro_e_equipe(), true );
remove_all_filters( 'rastro_e_equipe' );

// Filtro rastro_deve_medir: desliga, mas não liga sem destino nem em requisição restrita.
novo( $com_destino );
add_filter( 'rastro_deve_medir', '__return_false' );
confere( 'filtro desliga a medição', rastro_deve_medir(), false );
remove_all_filters( 'rastro_deve_medir' );
add_filter( 'rastro_deve_medir', '__return_true' );
novo();
confere( 'filtro não liga sem destino', rastro_deve_medir(), false );
novo( array( 'admin' => true ) + $com_destino );
confere( 'filtro não liga em requisição restrita', rastro_deve_medir(), false );
novo( array( 'host' => 'localhost' ) + $com_destino );
confere( 'filtro liga numa cópia local (escolha de quem filtra)', rastro_deve_medir(), true );
remove_all_filters( 'rastro_deve_medir' );
add_filter( 'rastro_deve_medir', static function () { return 'sim'; } );
novo( array( 'host' => 'localhost' ) + $com_destino );
confere( 'filtro que não devolve true não liga', rastro_deve_medir(), false );
remove_all_filters( 'rastro_deve_medir' );

// Calculado uma vez por requisição.
novo( $com_destino );
confere( 'primeira resposta', rastro_deve_medir(), true );
$GLOBALS['rastro_teste']['admin'] = true;
confere( 'resposta guardada na requisição', rastro_deve_medir(), true );

// Tipo da página.
$tipos_pagina = array(
	'pedido_recebido' => array( 'woo' => array( 'is_order_received_page' => true, 'is_checkout' => true ) ),
	'checkout'        => array( 'woo' => array( 'is_checkout' => true ) ),
	'carrinho'        => array( 'woo' => array( 'is_cart' => true ) ),
	'conta'           => array( 'woo' => array( 'is_account_page' => true ) ),
	'busca'           => array( 'wp' => array( 'is_search' => true ), 'woo' => array( 'is_shop' => true ) ),
	'produto'         => array( 'woo' => array( 'is_product' => true ) ),
	'categoria'       => array( 'woo' => array( 'is_product_category' => true ) ),
	'loja'            => array( 'woo' => array( 'is_shop' => true ) ),
	'inicio'          => array( 'wp' => array( 'is_front_page' => true ) ),
	'outra'           => array(),
);
foreach ( $tipos_pagina as $tipo => $sobre ) {
	novo( $sobre );
	confere( "tipo {$tipo}", rastro_pagina_tipo(), $tipo );
}
novo( array( 'woo' => array( 'is_product_tag' => true ) ) );
confere( 'tipo etiqueta de produto é categoria', rastro_pagina_tipo(), 'categoria' );
novo( array( 'acoes' => array( 'init' => 1 ), 'woo' => array( 'is_product' => true ) ) );
confere( 'antes do wp: outra', rastro_pagina_tipo(), 'outra' );

// Página privada.
novo( array( 'woo' => array( 'is_account_page' => true ) ) );
confere( 'privada: Minha Conta', rastro_pagina_privada(), true );
novo( array( 'woo' => array( 'is_checkout_pay_page' => true ) ) );
confere( 'privada: pagar pedido', rastro_pagina_privada(), true );
novo( array( 'metodo' => 'POST' ) );
confere( 'privada: resposta a POST', rastro_pagina_privada(), true );
novo( array( 'metodo' => 'HEAD' ) );
confere( 'HEAD não é privada', rastro_pagina_privada(), false );
novo( array( 'get' => array( 'key' => 'wc_order_x' ) ) );
confere( 'privada: endereço com key', rastro_pagina_privada(), true );
novo( array( 'query_vars' => array( 'order-received' => '123' ) ) );
confere( 'privada: endpoint order-received', rastro_pagina_privada(), true );
novo( array( 'woo' => array( 'is_product' => true ) ) );
confere( 'produto não é privada', rastro_pagina_privada(), false );
add_filter( 'rastro_pagina_privada', '__return_true' );
confere( 'filtro acrescenta página privada', rastro_pagina_privada(), true );
remove_all_filters( 'rastro_pagina_privada' );
novo( array( 'woo' => array( 'is_account_page' => true ) ) );
add_filter( 'rastro_pagina_privada', '__return_false' );
confere( 'filtro não tira página privada', rastro_pagina_privada(), true );
remove_all_filters( 'rastro_pagina_privada' );

// Endereço absoluto: host do site, nunca do cabeçalho.
novo( array( 'uri' => '/produto/racao/?utm_source=x' ) );
confere( 'url limpa absoluta com o host do site', rastro_url_limpa(), 'https://loja.exemplo.com.br/produto/racao/' );
confere( 'url de campanha absoluta com o utm', rastro_url_campanha(), 'https://loja.exemplo.com.br/produto/racao/?utm_source=x' );
novo( array( 'uri' => '/finalizar-compra/pedido-recebido/123/?key=wc_order_x&utm_source=x', 'woo' => array( 'is_order_received_page' => true ) ) );
confere( 'url limpa do pedido recebido', rastro_url_limpa(), 'https://loja.exemplo.com.br/finalizar-compra/pedido-recebido/' );
confere( 'url de campanha do pedido recebido sem consulta', rastro_url_campanha(), 'https://loja.exemplo.com.br/finalizar-compra/pedido-recebido/' );
novo( array( 'uri' => '/', 'esquema' => 'http', 'host' => 'loja.exemplo.com.br:8443' ) );
confere( 'url limpa com porta e http', rastro_url_limpa(), 'http://loja.exemplo.com.br:8443/' );

/* 7. Saída para o navegador ---------------------------------------------------------- */

confere( 'rastro_head_config no wp_head, prioridade 1', has_action( 'wp_head', 'rastro_head_config' ), 1 );
novo( $com_destino + array( 'uri' => '/produto/racao/?gclid=abc' ) );
$GLOBALS['rastro_teste']['woo'] = array( 'is_product' => true );
$nav = rastro_config_navegador();
confere( 'config do navegador: chaves base', array_keys( $nav ), array( 'versao', 'pagina', 'carregamento', 'debug' ) );
confere( 'config do navegador: página', $nav['pagina'], array( 'tipo' => 'produto', 'privada' => false, 'url' => 'https://loja.exemplo.com.br/produto/racao/' ) );
confere( 'config do navegador: carregamento', $nav['carregamento'], 'ocioso' );
confere( 'config do navegador: debug sem WP_DEBUG', $nav['debug'], false );
confere( 'config do navegador: versão', $nav['versao'], $versao_cabecalho );

add_filter(
	'rastro_config_navegador',
	static function ( $c ) {
		$c['teste'] = array( 'texto' => '</script><script>alert("x")</script>&\'' );
		return $c;
	}
);
$head = rastro_teste_saida( 'rastro_head_config' );
remove_all_filters( 'rastro_config_navegador' );
confere( 'head: abre com id e atributos de saída', 1 === preg_match( '#^<script data-no-optimize="1" data-no-defer="1" data-cfasync="false" id="rastro-config" class="nowprocket">#', $head ), true );
confere( 'head: </script> do dado não fecha a tag', substr_count( strtolower( $head ), '</script' ), 1 );
confere( 'head: nenhum < dentro do código', false !== strpos( $head, u( '003C' ) . '/script' . u( '003E' ) ) && 2 === substr_count( $head, '<' ), true );
confere( 'head: aspas e & escapados', false !== strpos( $head, u( '0022' ) . 'x' . u( '0022' ) ) && false !== strpos( $head, u( '0026' ) . u( '0027' ) ), true );
confere( 'head: define rastroConfig e window.rastro', 1 === preg_match( '/window\.rastroConfig=\{.*\};window\.rastro=window\.rastro\|\|\{\};/s', $head ), true );
confere( 'head: barras sem escape', false !== strpos( $head, '"url":"https://loja.exemplo.com.br/produto/racao/"' ), true );
preg_match( '/window\.rastroConfig=(\{.*\});window\.rastro=/s', $head, $partes );
$decodificado = json_decode( $partes[1] ?? '', true );
confere( 'head: JSON válido devolve o texto original', $decodificado['teste']['texto'] ?? null, '</script><script>alert("x")</script>&\'' );
confere( 'head: CSP (atributos pelo filtro do WordPress)', end( $GLOBALS['rastro_teste']['inline'] )['atributos']['id'] ?? null, 'rastro-config' );

novo();
confere( 'head: nada sem medição', rastro_teste_saida( 'rastro_head_config' ), '' );
confere( 'precisa saída: não por padrão', rastro_precisa_saida(), false );
add_filter( 'rastro_precisa_saida', '__return_true' );
confere( 'precisa saída: pelo filtro (aviso próprio)', rastro_precisa_saida(), true );
confere( 'head: impresso quando o aviso pede', false !== strpos( rastro_teste_saida( 'rastro_head_config' ), 'id="rastro-config"' ), true );
novo( array( 'admin' => true ) );
confere( 'precisa saída: nunca em requisição restrita', rastro_precisa_saida(), false );
remove_all_filters( 'rastro_precisa_saida' );
novo( $com_destino );
add_filter( 'rastro_config_navegador', static function () { return 'quebrado'; } );
confere( 'filtro do navegador que não devolve array: base', array_keys( rastro_config_navegador() ), array( 'versao', 'pagina', 'carregamento', 'debug' ) );
remove_all_filters( 'rastro_config_navegador' );

confere( 'rastro_json escapa a tag', rastro_json( '</script>' ), '"' . u( '003C' ) . '/script' . u( '003E' ) . '"' );
confere( 'rastro_json sem escapar barra', rastro_json( 'a/b' ), '"a/b"' );
confere( 'rastro_json com texto inválido: vazio', rastro_json( "\xB1\x31" ), '' );

// Script inline: id do Rastro e saneado.
confere( 'inline: id fora do prefixo não imprime', rastro_teste_saida( 'rastro_script_inline', array( 'outro-id', 'var a=1;' ) ), '' );
confere( 'inline: código vazio não imprime', rastro_teste_saida( 'rastro_script_inline', array( 'rastro-x', '  ' ) ), '' );
$inline = rastro_teste_saida( 'rastro_script_inline', array( 'rastro-"x', 'var a=1;' ) );
confere( 'inline: id saneado', false !== strpos( $inline, ' id="rastro-x" ' ) && false === strpos( $inline, 'rastro-"' ), true );

// Atributos dos scripts com src (WordPress 7: só pelo filtro de atributos).
confere( 'filtro de atributos ligado', has_filter( 'wp_script_attributes', 'rastro_filtro_atributos_script' ), 10 );
confere( 'filtro de atributos inline ligado', has_filter( 'wp_inline_script_attributes', 'rastro_filtro_atributos_inline' ), 10 );
confere( 'script_loader_tag ligado', has_filter( 'script_loader_tag', 'rastro_filtro_tag_script' ), 10 );
$tag = wp_get_script_tag( array( 'src' => 'https://loja.exemplo.com.br/x.js', 'id' => 'rastro-consent-js', 'defer' => true ) );
confere( 'script do Rastro com atributos de saída (data-cfasync antes do src)', $tag, '<script data-no-optimize="1" data-no-defer="1" data-cfasync="false" src="https://loja.exemplo.com.br/x.js" id="rastro-consent-js" defer class="nowprocket"></script>' . "\n" );
$tag = wp_get_script_tag( array( 'src' => 'https://loja.exemplo.com.br/y.js', 'id' => 'outro-js' ) );
confere( 'script de outro plugin intocado', $tag, '<script src="https://loja.exemplo.com.br/y.js" id="outro-js"></script>' . "\n" );
confere( 'classe existente mantida', rastro_filtro_atributos_script( array( 'id' => 'rastro-a-js', 'class' => 'minha' ) )['class'], 'minha nowprocket' );
confere( 'classe nowprocket não repete', rastro_filtro_atributos_script( array( 'id' => 'rastro-a-js', 'class' => 'nowprocket' ) )['class'], 'nowprocket' );
confere( 'atributos não array passam', rastro_filtro_atributos_script( 'x' ), 'x' );
$antes = '<script id="rastro-a-js-before">var a;</script><script src="/a.js" id="rastro-a-js" class="b"></script>';
$depois = rastro_filtro_tag_script( $antes, 'rastro-a' );
confere( 'loader_tag: atributos em cada script do handle', substr_count( $depois, 'data-no-optimize="1"' ), 2 );
confere( 'loader_tag: classe acrescentada', false !== strpos( $depois, 'class="b nowprocket"' ), true );
confere( 'loader_tag: data-cfasync antes do src', false !== strpos( $depois, '<script data-no-optimize="1" data-no-defer="1" data-cfasync="false" src="/a.js"' ), true );
confere( 'loader_tag: não repete atributo', rastro_filtro_tag_script( $depois, 'rastro-a' ), $depois );
confere( 'loader_tag: outro handle intocado', rastro_filtro_tag_script( '<script src="/b.js"></script>', 'outro' ), '<script src="/b.js"></script>' );

// Registro de scripts.
confere( 'registrar: handle sem prefixo', rastro_registrar_script( 'consent', 'consent.js' ), false );
confere( 'registrar: arquivo com ..', rastro_registrar_script( 'rastro-x', '../x.js' ), false );
confere( 'registrar: arquivo ausente', rastro_registrar_script( 'rastro-ausente', 'nao-existe-teste.js' ), false );
$pasta_js     = RASTRO_DIR . 'assets/js/';
$pasta_criada = ! is_dir( $pasta_js ) && mkdir( $pasta_js, 0777, true );
$arquivo_js   = $pasta_js . 'teste-base-' . getmypid() . '.js';
file_put_contents( $arquivo_js, "window.rastro=window.rastro||{};\n" ); // phpcs:ignore
try {
	novo();
	$ok = rastro_registrar_script( 'rastro-teste', basename( $arquivo_js ), array( 'jquery', 3 ) );
	$s  = $GLOBALS['rastro_teste']['scripts']['rastro-teste'] ?? array();
	confere( 'registrar: ok', $ok, true );
	confere( 'registrar: endereço pelo RASTRO_URL', $s['src'] ?? null, RASTRO_URL . 'assets/js/' . basename( $arquivo_js ) );
	confere( 'registrar: versão pela data do arquivo', $s['ver'] ?? null, (string) filemtime( $arquivo_js ) );
	confere( 'registrar: rodapé com defer', $s['args'] ?? null, array( 'in_footer' => true, 'strategy' => 'defer' ) );
	confere( 'registrar: dependências só texto', $s['deps'] ?? null, array( 'jquery' ) );
	confere( 'registrar: guardado para os otimizadores', rastro_scripts_registrados()['rastro-teste'] ?? null, '/wp-content/plugins/rastro/assets/js/' . basename( $arquivo_js ) );
} finally {
	unlink( $arquivo_js );
	if ( $pasta_criada ) {
		rmdir( $pasta_js );
	}
}

// Exclusões dos otimizadores, só os arquivos do Rastro.
confere( 'padrões: pasta de scripts do Rastro', rastro_otimizadores_padroes(), array( '/wp-content/plugins/rastro/assets/js/' ) );
confere( 'LiteSpeed: acrescenta à lista', rastro_otimizador_litespeed( array( 'jquery.js' ) ), array( 'jquery.js', '/wp-content/plugins/rastro/assets/js/' ) );
confere( 'LiteSpeed: não repete', rastro_otimizador_litespeed( array( '/wp-content/plugins/rastro/assets/js/' ) ), array( '/wp-content/plugins/rastro/assets/js/' ) );
confere( 'LiteSpeed: texto vira lista', rastro_otimizador_litespeed( "a.js\nb.js" ), array( 'a.js', 'b.js', '/wp-content/plugins/rastro/assets/js/' ) );
confere( 'LiteSpeed: nulo vira lista', rastro_otimizador_litespeed( null ), array( '/wp-content/plugins/rastro/assets/js/' ) );
confere( 'WP Rocket: padrão como expressão regular', rastro_otimizador_rocket( array() ), array( '/wp\-content/plugins/rastro/assets/js/' ) );
confere( 'WP Rocket: padrão válido na expressão', 1 === preg_match( '#' . rastro_otimizador_rocket( array() )[0] . '#i', '<script src="https://x/wp-content/plugins/rastro/assets/js/tags.js">' ), true );
confere( 'Autoptimize: texto separado por vírgula', rastro_otimizador_autoptimize( 'jquery.js, a.js' ), 'jquery.js, a.js, /wp-content/plugins/rastro/assets/js/' );
confere( 'Autoptimize: texto vazio', rastro_otimizador_autoptimize( '' ), '/wp-content/plugins/rastro/assets/js/' );
confere( 'Autoptimize: array com os padrões nas chaves', rastro_otimizador_autoptimize( array( 'a.js' => '' ) ), array( 'a.js' => '', '/wp-content/plugins/rastro/assets/js/' => '' ) );
confere( 'Autoptimize: não repete', rastro_otimizador_autoptimize( '/wp-content/plugins/rastro/assets/js/' ), '/wp-content/plugins/rastro/assets/js/' );
// Sem os otimizadores instalados, nenhum filtro deles.
foreach ( array( 'litespeed_optm_js_defer_exc', 'litespeed_optimize_js_excludes', 'rocket_delay_js_exclusions', 'rocket_exclude_defer_js', 'rocket_exclude_js', 'autoptimize_filter_js_exclude' ) as $gancho ) {
	confere( "sem o otimizador, sem {$gancho}", has_filter( $gancho ), false );
}
define( 'LSCWP_V', '7.9.3' );
define( 'WP_ROCKET_VERSION', '3.24' );
define( 'AUTOPTIMIZE_PLUGIN_VERSION', '2.8.4' );
rastro_otimizadores_registrar();
foreach ( array( 'litespeed_optm_js_defer_exc', 'litespeed_optimize_js_excludes' ) as $gancho ) {
	confere( "LiteSpeed: {$gancho}", has_filter( $gancho, 'rastro_otimizador_litespeed' ), 10 );
}
foreach ( array( 'rocket_delay_js_exclusions', 'rocket_exclude_defer_js', 'rocket_exclude_js' ) as $gancho ) {
	confere( "WP Rocket: {$gancho}", has_filter( $gancho, 'rastro_otimizador_rocket' ), 10 );
}
confere( 'Autoptimize: autoptimize_filter_js_exclude', has_filter( 'autoptimize_filter_js_exclude', 'rastro_otimizador_autoptimize' ), 10 );

/* 8. Painel ---------------------------------------------------------------------------- */

$admin = array(
	'admin'       => true,
	'logado'      => true,
	'usuario_id'  => 7,
	'capacidades' => array( 'manage_options' => true, 'edit_posts' => true ),
);
novo( $admin );
$abas = rastro_admin_abas();
confere( 'abas do esquema em ordem', array_keys( $abas ), array( 'geral', 'eventos', 'woocommerce', 'consentimento', 'verificacao' ) );
confere( 'abas: ordens 10 a 40 e 45', array_column( $abas, 'ordem' ), array( 10, 20, 30, 40, 45 ) );
confere( 'abas: títulos', array_column( $abas, 'titulo' ), array( 'Geral', 'Eventos', 'WooCommerce', 'Consentimento', 'Verificações' ) );
confere( 'abas: só administrador', array_unique( array_column( $abas, 'capacidade' ) ), array( 'manage_options' ) );
add_filter(
	'rastro_admin_abas',
	static function ( $a ) {
		$a['relatorios'] = array( 'titulo' => 'Relatórios', 'capacidade' => 'manage_woocommerce', 'callback' => '__return_true', 'ordem' => 50 );
		$a['primeira']   = array( 'titulo' => 'Primeira', 'callback' => '__return_true', 'ordem' => 5 );
		$a['quebrada']   = array( 'titulo' => 'Sem callback', 'callback' => 'funcao_que_nao_existe' );
		$a['Ruim!']      = array( 'titulo' => 'Chave ruim', 'callback' => '__return_true' );
		return $a;
	}
);
$abas = rastro_admin_abas();
confere( 'abas do filtro: ordenadas e validadas', array_keys( $abas ), array( 'primeira', 'geral', 'eventos', 'woocommerce', 'consentimento', 'verificacao', 'relatorios' ) );
confere( 'aba sem capacidade: manage_options', $abas['primeira']['capacidade'], 'manage_options' );
confere( 'aba de relatórios fora da vista do administrador sem manage_woocommerce', array_key_exists( 'relatorios', rastro_admin_abas_visiveis() ), false );
novo( array( 'admin' => true, 'logado' => true, 'capacidades' => array( 'manage_woocommerce' => true ) ) );
confere( 'gerente da loja: menu pela aba que ele vê', rastro_admin_capacidade_menu(), 'manage_woocommerce' );
confere( 'gerente da loja: aba atual', rastro_admin_aba_atual(), 'relatorios' );
remove_all_filters( 'rastro_admin_abas' );
novo( array( 'admin' => true ) );
confere( 'sem capacidade: nenhuma aba', rastro_admin_aba_atual(), '' );
$parou = null;
try {
	rastro_admin_tela();
} catch ( Rastro_Teste_Parada $p ) {
	$parou = $p->status;
}
confere( 'sem capacidade: tela recusa (403)', $parou, 403 );

novo( $admin + array( 'get' => array( 'aba' => 'eventos' ) ) );
confere( 'aba pedida', rastro_admin_aba_atual(), 'eventos' );
novo( $admin + array( 'get' => array( 'aba' => '<script>' ) ) );
confere( 'aba inválida: a primeira', rastro_admin_aba_atual(), 'geral' );
confere( 'endereço da aba', rastro_admin_url( 'eventos', array( 'rastro_aviso' => 'salvo' ) ), 'https://loja.exemplo.com.br/wp-admin/admin.php?page=rastro&aba=eventos&rastro_aviso=salvo' );

novo( $admin );
rastro_admin_menu();
$menu = $GLOBALS['rastro_teste']['menus']['rastro'] ?? array();
confere( 'menu: título', $menu['menu'] ?? null, 'Rastro' );
confere( 'menu: ícone', $menu['icone'] ?? null, 'dashicons-chart-line' );
confere( 'menu: capacidade', $menu['capacidade'] ?? null, 'manage_options' );
confere( 'menu: tela', rastro_admin_tela_id(), 'toplevel_page_rastro' );
rastro_admin_estilo( 'edit.php' );
confere( 'estilo: fora da tela do Rastro, não', isset( $GLOBALS['rastro_teste']['estilos']['rastro-admin'] ), false );
rastro_admin_estilo( 'toplevel_page_rastro' );
confere( 'estilo: na tela do Rastro', $GLOBALS['rastro_teste']['estilos']['rastro-admin']['src'] ?? null, RASTRO_URL . 'assets/css/admin.css' );

// Tela da aba Geral.
novo( $admin + array( 'opcoes' => array( 'rastro_config' => array( 'ga4' => 'G-ABC1234', 'meta' => '1111222233334444' ) ) ) );
add_filter(
	'rastro_admin_avisos',
	static function ( $a ) {
		$a[] = array( 'tipo' => 'warning', 'texto' => 'Outro plugin <script>x</script> mede a loja.' );
		$a[] = array( 'tipo' => 'inventado', 'texto' => 'Tipo trocado para info.' );
		$a[] = array( 'texto' => '' );
		$a[] = 'solto';
		return $a;
	}
);
confere( 'avisos validados', rastro_admin_avisos(), array( array( 'tipo' => 'warning', 'texto' => 'Outro plugin <script>x</script> mede a loja.' ), array( 'tipo' => 'info', 'texto' => 'Tipo trocado para info.' ) ) );
$tela = rastro_teste_saida( 'rastro_admin_tela' );
remove_all_filters( 'rastro_admin_avisos' );
confere( 'tela: wrap e título', false !== strpos( $tela, '<div class="wrap rastro-painel"><h1>Rastro</h1>' ), true );
confere( 'tela: cinco abas', substr_count( $tela, '<a class="nav-tab' ), 5 );
confere( 'tela: aba atual marcada', false !== strpos( $tela, 'nav-tab nav-tab-active" href="https://loja.exemplo.com.br/wp-admin/admin.php?page=rastro&amp;aba=geral" aria-current="page"' ), true );
confere( 'tela: aviso do filtro escapado', false !== strpos( $tela, 'Outro plugin &lt;script&gt;x&lt;/script&gt; mede a loja.' ) && false === strpos( $tela, '<script>x' ), true );
confere( 'tela: avisos com role status', substr_count( $tela, 'role="status"' ), 2 );
confere( 'tela: situação', false !== strpos( $tela, '<h2 id="rastro-situacao-titulo">Situação</h2>' ), true );
confere( 'tela: destinos na situação', false !== strpos( $tela, '<dd>Google Analytics 4, Meta Pixel</dd>' ), true );
confere( 'tela: medição ativa', false !== strpos( $tela, 'Ativa para os visitantes' ), true );
// Sem o módulo de consentimento (T2) a situação diz "aguardando"; com ele, mostra o provedor.
$provedor_esperado = function_exists( 'rastro_consent_provedor' ) ? (string) rastro_consent_provedor() : 'aguardando o módulo de consentimento';
confere( 'tela: provedor na situação', false !== strpos( $tela, '<dd>' . $provedor_esperado ), true );
confere( 'tela: formulário para o admin-post', false !== strpos( $tela, '<form method="post" action="https://loja.exemplo.com.br/wp-admin/admin-post.php" class="rastro-form">' ), true );
confere( 'tela: ação rastro_salvar', false !== strpos( $tela, 'name="action" value="rastro_salvar"' ), true );
confere( 'tela: aba no formulário', false !== strpos( $tela, 'name="aba" value="geral"' ), true );
confere( 'tela: nonce', false !== strpos( $tela, 'name="_wpnonce" value="' . wp_create_nonce( 'rastro_salvar' ) . '"' ), true );
confere( 'tela: campos da aba Geral', preg_match_all( '/name="rastro\[([a-z0-9_]+)\]/', $tela, $nomes ) > 0 ? array_values( array_unique( $nomes[1] ) ) : array(), array( 'gtm', 'ga4', 'ads', 'ads_rotulo_compra', 'meta', 'meta_cabecalho', 'tiktok', 'openai', 'openai_debug', 'posthog', 'posthog_regiao', 'posthog_logs', 'posthog_logs_console', 'carregamento' ) );
confere( 'tela: IDs sem preenchimento automático', substr_count( $tela, 'autocomplete="off" spellcheck="false"' ), 8 );
confere( 'tela: valor guardado no campo', false !== strpos( $tela, 'name="rastro[ga4]" value="G-ABC1234"' ), true );
confere( 'tela: seleção marcada', false !== strpos( $tela, '<option value="ocioso" selected=\'selected\'>' ), true );
confere( 'tela: ajuda ligada ao campo', false !== strpos( $tela, 'aria-describedby="rastro-campo-gtm-ajuda"' ) && false !== strpos( $tela, 'id="rastro-campo-gtm-ajuda"' ), true );
confere( 'tela: sem travessão', false === strpos( $tela, "\u{2014}" ), true );
confere( 'tela: sem campos de outras abas', false === strpos( $tela, 'rastro[evento_purchase]' ), true );

novo( $admin + array( 'host' => 'loja.localhost', 'opcoes' => array( 'rastro_config' => array( 'ga4' => 'G-ABC1234' ) ) ) );
confere( 'situação: cópia local', 0 === strpos( array_values( rastro_admin_situacao() )[1], 'Parada: este site é uma cópia local' ), true );
novo( $admin );
$situacao = array_values( rastro_admin_situacao() );
confere( 'situação: sem destino', array_slice( $situacao, 0, 2 ), array( 'Nenhum', 'Desligada: nenhum destino configurado.' ) );
if ( ! function_exists( 'rastro_consent_provedor' ) ) {
	confere( 'situação: provedor aguardando', $situacao[2], 'aguardando o módulo de consentimento' );
}

// Abas Eventos, WooCommerce e Consentimento.
novo( $admin + array( 'get' => array( 'aba' => 'woocommerce' ) ) );
$tela = rastro_teste_saida( 'rastro_admin_tela' );
confere( 'WooCommerce: lista de status marcada', false !== strpos( $tela, 'name="rastro[status_relatorio][]" value="processing" checked=\'checked\'' ), true );
confere( 'WooCommerce: status não marcado', false !== strpos( $tela, 'name="rastro[status_relatorio][]" value="refunded">' ), true );
confere( 'WooCommerce: inteiro com limites', false !== strpos( $tela, 'name="rastro[atribuicao_dias]" value="90" min="1" max="365"' ), true );
confere( 'WooCommerce: sem situação', false === strpos( $tela, 'rastro-situacao' ), true );
novo( $admin + array( 'get' => array( 'aba' => 'eventos' ) ) );
$tela = rastro_teste_saida( 'rastro_admin_tela' );
confere( 'Eventos: dez caixas', substr_count( $tela, 'type="checkbox"' ), 10 );
confere( 'Eventos: ligadas por padrão', substr_count( $tela, "checked='checked'" ), 10 );
novo( $admin + array( 'get' => array( 'aba' => 'consentimento' ) ) );
$tela = rastro_teste_saida( 'rastro_admin_tela' );
confere( 'Consentimento: texto do aviso', false !== strpos( $tela, '<textarea class="large-text" rows="3" id="rastro-campo-aviso-texto" name="rastro[aviso_texto]" maxlength="400"' ), true );

// Avisos do PRG.
novo( $admin + array( 'get' => array( 'rastro_aviso' => 'salvo' ) ) );
confere( 'PRG: salvo', false !== strpos( rastro_teste_saida( 'rastro_admin_avisos_imprimir' ), '<div class="notice notice-success inline rastro-aviso" role="status"><p>Configurações salvas.</p></div>' ), true );
novo( $admin + array( 'get' => array( 'rastro_aviso' => 'erro' ) ) );
set_transient( rastro_admin_chave_erros(), array( 'gtm' => 'Mensagem <b>do</b> gtm.', 'inventada' => 'x', 'meta' => array( 'x' ) ), 300 );
$avisos = rastro_teste_saida( 'rastro_admin_avisos_imprimir' );
confere( 'PRG: chave do transient por usuário', rastro_admin_chave_erros(), 'rastro_admin_erros_7' );
confere( 'PRG: erro listado por campo e escapado', false !== strpos( $avisos, '<li>ID do contêiner do Google Tag Manager: Mensagem &lt;b&gt;do&lt;/b&gt; gtm.</li>' ), true );
confere( 'PRG: só erros de campos do esquema', substr_count( $avisos, '<li>' ), 1 );
confere( 'PRG: transient apagado depois de mostrar', get_transient( 'rastro_admin_erros_7' ), false );
novo( $admin + array( 'get' => array( 'rastro_aviso' => '<script>' ) ) );
confere( 'PRG: código desconhecido não mostra nada', rastro_teste_saida( 'rastro_admin_avisos_imprimir' ), '' );

// Salvar por aba.
$antes = array( 'gtm' => 'GTM-AAAA1111', 'aviso_dias' => 200, 'evento_search' => '0', 'eventos_loja' => '1' );
novo( $admin + array( 'opcoes' => array( 'rastro_config' => $antes ) ) );
$r = rastro_admin_salvar_dados( 'eventos', array( 'evento_purchase' => '1', 'gtm' => 'GTM-OUTRA999', 'evento_view_item' => 'sim' ) );
confere( 'salvar aba: booleano marcado', $r['config']['evento_purchase'], '1' );
confere( 'salvar aba: booleano desmarcado vira 0', $r['config']['eventos_loja'], '0' );
confere( 'salvar aba: valor estranho em booleano vira 0', $r['config']['evento_view_item'], '0' );
confere( 'salvar aba: campo de outra aba não muda', $r['config']['gtm'], 'GTM-AAAA1111' );
confere( 'salvar aba: campo de outra aba guardado fica', $r['config']['aviso_dias'], 200 );
confere( 'salvar aba: sem erro', $r['erros'], array() );
$r = rastro_admin_salvar_dados( 'woocommerce', array( 'valor_compra' => 'total', 'atribuicao_dias' => '400', 'status_relatorio' => array( 'completed', array( 'x' ) ) ) );
confere( 'salvar aba: seleção', $r['config']['valor_compra'], 'total' );
confere( 'salvar aba: inteiro inválido mantém', $r['config']['atribuicao_dias'], 90 );
confere( 'salvar aba: erro do inteiro', array_keys( $r['erros'] ), array( 'atribuicao_dias' ) );
confere( 'salvar aba: lista', $r['config']['status_relatorio'], array( 'completed' ) );
confere( 'salvar aba: seleção ausente volta ao padrão', $r['config']['id_produto'], 'id' );
$r = rastro_admin_salvar_dados( 'woocommerce', array() );
confere( 'salvar aba: lista sem nenhum marcado volta ao padrão', $r['config']['status_relatorio'], array( 'processing', 'completed' ) );
$r = rastro_admin_salvar_dados( 'verificacao', array( 'google_verificacao' => '<meta name="google-site-verification" content="AbCdEfGhIjKlMnOpQrStUv-_123" />', 'facebook_verificacao' => '<meta name="facebook-domain-verification" content="abc123def456ghi789jkl012mno345" />', 'gtm' => 'GTM-ZZZZ9999' ) );
confere( 'salvar aba: tag de verificação colada vira o valor', $r['config']['google_verificacao'], 'AbCdEfGhIjKlMnOpQrStUv-_123' );
confere( 'salvar aba: verificação da Meta colada vira o valor', $r['config']['facebook_verificacao'], 'abc123def456ghi789jkl012mno345' );
confere( 'salvar aba: campo de outra aba não grava', $r['config']['gtm'] !== 'GTM-ZZZZ9999', true );
$r = rastro_admin_salvar_dados( 'geral', array( 'gtm' => 'gtm-bbbb2222' ) );
confere( 'salvar aba: maiúsculas', $r['config']['gtm'], 'GTM-BBBB2222' );
confere( 'salvar aba: ID ausente no envio vira vazio', $r['config']['meta'], '' );
confere( 'salvar aba: aba que não é do esquema', rastro_admin_salvar_dados( 'relatorios', array( 'gtm' => 'GTM-CCCC3333' ) ), null );
confere( 'salvar aba: aba inválida não grava', rastro_config_guardada()['gtm'], 'GTM-BBBB2222' );
confere( 'admin-post ligado', has_action( 'admin_post_rastro_salvar', 'rastro_admin_salvar' ), 10 );

// Capacidade antes do nonce, nonce antes de gravar.
novo( array( 'admin' => true, 'logado' => true, 'post' => array( 'aba' => 'geral', 'rastro' => array( 'gtm' => 'GTM-DDDD4444' ) ) ) );
$parou = null;
try {
	rastro_admin_salvar();
} catch ( Rastro_Teste_Parada $p ) {
	$parou = $p->getMessage();
}
confere( 'salvar sem capacidade: recusa', $parou, 'Sem permissão.' );
confere( 'salvar sem capacidade: nada gravado', isset( $GLOBALS['rastro_teste']['opcoes']['rastro_config'] ), false );
novo( $admin + array( 'nonce_ok' => false, 'post' => array( 'aba' => 'geral', 'rastro' => array( 'gtm' => 'GTM-DDDD4444' ) ) ) );
$parou = null;
try {
	rastro_admin_salvar();
} catch ( Rastro_Teste_Parada $p ) {
	$parou = $p->getMessage();
}
confere( 'salvar sem nonce: recusa', $parou, 'nonce' );
confere( 'salvar sem nonce: nada gravado', isset( $GLOBALS['rastro_teste']['opcoes']['rastro_config'] ), false );

confere( 'nenhuma falha registrada nos módulos', $falhas_registradas, array() );

echo "{$total} conferências da base do Rastro, {$falhas} falha(s).\n";
exit( $falhas > 0 ? 1 : 0 );
