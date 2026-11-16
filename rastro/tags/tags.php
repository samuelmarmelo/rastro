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

		case 'tiktok':
			return rastro_tags_tiktok_completo( $config, $privada ) ? array(
				'id'        => $id,
				'cabecalho' => 'completo',
			) : array( 'id' => $id );

		case 'meta':
			$cabecalho = rastro_tags_meta_tipo( $config, $privada );
			return '' === $cabecalho ? array( 'id' => $id ) : array(
				'id'        => $id,
				'cabecalho' => $cabecalho,
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
			// Logs do PostHog (módulo logs/logs.php; ausente, nada muda).
			if ( function_exists( 'rastro_logs_navegador' ) ) {
				$logs = rastro_logs_navegador( $config, function_exists( 'wp_get_environment_type' ) ? (string) wp_get_environment_type() : '', defined( 'RASTRO_VERSAO' ) ? (string) RASTRO_VERSAO : '' );
				if ( array() !== $logs ) {
					$opcoes['logs'] = $logs;
				}
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
 * Metas de verificação de domínio: campo do esquema => atributo `name` da
 * meta (nomes conferidos na documentação de cada serviço; docs/TAGS.md,
 * seção Verificações). Não são cookie nem script: não dependem de
 * consentimento.
 *
 * @return array<string,string>
 */
function rastro_tags_verificacoes(): array {
	return array(
		'google_verificacao'    => 'google-site-verification',
		'facebook_verificacao'  => 'facebook-domain-verification',
		'pinterest_verificacao' => 'p:domain_verify',
	);
}

/**
 * Regra pura: HTML de uma meta de verificação ('' para campo desconhecido
 * ou valor inválido). O valor passa de novo pela validação do esquema.
 *
 * @param string $chave Campo do esquema (rastro_tags_verificacoes()).
 * @param mixed  $valor Valor guardado.
 * @return string
 */
function rastro_tags_verificacao_html( string $chave, $valor ): string {
	$nomes = rastro_tags_verificacoes();
	if ( ! isset( $nomes[ $chave ] ) ) {
		return '';
	}
	$limpo = rastro_tags_id( $chave, $valor );
	if ( '' === $limpo ) {
		return '';
	}

	return '<meta name="' . esc_attr( $nomes[ $chave ] ) . '" content="' . esc_attr( $limpo ) . '" />' . "\n";
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

	return rastro_tags_verificacao_html( 'google_verificacao', $limpo );
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

/**
 * Regra pura: como o código base do Meta Pixel vai no <head> (contrato,
 * seção 5.1; docs/TAGS.md). '' = não vai (campo desligado); 'fila' = só a
 * fila fbq oficial, sem pedido à Meta (o fbevents.js carrega pelo tags.js
 * depois do aceite); 'completo' = o código oficial inteiro, com o
 * fbevents.js, só no modo básico (sem consentimento a pedir) e em página
 * pública (a página privada espera o tags.js sanear o endereço).
 *
 * @param array $config  Configuração do Rastro.
 * @param bool  $privada Página privada?
 * @return string '', 'fila' ou 'completo'.
 */
function rastro_tags_meta_tipo( array $config, bool $privada ): string {
	if ( ! isset( $config['meta_cabecalho'] ) || '1' !== $config['meta_cabecalho'] ) {
		return '';
	}

	return ! $privada && isset( $config['modo'] ) && 'basico' === $config['modo'] ? 'completo' : 'fila';
}

/**
 * Regra pura: o JavaScript do código base do Meta Pixel para o <head>
 * (trecho oficial da Meta, conferido em 2026-11-16 em
 * developers.facebook.com/docs/meta-pixel/get-started). Na `fila`, o mesmo
 * trecho sem a inserção do fbevents.js. Sem configuração automática
 * (autoConfig desligado, antes do init), como o tags.js já fazia.
 *
 * @param string $id   ID do Meta Pixel (validado de novo aqui).
 * @param string $tipo fila ou completo.
 * @return string '' para ID ou tipo inválido.
 */
function rastro_tags_meta_cabecalho_js( string $id, string $tipo ): string {
	$id = rastro_tags_id( 'meta', $id );
	if ( '' === $id || ! in_array( $tipo, array( 'fila', 'completo' ), true ) ) {
		return '';
	}

	$fila = "n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];";
	if ( 'completo' === $tipo ) {
		$js = '!function(f,b,e,v,n,t,s){if(f.fbq)return;' . $fila . "t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');";
	} else {
		$js = '!function(f,n){if(f.fbq)return;' . $fila . '}(window);';
	}
	$json = rastro_json( $id );

	return $js . "fbq('set','autoConfig',false," . $json . ");fbq('init'," . $json . ");fbq('track','PageView');";
}

/**
 * Regra pura: o <noscript> oficial do Meta Pixel (imagem de 1 pixel com o
 * PageView), só com o código `completo` (modo básico, página pública): sem
 * JavaScript não há como pedir consentimento, então nos outros modos ele
 * nunca sai.
 *
 * @param string $id   ID do Meta Pixel.
 * @param string $tipo Saída de rastro_tags_meta_tipo().
 * @return string
 */
function rastro_tags_meta_noscript_html( string $id, string $tipo ): string {
	$id = rastro_tags_id( 'meta', $id );
	if ( '' === $id || 'completo' !== $tipo ) {
		return '';
	}
	$src = 'https://www.facebook.com/tr?id=' . rawurlencode( $id ) . '&ev=PageView&noscript=1';

	return '<noscript><img height="1" width="1" style="display:none" alt="" src="' . esc_url( $src ) . '" /></noscript>' . "\n";
}

/**
 * Regra pura: o código base do TikTok Pixel vai completo no topo do <head>?
 * Só no modo básico (sem consentimento a pedir), com `tiktok_cabecalho`
 * ligado e em página pública. Com consentimento, nada: o trecho oficial
 * baixa o events.js no `ttq.load()`, mesmo com `holdConsent()` (documentação
 * do TikTok, Cookie Consent Mode, conferida em 2026-11-16).
 *
 * @param array $config  Configuração do Rastro.
 * @param bool  $privada Página privada?
 * @return bool
 */
function rastro_tags_tiktok_completo( array $config, bool $privada ): bool {
	return ! $privada
		&& isset( $config['tiktok_cabecalho'], $config['modo'] )
		&& '1' === $config['tiktok_cabecalho']
		&& 'basico' === $config['modo'];
}

/**
 * Regra pura: o código base oficial do TikTok Pixel (trecho de
 * business-api.tiktok.com, Install the pixel using code, v1.3, conferido em
 * 2026-11-16), com os métodos de consentimento na lista, `ttq.load` e
 * `ttq.page`.
 *
 * @param string $id ID do TikTok Pixel (validado de novo aqui).
 * @return string '' para ID inválido.
 */
function rastro_tags_tiktok_cabecalho_js( string $id ): string {
	$id = rastro_tags_id( 'tiktok', $id );
	if ( '' === $id ) {
		return '';
	}

	return '!function(w,d,t){w.TiktokAnalyticsObject=t;var ttq=w[t]=w[t]||[];ttq.methods=["page","track","identify","instances","debug","on","off","once","ready","alias","group","enableCookie","disableCookie","holdConsent","revokeConsent","grantConsent"],ttq.setAndDefer=function(t,e){t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}};for(var i=0;i<ttq.methods.length;i++)ttq.setAndDefer(ttq,ttq.methods[i]);ttq.instance=function(t){for(var e=ttq._i[t]||[],n=0;n<ttq.methods.length;n++)ttq.setAndDefer(e,ttq.methods[n]);return e},ttq.load=function(e,n){var r="https://analytics.tiktok.com/i18n/pixel/events.js";ttq._i=ttq._i||{},ttq._i[e]=[],ttq._i[e]._u=r,ttq._t=ttq._t||{},ttq._t[e]=+new Date,ttq._o=ttq._o||{},ttq._o[e]=n||{};var o=d.createElement("script");o.type="text/javascript",o.async=!0,o.src=r+"?sdkid="+e+"&lib="+t;var a=d.getElementsByTagName("script")[0];a.parentNode.insertBefore(o,a)};'
		. 'ttq.load(' . rastro_json( $id ) . ');ttq.page();}(window,document,"ttq");';
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
 * wp_head: metas de verificação de domínio (Google, Meta e as outras de
 * rastro_tags_verificacoes()) nas páginas do site. Não dependem de
 * consentimento nem da medição; ficam fora do painel, do login e das outras
 * requisições restritas.
 *
 * @return void
 */
function rastro_tags_meta_verificacao(): void {
	try {
		if ( rastro_requisicao_restrita() ) {
			return;
		}
		echo rastro_tags_meta_html( rastro_google_verificacao() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- valor validado e escapado em rastro_tags_meta_html().
		foreach ( array_keys( rastro_tags_verificacoes() ) as $chave ) {
			if ( 'google_verificacao' !== $chave ) {
				echo rastro_tags_verificacao_html( $chave, rastro_config_campo( $chave ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- valor validado e escapado em rastro_tags_verificacao_html().
			}
		}
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

/* Meta Pixel no cabeçalho e no rodapé ------------------------------------------ */

/**
 * O destino Meta desta página quando ele vai pelo cabeçalho: ID e tipo
 * (fila ou completo), ou null. Só quando a página mede e o tags.js está na
 * página (é ele quem carrega o fbevents.js depois do aceite e entrega os
 * eventos).
 *
 * @return array{id:string,tipo:string}|null
 */
function rastro_tags_meta_cabecalho_destino(): ?array {
	if ( rastro_requisicao_restrita() || ! rastro_deve_medir() || ! function_exists( 'wp_script_is' ) || ! wp_script_is( 'rastro-tags', 'enqueued' ) ) {
		return null;
	}
	$destinos = rastro_destinos();
	$meta     = isset( $destinos['meta'] ) ? $destinos['meta'] : array();
	if ( empty( $meta['ativo'] ) || empty( $meta['navegador']['cabecalho'] ) ) {
		return null;
	}

	return array(
		'id'   => (string) $meta['id'],
		'tipo' => (string) $meta['navegador']['cabecalho'],
	);
}

/**
 * wp_head (prioridade 3, depois da fila de consentimento): código base do
 * Meta Pixel. Na fila, nenhum pedido sai para a Meta antes do aceite.
 *
 * @return void
 */
function rastro_tags_meta_cabecalho(): void {
	try {
		$meta = rastro_tags_meta_cabecalho_destino();
		if ( null === $meta ) {
			return;
		}
		rastro_script_inline( 'rastro-meta-pixel', rastro_tags_meta_cabecalho_js( $meta['id'], $meta['tipo'] ) );
	} catch ( \Throwable $erro ) {
		do_action( 'rastro_falha', 'tags-meta-cabecalho', $erro );
	}
}
add_action( 'wp_head', 'rastro_tags_meta_cabecalho', 3 );

/**
 * wp_footer: o <noscript> do Meta Pixel (só com o código completo).
 *
 * @return void
 */
function rastro_tags_meta_rodape(): void {
	try {
		$meta = rastro_tags_meta_cabecalho_destino();
		if ( null === $meta ) {
			return;
		}
		echo rastro_tags_meta_noscript_html( $meta['id'], $meta['tipo'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ID validado e endereço por esc_url().
	} catch ( \Throwable $erro ) {
		do_action( 'rastro_falha', 'tags-meta-rodape', $erro );
	}
}
add_action( 'wp_footer', 'rastro_tags_meta_rodape', 20 );

/* TikTok Pixel no cabeçalho (modo básico) ---------------------------------------- */

/**
 * wp_head (prioridade 3): código base do TikTok Pixel no modo básico, em
 * página pública, quando a página mede e o tags.js está na página.
 *
 * @return void
 */
function rastro_tags_tiktok_cabecalho(): void {
	try {
		if ( rastro_requisicao_restrita() || ! rastro_deve_medir() || ! function_exists( 'wp_script_is' ) || ! wp_script_is( 'rastro-tags', 'enqueued' ) ) {
			return;
		}
		$destinos = rastro_destinos();
		$tiktok   = isset( $destinos['tiktok'] ) ? $destinos['tiktok'] : array();
		if ( empty( $tiktok['ativo'] ) || ! isset( $tiktok['navegador']['cabecalho'] ) || 'completo' !== $tiktok['navegador']['cabecalho'] ) {
			return;
		}
		rastro_script_inline( 'rastro-tiktok-pixel', rastro_tags_tiktok_cabecalho_js( (string) $tiktok['id'] ) );
	} catch ( \Throwable $erro ) {
		do_action( 'rastro_falha', 'tags-tiktok-cabecalho', $erro );
	}
}
add_action( 'wp_head', 'rastro_tags_tiktok_cabecalho', 3 );

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
