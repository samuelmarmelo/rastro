<?php
/**
 * Testes do atualizador do Rastro (rastro/atualizacao/atualizador.php), sem
 * WordPress, sem banco e sem rede: as respostas do servidor de atualizações
 * são simuladas e a assinatura usa uma chave gerada aqui.
 *
 * Contrato: docs/ATUALIZADOR.md e docs/RELEASE.md.
 *
 * @package Rastro
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'RASTRO_VERSAO', '1.0.0' );
define( 'RASTRO_ARQUIVO', '/var/www/wp-content/plugins/rastro/rastro.php' );

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

/* Stubs extras ------------------------------------------------------------------ */

class WP_Error { // phpcs:ignore
	public $codigo;
	public $mensagem;
	public function __construct( $codigo = '', $mensagem = '' ) {
		$this->codigo   = $codigo;
		$this->mensagem = $mensagem;
	}
	public function get_error_code() {
		return $this->codigo;
	}
	public function get_error_message() {
		return $this->mensagem;
	}
}
function is_wp_error( $x ) { // phpcs:ignore
	return $x instanceof WP_Error;
}

$GLOBALS['t_basename'] = 'rastro/rastro.php';
function plugin_basename( $arquivo ) { // phpcs:ignore
	return $GLOBALS['t_basename'];
}

$GLOBALS['t_site'] = array();
function get_site_transient( $k ) { // phpcs:ignore
	return isset( $GLOBALS['t_site'][ $k ] ) ? $GLOBALS['t_site'][ $k ] : false;
}
function set_site_transient( $k, $v, $t = 0 ) { // phpcs:ignore
	$GLOBALS['t_site'][ $k ]     = $v;
	$GLOBALS['t_site_ttl'][ $k ] = $t;
	return true;
}
function delete_site_transient( $k ) { // phpcs:ignore
	unset( $GLOBALS['t_site'][ $k ] );
	return true;
}

// Rede simulada: uma resposta por endereço; cada pedido fica anotado.
$GLOBALS['t_rede']    = array();
$GLOBALS['t_pedidos'] = array();
function wp_safe_remote_get( $url, $args = array() ) { // phpcs:ignore
	$GLOBALS['t_pedidos'][] = array( $url, $args );
	if ( ! isset( $GLOBALS['t_rede'][ $url ] ) ) {
		return new WP_Error( 'http_request_failed', 'sem rede' );
	}
	$r = $GLOBALS['t_rede'][ $url ];
	if ( ! empty( $args['stream'] ) && isset( $args['filename'] ) ) {
		$corpo = isset( $r['body'] ) ? $r['body'] : '';
		if ( isset( $args['limit_response_size'] ) ) {
			$corpo = substr( $corpo, 0, (int) $args['limit_response_size'] );
		}
		file_put_contents( $args['filename'], $corpo );
		$r['body'] = '';
	}
	return $r;
}
function wp_remote_retrieve_response_code( $r ) { // phpcs:ignore
	return is_array( $r ) && isset( $r['response']['code'] ) ? $r['response']['code'] : '';
}
function wp_remote_retrieve_body( $r ) { // phpcs:ignore
	return is_array( $r ) && isset( $r['body'] ) ? $r['body'] : '';
}
function wp_remote_retrieve_header( $r, $nome ) { // phpcs:ignore
	return is_array( $r ) && isset( $r['headers'][ $nome ] ) ? $r['headers'][ $nome ] : '';
}
$GLOBALS['t_tmp'] = sys_get_temp_dir() . '/rastro-atualizador-' . getmypid() . '-' . mt_rand();
mkdir( $GLOBALS['t_tmp'] );
function wp_tempnam( $prefixo = '' ) { // phpcs:ignore
	return tempnam( $GLOBALS['t_tmp'], 'r' );
}
$GLOBALS['t_apagados'] = array();
function wp_delete_file( $arquivo ) { // phpcs:ignore
	$GLOBALS['t_apagados'][] = $arquivo;
	if ( is_file( $arquivo ) ) {
		unlink( $arquivo );
	}
}
function get_file_data( $arquivo, $campos ) { // phpcs:ignore
	$texto = (string) file_get_contents( $arquivo );
	$saida = array();
	foreach ( $campos as $chave => $nome ) {
		$saida[ $chave ] = preg_match( '/^[ \t\/*#@]*' . preg_quote( $nome, '/' ) . ':(.*)$/mi', $texto, $m ) ? trim( $m[1] ) : '';
	}
	return $saida;
}
class Teste_FS { // phpcs:ignore
	public function move( $de, $para, $sobrescrever = false ) {
		return rename( rtrim( $de, '/' ), rtrim( $para, '/' ) );
	}
}
$GLOBALS['wp_filesystem'] = new Teste_FS();

// Chave de teste no lugar das chaves reais.
$par            = sodium_crypto_sign_keypair();
$GLOBALS['t_k'] = array(
	'secreta' => sodium_crypto_sign_secretkey( $par ),
	'publica' => bin2hex( sodium_crypto_sign_publickey( $par ) ),
);
function rastro_atualizador_chaves(): array { // phpcs:ignore
	return array( $GLOBALS['t_k']['publica'] );
}

require __DIR__ . '/../../rastro/atualizacao/chaves.php';
require __DIR__ . '/../../rastro/atualizacao/atualizador.php';

/* Fixtures ------------------------------------------------------------------------ */

const T_DL = 'https://github.com/samuelmarmelo/rastro/releases/download/';

function t_release( string $versao = '1.1.0', array $troca = array() ): array { // phpcs:ignore
	$tag  = 'v' . $versao;
	$zip  = 'rastro-' . $tag . '.zip';
	$base = array(
		'tag_name'     => $tag,
		'draft'        => false,
		'prerelease'   => false,
		'published_at' => '2026-11-16T12:00:00Z',
		'body'         => "Notas <script>alert(1)</script>\n- item",
		'assets'       => array(
			array(
				'name'                 => $zip,
				'size'                 => 4000,
				'state'                => 'uploaded',
				'browser_download_url' => T_DL . $tag . '/' . $zip,
			),
			array(
				'name'                 => $zip . '.sha256',
				'size'                 => 84,
				'state'                => 'uploaded',
				'browser_download_url' => T_DL . $tag . '/' . $zip . '.sha256',
			),
			array(
				'name'                 => $zip . '.sig',
				'size'                 => 129,
				'state'                => 'uploaded',
				'browser_download_url' => T_DL . $tag . '/' . $zip . '.sig',
			),
		),
	);
	return array_replace( $base, $troca );
}

/** Publica na rede simulada a versão com o zip, o .sha256 e o .sig. */
function t_publicar( string $versao, string $zip, array $opcoes = array() ) { // phpcs:ignore
	$tag  = 'v' . $versao;
	$nome = 'rastro-' . $tag . '.zip';
	$hex  = hash( 'sha256', $zip );
	$msg  = rastro_atualizador_mensagem( $tag, $nome, isset( $opcoes['hex_assinado'] ) ? $opcoes['hex_assinado'] : $hex );
	$sig  = bin2hex( sodium_crypto_sign_detached( isset( $opcoes['msg'] ) ? $opcoes['msg'] : $msg, isset( $opcoes['secreta'] ) ? $opcoes['secreta'] : $GLOBALS['t_k']['secreta'] ) ) . "\n";
	$sha  = ( isset( $opcoes['hex_publicado'] ) ? $opcoes['hex_publicado'] : $hex ) . '  ' . $nome . "\n";
	$json = t_release( $versao );

	$json['assets'][0]['size'] = isset( $opcoes['tamanho'] ) ? $opcoes['tamanho'] : strlen( $zip );
	$json['assets'][1]['size'] = strlen( $sha );
	$json['assets'][2]['size'] = strlen( $sig );
	if ( isset( $opcoes['digest'] ) ) {
		$json['assets'][0]['digest'] = $opcoes['digest'];
	}
	$GLOBALS['t_rede'] = array(
		'https://api.github.com/repos/samuelmarmelo/rastro/releases/latest' => array(
			'response' => array( 'code' => 200 ),
			'body'     => json_encode( $json ),
		),
	);
	foreach ( array( $nome => $zip, $nome . '.sha256' => $sha, $nome . '.sig' => $sig ) as $arquivo => $corpo ) {
		$assinado = 'https://release-assets.githubusercontent.com/x/' . rawurlencode( $arquivo ) . '?sig=abc';

		$GLOBALS['t_rede'][ T_DL . $tag . '/' . $arquivo ] = array(
			'response' => array( 'code' => 302 ),
			'headers'  => array( 'location' => isset( $opcoes['destino'] ) ? $opcoes['destino'] : $assinado ),
			'body'     => '',
		);
		$GLOBALS['t_rede'][ $assinado ] = array(
			'response' => array( 'code' => 200 ),
			'body'     => $corpo,
		);
	}
	$GLOBALS['t_site']    = array();
	$GLOBALS['t_pedidos'] = array();
}

function codigo_de( $x ) { // phpcs:ignore
	return is_wp_error( $x ) ? $x->get_error_code() : '';
}

/* 1. Ganchos só no plugin próprio --------------------------------------------------- */

confere( 'plugin próprio', rastro_atualizador_plugin_proprio(), true );
foreach ( array( 'update_plugins_github.com', 'upgrader_pre_download', 'upgrader_source_selection', 'auto_update_plugin', 'plugins_api' ) as $gancho ) {
	confere( "gancho {$gancho}", false !== has_filter( $gancho ), true );
}
$GLOBALS['t_basename'] = 'meu-plugin/includes/rastro/rastro.php';
confere( 'cópia embutida não é plugin próprio', rastro_atualizador_plugin_proprio(), false );
confere( 'cópia embutida: sem oferta', rastro_atualizador_oferta( false, array(), 'rastro/rastro.php' ), false );
$GLOBALS['t_basename'] = 'rastro/rastro.php';

/* 2. Regras puras -------------------------------------------------------------------- */

foreach ( array( '1.0.0', '0.1.0', '10.20.30', '9999.0.1' ) as $v ) {
	confere( "versão válida {$v}", rastro_atualizador_versao_valida( $v ), true );
}
foreach ( array( '1.0', '01.0.0', '1.0.0-beta', "1.0.0\n", ' 1.0.0', '10000.0.0', 1, null ) as $v ) {
	confere( 'versão inválida ' . var_export( $v, true ), rastro_atualizador_versao_valida( $v ), false );
}
confere( 'mensagem assinada', rastro_atualizador_mensagem( 'v1.2.3', 'rastro-v1.2.3.zip', str_repeat( 'a', 64 ) ), "rastro-release-v1\ntag: v1.2.3\narquivo: rastro-v1.2.3.zip\nsha256: " . str_repeat( 'a', 64 ) . "\n" );

$r = rastro_atualizador_regra_release( t_release() );
confere( 'release válida', array( $r['ok'], $r['versao'], $r['tag'], $r['zip']['tamanho'], $r['data'] ), array( true, '1.1.0', 'v1.1.0', 4000, '2026-11-16T12:00:00Z' ) );
confere( 'release: resposta inválida', rastro_atualizador_regra_release( 'x' )['erro'], 'resposta' );
confere( 'release: rascunho', rastro_atualizador_regra_release( t_release( '1.1.0', array( 'draft' => true ) ) )['erro'], 'rascunho' );
confere( 'release: pré-versão', rastro_atualizador_regra_release( t_release( '1.1.0', array( 'prerelease' => true ) ) )['erro'], 'rascunho' );
confere( 'release: tag sem v', rastro_atualizador_regra_release( t_release( '1.1.0', array( 'tag_name' => '1.1.0' ) ) )['erro'], 'tag' );
confere( 'release: tag inválida', rastro_atualizador_regra_release( t_release( '1.1.0', array( 'tag_name' => 'v1.1.0-rc1' ) ) )['erro'], 'tag' );

$x = t_release();
$x['assets'][0]['browser_download_url'] = 'https://evil.example/rastro-v1.1.0.zip';
confere( 'release: endereço de outro host', rastro_atualizador_regra_release( $x )['erro'], 'endereco' );
$x = t_release();
$x['assets'][0]['browser_download_url'] = T_DL . 'v1.0.0/rastro-v1.1.0.zip';
confere( 'release: endereço de outra tag', rastro_atualizador_regra_release( $x )['erro'], 'endereco' );
$x = t_release();
$x['assets'][0]['size'] = 5242881;
confere( 'release: zip acima do limite', rastro_atualizador_regra_release( $x )['erro'], 'tamanho' );
$x = t_release();
$x['assets'][2]['size'] = 2000;
confere( 'release: .sig acima do limite', rastro_atualizador_regra_release( $x )['erro'], 'tamanho' );
$x = t_release();
$x['assets'][1]['state'] = 'starter';
confere( 'release: arquivo não enviado', rastro_atualizador_regra_release( $x )['erro'], 'estado' );
$x = t_release();
unset( $x['assets'][2] );
confere( 'release: sem .sig', rastro_atualizador_regra_release( $x )['erro'], 'arquivos' );
$x = t_release();
$x['assets'][] = $x['assets'][0];
confere( 'release: zip duplicado', rastro_atualizador_regra_release( $x )['erro'], 'duplicado' );
$x = t_release();
$x['assets'][0]['digest'] = 'sha256:' . str_repeat( 'b', 64 );
confere( 'release: digest da API lido', rastro_atualizador_regra_release( $x )['zip']['digest'], str_repeat( 'b', 64 ) );
$x = t_release();
$x['assets'][0]['digest'] = 'md5:abc';
confere( 'release: digest estranho ignorado', rastro_atualizador_regra_release( $x )['zip']['digest'], '' );

foreach ( array( 'https://release-assets.githubusercontent.com/a?b=c', 'https://objects.githubusercontent.com/x', 'https://OBJECTS.githubusercontent.com:443/x' ) as $u ) {
	confere( "destino aceito {$u}", rastro_atualizador_regra_destino( $u ), true );
}
foreach ( array( 'http://objects.githubusercontent.com/x', 'https://githubusercontent.com/x', 'https://objects.githubusercontent.com.evil.com/x', 'https://a.b.githubusercontent.com/x', 'https://user:pw@objects.githubusercontent.com/x', 'https://objects.githubusercontent.com:8443/x', 'https://evil.com/?h=objects.githubusercontent.com', "https://objects.githubusercontent.com/x\n", '', array( 'x' ) ) as $u ) {
	confere( 'destino recusado ' . var_export( $u, true ), rastro_atualizador_regra_destino( $u ), false );
}

$h = str_repeat( 'c', 64 );
confere( 'sha256 lido', rastro_atualizador_regra_sha256( "{$h}  rastro-v1.1.0.zip\n", 'rastro-v1.1.0.zip' ), $h );
confere( 'sha256 sem quebra final', rastro_atualizador_regra_sha256( "{$h}  rastro-v1.1.0.zip", 'rastro-v1.1.0.zip' ), $h );
confere( 'sha256 de outro arquivo', rastro_atualizador_regra_sha256( "{$h}  rastro-v1.0.0.zip\n", 'rastro-v1.1.0.zip' ), '' );
confere( 'sha256 maiúsculo', rastro_atualizador_regra_sha256( strtoupper( $h ) . "  rastro-v1.1.0.zip\n", 'rastro-v1.1.0.zip' ), '' );
confere( 'sha256 com duas linhas', rastro_atualizador_regra_sha256( "{$h}  rastro-v1.1.0.zip\n{$h}  x\n", 'rastro-v1.1.0.zip' ), '' );

$msg = rastro_atualizador_mensagem( 'v1.1.0', 'rastro-v1.1.0.zip', $h );
$sig = bin2hex( sodium_crypto_sign_detached( $msg, $GLOBALS['t_k']['secreta'] ) );
confere( 'assinatura válida', rastro_atualizador_assinatura_valida( $msg, $sig . "\n", rastro_atualizador_chaves() ), true );
confere( 'assinatura de outra mensagem', rastro_atualizador_assinatura_valida( str_replace( 'v1.1.0', 'v1.2.0', $msg ), $sig, rastro_atualizador_chaves() ), false );
confere( 'assinatura com outra chave', rastro_atualizador_assinatura_valida( $msg, $sig, array( bin2hex( sodium_crypto_sign_publickey( sodium_crypto_sign_keypair() ) ) ) ), false );
confere( 'assinatura sem chave', rastro_atualizador_assinatura_valida( $msg, $sig, array() ), false );
confere( 'assinatura mal formada', rastro_atualizador_assinatura_valida( $msg, substr( $sig, 2 ), rastro_atualizador_chaves() ), false );
confere( 'assinatura maiúscula recusada', rastro_atualizador_assinatura_valida( $msg, strtoupper( $sig ), rastro_atualizador_chaves() ), false );
confere( 'chave mal formada é pulada', rastro_atualizador_assinatura_valida( $msg, $sig, array( 'xyz', $GLOBALS['t_k']['publica'] ) ), true );

/* 3. Download e conferência ---------------------------------------------------------- */

$zip = random_bytes( 3000 );
t_publicar( '1.1.0', $zip );
$release = rastro_atualizador_release();
confere( 'leitura da versão', $release['ok'], true );
confere( 'leitura em cache por 6 h', $GLOBALS['t_site_ttl']['rastro_atualizador_release'], 21600 );
$pedido = $GLOBALS['t_pedidos'][0][1];
confere( 'leitura sem token', isset( $pedido['headers']['Authorization'] ), false );
confere( 'leitura sem endereço do site no User-Agent', $pedido['user-agent'], 'Rastro/1.0.0; WordPress' );
confere( 'leitura sem seguir redirecionamento', $pedido['redirection'], 0 );

$arquivo = rastro_atualizador_baixar_conferido( $release );
confere( 'pacote conferido', is_string( $arquivo ) && is_file( $arquivo ) && file_get_contents( $arquivo ) === $zip, true );
foreach ( $GLOBALS['t_pedidos'] as $p ) {
	confere( 'download sem seguir redirecionamento ' . $p[0], $p[1]['redirection'], 0 );
	confere( 'download sem token ' . $p[0], isset( $p[1]['headers']['Authorization'] ), false );
}
if ( is_string( $arquivo ) ) {
	unlink( $arquivo );
}

// Zip trocado no caminho (o .sha256 e a assinatura são de outro conteúdo).
t_publicar( '1.1.0', $zip, array( 'hex_publicado' => hash( 'sha256', 'outro' ), 'hex_assinado' => hash( 'sha256', 'outro' ) ) );
$GLOBALS['t_apagados'] = array();
$erro                  = rastro_atualizador_baixar_conferido( rastro_atualizador_release() );
confere( 'zip diferente do checksum: recusado', codigo_de( $erro ), 'rastro_atualizador_checksum' );
confere( 'arquivos temporários apagados', count( $GLOBALS['t_apagados'] ) >= 3, true );

// Checksum certo, assinatura de outra chave (servidor comprometido).
t_publicar( '1.1.0', $zip, array( 'secreta' => sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() ) ) );
confere( 'assinatura de chave estranha: recusado', codigo_de( rastro_atualizador_baixar_conferido( rastro_atualizador_release() ) ), 'rastro_atualizador_assinatura' );

// Assinatura de outra versão reaproveitada.
t_publicar( '1.1.0', $zip, array( 'msg' => rastro_atualizador_mensagem( 'v1.0.9', 'rastro-v1.0.9.zip', hash( 'sha256', $zip ) ) ) );
confere( 'assinatura de outra versão: recusado', codigo_de( rastro_atualizador_baixar_conferido( rastro_atualizador_release() ) ), 'rastro_atualizador_assinatura' );

// Digest da API diferente.
t_publicar( '1.1.0', $zip, array( 'digest' => 'sha256:' . str_repeat( 'd', 64 ) ) );
confere( 'digest da API diferente: recusado', codigo_de( rastro_atualizador_baixar_conferido( rastro_atualizador_release() ) ), 'rastro_atualizador_checksum' );

// Tamanho publicado diferente do baixado.
t_publicar( '1.1.0', $zip, array( 'tamanho' => 2999 ) );
confere( 'tamanho diferente: recusado', codigo_de( rastro_atualizador_baixar_conferido( rastro_atualizador_release() ) ), 'rastro_atualizador_tamanho' );

// Redirecionamento para host estranho.
t_publicar( '1.1.0', $zip, array( 'destino' => 'https://evil.example/zip' ) );
confere( 'redirecionamento estranho: recusado', codigo_de( rastro_atualizador_baixar_conferido( rastro_atualizador_release() ) ), 'rastro_atualizador_endereco' );

// Endereço fora do prefixo nunca é pedido.
$GLOBALS['t_pedidos'] = array();
$erro                 = rastro_atualizador_baixar( 'https://evil.example/rastro.zip', 100 );
confere( 'download fora do prefixo: recusado sem pedido', array( codigo_de( $erro ), count( $GLOBALS['t_pedidos'] ) ), array( 'rastro_atualizador_endereco', 0 ) );

// Sem rede: erro, cache curto.
$GLOBALS['t_rede'] = array();
$GLOBALS['t_site'] = array();
confere( 'sem rede: sem versão', rastro_atualizador_release()['ok'], false );
confere( 'sem rede: cache de 30 min', $GLOBALS['t_site_ttl']['rastro_atualizador_release'], 1800 );

/* 4. Ganchos ------------------------------------------------------------------------- */

t_publicar( '1.1.0', $zip );
$oferta = rastro_atualizador_oferta( false, array(), 'rastro/rastro.php' );
confere( 'oferta: versão e pacote marcado', array( $oferta['version'], $oferta['package'] ), array( '1.1.0', T_DL . 'v1.1.0/rastro-v1.1.0.zip#rastro-assinado' ) );
confere( 'oferta: outro plugin intacto', rastro_atualizador_oferta( 'x', array(), 'outro/outro.php' ), 'x' );

confere( 'pre_download: pacote de outro plugin intacto', rastro_atualizador_pre_download( false, 'https://x/y.zip' ), false );
confere( 'pre_download: resposta anterior respeitada', rastro_atualizador_pre_download( 'ja', $oferta['package'] ), 'ja' );
confere( 'seleção sem download conferido: recusada', is_wp_error( rastro_atualizador_selecionar_pasta( '/x/rastro/', '/x/', null, array( 'plugin' => 'rastro/rastro.php' ) ) ), true );
$baixado = rastro_atualizador_pre_download( false, $oferta['package'] );
confere( 'pre_download: zip conferido', is_string( $baixado ) && file_get_contents( $baixado ) === $zip, true );
confere( 'pre_download: versão esperada', rastro_atualizador_esperada(), '1.1.0' );
if ( is_string( $baixado ) ) {
	unlink( $baixado );
}
t_publicar( '1.2.0', $zip );
confere( 'pre_download: versão mudou desde a oferta', is_wp_error( rastro_atualizador_pre_download( false, $oferta['package'] ) ), true );
t_publicar( '1.0.0', $zip );
$igual = rastro_atualizador_oferta( false, array(), 'rastro/rastro.php' );
confere( 'pre_download: nunca a mesma versão ou menor', is_wp_error( rastro_atualizador_pre_download( false, $igual['package'] ) ), true );

// Pasta extraída.
$extraida = $GLOBALS['t_tmp'] . '/extraida';
mkdir( $extraida . '/rastro-main', 0777, true );
file_put_contents( $extraida . '/rastro-main/rastro.php', "<?php\n/**\n * Plugin Name:       Rastro\n * Version:           1.1.0\n */\n" );
confere( 'seleção: outro plugin intacto', rastro_atualizador_selecionar_pasta( 'S', 'R', null, array( 'plugin' => 'outro/outro.php' ) ), 'S' );
$pasta = rastro_atualizador_selecionar_pasta( $extraida . '/rastro-main', $extraida, null, array( 'plugin' => 'rastro/rastro.php' ) );
confere( 'seleção: pasta vira rastro/', $pasta, $extraida . '/rastro/' );
file_put_contents( $extraida . '/rastro/rastro.php', "<?php\n/**\n * Plugin Name:       Rastro\n * Version:           1.0.9\n */\n" );
confere( 'seleção: versão diferente da conferida', is_wp_error( rastro_atualizador_selecionar_pasta( $extraida . '/rastro/', $extraida, null, array( 'plugin' => 'rastro/rastro.php' ) ) ), true );
file_put_contents( $extraida . '/rastro/rastro.php', "<?php\n/**\n * Plugin Name:       Outro\n * Version:           1.1.0\n */\n" );
confere( 'seleção: outro plugin no pacote', is_wp_error( rastro_atualizador_selecionar_pasta( $extraida . '/rastro/', $extraida, null, array( 'plugin' => 'rastro/rastro.php' ) ) ), true );
confere( 'seleção: pasta fora da extração', is_wp_error( rastro_atualizador_preparar_pasta( '/outro/lugar/', $extraida, '1.1.0' ) ), true );

$item = (object) array( 'plugin' => 'rastro/rastro.php' );
confere( 'atualização automática ligada', rastro_atualizador_auto( false, $item ), true );
confere( 'atualização automática: outro plugin intacto', rastro_atualizador_auto( false, (object) array( 'plugin' => 'x/x.php' ) ), false );

t_publicar( '1.1.0', $zip );
$info = rastro_atualizador_detalhes( false, 'plugin_information', (object) array( 'slug' => 'rastro' ) );
confere( 'detalhes: versão', $info->version, '1.1.0' );
confere( 'detalhes: notas escapadas', false === strpos( $info->sections['changelog'], '<script>' ), true );
confere( 'detalhes: outro slug intacto', rastro_atualizador_detalhes( 'r', 'plugin_information', (object) array( 'slug' => 'outro' ) ), 'r' );

define( 'RASTRO_ATUALIZAR_AUTO', false );
confere( 'atualização automática desligada pela constante', rastro_atualizador_auto( false, $item ), false );

/* 5. Textos -------------------------------------------------------------------------- */

foreach ( array( 'versao', 'endereco', 'download', 'tamanho', 'checksum', 'assinatura', 'pacote', 'outro' ) as $codigo ) {
	$texto = rastro_atualizador_texto_erro( $codigo );
	confere( "texto {$codigo} sem a plataforma", preg_match( '/git' . 'hub/i', $texto ), 0 );
	confere( "texto {$codigo} diz que nada foi instalado", false !== strpos( $texto, 'Nada foi instalado' ), true );
}

// Limpeza dos temporários do teste.
foreach ( array_merge( glob( $extraida . '/rastro/*' ) ?: array(), glob( $GLOBALS['t_tmp'] . '/*' ) ?: array() ) as $f ) {
	if ( is_file( $f ) ) {
		unlink( $f );
	}
}
foreach ( array( $extraida . '/rastro', $extraida, $GLOBALS['t_tmp'] ) as $d ) {
	if ( is_dir( $d ) ) {
		rmdir( $d );
	}
}

echo "{$total} conferências do atualizador do Rastro, {$falhas} falha(s).\n";
exit( $falhas > 0 ? 1 : 0 );
