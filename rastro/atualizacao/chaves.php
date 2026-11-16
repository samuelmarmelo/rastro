<?php
/**
 * Chaves públicas que assinam as versões publicadas do Rastro (Ed25519, 32
 * bytes em hex minúsculo). Só versão assinada por uma destas chaves é
 * instalada pelo atualizador (atualizacao/atualizador.php).
 *
 * Troca de chave (docs/RELEASE.md, seção 3): uma versão assinada pela chave
 * antiga traz a nova nesta lista; a seguinte já sai assinada pela nova e
 * tira a antiga. Sem filtro de propósito: nenhum código de fora acrescenta
 * chave.
 *
 * @package Rastro
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'rastro_atualizador_chaves' ) ) {

	/**
	 * Chaves públicas confiáveis.
	 *
	 * @return string[] Hex minúsculo, 64 caracteres cada.
	 */
	function rastro_atualizador_chaves(): array {
		return array(
			// Chave 1, criada em 2026-11-16.
			'2080d27bb53e55b39a8fdff5a6dd0980c23ab6b6db5f70679a09e4cb1b997bc7',
		);
	}
}
