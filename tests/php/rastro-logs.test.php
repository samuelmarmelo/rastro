<?php
/**
 * Testes dos logs do Rastro (rastro/logs/logs.php), sem WordPress: limpeza
 * de dado pessoal, corpo OTLP, chave `logs` do navegador, envio só com chave
 * e campo ligados, cópia local sem envio, cota por requisição e falha
 * isolada (`rastro_falha`) virando log.
 *
 * Uso: php tests/php/rastro-logs.test.php (sai com 1 se algo falhar).
 * Manual: docs/LOGS.md. Chave fictícia, só com o formato do PostHog.
 *
 * @package Rastro
 */

define( 'ABSPATH', '/srv/www/loja/' );

$rastro_dir = realpath( __DIR__ . '/../../rastro' );

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

// Envio falso: guarda os pedidos.
$GLOBALS['rastro_envios'] = array();
function wp_safe_remote_post( $url, $args = array() ) { // phpcs:ignore
	$GLOBALS['rastro_envios'][] = array( $url, $args );
	return array();
}

const CHAVE = 'phc_ABCDEFGHIJKLMNOPQRSTUVWXYZ0123';

function com_config( array $config, array $sobre = array() ) { // phpcs:ignore
	rastro_teste_reset( array_replace( array( 'opcoes' => array( 'rastro_config' => $config ) ), $sobre ) );
	rastro_config_redefinir_cache();
	rastro_regras_redefinir_cache();
	rastro_logs_cota( '', true );
	$GLOBALS['rastro_envios'] = array();
}

rastro_teste_reset();
require $rastro_dir . '/rastro.php';

/* 0. Carga ------------------------------------------------------------------ */

confere( 'módulo de logs carregado', function_exists( 'rastro_log' ), true );
confere( 'rastro_falha vira log', has_action( 'rastro_falha', 'rastro_logs_falha' ), 10 );
confere( 'campos no esquema', array_key_exists( 'posthog_logs', rastro_config_esquema() ) && array_key_exists( 'posthog_logs_console', rastro_config_esquema() ), true );
confere( 'padrão: logs ligados', rastro_config_padrao()['posthog_logs'], '1' );
confere( 'padrão: console desligado', rastro_config_padrao()['posthog_logs_console'], '0' );

/* 1. Regras puras ------------------------------------------------------------- */

confere( 'e-mail mascarado', rastro_logs_limpar_texto( 'falhou para ana.souza@exemplo.com.br' ), 'falhou para [e-mail]' );
confere( 'e-mail com %40', rastro_logs_limpar_texto( 'ana%40exemplo.com' ), '[e-mail]' );
confere( 'CPF mascarado', rastro_logs_limpar_texto( 'cpf 123.456.789-09' ), 'cpf [cpf]' );
confere( 'telefone mascarado', rastro_logs_limpar_texto( 'tel (21) 99876-5432' ), 'tel [telefone]' );
confere( 'pedido mascarado', rastro_logs_limpar_texto( 'pedido 4521 falhou' ), 'pedido [número] falhou' );
confere( 'caminho com número', rastro_logs_limpar_texto( '/minha-conta/view-order/4521/' ), '/minha-conta/view-order/:id/' );
confere( 'número longo', rastro_logs_limpar_texto( 'token 1234567890123' ), 'token [número]' );
confere( 'corta o tamanho', strlen( rastro_logs_limpar_texto( str_repeat( 'a', 3000 ) ) ), 2000 );
confere( 'sem a pasta do servidor', rastro_logs_sem_raiz( 'em /srv/www/loja/wp-content/plugins/x.php', '/srv/www/loja/' ), 'em wp-content/plugins/x.php' );
confere( 'sem a pasta do Windows', rastro_logs_sem_raiz( 'em C:\\sites\\loja\\wp-content\\x.php', 'C:/sites/loja' ), 'em wp-content\\x.php' );
confere( 'nível conhecido', rastro_logs_nivel( 'WARN' ), 'warn' );
confere( 'nível desconhecido vira info', rastro_logs_nivel( 'fatal' ), 'info' );
confere( 'ambiente só letras', rastro_logs_ambiente( 'staging' ), 'staging' );
confere( 'ambiente estranho vira production', rastro_logs_ambiente( 'prod<script>' ), 'production' );

$corpo = rastro_logs_corpo( 'error', 'Falha X', array( 'modulo' => 'tags', 'n' => 3, 'ok' => true, 'lista' => array( 1 ), 'chave ruim' => 'x' ), array( 'service.name' => 'rastro' ), '1700000000000000000' );
$registro = $corpo['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0];
confere( 'OTLP: nível numérico', $registro['severityNumber'], 17 );
confere( 'OTLP: texto do nível', $registro['severityText'], 'ERROR' );
confere( 'OTLP: corpo', $registro['body'], array( 'stringValue' => 'Falha X' ) );
confere( 'OTLP: hora', $registro['timeUnixNano'], '1700000000000000000' );
confere(
	'OTLP: atributos só simples e com chave válida',
	$registro['attributes'],
	array(
		array( 'key' => 'modulo', 'value' => array( 'stringValue' => 'tags' ) ),
		array( 'key' => 'n', 'value' => array( 'intValue' => '3' ) ),
		array( 'key' => 'ok', 'value' => array( 'boolValue' => true ) ),
	)
);
confere( 'OTLP: service.name no recurso', $corpo['resourceLogs'][0]['resource']['attributes'][0], array( 'key' => 'service.name', 'value' => array( 'stringValue' => 'rastro' ) ) );

confere( 'navegador: sem campo, sem logs', rastro_logs_navegador( array(), 'production', '1.0.0' ), array() );
confere(
	'navegador: logs ligados',
	rastro_logs_navegador( array( 'posthog_logs' => '1', 'posthog_logs_console' => '1' ), 'staging', '1.2.3' ),
	array(
		'serviceName'        => 'rastro',
		'environment'        => 'staging',
		'serviceVersion'     => '1.2.3',
		'captureConsoleLogs' => true,
	)
);
confere( 'navegador: versão estranha', rastro_logs_navegador( array( 'posthog_logs' => '1' ), 'x1', 'v1' )['serviceVersion'], '0.0.0' );

/* 2. Envio do servidor ----------------------------------------------------------- */

com_config( array() );
confere( 'sem chave: não envia', rastro_log( 'error', 'x' ), false );
confere( 'sem chave: nenhum pedido', count( $GLOBALS['rastro_envios'] ), 0 );

com_config( array( 'posthog' => CHAVE, 'posthog_logs' => '0' ) );
confere( 'campo desligado: não envia', rastro_log( 'error', 'x' ), false );

com_config( array( 'posthog' => CHAVE ), array( 'host' => 'loja.test' ) );
confere( 'cópia local: não envia', rastro_log( 'error', 'x' ), false );

com_config( array( 'posthog' => CHAVE, 'posthog_regiao' => 'eu' ) );
confere( 'envia com chave', rastro_log( 'warn', 'Frete sem resposta para ana@exemplo.com', array( 'segundos' => 8 ) ), true );
$envio = $GLOBALS['rastro_envios'][0];
confere( 'endereço da região eu', $envio[0], 'https://eu.i.posthog.com/i/v1/logs' );
confere( 'chave no Authorization', $envio[1]['headers']['Authorization'], 'Bearer ' . CHAVE );
confere( 'não bloqueante', $envio[1]['blocking'], false );
confere( 'sem seguir redirecionamento', $envio[1]['redirection'], 0 );
$json = json_decode( $envio[1]['body'], true );
confere( 'corpo sem e-mail', $json['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0]['body']['stringValue'], 'Frete sem resposta para [e-mail]' );
confere( 'chave fora do corpo', false === strpos( $envio[1]['body'], CHAVE ), true );
confere( 'repetido vai uma vez', rastro_log( 'warn', 'Frete sem resposta para ana@exemplo.com' ), false );
for ( $i = 0; $i < 20; $i++ ) {
	rastro_log( 'info', 'log ' . $i );
}
confere( 'cota de 10 por requisição', count( $GLOBALS['rastro_envios'] ), 10 );

com_config( array( 'posthog' => CHAVE ) );
add_filter( 'rastro_logs_ativo', '__return_false' );
confere( 'filtro rastro_logs_ativo desliga', rastro_log( 'error', 'x' ), false );
remove_all_filters( 'rastro_logs_ativo' );

com_config( array( 'posthog' => CHAVE ) );
do_action( 'rastro_falha', 'tags-script', new RuntimeException( 'quebrou em /srv/www/loja/wp-content/x.php' ) );
$envio = $GLOBALS['rastro_envios'][0];
$json  = json_decode( $envio[1]['body'], true );
$reg   = $json['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0];
confere( 'falha isolada: nível error', $reg['severityText'], 'ERROR' );
confere( 'falha isolada: texto sem a pasta', $reg['body']['stringValue'], 'Falha isolada em tags-script: RuntimeException quebrou em wp-content/x.php' );
confere( 'falha isolada: endereço us', $envio[0], 'https://us.i.posthog.com/i/v1/logs' );

com_config( array( 'posthog' => CHAVE ) );
rastro_logs_falha( "módulo\n<script>", 'não é erro' );
$json = json_decode( $GLOBALS['rastro_envios'][0][1]['body'], true );
confere( 'falha com módulo estranho', $json['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0]['attributes'][0]['value']['stringValue'], 'desconhecido' );

fwrite( STDOUT, "{$total} conferências dos logs do Rastro, {$falhas} falha(s).\n" );
exit( $falhas ? 1 : 0 );
