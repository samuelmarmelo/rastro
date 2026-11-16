<?php
/**
 * Testes do instalador do plugin próprio (rastro/atualizacao/instalador.php),
 * numa cópia embutida simulada, sem WordPress e sem rede.
 *
 * Contrato: docs/ATUALIZADOR.md, seção 5.
 *
 * @package Rastro
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'RASTRO_VERSAO', '1.0.0' );
define( 'RASTRO_ARQUIVO', '/var/www/wp-content/plugins/meu-plugin/includes/rastro/rastro.php' );

$GLOBALS['t_tmp'] = sys_get_temp_dir() . '/rastro-instalador-' . getmypid() . '-' . mt_rand();
mkdir( $GLOBALS['t_tmp'] . '/plugins', 0777, true );
define( 'WP_PLUGIN_DIR', $GLOBALS['t_tmp'] . '/plugins' );

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
	public function __construct( $codigo = '', $mensagem = '' ) {
		$this->codigo = $codigo;
	}
	public function get_error_code() {
		return $this->codigo;
	}
}
function is_wp_error( $x ) { // phpcs:ignore
	return $x instanceof WP_Error;
}
function plugin_basename( $arquivo ) { // phpcs:ignore
	return 'meu-plugin/includes/rastro/rastro.php';
}
$GLOBALS['t_ativos'] = array();
function is_plugin_active( $p ) { // phpcs:ignore
	return in_array( $p, $GLOBALS['t_ativos'], true );
}
function is_multisite() { // phpcs:ignore
	return false;
}
function is_plugin_active_for_network( $p ) { // phpcs:ignore
	return false;
}
$GLOBALS['t_ativou']      = array();
$GLOBALS['t_ativar_erro'] = false;
function activate_plugin( $p ) { // phpcs:ignore
	$GLOBALS['t_ativou'][] = $p;
	return $GLOBALS['t_ativar_erro'] ? new WP_Error( 'x' ) : null;
}
$GLOBALS['t_tela'] = 'plugins';
function get_current_screen() { // phpcs:ignore
	return (object) array( 'id' => $GLOBALS['t_tela'] );
}
function self_admin_url( $p = '' ) { // phpcs:ignore
	return 'https://loja.exemplo.com.br/wp-admin/' . $p;
}
function get_site_transient( $k ) { // phpcs:ignore
	return false;
}
function set_site_transient( $k, $v, $t = 0 ) { // phpcs:ignore
	return true;
}
function wp_safe_remote_get( $url, $args = array() ) { // phpcs:ignore
	return new WP_Error( 'http_request_failed' );
}
function get_file_data( $arquivo, $campos ) { // phpcs:ignore
	$texto = (string) file_get_contents( $arquivo );
	$saida = array();
	foreach ( $campos as $chave => $nome ) {
		$saida[ $chave ] = preg_match( '/^[ \t\/*#@]*' . preg_quote( $nome, '/' ) . ':(.*)$/mi', $texto, $m ) ? trim( $m[1] ) : '';
	}
	return $saida;
}
const T_CABECALHO = "<?php\n/**\n * Plugin Name: Rastro\n */\n";

require __DIR__ . '/../../rastro/atualizacao/chaves.php';
require __DIR__ . '/../../rastro/atualizacao/atualizador.php';
require __DIR__ . '/../../rastro/atualizacao/instalador.php';

function admin( array $capacidades = array( 'install_plugins' => true, 'activate_plugins' => true ), array $sobre = array() ) { // phpcs:ignore
	rastro_teste_reset( array_replace( array( 'admin' => true, 'capacidades' => $capacidades ), $sobre ) );
}

function parada( callable $f ) { // phpcs:ignore
	try {
		$f();
	} catch ( Rastro_Teste_Parada $p ) {
		return array( $p->getMessage(), $p->status );
	}
	return null;
}

/* 1. Carga só na cópia embutida ----------------------------------------------------- */

confere( 'cópia embutida carrega o instalador', function_exists( 'rastro_instalador_processar' ), true );
confere( 'aviso registrado', false !== has_action( 'admin_notices', 'rastro_instalador_aviso' ), true );
confere( 'admin-post registrado', false !== has_action( 'admin_post_rastro_instalar_plugin', 'rastro_instalador_handle' ), true );
confere( 'atualizador sem ganchos na cópia embutida', has_filter( 'upgrader_pre_download' ), false );

/* 2. Estado e permissão --------------------------------------------------------------- */

admin();
confere( 'estado: ausente', rastro_instalador_estado(), 'ausente' );
mkdir( WP_PLUGIN_DIR . '/rastro' );
file_put_contents( WP_PLUGIN_DIR . '/rastro/rastro.php', "<?php\n/**\n * Plugin Name: Outro\n */\n" );
confere( 'estado: pasta com outro plugin', rastro_instalador_estado(), 'conflito' );
ob_start();
rastro_instalador_aviso();
$aviso = ob_get_clean();
confere( 'conflito: aviso sem botão', array( false !== strpos( $aviso, 'outro plugin' ), false === strpos( $aviso, '<form' ) ), array( true, true ) );
file_put_contents( WP_PLUGIN_DIR . '/rastro/rastro.php', T_CABECALHO );
confere( 'estado: inativo', rastro_instalador_estado(), 'inativo' );
$GLOBALS['t_ativos'] = array( 'rastro/rastro.php' );
confere( 'estado: ativo', rastro_instalador_estado(), 'ativo' );
ob_start();
rastro_instalador_aviso();
confere( 'ativo mas parado: aviso', false !== strpos( ob_get_clean(), 'ativo, mas parado' ), true );
$GLOBALS['t_ativos'] = array();
unlink( WP_PLUGIN_DIR . '/rastro/rastro.php' );
rmdir( WP_PLUGIN_DIR . '/rastro' );

confere( 'pode instalar com as duas capacidades', rastro_instalador_pode( 'ausente' ), true );
admin( array( 'activate_plugins' => true ) );
confere( 'sem install_plugins não instala', rastro_instalador_pode( 'ausente' ), false );
confere( 'ativar só com activate_plugins', rastro_instalador_pode( 'inativo' ), true );
admin( array() );
confere( 'sem capacidade: nada', array( rastro_instalador_pode( 'ausente' ), rastro_instalador_pode( 'inativo' ) ), array( false, false ) );
confere( 'sem capacidade: sem formulário', false === strpos( rastro_instalador_botao( 'ausente' ), '<form' ), true );

admin();
$botao = rastro_instalador_botao( 'ausente' );
foreach ( array( 'admin-post.php', 'value="rastro_instalar_plugin"', 'name="rastro_instalador_modo" value="instalar"', 'name="_wpnonce"', 'Instalar o Rastro' ) as $trecho ) {
	confere( "botão: {$trecho}", false !== strpos( $botao, $trecho ), true );
}
confere( 'botão de ativar', false !== strpos( rastro_instalador_botao( 'inativo' ), 'value="ativar"' ), true );

/* 3. Aviso ------------------------------------------------------------------------------ */

ob_start();
rastro_instalador_aviso();
$aviso = ob_get_clean();
confere( 'aviso na tela de plugins', false !== strpos( $aviso, 'Rastro embutido neste site' ), true );
confere( 'aviso com a versão embutida', false !== strpos( $aviso, 'versão 1.0.0' ), true );
$GLOBALS['t_tela'] = 'dashboard';
ob_start();
rastro_instalador_aviso();
confere( 'sem aviso em outras telas', ob_get_clean(), '' );
$GLOBALS['t_tela'] = 'toplevel_page_rastro';
ob_start();
rastro_instalador_aviso();
confere( 'aviso na tela do Rastro', false !== strpos( ob_get_clean(), 'Instalar o Rastro' ), true );
$GLOBALS['t_tela'] = 'plugins';
admin( array() );
ob_start();
rastro_instalador_aviso();
confere( 'sem aviso para quem não ativa plugins', ob_get_clean(), '' );
admin();
$_GET = array( 'rastro_instalador' => 'pacote' );
ob_start();
rastro_instalador_aviso();
confere( 'retorno do clique com texto fixo', false !== strpos( ob_get_clean(), 'não passou na conferência' ), true );
$_GET = array( 'rastro_instalador' => '<b>inventado' );
ob_start();
rastro_instalador_aviso();
confere( 'código inventado não vira texto', false === strpos( ob_get_clean(), 'inventado' ), true );
$_GET = array();

/* 4. Clique ------------------------------------------------------------------------------- */

admin( array() );
confere( 'clique sem permissão: 403', parada( static function () { rastro_instalador_processar( 'instalar' ); } ), array( 'Você não tem permissão para fazer isso.', 403 ) );
admin( array( 'activate_plugins' => true ) );
confere( 'instalar sem install_plugins: 403', parada( static function () { rastro_instalador_processar( 'instalar' ); } )[1], 403 );
admin( array( 'install_plugins' => true, 'activate_plugins' => true ), array( 'nonce_ok' => false ) );
confere( 'clique com nonce inválido: para', parada( static function () { rastro_instalador_processar( 'instalar' ); } ), array( 'nonce', 403 ) );

admin();
confere( 'instalar sem servidor de atualizações: nada instalado', rastro_instalador_processar( 'instalar' ), 'sem_versao' );
confere( 'modo inválido: estado', rastro_instalador_processar( '' ), 'estado' );
confere( 'ativar com plugin ausente: estado', rastro_instalador_processar( 'ativar' ), 'estado' );
confere( 'nada ativado até aqui', $GLOBALS['t_ativou'], array() );

mkdir( WP_PLUGIN_DIR . '/rastro' );
file_put_contents( WP_PLUGIN_DIR . '/rastro/rastro.php', T_CABECALHO );
confere( 'ativar instalado', rastro_instalador_processar( 'ativar' ), 'ativado' );
confere( 'ativou o plugin próprio', $GLOBALS['t_ativou'], array( 'rastro/rastro.php' ) );
$GLOBALS['t_ativar_erro'] = true;
confere( 'ativar com erro', rastro_instalador_processar( 'ativar' ), 'ativar' );
confere( 'instalar com plugin já instalado: estado', rastro_instalador_processar( 'instalar' ), 'estado' );
$GLOBALS['t_ativos'] = array( 'rastro/rastro.php' );
confere( 'já ativo', rastro_instalador_processar( 'ativar' ), 'ja_ativo' );

foreach ( rastro_instalador_mensagens() as $codigo => $m ) {
	confere( "mensagem {$codigo} sem a plataforma", preg_match( '/git' . 'hub/i', $m[1] ), 0 );
}

unlink( WP_PLUGIN_DIR . '/rastro/rastro.php' );
rmdir( WP_PLUGIN_DIR . '/rastro' );
rmdir( WP_PLUGIN_DIR );
rmdir( $GLOBALS['t_tmp'] );

echo "{$total} conferências do instalador do Rastro, {$falhas} falha(s).\n";
exit( $falhas > 0 ? 1 : 0 );
