<?php
/**
 * Rastro: painel (contrato, seção 3.4; manual docs/BASE.md).
 *
 * Menu próprio "Rastro" com abas registradas pelo filtro
 * `rastro_admin_abas`. As abas Geral, Eventos, WooCommerce e Consentimento
 * mostram os campos do esquema (nucleo/config.php); outras frentes
 * acrescentam as suas (Relatórios, ordem 50).
 *
 * Salvar: um formulário por aba, admin-post `rastro_salvar`, capacidade
 * manage_options e nonce antes de gravar. Só os campos da aba enviada são
 * gravados; campo inválido mantém o valor anterior e o aviso aparece depois
 * do redirecionamento (PRG). Nada é apagado.
 *
 * @package Rastro
 */

defined( 'ABSPATH' ) || exit;

/** Slug da tela. */
const RASTRO_ADMIN_SLUG = 'rastro';

/** Ação do admin-post e do nonce. */
const RASTRO_ADMIN_ACAO = 'rastro_salvar';

/** Capacidade das abas de configuração. */
const RASTRO_ADMIN_CAPACIDADE = 'manage_options';

/* Abas ---------------------------------------------------------------------- */

/**
 * Abas do esquema: chave => título.
 *
 * @return array<string,string>
 */
function rastro_admin_abas_esquema(): array {
	return array(
		'geral'         => __( 'Geral', 'rastro' ),
		'eventos'       => __( 'Eventos', 'rastro' ),
		'woocommerce'   => __( 'WooCommerce', 'rastro' ),
		'consentimento' => __( 'Consentimento', 'rastro' ),
		'verificacao'   => __( 'Verificações', 'rastro' ),
	);
}

/**
 * Abas do painel, em ordem. Cada uma: titulo, capacidade, callback
 * (recebe a chave da aba) e ordem. Entrada sem título ou sem callback
 * válido é ignorada.
 *
 * @return array<string,array{titulo:string,capacidade:string,callback:callable,ordem:int}>
 */
function rastro_admin_abas(): array {
	$abas  = array();
	$ordem = 10;
	// Verificações antes de Relatórios (ordem 50, frente T6), sem empate.
	$fixas = array( 'verificacao' => 45 );
	foreach ( rastro_admin_abas_esquema() as $chave => $titulo ) {
		$abas[ $chave ] = array(
			'titulo'     => $titulo,
			'capacidade' => RASTRO_ADMIN_CAPACIDADE,
			'callback'   => 'rastro_admin_aba_campos',
			'ordem'      => isset( $fixas[ $chave ] ) ? $fixas[ $chave ] : $ordem,
		);
		$ordem         += 10;
	}

	/**
	 * Filtra as abas do painel do Rastro.
	 *
	 * @param array $abas chave => titulo, capacidade, callback, ordem.
	 */
	$filtradas = apply_filters( 'rastro_admin_abas', $abas );
	$filtradas = is_array( $filtradas ) ? $filtradas : $abas;

	$validas = array();
	foreach ( $filtradas as $chave => $aba ) {
		if ( ! is_string( $chave ) || 1 !== preg_match( '/^[a-z0-9_-]{1,40}$/D', $chave ) || ! is_array( $aba ) ) {
			continue;
		}
		if ( empty( $aba['titulo'] ) || ! is_string( $aba['titulo'] ) || ! isset( $aba['callback'] ) || ! is_callable( $aba['callback'] ) ) {
			continue;
		}
		$validas[ $chave ] = array(
			'titulo'     => $aba['titulo'],
			'capacidade' => isset( $aba['capacidade'] ) && is_string( $aba['capacidade'] ) && '' !== $aba['capacidade'] ? $aba['capacidade'] : RASTRO_ADMIN_CAPACIDADE,
			'callback'   => $aba['callback'],
			'ordem'      => isset( $aba['ordem'] ) ? (int) $aba['ordem'] : 100,
		);
	}

	uasort(
		$validas,
		static function ( array $a, array $b ): int {
			return $a['ordem'] <=> $b['ordem'];
		}
	);

	return $validas;
}

/**
 * Abas que o usuário atual pode ver.
 *
 * @return array
 */
function rastro_admin_abas_visiveis(): array {
	return array_filter(
		rastro_admin_abas(),
		static function ( array $aba ): bool {
			return current_user_can( $aba['capacidade'] );
		}
	);
}

/**
 * Capacidade do menu: a da primeira aba que o usuário pode ver (quem só
 * gerencia a loja vê só os relatórios).
 *
 * @return string
 */
function rastro_admin_capacidade_menu(): string {
	$visiveis = rastro_admin_abas_visiveis();
	$primeira = reset( $visiveis );

	return is_array( $primeira ) ? $primeira['capacidade'] : RASTRO_ADMIN_CAPACIDADE;
}

/**
 * Aba pedida no endereço (só as visíveis; sem pedido, a primeira).
 *
 * @return string '' quando nenhuma aba é visível.
 */
function rastro_admin_aba_atual(): string {
	$visiveis = rastro_admin_abas_visiveis();
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- só escolhe uma aba de uma lista fechada.
	$pedida = isset( $_GET['aba'] ) && is_string( $_GET['aba'] ) ? sanitize_key( wp_unslash( $_GET['aba'] ) ) : '';
	if ( '' !== $pedida && isset( $visiveis[ $pedida ] ) ) {
		return $pedida;
	}
	$chaves = array_keys( $visiveis );

	return isset( $chaves[0] ) ? (string) $chaves[0] : '';
}

/**
 * Endereço de uma aba do painel.
 *
 * @param string $aba  Chave da aba.
 * @param array  $args Parâmetros extras.
 * @return string
 */
function rastro_admin_url( string $aba = '', array $args = array() ): string {
	$parametros = array( 'page' => RASTRO_ADMIN_SLUG );
	if ( '' !== $aba ) {
		$parametros['aba'] = $aba;
	}

	return add_query_arg( array_merge( $parametros, $args ), admin_url( 'admin.php' ) );
}

/* Menu e estilo ------------------------------------------------------------- */

/**
 * Sufixo da tela do Rastro (o que add_menu_page devolve).
 *
 * @param string|null $novo Guarda um novo valor.
 * @return string
 */
function rastro_admin_tela_id( ?string $novo = null ): string {
	static $tela = '';
	if ( null !== $novo ) {
		$tela = $novo;
	}

	return $tela;
}

/** Menu próprio "Rastro". */
function rastro_admin_menu(): void {
	$tela = add_menu_page(
		__( 'Rastro', 'rastro' ),
		__( 'Rastro', 'rastro' ),
		rastro_admin_capacidade_menu(),
		RASTRO_ADMIN_SLUG,
		'rastro_admin_tela',
		'dashicons-chart-line',
		58
	);
	rastro_admin_tela_id( is_string( $tela ) ? $tela : '' );
}
add_action( 'admin_menu', 'rastro_admin_menu' );

/**
 * Estilo do painel, só nas telas do Rastro.
 *
 * @param string $sufixo Sufixo da tela atual.
 * @return void
 */
function rastro_admin_estilo( $sufixo = '' ): void {
	$tela = rastro_admin_tela_id();
	if ( '' === $tela || $tela !== $sufixo ) {
		return;
	}
	$arquivo = RASTRO_DIR . 'assets/css/admin.css';
	if ( ! is_readable( $arquivo ) ) {
		return;
	}
	$data = @filemtime( $arquivo ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- sem data, vale a versão do plugin.
	wp_enqueue_style( 'rastro-admin', RASTRO_URL . 'assets/css/admin.css', array(), false !== $data ? (string) $data : RASTRO_VERSAO );
}
add_action( 'admin_enqueue_scripts', 'rastro_admin_estilo' );

/* Tela ---------------------------------------------------------------------- */

/**
 * Avisos do topo de toda aba (filtro `rastro_admin_avisos`), já validados.
 *
 * @return array<int,array{tipo:string,texto:string}>
 */
function rastro_admin_avisos(): array {
	/**
	 * Avisos do painel do Rastro (ex.: outro plugin de medição ativo).
	 *
	 * @param array $avisos Lista de array( 'tipo' => warning|info|error, 'texto' => string ).
	 */
	$avisos = apply_filters( 'rastro_admin_avisos', array() );
	$limpos = array();
	foreach ( is_array( $avisos ) ? $avisos : array() as $aviso ) {
		if ( ! is_array( $aviso ) || ! isset( $aviso['texto'] ) || ! is_string( $aviso['texto'] ) || '' === trim( $aviso['texto'] ) ) {
			continue;
		}
		$tipo     = isset( $aviso['tipo'] ) && in_array( $aviso['tipo'], array( 'warning', 'info', 'error' ), true ) ? $aviso['tipo'] : 'info';
		$limpos[] = array(
			'tipo'  => $tipo,
			'texto' => $aviso['texto'],
		);
	}

	return $limpos;
}

/**
 * Imprime um aviso do painel.
 *
 * @param string $tipo  success, warning, info ou error.
 * @param string $texto Texto (escapado aqui).
 * @param array  $itens Itens da lista abaixo do texto (escapados aqui).
 * @return void
 */
function rastro_admin_aviso( string $tipo, string $texto, array $itens = array() ): void {
	echo '<div class="notice notice-' . esc_attr( $tipo ) . ' inline rastro-aviso" role="status"><p>' . esc_html( $texto ) . '</p>';
	if ( array() !== $itens ) {
		echo '<ul class="rastro-aviso-lista">';
		foreach ( $itens as $item ) {
			echo '<li>' . esc_html( (string) $item ) . '</li>';
		}
		echo '</ul>';
	}
	echo '</div>';
}

/**
 * Chave do transient dos erros do último envio (por usuário).
 *
 * @return string
 */
function rastro_admin_chave_erros(): string {
	return 'rastro_admin_erros_' . (int) get_current_user_id();
}

/** Avisos de retorno do salvar (PRG) e do filtro. */
function rastro_admin_avisos_imprimir(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- só escolhe um aviso fixo.
	$retorno = isset( $_GET['rastro_aviso'] ) && is_string( $_GET['rastro_aviso'] ) ? sanitize_key( wp_unslash( $_GET['rastro_aviso'] ) ) : '';
	if ( 'salvo' === $retorno ) {
		rastro_admin_aviso( 'success', __( 'Configurações salvas.', 'rastro' ) );
	} elseif ( 'erro' === $retorno ) {
		$erros = get_transient( rastro_admin_chave_erros() );
		delete_transient( rastro_admin_chave_erros() );
		$esquema = rastro_config_esquema();
		$itens   = array();
		foreach ( is_array( $erros ) ? $erros : array() as $chave => $mensagem ) {
			if ( is_string( $chave ) && isset( $esquema[ $chave ] ) && is_string( $mensagem ) ) {
				$itens[] = $esquema[ $chave ]['rotulo'] . ': ' . $mensagem;
			}
		}
		rastro_admin_aviso( 'error', __( 'Alguns campos não foram salvos. Os outros foram.', 'rastro' ), $itens );
	}

	foreach ( rastro_admin_avisos() as $aviso ) {
		rastro_admin_aviso( $aviso['tipo'], $aviso['texto'] );
	}
}

/** Tela do Rastro: abas, avisos e o conteúdo da aba atual. */
function rastro_admin_tela(): void {
	$abas  = rastro_admin_abas_visiveis();
	$atual = rastro_admin_aba_atual();
	if ( '' === $atual || ! isset( $abas[ $atual ] ) ) {
		wp_die( esc_html__( 'Sem permissão.', 'rastro' ), '', array( 'response' => 403 ) );
	}

	echo '<div class="wrap rastro-painel"><h1>' . esc_html__( 'Rastro', 'rastro' ) . '</h1>';

	echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Seções do Rastro', 'rastro' ) . '">';
	foreach ( $abas as $chave => $aba ) {
		$classe = 'nav-tab' . ( $chave === $atual ? ' nav-tab-active' : '' );
		echo '<a class="' . esc_attr( $classe ) . '" href="' . esc_url( rastro_admin_url( $chave ) ) . '"' . ( $chave === $atual ? ' aria-current="page"' : '' ) . '>' . esc_html( $aba['titulo'] ) . '</a>';
	}
	echo '</nav>';

	rastro_admin_avisos_imprimir();

	try {
		call_user_func( $abas[ $atual ]['callback'], $atual );
	} catch ( \Throwable $erro ) {
		do_action( 'rastro_falha', 'admin-' . $atual, $erro );
		echo '<p>' . esc_html__( 'Esta seção está temporariamente indisponível.', 'rastro' ) . '</p>';
	}

	echo '</div>';
}

/* Situação ------------------------------------------------------------------ */

/**
 * Nomes dos destinos para a tela.
 *
 * @return array<string,string>
 */
function rastro_admin_nomes_destinos(): array {
	return array(
		'gtm'     => 'Google Tag Manager',
		'ga4'     => 'Google Analytics 4',
		'ads'     => 'Google Ads',
		'meta'    => 'Meta Pixel',
		'tiktok'  => 'TikTok Pixel',
		'openai'  => 'OpenAI',
		'posthog' => 'PostHog',
	);
}

/**
 * Linhas da caixa "Situação" (só leitura): rótulo => valor.
 *
 * @return array<string,string>
 */
function rastro_admin_situacao(): array {
	$config  = rastro_config();
	$ligados = array();
	foreach ( rastro_admin_nomes_destinos() as $chave => $nome ) {
		if ( isset( $config[ $chave ] ) && is_string( $config[ $chave ] ) && '' !== $config[ $chave ] ) {
			$ligados[] = $nome;
		}
	}

	if ( array() === $ligados ) {
		$medicao = __( 'Desligada: nenhum destino configurado.', 'rastro' );
	} elseif ( rastro_ambiente_local() ) {
		$medicao = __( 'Parada: este site é uma cópia local e não mede nada. Para testar de propósito, defina a constante RASTRO_LOCAL.', 'rastro' );
	} else {
		$medicao = __( 'Ativa para os visitantes, depois do aceite de cookies. A equipe do site não é medida, a menos que a aba Consentimento diga o contrário.', 'rastro' );
	}

	$provedor = function_exists( 'rastro_consent_provedor' ) ? (string) rastro_consent_provedor() : '';

	return array(
		__( 'Destinos configurados', 'rastro' )     => array() === $ligados ? __( 'Nenhum', 'rastro' ) : implode( ', ', $ligados ),
		__( 'Medição neste site', 'rastro' )        => $medicao,
		__( 'Provedor de consentimento', 'rastro' ) => '' !== $provedor ? $provedor : __( 'aguardando o módulo de consentimento', 'rastro' ),
	);
}

/** Caixa "Situação" da aba Geral. */
function rastro_admin_situacao_imprimir(): void {
	echo '<div class="rastro-situacao" role="region" aria-labelledby="rastro-situacao-titulo">';
	echo '<h2 id="rastro-situacao-titulo">' . esc_html__( 'Situação', 'rastro' ) . '</h2>';
	echo '<dl class="rastro-situacao-lista">';
	foreach ( rastro_admin_situacao() as $rotulo => $valor ) {
		echo '<dt>' . esc_html( (string) $rotulo ) . '</dt><dd>' . esc_html( $valor ) . '</dd>';
	}
	echo '</dl></div>';
}

/* Campos -------------------------------------------------------------------- */

/**
 * Campos do esquema de uma aba.
 *
 * @param string $aba Chave da aba.
 * @return array<string,array<string,mixed>>
 */
function rastro_admin_campos_da_aba( string $aba ): array {
	return array_filter(
		rastro_config_esquema(),
		static function ( array $campo ) use ( $aba ): bool {
			return $campo['aba'] === $aba;
		}
	);
}

/**
 * Imprime o controle de um campo, conforme o tipo.
 *
 * @param string $chave Chave do esquema.
 * @param array  $campo Entrada do esquema.
 * @param mixed  $valor Valor guardado (já validado).
 * @return void
 */
function rastro_admin_campo( string $chave, array $campo, $valor ): void {
	$id    = 'rastro-campo-' . str_replace( '_', '-', $chave );
	$nome  = 'rastro[' . $chave . ']';
	$ajuda = isset( $campo['ajuda'] ) && is_string( $campo['ajuda'] ) && '' !== $campo['ajuda'];
	$desc  = $ajuda ? ' aria-describedby="' . esc_attr( $id . '-ajuda' ) . '"' : '';

	switch ( $campo['tipo'] ) {
		case 'id':
			// A verificação do Google aceita a tag inteira colada.
			$maximo = ! empty( $campo['extrair_content'] ) ? 400 : 100;
			echo '<input type="text" class="regular-text code" id="' . esc_attr( $id ) . '" name="' . esc_attr( $nome ) . '" value="' . esc_attr( (string) $valor ) . '" autocomplete="off" spellcheck="false" maxlength="' . esc_attr( (string) $maximo ) . '"'
				. ( isset( $campo['exemplo'] ) ? ' placeholder="' . esc_attr( (string) $campo['exemplo'] ) . '"' : '' )
				. ( ! empty( $campo['numeros'] ) ? ' inputmode="numeric"' : '' )
				. $desc . '>';
			break;

		case 'texto':
			echo '<textarea class="large-text" rows="3" id="' . esc_attr( $id ) . '" name="' . esc_attr( $nome ) . '" maxlength="' . esc_attr( (string) (int) $campo['max'] ) . '"' . $desc . '>' . esc_textarea( (string) $valor ) . '</textarea>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $desc montado com esc_attr.
			break;

		case 'selecao':
			echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $nome ) . '"' . $desc . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $desc montado com esc_attr.
			foreach ( rastro_config_opcoes( $campo ) as $opcao => $rotulo ) {
				echo '<option value="' . esc_attr( (string) $opcao ) . '"' . selected( (string) $valor, (string) $opcao, false ) . '>' . esc_html( (string) $rotulo ) . '</option>';
			}
			echo '</select>';
			break;

		case 'booleano':
			echo '<label for="' . esc_attr( $id ) . '"><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $nome ) . '" value="1"' . checked( '1', (string) $valor, false ) . $desc . '> ' . esc_html( isset( $campo['marca'] ) ? (string) $campo['marca'] : (string) $campo['rotulo'] ) . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $desc montado com esc_attr.
			break;

		case 'inteiro':
			echo '<input type="number" class="small-text" step="1" id="' . esc_attr( $id ) . '" name="' . esc_attr( $nome ) . '" value="' . esc_attr( (string) $valor ) . '" min="' . esc_attr( (string) (int) $campo['min'] ) . '" max="' . esc_attr( (string) (int) $campo['max'] ) . '"' . $desc . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $desc montado com esc_attr.
			break;

		case 'lista':
			$marcados = is_array( $valor ) ? $valor : array();
			echo '<fieldset id="' . esc_attr( $id ) . '"' . $desc . '><legend class="screen-reader-text">' . esc_html( (string) $campo['rotulo'] ) . '</legend>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $desc montado com esc_attr.
			foreach ( rastro_config_opcoes( $campo ) as $opcao => $rotulo ) {
				echo '<label class="rastro-opcao"><input type="checkbox" name="' . esc_attr( $nome . '[]' ) . '" value="' . esc_attr( (string) $opcao ) . '"' . checked( in_array( (string) $opcao, $marcados, true ), true, false ) . '> ' . esc_html( (string) $rotulo ) . '</label>';
			}
			echo '</fieldset>';
			break;
	}

	if ( $ajuda ) {
		echo '<p class="description" id="' . esc_attr( $id . '-ajuda' ) . '">' . esc_html( (string) $campo['ajuda'] ) . '</p>';
	}
}

/**
 * Conteúdo das abas do esquema: formulário com os campos da aba (e a caixa
 * "Situação" na aba Geral).
 *
 * @param string $aba Chave da aba.
 * @return void
 */
function rastro_admin_aba_campos( string $aba = 'geral' ): void {
	if ( ! current_user_can( RASTRO_ADMIN_CAPACIDADE ) ) {
		echo '<p>' . esc_html__( 'Sem permissão.', 'rastro' ) . '</p>';
		return;
	}

	if ( 'geral' === $aba ) {
		rastro_admin_situacao_imprimir();
	}

	$campos = rastro_admin_campos_da_aba( $aba );
	if ( array() === $campos ) {
		echo '<p>' . esc_html__( 'Nada para configurar nesta aba.', 'rastro' ) . '</p>';
		return;
	}

	$config = rastro_config_guardada();

	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="rastro-form">';
	echo '<input type="hidden" name="action" value="' . esc_attr( RASTRO_ADMIN_ACAO ) . '">';
	echo '<input type="hidden" name="aba" value="' . esc_attr( $aba ) . '">';
	wp_nonce_field( RASTRO_ADMIN_ACAO );
	echo '<table class="form-table" role="presentation"><tbody>';
	foreach ( $campos as $chave => $campo ) {
		$id = 'rastro-campo-' . str_replace( '_', '-', $chave );
		echo '<tr><th scope="row">';
		if ( in_array( $campo['tipo'], array( 'booleano', 'lista' ), true ) ) {
			echo esc_html( (string) $campo['rotulo'] );
		} else {
			echo '<label for="' . esc_attr( $id ) . '">' . esc_html( (string) $campo['rotulo'] ) . '</label>';
		}
		echo '</th><td>';
		rastro_admin_campo( $chave, $campo, $config[ $chave ] ?? $campo['padrao'] );
		echo '</td></tr>';
	}
	echo '</tbody></table>';
	submit_button( __( 'Salvar alterações', 'rastro' ) );
	echo '</form>';
}

/* Salvar -------------------------------------------------------------------- */

/**
 * Regra do salvar: separa os campos da aba no envio e grava. Liga e
 * desliga ausente vira '0'; lista ausente volta ao padrão; campos de outras
 * abas não mudam. Quem chama confere capacidade e nonce.
 *
 * @param string $aba    Chave da aba (do esquema).
 * @param array  $postado Valores enviados (já sem barras), chave => valor.
 * @return array{config:array,erros:array<string,string>}|null null para aba que não é do esquema.
 */
function rastro_admin_salvar_dados( string $aba, array $postado ): ?array {
	if ( ! isset( rastro_admin_abas_esquema()[ $aba ] ) ) {
		return null;
	}

	$bruto = array();
	foreach ( rastro_admin_campos_da_aba( $aba ) as $chave => $campo ) {
		$enviado = array_key_exists( $chave, $postado ) ? $postado[ $chave ] : null;
		switch ( $campo['tipo'] ) {
			case 'booleano':
				$bruto[ $chave ] = '1' === $enviado ? '1' : '0';
				break;
			case 'lista':
				$bruto[ $chave ] = is_array( $enviado ) ? array_values( array_filter( $enviado, 'is_string' ) ) : array();
				break;
			default:
				// Sem sanitize_text_field: ele apagaria a tag de verificação colada.
				// A validação do esquema é a regra (lista fechada ou regex).
				$bruto[ $chave ] = is_string( $enviado ) ? $enviado : '';
		}
	}

	return rastro_config_salvar( $bruto );
}

/** admin-post `rastro_salvar`: capacidade, nonce, gravação e PRG. */
function rastro_admin_salvar(): void {
	if ( ! current_user_can( RASTRO_ADMIN_CAPACIDADE ) ) {
		wp_die( esc_html__( 'Sem permissão.', 'rastro' ), '', array( 'response' => 403 ) );
	}

	check_admin_referer( RASTRO_ADMIN_ACAO );

	$aba     = isset( $_POST['aba'] ) && is_string( $_POST['aba'] ) ? sanitize_key( wp_unslash( $_POST['aba'] ) ) : '';
	$postado = isset( $_POST['rastro'] ) && is_array( $_POST['rastro'] ) ? wp_unslash( $_POST['rastro'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cada campo passa pela validação do esquema.

	$resultado = rastro_admin_salvar_dados( $aba, is_array( $postado ) ? $postado : array() );
	if ( null === $resultado ) {
		wp_safe_redirect( rastro_admin_url() );
		exit;
	}

	$aviso = 'salvo';
	if ( array() !== $resultado['erros'] ) {
		set_transient( rastro_admin_chave_erros(), $resultado['erros'], 5 * MINUTE_IN_SECONDS );
		$aviso = 'erro';
	}

	wp_safe_redirect( rastro_admin_url( $aba, array( 'rastro_aviso' => $aviso ) ) );
	exit;
}
add_action( 'admin_post_' . RASTRO_ADMIN_ACAO, 'rastro_admin_salvar' );
