<?php
/**
 * Testes do arquivo de verificação do Rastro (rastro/verificacao/arquivo.php),
 * sem WordPress: regra do caminho, nome e conteúdo validados, resposta em
 * texto puro só no GET e no HEAD do endereço certo, nada no painel.
 *
 * Uso: php tests/php/rastro-verificacao.test.php (sai com 1 se algo falhar).
 * Manual: docs/TAGS.md, seção Verificações de domínio.
 *
 * @package Rastro
 */

define( 'ABSPATH', __DIR__ . '/' );

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

function status_header( $codigo ) { // phpcs:ignore
	$GLOBALS['rastro_status'] = $codigo;
}

const NOME     = 'tiktokAbC123xyz.txt';
const CONTEUDO = 'tiktok-developers-site-verification=AbC123xyz';

/** Prepara a requisição e devolve a saída (null quando não respondeu). */
function pedir( array $config, string $uri, string $metodo = 'GET', array $sobre = array() ) { // phpcs:ignore
	rastro_teste_reset( array_replace( array( 'opcoes' => array( 'rastro_config' => $config ), 'uri' => $uri ), $sobre ) );
	rastro_config_redefinir_cache();
	rastro_regras_redefinir_cache();
	$_SERVER['REQUEST_URI']    = $uri;
	$_SERVER['REQUEST_METHOD'] = $metodo;
	$GLOBALS['rastro_status']  = null;
	ob_start();
	try {
		do_action( 'parse_request' );
		$parou = false;
	} catch ( Rastro_Teste_Parada $p ) {
		$parou = true;
	}
	$saida = (string) ob_get_clean();

	return $parou ? $saida : null;
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

rastro_teste_reset();
require $rastro_dir . '/rastro.php';
add_action(
	'rastro_verificacao_enviado',
	static function () {
		throw new Rastro_Teste_Parada( 'enviado' );
	}
);

confere( 'módulo carregado', function_exists( 'rastro_verificacao_responder' ), true );
confere( 'no parse_request, prioridade 0', has_action( 'parse_request', 'rastro_verificacao_responder' ), 0 );

/* Regra pura do caminho */
confere( 'raiz', rastro_verificacao_arquivo_regra( '/' . NOME, '/', NOME ), true );
confere( 'com consulta', rastro_verificacao_arquivo_regra( '/' . NOME . '?x=1', '/', NOME ), true );
confere( 'subpasta do site', rastro_verificacao_arquivo_regra( '/loja/' . NOME, '/loja/', NOME ), true );
confere( 'subpasta errada', rastro_verificacao_arquivo_regra( '/outra/' . NOME, '/loja/', NOME ), false );
confere( 'outro nome', rastro_verificacao_arquivo_regra( '/robots.txt', '/', NOME ), false );
confere( 'nome vazio desliga', rastro_verificacao_arquivo_regra( '/', '/', '' ), false );

/* Resposta */
$cheia = array(
	'arquivo_verificacao_nome'     => NOME,
	'arquivo_verificacao_conteudo' => CONTEUDO,
);
confere( 'GET do arquivo: conteúdo', pedir( $cheia, '/' . NOME ), CONTEUDO );
confere( 'GET do arquivo: 200', $GLOBALS['rastro_status'], 200 );
confere( 'HEAD do arquivo: sem corpo', pedir( $cheia, '/' . NOME, 'HEAD' ), '' );
confere( 'POST não responde', pedir( $cheia, '/' . NOME, 'POST' ), null );
confere( 'outro endereço não responde', pedir( $cheia, '/produto/racao/' ), null );
confere( 'painel não responde', pedir( $cheia, '/' . NOME, 'GET', array( 'admin' => true ) ), null );
confere( 'sem conteúdo não responde', pedir( array( 'arquivo_verificacao_nome' => NOME ), '/' . NOME ), null );
confere( 'sem nome não responde', pedir( array( 'arquivo_verificacao_conteudo' => CONTEUDO ), '/' . NOME ), null );

/* Validação */
$v = static function ( $chave, $valor ) {
	return rastro_config_validar_campo( $chave, $valor );
};
confere( 'nome sem .txt recusado', $v( 'arquivo_verificacao_nome', 'arquivo.html' ), null );
confere( 'nome com barra recusado', $v( 'arquivo_verificacao_nome', '../wp-config.txt' ), null );
confere( 'nome com ponto extra recusado', $v( 'arquivo_verificacao_nome', '.htaccess.txt' ), null );
confere( 'nome válido', $v( 'arquivo_verificacao_nome', NOME ), NOME );
confere( 'conteúdo com HTML recusado', $v( 'arquivo_verificacao_conteudo', '<script>alert(1)</script>' ), null );
confere( 'conteúdo com espaço recusado', $v( 'arquivo_verificacao_conteudo', 'a b' ), null );
confere( 'conteúdo válido', $v( 'arquivo_verificacao_conteudo', CONTEUDO ), CONTEUDO );
confere( 'conteúdo inválido guardado não responde', pedir( array( 'arquivo_verificacao_nome' => NOME, 'arquivo_verificacao_conteudo' => '<b>x</b>' ), '/' . NOME ), null );

confere( 'nenhuma falha registrada', $falhas_registradas, array() );

echo "{$total} conferências do arquivo de verificação do Rastro, {$falhas} falha(s).\n";
exit( $falhas > 0 ? 1 : 0 );
