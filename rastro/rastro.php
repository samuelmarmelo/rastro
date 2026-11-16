<?php
/**
 * Plugin Name:       Rastro
 * Description:       Rastreamento, consentimento de cookies (LGPD) e relatórios de venda do WooCommerce por origem, com as tags carregadas fora do caminho crítico da página.
 * Version:           1.1.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            Samuel Neves
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       rastro
 * Update URI:        https://github.com/samuelmarmelo/rastro
 *
 * Base do plugin (contrato docs/CONTRATO.md,
 * seção 3; manual docs/BASE.md): constantes e carregador dos módulos.
 *
 * A mesma pasta funciona de dois jeitos: como plugin próprio (esta pasta
 * zipada) ou embutida em outro plugin, que inclui este arquivo. Com o plugin
 * próprio ativo, a cópia embutida não carrega (guarda RASTRO_VERSAO).
 *
 * As funções deste arquivo ficam dentro de um `if`: declaradas no topo, o PHP
 * as registraria antes da guarda e a segunda cópia daria erro fatal.
 *
 * @package Rastro
 */

defined( 'ABSPATH' ) || exit;

// Plugin próprio ativo (ou esta pasta já carregada): a outra cópia não carrega.
if ( defined( 'RASTRO_VERSAO' ) ) {
	return;
}

define( 'RASTRO_VERSAO', '1.1.0' );
define( 'RASTRO_ARQUIVO', __FILE__ );
define( 'RASTRO_DIR', __DIR__ . '/' );
// Funciona embutido e como plugin próprio.
define( 'RASTRO_URL', rtrim( (string) plugins_url( '', RASTRO_ARQUIVO ), '/' ) . '/' );

if ( ! function_exists( 'rastro_modulos' ) ) {

	/**
	 * Módulos do Rastro, na ordem de carga (caminhos a partir desta pasta).
	 * A lista inteira nasce aqui; cada frente só acrescenta os seus arquivos
	 * e o que ainda não existe é pulado.
	 *
	 * @return string[]
	 */
	function rastro_modulos(): array {
		$modulos = array(
			'nucleo/config.php',
			'nucleo/regras.php',
			'nucleo/saida.php',
			'logs/logs.php',
			'consentimento/consentimento.php',
			'consentimento/aviso.php',
			'tags/tags.php',
			'verificacao/arquivo.php',
			'loja/eventos.php',
			'atribuicao/atribuicao.php',
			'atribuicao/pedido.php',
			'relatorios/dados.php',
			'relatorios/relatorios.php',
			'atualizacao/chaves.php',
			'atualizacao/atualizador.php',
		);
		if ( is_admin() ) {
			$modulos[] = 'admin/admin.php';
			$modulos[] = 'atualizacao/instalador.php';
		}

		/**
		 * Filtra a lista de módulos do Rastro.
		 *
		 * @param string[] $modulos Caminhos a partir da pasta do Rastro.
		 */
		$filtrados = apply_filters( 'rastro_modulos', $modulos );

		return is_array( $filtrados ) ? array_values( array_filter( $filtrados, 'is_string' ) ) : $modulos;
	}

	/**
	 * Carrega um módulo. SafeWidget: arquivo ausente é pulado e erro no
	 * módulo vira a ação `rastro_falha`, sem derrubar o site.
	 *
	 * @param string $modulo Caminho a partir da pasta (ex.: nucleo/config.php).
	 * @param string $pasta  Pasta de origem, com barra final (padrão: a do Rastro).
	 * @return bool Carregou?
	 */
	function rastro_carregar_arquivo( string $modulo, string $pasta = RASTRO_DIR ): bool {
		// Só caminhos simples dentro da pasta (sem "..", sem barra inicial).
		if ( 1 !== preg_match( '#^[a-z0-9_-]+(?:/[a-z0-9_-]+)*\.php$#D', $modulo ) ) {
			return false;
		}

		$caminho = $pasta . $modulo;
		if ( ! is_readable( $caminho ) ) {
			return false;
		}

		try {
			require_once $caminho;
		} catch ( \Throwable $erro ) {
			/**
			 * Um módulo do Rastro falhou ao carregar ou ao rodar.
			 *
			 * @param string     $modulo Módulo ou ponto da falha.
			 * @param \Throwable $erro   Erro capturado.
			 */
			do_action( 'rastro_falha', $modulo, $erro );
			return false;
		}

		return true;
	}

	/**
	 * Carrega os módulos e avisa (`rastro_carregado`).
	 *
	 * @return void
	 */
	function rastro_carregar(): void {
		foreach ( rastro_modulos() as $modulo ) {
			rastro_carregar_arquivo( $modulo );
		}

		/** O Rastro terminou de carregar os módulos. */
		do_action( 'rastro_carregado' );
	}
}

// Carga imediata: vale em plugins_loaded (plugin próprio) e em
// after_setup_theme (embutido).
rastro_carregar();
