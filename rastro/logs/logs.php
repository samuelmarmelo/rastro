<?php
/**
 * Rastro: logs no PostHog Logs (manual docs/LOGS.md; contrato, seção 15).
 *
 * Duas pontas, as duas só com a chave do PostHog e o campo `posthog_logs`
 * ligado:
 *
 * - Servidor: rastro_log( $nivel, $mensagem, $atributos ) manda um registro
 *   em OTLP/HTTP JSON para `<host da região>/i/v1/logs`, com a chave do
 *   projeto no cabeçalho Authorization. A ação `rastro_falha` (falha isolada
 *   de um módulo, SafeWidget) vira um log `error`. Não mede visitante: só
 *   falhas do site, sem dado pessoal (e-mail, CPF, telefone, número de pedido
 *   e números longos mascarados; a pasta da instalação sai do texto).
 * - Navegador: a chave `logs` do `posthog.init` (serviceName, environment,
 *   serviceVersion e o console só com `posthog_logs_console`). Segue o
 *   consentimento do PostHog: nada antes do aceite de análise.
 *
 * Envio não bloqueante (wp_safe_remote_post, 1 s), até 10 logs por
 * requisição, repetidos uma vez só. Cópia local não envia. Falha do envio é
 * engolida: o log nunca derruba o site.
 *
 * @package Rastro
 */

defined( 'ABSPATH' ) || exit;

/** Máximo de logs do servidor por requisição. */
const RASTRO_LOGS_MAXIMO = 10;

/** service.name dos logs do servidor. */
const RASTRO_LOGS_SERVICO = 'rastro';

/* Regras puras ------------------------------------------------------------- */

/**
 * Regra pura: tira dado pessoal de um texto de log e corta o tamanho.
 * Mascara e-mail (também %40), CPF, telefone, número de pedido ("pedido
 * 4521", "#4521"), trecho numérico de caminho (/view-order/4521/ vira
 * /view-order/:id/) e qualquer sequência de 8 ou mais dígitos.
 *
 * @param string $texto  Texto.
 * @param int    $limite Máximo de caracteres.
 * @return string
 */
function rastro_logs_limpar_texto( string $texto, int $limite = 2000 ): string {
	$texto = str_ireplace( '%40', '@', $texto );
	$texto = (string) preg_replace( '/[\p{L}\p{N}._%+\-]+@[\p{L}\p{N}.\-]+\.\p{L}{2,}/u', '[e-mail]', $texto );
	$texto = (string) preg_replace( '/\b\d{3}[.\s]?\d{3}[.\s]?\d{3}[\s.\-]?\d{2}\b/', '[cpf]', $texto );
	$texto = (string) preg_replace( '/\(?\b\d{2}\)?\s?9?\s?\d{4}[\s.\-]?\d{4}\b/', '[telefone]', $texto );
	$texto = (string) preg_replace( '/\b(pedidos?|orders?|encomendas?)(\s*(?:n[º°o.]?|#|id|número|numero)?\s*[:#]?\s*)\d+/iu', '$1$2[número]', $texto );
	$texto = (string) preg_replace( '/#\d{3,}\b/', '#[número]', $texto );
	$texto = (string) preg_replace( '~/\d+(?=[/?#\s]|$)~', '/:id', $texto );
	$texto = (string) preg_replace( '/\d{8,}/', '[número]', $texto );

	return function_exists( 'mb_substr' ) ? (string) mb_substr( $texto, 0, $limite, 'UTF-8' ) : substr( $texto, 0, $limite );
}

/**
 * Regra pura: tira a pasta da instalação (barra normal ou invertida) de um
 * texto, para o log não expor o caminho do servidor.
 *
 * @param string $texto Texto.
 * @param string $raiz  Pasta da instalação (ABSPATH).
 * @return string
 */
function rastro_logs_sem_raiz( string $texto, string $raiz ): string {
	$raiz = rtrim( str_replace( '\\', '/', $raiz ), '/' );
	if ( '' === $raiz ) {
		return $texto;
	}
	$invertida = str_replace( '/', '\\', $raiz );

	return str_replace( array( $raiz . '/', $invertida . '\\', $raiz . '\\', $invertida . '/' ), '', $texto );
}

/**
 * Regra pura: nível conhecido (debug, info, warn, error); outro vira info.
 *
 * @param mixed $nivel Nível pedido.
 * @return string
 */
function rastro_logs_nivel( $nivel ): string {
	$nivel = is_string( $nivel ) ? strtolower( trim( $nivel ) ) : '';

	return in_array( $nivel, array( 'debug', 'info', 'warn', 'error' ), true ) ? $nivel : 'info';
}

/**
 * Regra pura: corpo OTLP/HTTP JSON de um registro de log. Atributos só com
 * valores simples (texto limpo, número, booleano); chave com letras,
 * números, ponto, hífen ou sublinhado; listas e objetos ficam de fora.
 *
 * @param string $nivel     debug|info|warn|error.
 * @param string $mensagem  Texto já limpo.
 * @param array  $atributos Atributos do registro.
 * @param array  $recurso   Atributos do recurso (service.name e outros).
 * @param string $nano      Hora em nanossegundos (texto).
 * @return array
 */
function rastro_logs_corpo( string $nivel, string $mensagem, array $atributos, array $recurso, string $nano ): array {
	$numeros = array(
		'debug' => 5,
		'info'  => 9,
		'warn'  => 13,
		'error' => 17,
	);
	$nivel   = rastro_logs_nivel( $nivel );

	return array(
		'resourceLogs' => array(
			array(
				'resource'  => array( 'attributes' => rastro_logs_atributos( $recurso ) ),
				'scopeLogs' => array(
					array(
						'scope'      => array( 'name' => RASTRO_LOGS_SERVICO ),
						'logRecords' => array(
							array(
								'timeUnixNano'   => $nano,
								'severityNumber' => $numeros[ $nivel ],
								'severityText'   => strtoupper( $nivel ),
								'body'           => array( 'stringValue' => $mensagem ),
								'attributes'     => rastro_logs_atributos( $atributos ),
							),
						),
					),
				),
			),
		),
	);
}

/**
 * Regra pura: atributos no formato OTLP (lista de chave e valor tipado).
 *
 * @param array $atributos chave => valor simples.
 * @return array<int,array{key:string,value:array}>
 */
function rastro_logs_atributos( array $atributos ): array {
	$saida = array();
	foreach ( $atributos as $chave => $valor ) {
		if ( ! is_string( $chave ) || 1 !== preg_match( '/^[A-Za-z0-9_.-]{1,64}$/D', $chave ) ) {
			continue;
		}
		if ( is_bool( $valor ) ) {
			$tipado = array( 'boolValue' => $valor );
		} elseif ( is_int( $valor ) ) {
			$tipado = array( 'intValue' => (string) $valor );
		} elseif ( is_float( $valor ) ) {
			$tipado = array( 'doubleValue' => $valor );
		} elseif ( is_string( $valor ) ) {
			$tipado = array( 'stringValue' => rastro_logs_limpar_texto( $valor, 500 ) );
		} else {
			continue;
		}
		$saida[] = array(
			'key'   => $chave,
			'value' => $tipado,
		);
	}

	return $saida;
}

/**
 * Regra pura: o ambiente do WordPress, só letras (production, staging...).
 *
 * @param mixed $ambiente wp_get_environment_type().
 * @return string
 */
function rastro_logs_ambiente( $ambiente ): string {
	$ambiente = is_string( $ambiente ) ? strtolower( $ambiente ) : '';

	return 1 === preg_match( '/^[a-z]{1,20}$/D', $ambiente ) ? $ambiente : 'production';
}

/**
 * Regra pura: chave `logs` do `posthog.init` no navegador ('' sem logs).
 *
 * @param array  $config   Configuração do Rastro (rastro_config()).
 * @param string $ambiente Ambiente do WordPress.
 * @param string $versao   Versão do Rastro.
 * @return array<string,mixed>
 */
function rastro_logs_navegador( array $config, string $ambiente, string $versao ): array {
	if ( ! isset( $config['posthog_logs'] ) || '1' !== $config['posthog_logs'] ) {
		return array();
	}

	return array(
		'serviceName'        => RASTRO_LOGS_SERVICO,
		'environment'        => rastro_logs_ambiente( $ambiente ),
		'serviceVersion'     => 1 === preg_match( '/^[0-9]+\.[0-9]+\.[0-9]+$/D', $versao ) ? $versao : '0.0.0',
		'captureConsoleLogs' => isset( $config['posthog_logs_console'] ) && '1' === $config['posthog_logs_console'],
	);
}

/* Estado ------------------------------------------------------------------- */

/**
 * Chave e endereço do envio do servidor, ou null quando não envia (sem
 * chave, campo desligado, cópia local, sem wp_safe_remote_post ou filtro
 * `rastro_logs_ativo` desligado).
 *
 * @return array{chave:string,url:string}|null
 */
function rastro_logs_destino(): ?array {
	if ( ! function_exists( 'wp_safe_remote_post' ) || ! function_exists( 'rastro_config' ) || rastro_ambiente_local() ) {
		return null;
	}
	$config = rastro_config();
	$chave  = rastro_config_validar_campo( 'posthog', isset( $config['posthog'] ) ? $config['posthog'] : '' );
	if ( ! is_string( $chave ) || '' === $chave || ! isset( $config['posthog_logs'] ) || '1' !== $config['posthog_logs'] ) {
		return null;
	}

	/**
	 * Liga ou desliga os logs do servidor (padrão: ligados com a chave e o
	 * campo). Só `false` desliga.
	 *
	 * @param bool $ativo Logs do servidor ligados?
	 */
	if ( false === apply_filters( 'rastro_logs_ativo', true ) ) {
		return null;
	}

	$regiao = isset( $config['posthog_regiao'] ) && 'eu' === $config['posthog_regiao'] ? 'eu' : 'us';
	$host   = 'eu' === $regiao ? 'https://eu.i.posthog.com' : 'https://us.i.posthog.com';

	return array(
		'chave' => $chave,
		'url'   => $host . '/i/v1/logs',
	);
}

/**
 * Cota por requisição: até RASTRO_LOGS_MAXIMO logs, repetidos uma vez.
 * $zerar = true limpa a cota (testes).
 *
 * @param string $assinatura Deduplicação (nível + mensagem).
 * @param bool   $zerar      Zera a cota.
 * @return bool Pode enviar?
 */
function rastro_logs_cota( string $assinatura, bool $zerar = false ): bool {
	static $enviados = 0;
	static $vistos   = array();
	if ( $zerar ) {
		$enviados = 0;
		$vistos   = array();
		return true;
	}
	if ( $enviados >= RASTRO_LOGS_MAXIMO || isset( $vistos[ $assinatura ] ) ) {
		return false;
	}
	++$enviados;
	$vistos[ $assinatura ] = true;

	return true;
}

/* Envio -------------------------------------------------------------------- */

/**
 * Envia um log do servidor ao PostHog Logs. Sem chave, desligado ou em
 * cópia local, não faz nada. Nunca lança erro.
 *
 * @param string $nivel     debug|info|warn|error.
 * @param string $mensagem  Texto (sem dado de pessoa; e-mail, CPF e números são mascarados).
 * @param array  $atributos Atributos simples (texto, número, booleano).
 * @return bool O envio foi pedido?
 */
function rastro_log( string $nivel, string $mensagem, array $atributos = array() ): bool {
	try {
		$destino = rastro_logs_destino();
		if ( null === $destino ) {
			return false;
		}
		$nivel    = rastro_logs_nivel( $nivel );
		$raiz     = defined( 'ABSPATH' ) ? (string) ABSPATH : '';
		$mensagem = rastro_logs_limpar_texto( rastro_logs_sem_raiz( $mensagem, $raiz ) );
		if ( '' === trim( $mensagem ) || ! rastro_logs_cota( md5( $nivel . "\n" . $mensagem ) ) ) {
			return false;
		}
		$recurso = array(
			'service.name'           => RASTRO_LOGS_SERVICO,
			'service.version'        => defined( 'RASTRO_VERSAO' ) ? (string) RASTRO_VERSAO : '',
			'deployment.environment' => rastro_logs_ambiente( function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : '' ),
		);
		$nano    = sprintf( '%.0f', microtime( true ) * 1000 ) . '000000';
		$json    = function_exists( 'wp_json_encode' )
			? wp_json_encode( rastro_logs_corpo( $nivel, $mensagem, $atributos, $recurso, $nano ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
			: false;
		if ( ! is_string( $json ) ) {
			return false;
		}
		wp_safe_remote_post(
			$destino['url'],
			array(
				'blocking'    => false,
				'timeout'     => 1,
				'redirection' => 0,
				'headers'     => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $destino['chave'],
				),
				'body'        => $json,
			)
		);
		return true;
	} catch ( \Throwable $erro ) {
		// O log nunca derruba o site (e não chama rastro_falha: evita laço).
		unset( $erro );
		return false;
	}
}

/**
 * Ação `rastro_falha`: a falha isolada de um módulo vira um log `error`
 * (módulo, classe do erro e a mensagem mascarada, sem a pasta do servidor).
 *
 * @param mixed $modulo Módulo ou ponto da falha.
 * @param mixed $erro   Erro capturado.
 * @return void
 */
function rastro_logs_falha( $modulo, $erro = null ): void {
	$modulo = is_string( $modulo ) && 1 === preg_match( '/^[a-z0-9_\/.-]{1,80}$/D', $modulo ) ? $modulo : 'desconhecido';
	$classe = $erro instanceof \Throwable ? get_class( $erro ) : 'Erro';
	$texto  = $erro instanceof \Throwable ? $erro->getMessage() : '';
	rastro_log(
		'error',
		sprintf( 'Falha isolada em %s: %s %s', $modulo, $classe, $texto ),
		array(
			'modulo' => $modulo,
			'erro'   => $classe,
		)
	);
}
add_action( 'rastro_falha', 'rastro_logs_falha', 10, 2 );
