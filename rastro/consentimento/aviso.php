<?php
/**
 * Rastro: aviso de cookies próprio e botão "Preferências de cookies"
 * (contrato, seções 2.1 e 4; manual docs/CONSENTIMENTO.md).
 *
 * - Região no fim do <body>, escondida no HTML (`hidden`); o consent.js
 *   mostra quando não há escolha. HTML igual para todo visitante.
 * - Botões Recusar, Personalizar e Aceitar com a mesma classe (mesmo tamanho
 *   e destaque). Personalizar mostra só as finalidades com destino, com as
 *   caixas desmarcadas, e "Salvar escolhas".
 * - Botão de preferências: shortcode [rastro_preferencias],
 *   rastro_aviso_botao_preferencias() e o botão fixo discreto (filtro
 *   `rastro_aviso_botao_flutuante`, padrão só para o provedor próprio).
 *   Fora do modo básico.
 *
 * @package Rastro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Textos das finalidades no painel "Personalizar".
 *
 * @return array<string,array{titulo:string,ajuda:string}>
 */
function rastro_aviso_finalidades_textos(): array {
	return array(
		'analise'     => array(
			'titulo' => __( 'Análise', 'rastro' ),
			'ajuda'  => __( 'Mede como o site é usado, em números agregados.', 'rastro' ),
		),
		'publicidade' => array(
			'titulo' => __( 'Publicidade', 'rastro' ),
			'ajuda'  => __( 'Mede o resultado dos nossos anúncios e permite mostrar anúncios mais relevantes.', 'rastro' ),
		),
	);
}

/**
 * O botão fixo discreto de preferências aparece? Padrão: só com o provedor
 * próprio (o aviso de outro provedor costuma ter o seu lugar). Nunca no
 * modo básico, nunca com a WP Consent API (o Rastro não tem como abrir o
 * aviso dela) e nunca sem provedor.
 *
 * @return bool
 */
function rastro_aviso_botao_flutuante_ativo(): bool {
	$provedor = rastro_consent_provedor();
	if ( 'basico' === rastro_consent_modo() || in_array( $provedor, array( 'nenhum', 'wp_consent_api' ), true ) ) {
		return false;
	}

	/**
	 * Mostra o botão fixo "Preferências de cookies" no canto da página.
	 *
	 * @param bool   $ativo    Padrão: provedor próprio.
	 * @param string $provedor Provedor em uso.
	 */
	return true === apply_filters( 'rastro_aviso_botao_flutuante', 'proprio' === $provedor, $provedor );
}

/**
 * HTML do botão "Preferências de cookies" (o consent.js liga o clique a
 * window.rastro.consent.abrir()). Vazio no modo básico, sem provedor, com a
 * WP Consent API ou quando a página não leva o consentimento.
 *
 * @param array $args {
 *     @type string $classe Classes extras.
 *     @type string $texto  Texto do botão.
 * }
 * @return string
 */
function rastro_aviso_botao_preferencias( array $args = array() ): string {
	try {
		if ( 'basico' === rastro_consent_modo() || 'wp_consent_api' === rastro_consent_provedor() || ! rastro_consent_ativo() ) {
			return '';
		}
	} catch ( \Throwable $erro ) {
		do_action( 'rastro_falha', 'aviso-botao', $erro );
		return '';
	}

	$classes = 'rastro-preferencias';
	if ( isset( $args['classe'] ) && is_string( $args['classe'] ) ) {
		$extra = trim( (string) preg_replace( '/[^A-Za-z0-9_ -]/', '', $args['classe'] ) );
		if ( '' !== $extra ) {
			$classes .= ' ' . $extra;
		}
	}
	$texto = isset( $args['texto'] ) && is_string( $args['texto'] ) && '' !== trim( $args['texto'] ) ? trim( $args['texto'] ) : __( 'Preferências de cookies', 'rastro' );

	return '<button type="button" class="' . esc_attr( $classes ) . '" data-rastro-preferencias>' . esc_html( $texto ) . '</button>';
}

/**
 * Shortcode [rastro_preferencias].
 *
 * @return string
 */
function rastro_aviso_shortcode(): string {
	return rastro_aviso_botao_preferencias();
}

/**
 * HTML do aviso próprio (vazio quando nenhuma finalidade tem destino).
 *
 * @return string
 */
function rastro_aviso_html(): string {
	$finalidades = rastro_consent_finalidades();
	if ( array() === $finalidades ) {
		return '';
	}

	$texto = rastro_config_campo( 'aviso_texto' );
	if ( ! is_string( $texto ) || '' === trim( $texto ) ) {
		$texto = __( 'Usamos cookies para medir o uso do site e os nossos anúncios, só com a sua permissão.', 'rastro' );
	}
	$politica = function_exists( 'get_privacy_policy_url' ) ? (string) get_privacy_policy_url() : '';

	$html  = '<div class="rastro-aviso" id="rastro-aviso" data-rastro-aviso hidden role="region" aria-label="' . esc_attr__( 'Aviso de cookies', 'rastro' ) . '">';
	$html .= '<div class="rastro-aviso__caixa">';
	$html .= '<p class="rastro-aviso__texto" id="rastro-aviso-texto">' . esc_html( $texto );
	if ( '' !== $politica && '' !== esc_url( $politica ) ) {
		$html .= ' <a class="rastro-aviso__link" href="' . esc_url( $politica ) . '">' . esc_html__( 'Política de privacidade', 'rastro' ) . '</a>';
	}
	$html .= '</p>';

	$html  .= '<div class="rastro-aviso__preferencias" id="rastro-aviso-preferencias" data-rastro-aviso-preferencias hidden>';
	$html  .= '<fieldset class="rastro-aviso__grupo"><legend class="rastro-aviso__legenda">' . esc_html__( 'Escolha o que permitir', 'rastro' ) . '</legend>';
	$textos = rastro_aviso_finalidades_textos();
	foreach ( $finalidades as $finalidade ) {
		$id    = 'rastro-aviso-' . $finalidade;
		$html .= '<div class="rastro-aviso__opcao">';
		$html .= '<input type="checkbox" class="rastro-aviso__caixa-marcar" id="' . esc_attr( $id ) . '" data-rastro-finalidade="' . esc_attr( $finalidade ) . '" aria-describedby="' . esc_attr( $id . '-ajuda' ) . '">';
		$html .= ' <label for="' . esc_attr( $id ) . '">' . esc_html( $textos[ $finalidade ]['titulo'] ) . '</label>';
		$html .= '<p class="rastro-aviso__ajuda" id="' . esc_attr( $id . '-ajuda' ) . '">' . esc_html( $textos[ $finalidade ]['ajuda'] ) . '</p>';
		$html .= '</div>';
	}
	$html .= '</fieldset>';
	$html .= '<button type="button" class="rastro-aviso__botao" data-rastro-aviso-salvar>' . esc_html__( 'Salvar escolhas', 'rastro' ) . '</button>';
	$html .= '</div>';

	$html .= '<div class="rastro-aviso__acoes">';
	$html .= '<button type="button" class="rastro-aviso__botao" data-rastro-aviso-recusar>' . esc_html__( 'Recusar', 'rastro' ) . '</button>';
	$html .= '<button type="button" class="rastro-aviso__botao" data-rastro-aviso-personalizar aria-expanded="false" aria-controls="rastro-aviso-preferencias">' . esc_html__( 'Personalizar', 'rastro' ) . '</button>';
	$html .= '<button type="button" class="rastro-aviso__botao" data-rastro-aviso-aceitar>' . esc_html__( 'Aceitar', 'rastro' ) . '</button>';
	$html .= '</div>';

	$html .= '</div></div>';

	return $html;
}

/**
 * HTML do botão fixo (escondido; o consent.js mostra quando o aviso está
 * fechado).
 *
 * @return string
 */
function rastro_aviso_flutuante_html(): string {
	return '<button type="button" class="rastro-preferencias rastro-preferencias--flutuante" data-rastro-preferencias data-rastro-flutuante hidden>' . esc_html__( 'Preferências de cookies', 'rastro' ) . '</button>';
}

/**
 * wp_footer: o aviso próprio e o botão fixo, só quando a página leva o
 * consentimento.
 *
 * @return void
 */
function rastro_aviso_imprimir(): void {
	try {
		if ( ! rastro_consent_ativo() ) {
			return;
		}
		$html = rastro_consent_aviso_proprio() ? rastro_aviso_html() : '';
		if ( rastro_aviso_botao_flutuante_ativo() ) {
			$html .= rastro_aviso_flutuante_html();
		}
		if ( '' !== $html ) {
			echo $html . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- montado com esc_html, esc_attr e esc_url acima.
		}
	} catch ( \Throwable $erro ) {
		do_action( 'rastro_falha', 'aviso', $erro );
	}
}

/**
 * Liga a parte pública do aviso no gancho `wp`.
 *
 * @return void
 */
function rastro_aviso_iniciar(): void {
	add_action( 'wp_footer', 'rastro_aviso_imprimir', 20 );
}
add_action( 'wp', 'rastro_aviso_iniciar', 1 );

/** Registra o shortcode [rastro_preferencias]. */
function rastro_aviso_registrar_shortcode(): void {
	add_shortcode( 'rastro_preferencias', 'rastro_aviso_shortcode' );
}
if ( did_action( 'init' ) ) {
	rastro_aviso_registrar_shortcode();
} else {
	add_action( 'init', 'rastro_aviso_registrar_shortcode' );
}
