<?php
/**
 * Instalador do plugin próprio a partir de uma cópia embutida (contrato
 * docs/ATUALIZADOR.md, seção 5).
 *
 * Um tema ou plugin de site pode trazer o Rastro embutido. A cópia
 * embutida funciona, mas só muda quando o site a atualiza. Este módulo
 * mostra, só no painel e só para quem pode ativar plugins, o aviso com o
 * botão **Instalar o Rastro** (plugin ausente) ou **Ativar o Rastro**
 * (instalado e desativado). Nada é instalado sem o clique.
 *
 * Instalar: `admin-post` com nonce próprio, capacidades `install_plugins` e
 * `activate_plugins`. Baixa a última versão publicada pelo mesmo caminho do
 * atualizador (rastro_atualizador_baixar_conferido: tamanho, SHA-256 e
 * assinatura Ed25519), instala pelo `Plugin_Upgrader` com o arquivo local
 * (pasta `rastro/`, `Plugin Name: Rastro` e a versão conferida) e ativa. Na
 * próxima página o plugin próprio carrega primeiro e a cópia embutida fica
 * parada (guarda `RASTRO_VERSAO`). As opções são as mesmas: nada se perde.
 *
 * Com o plugin próprio carregado, este arquivo não faz nada.
 *
 * @package Rastro
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'rastro_atualizador_plugin_proprio' ) || rastro_atualizador_plugin_proprio() ) {
	return;
}

/** Ação do admin-post e do nonce. */
const RASTRO_INSTALADOR_ACAO = 'rastro_instalar_plugin';

/**
 * Situação do plugin próprio: 'ativo', 'inativo' (instalado) ou 'ausente'.
 *
 * @return string
 */
function rastro_instalador_estado(): string {
	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	if ( is_plugin_active( RASTRO_ATUALIZADOR_PLUGIN ) || ( is_multisite() && is_plugin_active_for_network( RASTRO_ATUALIZADOR_PLUGIN ) ) ) {
		return 'ativo';
	}

	return is_readable( trailingslashit( WP_PLUGIN_DIR ) . RASTRO_ATUALIZADOR_PLUGIN ) ? 'inativo' : 'ausente';
}

/**
 * O usuário pode usar o botão deste estado?
 *
 * @param string $estado Ver rastro_instalador_estado().
 * @return bool
 */
function rastro_instalador_pode( string $estado ): bool {
	if ( 'ausente' === $estado ) {
		return current_user_can( 'install_plugins' ) && current_user_can( 'activate_plugins' );
	}

	return 'inativo' === $estado && current_user_can( 'activate_plugins' );
}

/**
 * Textos dos códigos de retorno.
 *
 * @return array<string,array{0:string,1:string}> Código => [tipo, texto].
 */
function rastro_instalador_mensagens(): array {
	return array(
		'instalado'  => array( 'success', __( 'Rastro instalado e ativado. A partir de agora ele recebe as versões assinadas sozinho.', 'rastro' ) ),
		'ativado'    => array( 'success', __( 'Rastro ativado.', 'rastro' ) ),
		'ja_ativo'   => array( 'success', __( 'O Rastro já está ativo como plugin próprio.', 'rastro' ) ),
		'sem_versao' => array( 'error', __( 'Não foi possível ler a última versão no servidor de atualizações. Nada foi instalado; tente de novo mais tarde.', 'rastro' ) ),
		'pacote'     => array( 'error', __( 'O pacote baixado não passou na conferência (tamanho, checksum ou assinatura). Nada foi instalado.', 'rastro' ) ),
		'instalar'   => array( 'error', __( 'O WordPress não conseguiu instalar o Rastro (permissão de escrita na pasta de plugins?). Nada foi ativado.', 'rastro' ) ),
		'ativar'     => array( 'error', __( 'O Rastro foi instalado, mas não foi possível ativá-lo. Ative em Plugins.', 'rastro' ) ),
		'estado'     => array( 'error', __( 'A situação do plugin mudou desde que a tela abriu. Confira e tente de novo.', 'rastro' ) ),
	);
}

/**
 * Formulário com o botão do estado atual.
 *
 * @param string $estado Ver rastro_instalador_estado().
 * @return string HTML.
 */
function rastro_instalador_botao( string $estado ): string {
	if ( ! rastro_instalador_pode( $estado ) ) {
		return '<p>' . esc_html__( 'Peça a um administrador para instalar e ativar o plugin Rastro.', 'rastro' ) . '</p>';
	}

	$instalar = 'ausente' === $estado;

	return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
		. '<input type="hidden" name="action" value="' . esc_attr( RASTRO_INSTALADOR_ACAO ) . '">'
		. '<input type="hidden" name="rastro_instalador_modo" value="' . esc_attr( $instalar ? 'instalar' : 'ativar' ) . '">'
		. wp_nonce_field( RASTRO_INSTALADOR_ACAO, '_wpnonce', true, false )
		. '<p><button type="submit" class="button button-primary">'
		. esc_html( $instalar ? __( 'Instalar o Rastro', 'rastro' ) : __( 'Ativar o Rastro', 'rastro' ) )
		. '</button></p></form>';
}

/**
 * A tela atual mostra o aviso? (Plugins e telas do Rastro)
 *
 * @return bool
 */
function rastro_instalador_tela_do_aviso(): bool {
	$tela = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	$id   = $tela && isset( $tela->id ) ? (string) $tela->id : '';

	return 'plugins' === $id || 'plugins-network' === $id || false !== strpos( $id, 'page_rastro' );
}

/**
 * admin_notices: aviso da cópia embutida, com o retorno do último clique.
 *
 * @return void
 */
function rastro_instalador_aviso() {
	try {
		if ( ! current_user_can( 'activate_plugins' ) || ! rastro_instalador_tela_do_aviso() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- só escolhe um texto fixo, sem efeito.
		$codigo    = isset( $_GET['rastro_instalador'] ) && is_scalar( $_GET['rastro_instalador'] ) ? sanitize_key( (string) wp_unslash( $_GET['rastro_instalador'] ) ) : '';
		$mensagens = rastro_instalador_mensagens();
		if ( isset( $mensagens[ $codigo ] ) ) {
			echo '<div class="notice notice-' . esc_attr( $mensagens[ $codigo ][0] ) . ' is-dismissible"><p>' . esc_html( $mensagens[ $codigo ][1] ) . '</p></div>';
		}

		$estado = rastro_instalador_estado();
		if ( 'ativo' === $estado ) {
			return;
		}

		echo '<div class="notice notice-info"><p><strong>' . esc_html__( 'Rastro embutido neste site', 'rastro' ) . '</strong></p><p>';
		printf(
			/* translators: %s: versão embutida. */
			esc_html__( 'O Rastro deste site (versão %s) vem embutido no tema ou em outro plugin e só muda quando eles mudam. Instale o plugin Rastro para receber sozinho as versões novas, conferidas por assinatura. As configurações continuam as mesmas.', 'rastro' ),
			esc_html( RASTRO_VERSAO )
		);
		echo '</p>' . rastro_instalador_botao( $estado ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- botão montado com escape.
	} catch ( \Throwable $erro ) {
		do_action( 'rastro_falha', 'instalador', $erro );
	}
}

/**
 * Instala o plugin próprio a partir da última versão conferida.
 *
 * @return string Código de rastro_instalador_mensagens().
 */
function rastro_instalador_instalar(): string {
	$release = rastro_atualizador_release( true );
	if ( empty( $release['ok'] ) ) {
		return 'sem_versao';
	}

	$zip = rastro_atualizador_baixar_conferido( $release );
	if ( is_wp_error( $zip ) || ! is_string( $zip ) ) {
		return is_wp_error( $zip ) && in_array( $zip->get_error_code(), array( 'rastro_atualizador_tamanho', 'rastro_atualizador_checksum', 'rastro_atualizador_assinatura', 'rastro_atualizador_endereco' ), true ) ? 'pacote' : 'sem_versao';
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/misc.php';
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

	$esperada = (string) $release['versao'];
	$conferir = static function ( $source, $remote_source ) use ( $esperada ) {
		return rastro_atualizador_preparar_pasta( $source, $remote_source, $esperada );
	};

	// Prioridade 20: depois da conferência padrão do Plugin_Upgrader.
	add_filter( 'upgrader_source_selection', $conferir, 20, 2 );
	$skin      = new \WP_Ajax_Upgrader_Skin();
	$upgrader  = new \Plugin_Upgrader( $skin );
	$resultado = $upgrader->install( $zip, array( 'overwrite_package' => false ) );
	remove_filter( 'upgrader_source_selection', $conferir, 20 );

	// Arquivo temporário do próprio download.
	wp_delete_file( $zip );

	$erros = $skin->get_errors();
	if ( is_wp_error( $resultado ) || ( is_wp_error( $erros ) && $erros->has_errors() ) ) {
		$codigos = is_wp_error( $resultado ) ? array( $resultado->get_error_code() ) : $erros->get_error_codes();
		return in_array( 'rastro_atualizador_pacote', $codigos, true ) ? 'pacote' : 'instalar';
	}
	if ( true !== $resultado || 'ausente' === rastro_instalador_estado() ) {
		return 'instalar';
	}

	wp_clean_plugins_cache();

	return is_wp_error( activate_plugin( RASTRO_ATUALIZADOR_PLUGIN ) ) ? 'ativar' : 'instalado';
}

/**
 * Regra do clique: confere capacidade, nonce e estado e faz o pedido.
 *
 * @param string $modo 'instalar' ou 'ativar'.
 * @return string Código de retorno.
 */
function rastro_instalador_processar( string $modo ): string {
	if ( ! current_user_can( 'activate_plugins' ) || ( 'instalar' === $modo && ! current_user_can( 'install_plugins' ) ) ) {
		wp_die( esc_html__( 'Você não tem permissão para fazer isso.', 'rastro' ), '', array( 'response' => 403 ) );
	}

	check_admin_referer( RASTRO_INSTALADOR_ACAO );

	$estado = rastro_instalador_estado();
	if ( 'ativo' === $estado ) {
		return 'ja_ativo';
	}
	if ( 'ativar' === $modo && 'inativo' === $estado ) {
		return is_wp_error( activate_plugin( RASTRO_ATUALIZADOR_PLUGIN ) ) ? 'ativar' : 'ativado';
	}
	if ( 'instalar' === $modo && 'ausente' === $estado ) {
		try {
			return rastro_instalador_instalar();
		} catch ( \Throwable $erro ) {
			do_action( 'rastro_falha', 'instalador', $erro );
			return 'instalar';
		}
	}

	return 'estado';
}

/**
 * admin-post: clique em Instalar ou Ativar, de volta para Plugins.
 *
 * @return void
 */
function rastro_instalador_handle() {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce conferido em rastro_instalador_processar(), antes de qualquer efeito.
	$modo   = isset( $_POST['rastro_instalador_modo'] ) && is_string( $_POST['rastro_instalador_modo'] ) ? sanitize_key( wp_unslash( $_POST['rastro_instalador_modo'] ) ) : '';
	$codigo = rastro_instalador_processar( in_array( $modo, array( 'instalar', 'ativar' ), true ) ? $modo : '' );

	wp_safe_redirect( add_query_arg( 'rastro_instalador', $codigo, self_admin_url( 'plugins.php' ) ) );
	exit;
}

add_action( 'admin_notices', 'rastro_instalador_aviso' );
add_action( 'network_admin_notices', 'rastro_instalador_aviso' );
add_action( 'admin_post_' . RASTRO_INSTALADOR_ACAO, 'rastro_instalador_handle' );
