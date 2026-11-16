<?php
/**
 * Rastro: motor de consentimento (contrato, seções 2.1 e 4; manual
 * docs/CONSENTIMENTO.md).
 *
 * - Duas finalidades: `analise` e `publicidade`. Só entram as que têm
 *   destino configurado (rastro_consent_finalidades()).
 * - Três modos (`modo`): `lgpd` (padrão, nada antes do aceite),
 *   `aceite_automatico` (o aviso próprio some sozinho e liga só a análise) e
 *   `basico` (sem aviso, tudo concedido desde a carga).
 * - Provedores: `proprio` (aviso do Rastro e cookie rastro_consent),
 *   `wp_consent_api` (plugin WP Consent API) e as pontes registradas pelo
 *   filtro `rastro_consent_provedores` (o aviso que o site já tem). Com
 *   `aviso = externo` e nenhum provedor externo ativo, o provedor é `nenhum`
 *   e nada carrega (falha fechada).
 * - Fila do <head> (wp_head, prioridade 2): dataLayer, gtag e o padrão do
 *   Consent Mode v2 (negado; concedido só no modo básico).
 * - Configuração do navegador: chave `consent` em window.rastroConfig.
 *
 * O estado da escolha nunca vem do servidor para o HTML: a página é igual
 * para todo visitante (cache seguro) e o navegador lê a escolha.
 *
 * @package Rastro
 */

defined( 'ABSPATH' ) || exit;

/** Cookie próprio da escolha (a1p1, a1p0, a0p1, a0p0). */
const RASTRO_CONSENT_COOKIE = 'rastro_consent';

/** Chave do sessionStorage da recusa só nesta visita (Esc no aviso). */
const RASTRO_CONSENT_VISITA = 'rastro_consent_visita';

/* Regras puras -------------------------------------------------------------- */

/**
 * Regra pura: modo de consentimento válido (`lgpd` quando não reconhece).
 *
 * @param mixed $modo Valor da configuração.
 * @return string lgpd, aceite_automatico ou basico.
 */
function rastro_consent_regra_modo( $modo ): string {
	return is_string( $modo ) && in_array( $modo, array( 'lgpd', 'aceite_automatico', 'basico' ), true ) ? $modo : 'lgpd';
}

/**
 * Regra pura: finalidades que têm destino. Análise: GA4 e PostHog.
 * Publicidade: Google Ads, Meta, TikTok, OpenAI e GTM (o contêiner pode ter
 * tags de anúncio, então ele só carrega com a publicidade aceita).
 *
 * @param array $config Configuração (rastro_config()).
 * @return string[] Na ordem analise, publicidade.
 */
function rastro_consent_regra_finalidades( array $config ): array {
	$tem = static function ( array $chaves ) use ( $config ): bool {
		foreach ( $chaves as $chave ) {
			if ( isset( $config[ $chave ] ) && is_string( $config[ $chave ] ) && '' !== $config[ $chave ] ) {
				return true;
			}
		}
		return false;
	};

	$finalidades = array();
	if ( $tem( array( 'ga4', 'posthog' ) ) ) {
		$finalidades[] = 'analise';
	}
	if ( $tem( array( 'ads', 'meta', 'tiktok', 'openai', 'gtm' ) ) ) {
		$finalidades[] = 'publicidade';
	}

	return $finalidades;
}

/**
 * Regra pura: qual provedor de consentimento vale.
 *
 * Ordem: modo básico ou `aviso = proprio` usam o próprio; senão a primeira
 * ponte ativa; senão a WP Consent API; senão o próprio (`automatico`) ou
 * `nenhum` (`externo`, falha fechada).
 *
 * @param string   $aviso          automatico, proprio ou externo.
 * @param string[] $pontes_ativas  Nomes das pontes ativas, na ordem do registro.
 * @param bool     $wp_consent_api O plugin WP Consent API está ativo.
 * @param string   $modo           Modo de consentimento.
 * @return string
 */
function rastro_consent_regra_provedor( string $aviso, array $pontes_ativas, bool $wp_consent_api, string $modo = 'lgpd' ): string {
	if ( 'basico' === $modo || 'proprio' === $aviso ) {
		return 'proprio';
	}
	foreach ( $pontes_ativas as $nome ) {
		if ( is_string( $nome ) && '' !== $nome ) {
			return $nome;
		}
	}
	if ( $wp_consent_api ) {
		return 'wp_consent_api';
	}

	return 'externo' === $aviso ? 'nenhum' : 'proprio';
}

/**
 * Regra pura: lê o valor do cookie próprio. Formato `a<0|1>p<0|1>`; o que
 * não casa vale como "sem escolha" (tudo negado).
 *
 * @param mixed $valor Valor do cookie.
 * @return array{analise:bool,publicidade:bool,decidido:bool}
 */
function rastro_consent_regra_cookie( $valor ): array {
	if ( is_string( $valor ) && 1 === preg_match( '/^a([01])p([01])$/D', $valor, $partes ) ) {
		return array(
			'analise'     => '1' === $partes[1],
			'publicidade' => '1' === $partes[2],
			'decidido'    => true,
		);
	}

	return array(
		'analise'     => false,
		'publicidade' => false,
		'decidido'    => false,
	);
}

/**
 * Regra pura: valida a configuração de navegador de uma ponte.
 *
 * Chaves: `cookie` (nome do cookie do aviso do site), `mapa` (valor do
 * cookie => array( 'analise' => bool, 'publicidade' => bool )), `evento`
 * (evento do document que avisa a mudança, opcional), `campo` (campo do
 * detail com o valor, opcional) e `abrir` (seletor do botão que reabre o
 * aviso do site, opcional).
 *
 * @param mixed $js Configuração recebida.
 * @return array|null Limpa, ou null quando não serve.
 */
function rastro_consent_regra_ponte_js( $js ): ?array {
	if ( ! is_array( $js ) || ! isset( $js['cookie'], $js['mapa'] ) || ! is_string( $js['cookie'] ) || ! is_array( $js['mapa'] ) ) {
		return null;
	}
	if ( 1 !== preg_match( '/^[A-Za-z0-9_-]{1,64}$/D', $js['cookie'] ) ) {
		return null;
	}

	$mapa = array();
	foreach ( $js['mapa'] as $valor => $estado ) {
		$valor = (string) $valor;
		if ( 1 !== preg_match( '/^[A-Za-z0-9_.-]{1,40}$/D', $valor ) || ! is_array( $estado ) ) {
			continue;
		}
		$mapa[ $valor ] = array(
			'analise'     => isset( $estado['analise'] ) && true === $estado['analise'],
			'publicidade' => isset( $estado['publicidade'] ) && true === $estado['publicidade'],
		);
	}
	if ( array() === $mapa ) {
		return null;
	}

	$texto = static function ( string $chave, string $regex ) use ( $js ): string {
		return isset( $js[ $chave ] ) && is_string( $js[ $chave ] ) && 1 === preg_match( $regex, $js[ $chave ] ) ? $js[ $chave ] : '';
	};

	return array(
		'cookie' => $js['cookie'],
		'mapa'   => $mapa,
		'evento' => $texto( 'evento', '/^[A-Za-z0-9_:.-]{1,60}$/D' ),
		'campo'  => $texto( 'campo', '/^[A-Za-z0-9_]{1,40}$/D' ),
		'abrir'  => $texto( 'abrir', '/^[A-Za-z0-9_\[\]=".#: ,>-]{1,160}$/D' ),
	);
}

/**
 * Regra pura: a fila do <head>. Cria dataLayer e gtag e grava o padrão do
 * Consent Mode v2: negado para os quatro sinais, com `wait_for_update: 500`;
 * no modo básico, concedido (sem padrão negado).
 *
 * @param string $modo Modo de consentimento.
 * @return string
 */
function rastro_consent_regra_fila( string $modo ): string {
	$valor  = 'basico' === $modo ? 'granted' : 'denied';
	$padrao = array(
		'ad_storage'         => $valor,
		'analytics_storage'  => $valor,
		'ad_user_data'       => $valor,
		'ad_personalization' => $valor,
	);
	if ( 'basico' !== $modo ) {
		$padrao['wait_for_update'] = 500;
	}

	return 'window.dataLayer=window.dataLayer||[];'
		. 'window.gtag=window.gtag||function(){window.dataLayer.push(arguments);};'
		. "window.gtag('consent','default'," . rastro_json( $padrao ) . ');';
}

/* Leitura da configuração -------------------------------------------------- */

/**
 * Memória da requisição (provedor e pontes).
 *
 * @return array
 */
function &rastro_consent_memoria(): array {
	static $memoria = array(
		'provedor' => null,
		'pontes'   => null,
	);

	return $memoria;
}

/** Zera a memória (testes e mudança de configuração). */
function rastro_consent_redefinir_cache(): void {
	$memoria             = &rastro_consent_memoria();
	$memoria['provedor'] = null;
	$memoria['pontes']   = null;
}

/**
 * Modo de consentimento em uso.
 *
 * @return string lgpd, aceite_automatico ou basico.
 */
function rastro_consent_modo(): string {
	return rastro_consent_regra_modo( rastro_config_campo( 'modo' ) );
}

/**
 * Finalidades com destino configurado.
 *
 * @return string[]
 */
function rastro_consent_finalidades(): array {
	return rastro_consent_regra_finalidades( rastro_config() );
}

/**
 * Pontes registradas pelo filtro `rastro_consent_provedores`, validadas.
 * Nomes `proprio`, `wp_consent_api` e `nenhum` são reservados.
 *
 * @return array<string,array{ativo:callable,js:array}>
 */
function rastro_consent_pontes(): array {
	$memoria = &rastro_consent_memoria();
	if ( is_array( $memoria['pontes'] ) ) {
		return $memoria['pontes'];
	}

	/**
	 * Registra provedores de consentimento de um aviso que o site já tem.
	 *
	 * @param array $provedores nome => array( 'ativo' => callable, 'js' => array( 'cookie', 'mapa', 'evento', 'campo', 'abrir' ) ).
	 */
	$registrados = apply_filters( 'rastro_consent_provedores', array() );
	$pontes      = array();
	foreach ( is_array( $registrados ) ? $registrados : array() as $nome => $provedor ) {
		if ( ! is_string( $nome ) || 1 !== preg_match( '/^[a-z0-9_]{2,40}$/D', $nome ) || in_array( $nome, array( 'proprio', 'wp_consent_api', 'nenhum' ), true ) ) {
			continue;
		}
		if ( ! is_array( $provedor ) || ! isset( $provedor['ativo'] ) || ! is_callable( $provedor['ativo'] ) ) {
			continue;
		}
		$js = rastro_consent_regra_ponte_js( isset( $provedor['js'] ) ? $provedor['js'] : null );
		if ( null === $js ) {
			continue;
		}
		$pontes[ $nome ] = array(
			'ativo' => $provedor['ativo'],
			'js'    => $js,
		);
	}

	if ( did_action( 'wp' ) ) {
		$memoria['pontes'] = $pontes;
	}

	return $pontes;
}

/**
 * O plugin WP Consent API está ativo? (função PHP wp_has_consent, conferida
 * no leia-me oficial do plugin)
 *
 * @return bool
 */
function rastro_consent_wp_consent_api_ativa(): bool {
	return function_exists( 'wp_has_consent' );
}

/**
 * Provedor de consentimento em uso: `proprio`, `wp_consent_api`, o nome de
 * uma ponte ou `nenhum` (aviso externo pedido e nenhum ativo).
 *
 * @return string
 */
function rastro_consent_provedor(): string {
	$memoria = &rastro_consent_memoria();
	if ( is_string( $memoria['provedor'] ) ) {
		return $memoria['provedor'];
	}

	$modo     = rastro_consent_modo();
	$aviso    = (string) rastro_config_campo( 'aviso' );
	$ativas   = array();
	$provedor = 'proprio';
	try {
		if ( 'basico' !== $modo && 'proprio' !== $aviso ) {
			foreach ( rastro_consent_pontes() as $nome => $ponte ) {
				if ( true === (bool) call_user_func( $ponte['ativo'] ) ) {
					$ativas[] = $nome;
				}
			}
		}
		$provedor = rastro_consent_regra_provedor( $aviso, $ativas, rastro_consent_wp_consent_api_ativa(), $modo );
	} catch ( \Throwable $erro ) {
		do_action( 'rastro_falha', 'consentimento', $erro );
		// Falha fechada: sem saber qual aviso vale, nada carrega.
		$provedor = 'nenhum';
	}

	if ( did_action( 'wp' ) ) {
		$memoria['provedor'] = $provedor;
	}

	return $provedor;
}

/**
 * Escolha lida no servidor (só para decisões do servidor, como a
 * atribuição). No modo básico, tudo concedido. Com outro provedor que não o
 * próprio, o servidor não sabe: null nas três chaves.
 *
 * @return array{analise:?bool,publicidade:?bool,decidido:?bool}
 */
function rastro_consent_servidor(): array {
	if ( 'basico' === rastro_consent_modo() ) {
		return array(
			'analise'     => true,
			'publicidade' => true,
			'decidido'    => true,
		);
	}
	if ( 'proprio' !== rastro_consent_provedor() ) {
		return array(
			'analise'     => null,
			'publicidade' => null,
			'decidido'    => null,
		);
	}

	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- só casa com a expressão fechada.
	$valor = isset( $_COOKIE[ RASTRO_CONSENT_COOKIE ] ) && is_string( $_COOKIE[ RASTRO_CONSENT_COOKIE ] ) ? wp_unslash( $_COOKIE[ RASTRO_CONSENT_COOKIE ] ) : '';

	return rastro_consent_regra_cookie( $valor );
}

/**
 * Dias de validade da escolha (30 a 395).
 *
 * @return int
 */
function rastro_consent_dias(): int {
	$dias = (int) rastro_config_campo( 'aviso_dias' );

	return $dias >= 30 && $dias <= 395 ? $dias : 180;
}

/**
 * O aviso próprio entra nesta página? (provedor próprio, modo diferente do
 * básico e alguma finalidade com destino)
 *
 * @return bool
 */
function rastro_consent_aviso_proprio(): bool {
	return 'proprio' === rastro_consent_provedor() && 'basico' !== rastro_consent_modo() && array() !== rastro_consent_finalidades();
}

/**
 * A página leva o consentimento (fila, configuração e consent.js)?
 *
 * @return bool
 */
function rastro_consent_ativo(): bool {
	return 'nenhum' !== rastro_consent_provedor() && rastro_precisa_saida();
}

/* Ganchos ------------------------------------------------------------------ */

/**
 * rastro_precisa_saida: com destino e provedor próprio, a configuração e o
 * aviso saem mesmo quando a página não mede (equipe e cópia local veem o
 * aviso para conferir; as tags seguem a decisão de medir).
 *
 * @param mixed $precisa Resposta anterior.
 * @return bool
 */
function rastro_consent_filtro_precisa_saida( $precisa ): bool {
	if ( true === $precisa ) {
		return true;
	}
	try {
		return rastro_tem_destino() && 'proprio' === rastro_consent_provedor();
	} catch ( \Throwable $erro ) {
		do_action( 'rastro_falha', 'consentimento', $erro );
		return false;
	}
}

/**
 * rastro_config_navegador: chave `consent` (sem dado pessoal e sem a
 * escolha do visitante).
 *
 * @param mixed $config Configuração do navegador.
 * @return mixed
 */
function rastro_consent_filtro_navegador( $config ) {
	if ( ! is_array( $config ) ) {
		return $config;
	}
	try {
		$provedor = rastro_consent_provedor();
		$consent  = array(
			'modo'        => rastro_consent_modo(),
			'provedor'    => $provedor,
			'finalidades' => rastro_consent_finalidades(),
			'cookie'      => RASTRO_CONSENT_COOKIE,
			'visita'      => RASTRO_CONSENT_VISITA,
			'dias'        => rastro_consent_dias(),
			'segundos'    => max( 1, min( 30, (int) rastro_config_campo( 'aceite_segundos' ) ) ),
			'flutuante'   => function_exists( 'rastro_aviso_botao_flutuante_ativo' ) && rastro_aviso_botao_flutuante_ativo(),
			'medir'       => rastro_deve_medir(),
			// Visita de campanha ligada (contrato 2.1): recusar mantém só o cookie de origem que é de campanha (consent.js, origemFica()).
			'manterOrigem' => '1' === (string) rastro_config_campo( 'atribuicao_utm_sempre' ) && 'desligada' !== (string) rastro_config_campo( 'atribuicao' ),
		);
		$pontes   = rastro_consent_pontes();
		if ( 'basico' !== $consent['modo'] && isset( $pontes[ $provedor ] ) ) {
			$consent['externo'] = $pontes[ $provedor ]['js'];
		}
		$config['consent'] = $consent;
	} catch ( \Throwable $erro ) {
		do_action( 'rastro_falha', 'consentimento', $erro );
	}

	return $config;
}

/**
 * wp_head (prioridade 2): a fila do Consent Mode, logo depois da
 * configuração.
 *
 * @return void
 */
function rastro_consent_fila(): void {
	try {
		if ( ! rastro_consent_ativo() ) {
			return;
		}
		rastro_script_inline( 'rastro-fila', rastro_consent_regra_fila( rastro_consent_modo() ) );
	} catch ( \Throwable $erro ) {
		do_action( 'rastro_falha', 'consentimento-fila', $erro );
	}
}

/**
 * wp_enqueue_scripts: consent.js (rodapé, defer) e o estilo do aviso
 * próprio.
 *
 * @return void
 */
function rastro_consent_scripts(): void {
	try {
		if ( ! rastro_consent_ativo() ) {
			return;
		}
		if ( rastro_registrar_script( 'rastro-consent', 'consent.js' ) ) {
			wp_enqueue_script( 'rastro-consent' );
		}
		if ( rastro_consent_aviso_proprio() ) {
			$arquivo = RASTRO_DIR . 'assets/css/aviso.css';
			if ( is_readable( $arquivo ) ) {
				$data = @filemtime( $arquivo ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- sem data, vale a versão do plugin.
				wp_enqueue_style( 'rastro-aviso', RASTRO_URL . 'assets/css/aviso.css', array(), false !== $data ? (string) $data : RASTRO_VERSAO );
			}
		}
	} catch ( \Throwable $erro ) {
		do_action( 'rastro_falha', 'consentimento-scripts', $erro );
	}
}

/**
 * rastro_admin_avisos: falha fechada do aviso externo e modos que não
 * atendem a LGPD.
 *
 * @param mixed $avisos Avisos recebidos.
 * @return mixed
 */
function rastro_consent_filtro_avisos( $avisos ) {
	if ( ! is_array( $avisos ) ) {
		return $avisos;
	}
	try {
		$modo = rastro_consent_modo();
		if ( in_array( $modo, array( 'aceite_automatico', 'basico' ), true ) ) {
			$avisos[] = array(
				'tipo'  => 'warning',
				'texto' => __( 'Este modo não atende a LGPD: ficar parado ou não ser perguntado não é consentimento. A escolha e o risco são do site.', 'rastro' ),
			);
		}
		if ( 'nenhum' === rastro_consent_provedor() ) {
			$avisos[] = array(
				'tipo'  => 'error',
				'texto' => __( 'O aviso de cookies está como "Só o aviso que o site já tem", mas nenhum aviso externo está ativo. Nenhuma tag carrega até um aviso ser ativado ou a opção mudar.', 'rastro' ),
			);
		}
	} catch ( \Throwable $erro ) {
		do_action( 'rastro_falha', 'consentimento-avisos', $erro );
	}

	return $avisos;
}

/**
 * Liga a parte pública no gancho `wp` (consulta pronta, só no site).
 *
 * @return void
 */
function rastro_consent_iniciar(): void {
	add_filter( 'rastro_precisa_saida', 'rastro_consent_filtro_precisa_saida', 10, 1 );
	add_filter( 'rastro_config_navegador', 'rastro_consent_filtro_navegador', 10, 1 );
	add_action( 'wp_head', 'rastro_consent_fila', 2 );
	add_action( 'wp_enqueue_scripts', 'rastro_consent_scripts', 10 );
}
add_action( 'wp', 'rastro_consent_iniciar', 1 );
add_filter( 'rastro_admin_avisos', 'rastro_consent_filtro_avisos', 10, 1 );

// WP Consent API: o Rastro declara que segue a API (padrão do leia-me oficial).
if ( function_exists( 'plugin_basename' ) ) {
	add_filter( 'wp_consent_api_registered_' . plugin_basename( RASTRO_ARQUIVO ), '__return_true' );
}
