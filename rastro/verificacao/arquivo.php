<?php
/**
 * Rastro: arquivo de verificação na raiz do site (manual docs/TAGS.md,
 * seção Verificações; contrato, seção 5.2).
 *
 * Alguns serviços verificam o domínio por um arquivo de texto na raiz do
 * site em vez de uma meta tag (o TikTok for Developers verifica um prefixo
 * de endereço por um arquivo de assinatura; DNS fica fora do WordPress).
 * Com nome e conteúdo preenchidos na aba Verificações, o Rastro responde
 * `GET /<nome>.txt` com o conteúdo em texto puro. Nada é gravado em disco.
 *
 * Nome e conteúdo passam pela validação do esquema na leitura: só letras,
 * números e poucos sinais; o conteúdo sai como text/plain com `nosniff`.
 *
 * @package Rastro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Regra pura: o caminho pedido é o do arquivo de verificação? Compara o
 * caminho da requisição (sem consulta) com o caminho da página inicial mais
 * o nome do arquivo.
 *
 * @param string $uri        REQUEST_URI.
 * @param string $base       Caminho da página inicial (ex.: / ou /loja/).
 * @param string $nome       Nome do arquivo já validado ('' = desligado).
 * @return bool
 */
function rastro_verificacao_arquivo_regra( string $uri, string $base, string $nome ): bool {
	if ( '' === $nome ) {
		return false;
	}
	$caminho = (string) strtok( $uri, '?#' );
	$base    = '/' . trim( $base, '/' );
	$base    = '/' === $base ? '/' : $base . '/';

	return $caminho === $base . $nome;
}

/**
 * Nome e conteúdo válidos do arquivo de verificação, ou null.
 *
 * @return array{nome:string,conteudo:string}|null
 */
function rastro_verificacao_arquivo(): ?array {
	if ( ! function_exists( 'rastro_config_campo' ) ) {
		return null;
	}
	$nome     = rastro_config_validar_campo( 'arquivo_verificacao_nome', rastro_config_campo( 'arquivo_verificacao_nome' ) );
	$conteudo = rastro_config_validar_campo( 'arquivo_verificacao_conteudo', rastro_config_campo( 'arquivo_verificacao_conteudo' ) );
	if ( ! is_string( $nome ) || '' === $nome || ! is_string( $conteudo ) || '' === $conteudo ) {
		return null;
	}

	return array(
		'nome'     => $nome,
		'conteudo' => $conteudo,
	);
}

/**
 * parse_request: responde o arquivo de verificação (GET ou HEAD) e encerra.
 * Fora disso, não faz nada.
 *
 * @return void
 */
function rastro_verificacao_responder(): void {
	if ( rastro_verificacao_enviar() ) {
		rastro_verificacao_sair();
	}
}
add_action( 'parse_request', 'rastro_verificacao_responder', 0 );

/**
 * Envia o arquivo de verificação quando a requisição é a dele.
 *
 * @return bool Enviou?
 */
function rastro_verificacao_enviar(): bool {
	try {
		if ( ( function_exists( 'is_admin' ) && is_admin() ) || ! in_array( rastro_requisicao_metodo(), array( 'GET', 'HEAD' ), true ) ) {
			return false;
		}
		$arquivo = rastro_verificacao_arquivo();
		if ( null === $arquivo ) {
			return false;
		}
		$base = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		if ( ! rastro_verificacao_arquivo_regra( rastro_requisicao_uri(), '' === $base ? '/' : $base, $arquivo['nome'] ) ) {
			return false;
		}
		if ( ! headers_sent() ) {
			status_header( 200 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			header( 'X-Content-Type-Options: nosniff' );
			header( 'Cache-Control: public, max-age=300' );
		}
		if ( 'HEAD' !== rastro_requisicao_metodo() ) {
			echo $arquivo['conteudo']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- texto puro validado pela regra do esquema (letras, números e = : . _ -), enviado como text/plain com nosniff.
		}
		return true;
	} catch ( \Throwable $erro ) {
		do_action( 'rastro_falha', 'verificacao-arquivo', $erro );
		return false;
	}
}

/**
 * Encerra a requisição depois do arquivo. A ação `rastro_verificacao_enviado`
 * roda antes (os testes a usam para parar sem o exit).
 *
 * @return void
 */
function rastro_verificacao_sair(): void {
	/** O arquivo de verificação foi enviado; a requisição termina aqui. */
	do_action( 'rastro_verificacao_enviado' );
	exit;
}
