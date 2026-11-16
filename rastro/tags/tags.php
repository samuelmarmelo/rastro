<?php
/**
 * Rastro: tags e desempenho (contrato, seção 5; manual docs/TAGS.md).
 *
 * - rastro_destinos(): cada destino (gtm, ga4, ads, meta, tiktok, openai,
 *   posthog) com a finalidade, se está ativo e a configuração para o
 *   navegador. A finalidade é fixa (o filtro `rastro_destinos` só liga,
 *   desliga ou troca o ID, que é validado de novo).
 * - `tags` em window.rastroConfig (filtro `rastro_config_navegador`) e o
 *   assets/js/tags.js, que espera o consentimento e carrega cada destino
 *   aceito uma vez, fora do caminho crítico.
 * - Meta de verificação do Google no <head> das páginas do site (não é
 *   cookie nem script: não depende de consentimento).
 * - Avisos do painel: GTM junto com tags diretas e outros plugins de
 *   medição ativos (risco de contar a mesma visita duas vezes).
 *
 * Nada de terceiro sai no HTML: o servidor só imprime IDs dentro do JSON
 * da configuração (rastro_json(), com JSON_HEX_*). Os carregadores rodam no
 * navegador, depois do aceite.
 *
 * @package Rastro
 */

defined( 'ABSPATH' ) || exit;

/** Data dos padrões do PostHog (opção `defaults`, conferida na documentação em 2026-11-11). */
const RASTRO_TAGS_POSTHOG_PADROES = '2026-08-30';

/* Regras puras ------------------------------------------------------------- */

/**
 * Finalidades de cada destino (fixas): a finalidade declarada e com quais
 * aceites ele carrega. O GTM é de publicidade e carrega só com a
 * publicidade aceita (LGPD): o contêiner pode ter tags que não respeitam o
 * Consent Mode, então só a análise aceita não basta.
 *
 * @return array<string,array{finalidade:string,carrega_com:string[]}>
 */
function rastro_tags_finalidades(): array {
	return array(
		'gtm'     => array(
			'finalidade'  => 'publicidade',
			'carrega_com' => array( 'publicidade' ),
		),
		'ga4'     => array(
			'finalidade'  => 'analise',
			'carrega_com' => array( 'analise' ),
		),
		'ads'     => array(
			'finalidade'  => 'publicidade',
			'carrega_com' => array( 'publicidade' ),
		),
		'meta'    => array(
			'finalidade'  => 'publicidade',
			'carrega_com' => array( 'publicidade' ),
		),
		'tiktok'  => array(
			'finalidade'  => 'publicidade',
			'carrega_com' => array( 'publicidade' ),
		),
		'openai'  => array(
			'finalidade'  => 'publicidade',
			'carrega_com' => array( 'publicidade' ),
		),
		'posthog' => array(
			'finalidade'  => 'analise',
			'carrega_com' => array( 'analise' ),
		),
	);
}

/**
 * Endereço da API do PostHog pela região (lista fechada; fora dela, us).
 *
 * @param string $regiao us ou eu.
 * @return string
 */
function rastro_tags_posthog_host( string $regiao ): string {
	return 'eu' === $regiao ? 'https://eu.i.posthog.com' : 'https://us.i.posthog.com';
}

/**
 * Valida um ID com a regra do esquema da base ('' quando vazio ou inválido).
 *
 * @param string $chave Chave do esquema (gtm, ga4, ads_rotulo_compra...).
 * @param mixed  $bruto Valor.
 * @return string
 */
function rastro_tags_id( string $chave, $bruto ): string {
	$limpo = rastro_config_validar_campo( $chave, $bruto );

	return is_string( $limpo ) ? $limpo : '';
}

/**
 * Configuração para o navegador de um destino com ID válido. Só IDs e
 * opções fixas, nunca dado pessoal.
 *
 * @param string $destino Chave do destino.
 * @param string $id      ID já validado.
 * @param array  $config  Configuração do Rastro (rastro_config()).
 * @param array  $pagina  privada (bool), url (endereço saneado) e campanha
 *                        (endereço saneado com as chaves de campanha, só em
 *                        página pública).
 * @return array<string,mixed>
 */
function rastro_tags_navegador_destino( string $destino, string $id, array $config, array $pagina ): array {
	$privada = ! empty( $pagina['privada'] );
	$url     = isset( $pagina['url'] ) && is_string( $pagina['url'] ) ? $pagina['url'] : '';

	switch ( $destino ) {
		case 'ga4':
			$saida = array(
				'id'             => $id,
				'send_page_view' => true,
			);
			// Página pública: utm_* e IDs de clique ficam (atribuição da campanha).
			if ( ! $privada && isset( $pagina['campanha'] ) && is_string( $pagina['campanha'] ) && 1 === preg_match( '#^https?://#i', $pagina['campanha'] ) ) {
				$url = $pagina['campanha'];
			}
			// O GA4 pede o endereço completo, com o protocolo.
			if ( 1 === preg_match( '#^https?://#i', $url ) ) {
				$saida['page_location'] = $url;
			}
			return $saida;

		case 'ads':
			return array(
				'id'            => $id,
				'rotulo_compra' => rastro_tags_id( 'ads_rotulo_compra', isset( $config['ads_rotulo_compra'] ) ? $config['ads_rotulo_compra'] : '' ),
			);

		case 'openai':
			return array(
				'id'    => $id,
				'debug' => isset( $config['openai_debug'] ) && '1' === $config['openai_debug'],
			);

		case 'posthog':
			$regiao  = isset( $config['posthog_regiao'] ) && 'eu' === $config['posthog_regiao'] ? 'eu' : 'us';
			$opcoes  = array(
				'api_host'          => rastro_tags_posthog_host( $regiao ),
				'defaults'          => RASTRO_TAGS_POSTHOG_PADROES,
				'person_profiles'   => 'identified_only',
				// Se a gravação estiver ligada no projeto, os campos saem mascarados.
				'session_recording' => array( 'maskAllInputs' => true ),
			);
			if ( $privada ) {
				// Página privada: sem replay de sessão e sem captura automática de cliques e formulários.
				$opcoes['disable_session_recording'] = true;
				$opcoes['autocapture']               = false;
			}
			return array(
				'id'     => $id,
				'config' => $opcoes,
			);
	}

	return array( 'id' => $id );
}

/**
 * Regra pura: monta os destinos a partir da configuração e da página.
 *
 * @param array $config Configuração do Rastro (já validada).
 * @param array $pagina privada (bool) e url (endereço saneado).
 * @return array<string,array{finalidade:string,carrega_com:string[],ativo:bool,id:string,navegador:array}>
 */
function rastro_destinos_montar( array $config, array $pagina ): array {
	$destinos = array();
	foreach ( rastro_tags_finalidades() as $destino => $finalidade ) {
		$id                   = rastro_tags_id( $destino, isset( $config[ $destino ] ) ? $config[ $destino ] : '' );
		$destinos[ $destino ] = array(
			'finalidade'  => $finalidade['finalidade'],
			'carrega_com' => $finalidade['carrega_com'],
			'ativo'       => '' !== $id,
			'id'          => $id,
			'navegador'   => '' !== $id ? rastro_tags_navegador_destino( $destino, $id, $config, $pagina ) : array(),
		);
	}

	return $destinos;
}

/**
 * Regra pura: aplica o resultado do filtro `rastro_destinos` sobre os
 * destinos montados. Só `ativo` e `id` mudam; o ID passa de novo pela
 * validação e um destino só fica ativo com ID válido. Finalidade e
 * configuração do navegador são sempre as do Rastro.
 *
 * @param array $destinos Saída de rastro_destinos_montar().
 * @param mixed $filtrado Resultado do filtro.
 * @param array $config   Configuração do Rastro.
 * @param array $pagina   privada e url.
 * @return array
 */
function rastro_destinos_aplicar_filtro( array $destinos, $filtrado, array $config, array $pagina ): array {
	if ( ! is_array( $filtrado ) ) {
		return $destinos;
	}

	foreach ( $destinos as $destino => $dados ) {
		if ( ! isset( $filtrado[ $destino ] ) || ! is_array( $filtrado[ $destino ] ) ) {
			continue;
		}
		$novo = $filtrado[ $destino ];
		$id   = array_key_exists( 'id', $novo ) ? rastro_tags_id( $destino, $novo['id'] ) : $dados['id'];
		// Sem a chave `ativo`, vale o ID; com ela, só liga com ID válido.
		$ativo = array_key_exists( 'ativo', $novo ) ? ( true === $novo['ativo'] && '' !== $id ) : '' !== $id;

		$destinos[ $destino ]['id']        = $id;
		$destinos[ $destino ]['ativo']     = $ativo;
		$destinos[ $destino ]['navegador'] = $ativo ? rastro_tags_navegador_destino( $destino, $id, $config, $pagina ) : array();
	}

	return $destinos;
}

/**
 * Regra pura: avisos do painel. GTM junto com GA4, Ads, Meta ou TikTok
 * diretos, e cada plugin de medição ativo.
 *
 * @param string[] $ativos      Destinos ativos do Rastro.
 * @param string[] $plugins     Nomes dos plugins de medição ativos.
 * @param string[] $nomes       destino => nome legível.
 * @return array<int,array{tipo:string,texto:string}>
 */
function rastro_tags_regra_avisos( array $ativos, array $plugins, array $nomes ): array {
	$avisos  = array();
	$diretos = array_values( array_intersect( array( 'ga4', 'ads', 'meta', 'tiktok' ), $ativos ) );

	if ( in_array( 'gtm', $ativos, true ) && array() !== $diretos ) {
		$lista = array();
		foreach ( $diretos as $destino ) {
			$lista[] = isset( $nomes[ $destino ] ) ? $nomes[ $destino ] : $destino;
		}
		$avisos[] = array(
			'tipo'  => 'warning',
			/* translators: %s: lista de tags diretas. */
			'texto' => sprintf( __( 'O Google Tag Manager está ligado junto com tags diretas (%s). Se o contêiner também tiver essas tags, a mesma visita conta duas vezes: deixe cada tag em um lugar só.', 'rastro' ), implode( ', ', $lista ) ),
		);
	}

	$tem_destino = array() !== $ativos;
	foreach ( $plugins as $plugin ) {
		$avisos[] = array(
			'tipo'  => $tem_destino ? 'warning' : 'info',
			'texto' => $tem_destino
				/* translators: %s: nome do plugin. */
				? sprintf( __( 'O plugin "%s" está ativo e também mede o site. Com as mesmas tags no Rastro, a mesma visita conta duas vezes. O Rastro não desativa plugin de ninguém: desative-o quando o Rastro estiver conferido.', 'rastro' ), $plugin )
				/* translators: %s: nome do plugin. */
				: sprintf( __( 'O plugin "%s" está ativo e mede o site. Antes de ligar as mesmas tags no Rastro, planeje desativá-lo, senão a mesma visita conta duas vezes.', 'rastro' ), $plugin ),
		);
	}

	return $avisos;
}

/**
 * Regra pura: HTML da meta de verificação do Google ('' para valor
 * inválido). O valor passa de novo pela validação do esquema.
 *
 * @param mixed $valor Valor guardado.
 * @return string
 */
function rastro_tags_meta_html( $valor ): string {
	$limpo = rastro_tags_id( 'google_verificacao', $valor );
	if ( '' === $limpo ) {
		return '';
	}

	return '<meta name="google-site-verification" content="' . esc_attr( $limpo ) . '" />' . "\n";
}

/**
 * Regra pura: HTML da política de referrer das páginas privadas. Com
 * `strict-origin`, a próxima página (e cada script de terceiro) recebe só a
 * origem do site no referrer, nunca o caminho com o número ou a chave do
 * pedido. Página pública ou site sem destino: nada.
 *
 * @param bool $privada     Página privada?
 * @param bool $tem_destino O Rastro tem algum destino configurado?
 * @return string
 */
function rastro_tags_meta_referrer_html( bool $privada, bool $tem_destino ): string {
	return $privada && $tem_destino ? '<meta name="referrer" content="strict-origin" />' . "\n" : '';
}

/* Leitura ------------------------------------------------------------------ */

/**
 * Destinos desta página, depois do filtro `rastro_destinos`.
 *
 * @return array<string,array{finalidade:string,carrega_com:string[],ativo:bool,id:string,navegador:array}>
 */
function rastro_destinos(): array {
	$config = rastro_config();
	$pagina = array(
		'privada'  => rastro_pagina_privada(),
		'url'      => rastro_url_limpa(),
		'campanha' => rastro_url_campanha(),
	);

	$destinos = rastro_destinos_montar( $config, $pagina );

	/**
	 * Filtra os destinos (ex.: a ponte de um site desliga o PostHog do
	 * Rastro quando o site já carrega o seu). Só `ativo` e `id` valem; o ID é
	 * validado de novo e a finalidade não muda.
	 *
	 * @param array $destinos destino => finalidade, carrega_com, ativo, id, navegador.
	 */
	$filtrado = apply_filters( 'rastro_destinos', $destinos );

	return rastro_destinos_aplicar_filtro( $destinos, $filtrado, $config, $pagina );
}

/**
 * Chaves dos destinos ativos nesta página.
 *
 * @return string[]
 */
function rastro_destinos_ativos(): array {
	$ativos = array();
	foreach ( rastro_destinos() as $destino => $dados ) {
		if ( $dados['ativo'] ) {
			$ativos[] = $destino;
		}
	}

	return $ativos;
}

/**
 * Chave `tags` de window.rastroConfig: só os destinos ativos, com o que o
 * navegador precisa para carregar cada um.
 *
 * @return array{destinos:array<string,array>}
 */
function rastro_tags_config_navegador(): array {
	$saida = array();
	foreach ( rastro_destinos() as $destino => $dados ) {
		if ( ! $dados['ativo'] ) {
			continue;
		}
		$saida[ $destino ] = array_merge(
			array( 'carrega_com' => $dados['carrega_com'] ),
			$dados['navegador']
		);
	}

	return array( 'destinos' => (object) $saida );
}

/**
 * Filtro `rastro_config_navegador`: acrescenta `tags` quando a página mede
 * e o tags.js está na página (a configuração só viaja com o script que a
 * lê). No WordPress, o `wp_enqueue_scripts` roda dentro do `wp_head` na
 * prioridade 1, antes do `<script id="rastro-config">`.
 *
 * @param mixed $config Configuração para o navegador.
 * @return mixed
 */
function rastro_tags_navegador( $config ) {
	if ( ! is_array( $config ) ) {
		return $config;
	}
	try {
		if ( rastro_deve_medir() && function_exists( 'wp_script_is' ) && wp_script_is( 'rastro-tags', 'enqueued' ) ) {
			$config['tags'] = rastro_tags_config_navegador();
		}
	} catch ( \Throwable $erro ) {
		do_action( 'rastro_falha', 'tags-navegador', $erro );
	}

	return $config;
}
add_filter( 'rastro_config_navegador', 'rastro_tags_navegador', 10, 1 );

/**
 * wp_enqueue_scripts (prioridade 20, depois do consentimento): tags.js só
 * quando a página mede e há destino ativo. Depende do script de
 * consentimento quando ele está registrado.
 *
 * @return void
 */
function rastro_tags_enfileirar(): void {
	try {
		if ( ! rastro_deve_medir() || array() === rastro_destinos_ativos() ) {
			return;
		}
		$deps = function_exists( 'wp_script_is' ) && wp_script_is( 'rastro-consent', 'registered' ) ? array( 'rastro-consent' ) : array();
		if ( rastro_registrar_script( 'rastro-tags', 'tags.js', $deps ) ) {
			wp_enqueue_script( 'rastro-tags' );
		}
	} catch ( \Throwable $erro ) {
		do_action( 'rastro_falha', 'tags-script', $erro );
	}
}
add_action( 'wp_enqueue_scripts', 'rastro_tags_enfileirar', 20 );

/* Verificação do Google ------------------------------------------------------ */

/**
 * Valor válido da verificação do Google ('' sem valor).
 *
 * @return string
 */
function rastro_google_verificacao(): string {
	return rastro_tags_id( 'google_verificacao', rastro_config_campo( 'google_verificacao' ) );
}

/**
 * wp_head: meta de verificação do Google nas páginas do site. Não depende
 * de consentimento nem da medição; fica fora do painel, do login e das
 * outras requisições restritas.
 *
 * @return void
 */
function rastro_tags_meta_verificacao(): void {
	try {
		if ( rastro_requisicao_restrita() ) {
			return;
		}
		echo rastro_tags_meta_html( rastro_google_verificacao() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- valor validado e escapado em rastro_tags_meta_html().
	} catch ( \Throwable $erro ) {
		do_action( 'rastro_falha', 'tags-verificacao', $erro );
	}
}
add_action( 'wp_head', 'rastro_tags_meta_verificacao', 1 );

/**
 * wp_head: `<meta name="referrer" content="strict-origin">` nas páginas
 * privadas (conta, checkout, pedido recebido, pagar pedido...). Depende só
 * do tipo da página e da configuração, nunca do visitante (cache de página
 * seguro); fica fora das requisições restritas.
 *
 * @return void
 */
function rastro_tags_meta_referrer(): void {
	try {
		if ( rastro_requisicao_restrita() ) {
			return;
		}
		echo rastro_tags_meta_referrer_html( rastro_pagina_privada(), rastro_tem_destino() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- texto fixo.
	} catch ( \Throwable $erro ) {
		do_action( 'rastro_falha', 'tags-referrer', $erro );
	}
}
add_action( 'wp_head', 'rastro_tags_meta_referrer', 1 );

/* Avisos do painel -------------------------------------------------------------- */

/**
 * Plugins de medição conhecidos: nome => arquivo principal, constantes e
 * classes (nomes conferidos no código de cada um; ver docs/TAGS.md).
 * Filtro `rastro_tags_plugins_conhecidos`.
 *
 * @return array<string,array{arquivos:string[],constantes:string[],classes:string[]}>
 */
function rastro_tags_plugins_conhecidos(): array {
	$lista = array(
		'PixelYourSite'                => array(
			'arquivos'   => array( 'pixelyoursite/facebook-pixel-master.php' ),
			'constantes' => array( 'PYS_FREE_VERSION' ),
			'classes'    => array(),
		),
		'PixelYourSite Pro'            => array(
			'arquivos'   => array( 'pixelyoursite-pro/pixelyoursite-pro.php' ),
			'constantes' => array( 'PYS_VERSION' ),
			'classes'    => array(),
		),
		'Google Analytics for WooCommerce' => array(
			'arquivos'   => array( 'woocommerce-google-analytics-integration/woocommerce-google-analytics-integration.php' ),
			'constantes' => array( 'WC_GOOGLE_ANALYTICS_INTEGRATION_VERSION' ),
			'classes'    => array( 'WC_Google_Analytics_Integration' ),
		),
		'Site Kit by Google'           => array(
			'arquivos'   => array( 'google-site-kit/google-site-kit.php' ),
			'constantes' => array( 'GOOGLESITEKIT_VERSION' ),
			'classes'    => array(),
		),
		'GTM4WP'                       => array(
			'arquivos'   => array( 'duracelltomi-google-tag-manager/duracelltomi-google-tag-manager-for-wordpress.php' ),
			'constantes' => array( 'GTM4WP_VERSION' ),
			'classes'    => array(),
		),
		'MonsterInsights'              => array(
			'arquivos'   => array( 'google-analytics-for-wordpress/googleanalytics.php' ),
			'constantes' => array( 'MONSTERINSIGHTS_VERSION' ),
			'classes'    => array( 'MonsterInsights_Lite' ),
		),
		'Meta for WooCommerce'         => array(
			'arquivos'   => array( 'facebook-for-woocommerce/facebook-for-woocommerce.php' ),
			'constantes' => array(),
			'classes'    => array( 'WC_Facebook_Loader' ),
		),
		'TikTok'                       => array(
			'arquivos'   => array( 'tiktok-for-business/tiktok-for-woocommerce.php' ),
			'constantes' => array(),
			'classes'    => array(),
		),
		'Google for WooCommerce'       => array(
			'arquivos'   => array( 'google-listings-and-ads/google-listings-and-ads.php' ),
			'constantes' => array( 'WC_GLA_VERSION' ),
			'classes'    => array(),
		),
	);

	/**
	 * Filtra os plugins de medição que o painel reconhece.
	 *
	 * @param array $lista nome => arquivos, constantes, classes.
	 */
	$filtrada = apply_filters( 'rastro_tags_plugins_conhecidos', $lista );

	return is_array( $filtrada ) ? $filtrada : $lista;
}

/**
 * Nomes dos plugins de medição ativos (pelo arquivo principal na lista de
 * plugins ativos, pela constante ou pela classe).
 *
 * @return string[]
 */
function rastro_tags_plugins_ativos(): array {
	$ativos = get_option( 'active_plugins', array() );
	$ativos = is_array( $ativos ) ? array_values( array_filter( $ativos, 'is_string' ) ) : array();
	if ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_site_option' ) ) {
		$rede = get_site_option( 'active_sitewide_plugins', array() );
		if ( is_array( $rede ) ) {
			$ativos = array_merge( $ativos, array_keys( $rede ) );
		}
	}

	$nomes = array();
	foreach ( rastro_tags_plugins_conhecidos() as $nome => $sinais ) {
		if ( ! is_string( $nome ) || ! is_array( $sinais ) ) {
			continue;
		}
		$achou = false;
		foreach ( isset( $sinais['arquivos'] ) ? (array) $sinais['arquivos'] : array() as $arquivo ) {
			$achou = $achou || in_array( $arquivo, $ativos, true );
		}
		foreach ( isset( $sinais['constantes'] ) ? (array) $sinais['constantes'] : array() as $constante ) {
			$achou = $achou || ( is_string( $constante ) && defined( $constante ) );
		}
		foreach ( isset( $sinais['classes'] ) ? (array) $sinais['classes'] : array() as $classe ) {
			$achou = $achou || ( is_string( $classe ) && class_exists( $classe, false ) );
		}
		if ( $achou ) {
			$nomes[] = $nome;
		}
	}

	return $nomes;
}

/**
 * Filtro `rastro_admin_avisos`: risco de contar a mesma visita duas vezes.
 *
 * @param mixed $avisos Avisos recebidos.
 * @return mixed
 */
function rastro_tags_avisos( $avisos ) {
	if ( ! is_array( $avisos ) ) {
		return $avisos;
	}
	try {
		$ativos = array();
		foreach ( rastro_destinos_montar( rastro_config(), array() ) as $destino => $dados ) {
			if ( $dados['ativo'] ) {
				$ativos[] = $destino;
			}
		}
		$nomes = array(
			'ga4'    => __( 'Google Analytics 4', 'rastro' ),
			'ads'    => __( 'Google Ads', 'rastro' ),
			'meta'   => __( 'Meta Pixel', 'rastro' ),
			'tiktok' => __( 'TikTok Pixel', 'rastro' ),
		);
		foreach ( rastro_tags_regra_avisos( $ativos, rastro_tags_plugins_ativos(), $nomes ) as $aviso ) {
			$avisos[] = $aviso;
		}
	} catch ( \Throwable $erro ) {
		do_action( 'rastro_falha', 'tags-avisos', $erro );
	}

	return $avisos;
}
add_filter( 'rastro_admin_avisos', 'rastro_tags_avisos', 10, 1 );
