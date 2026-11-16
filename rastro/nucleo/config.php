<?php
/**
 * Rastro: configuração (contrato, seção 3.1; manual docs/BASE.md).
 *
 * Uma opção só, `rastro_config` (array, autoload). O esquema
 * rastro_config_esquema() é a fonte da verdade dos campos: a tela, o salvar
 * e a leitura saem dele. Nada do banco é usado sem passar de novo pela
 * validação. Os IDs são públicos (aparecem no HTML de quem usa as tags),
 * mas só quem tem manage_options os altera, pelo painel.
 *
 * Valores guardados: texto para IDs, seleções e textos; '1' ou '0' para
 * liga e desliga; inteiro para dias; lista de textos para os status.
 *
 * @package Rastro
 */

defined( 'ABSPATH' ) || exit;

/** Nome da opção. */
const RASTRO_OPCAO = 'rastro_config';

/* Memória da requisição ---------------------------------------------------- */

/**
 * Memória da requisição (esquema e configuração já lida).
 *
 * @return array{esquema:?array,config:?array}
 */
function &rastro_config_memoria(): array {
	static $memoria = array(
		'esquema' => null,
		'config'  => null,
	);

	return $memoria;
}

/**
 * Esquece o que foi lido nesta requisição (testes e depois de salvar).
 *
 * @return void
 */
function rastro_config_redefinir_cache(): void {
	$memoria            = &rastro_config_memoria();
	$memoria['esquema'] = null;
	$memoria['config']  = null;
}

/* Esquema ------------------------------------------------------------------ */

/**
 * Eventos canônicos da loja (contrato, seção 6): nome => rótulo.
 *
 * @return array<string,string>
 */
function rastro_config_eventos(): array {
	return array(
		'view_item_list'   => __( 'Lista de produtos (view_item_list)', 'rastro' ),
		'view_item'        => __( 'Página de produto (view_item)', 'rastro' ),
		'add_to_cart'      => __( 'Adicionar ao carrinho (add_to_cart)', 'rastro' ),
		'remove_from_cart' => __( 'Remover do carrinho (remove_from_cart)', 'rastro' ),
		'view_cart'        => __( 'Ver o carrinho (view_cart)', 'rastro' ),
		'begin_checkout'   => __( 'Início da finalização da compra (begin_checkout)', 'rastro' ),
		'add_payment_info' => __( 'Forma de pagamento escolhida (add_payment_info)', 'rastro' ),
		'purchase'         => __( 'Compra (purchase)', 'rastro' ),
		'search'           => __( 'Busca (search)', 'rastro' ),
	);
}

/**
 * Chaves dos destinos (IDs que ligam alguma tag).
 *
 * @return string[]
 */
function rastro_config_destinos(): array {
	return array( 'gtm', 'ga4', 'ads', 'meta', 'tiktok', 'openai', 'posthog' );
}

/**
 * Esquema dos campos: chave => aba, tipo, rótulo, ajuda, padrão e regra.
 *
 * Tipos: `id` (regex, opcional `maiusculas` e `extrair_content`), `texto`
 * (`max`), `selecao` (`opcoes`), `booleano` (`marca`), `inteiro` (`min`,
 * `max`) e `lista` (`opcoes_de`, função que devolve as opções).
 *
 * @return array<string,array<string,mixed>>
 */
function rastro_config_esquema(): array {
	$memoria = &rastro_config_memoria();
	if ( is_array( $memoria['esquema'] ) ) {
		return $memoria['esquema'];
	}

	$esquema = array(
		'gtm'                          => array(
			'aba'        => 'geral',
			'tipo'       => 'id',
			'rotulo'     => __( 'ID do contêiner do Google Tag Manager', 'rastro' ),
			'ajuda'      => __( 'Começa com GTM-. Fica no topo do Tag Manager, ao lado do nome do contêiner. Vazio desliga.', 'rastro' ),
			'padrao'     => '',
			'regex'      => '/^GTM-[A-Z0-9]{4,12}$/D',
			'maiusculas' => true,
			'exemplo'    => 'GTM-XXXXXXX',
			'erro'       => __( 'O ID do Google Tag Manager começa com GTM- e tem de 4 a 12 letras ou números depois do hífen.', 'rastro' ),
		),
		'ga4'                          => array(
			'aba'        => 'geral',
			'tipo'       => 'id',
			'rotulo'     => __( 'ID de medição do Google Analytics 4', 'rastro' ),
			'ajuda'      => __( 'Começa com G-. No Google Analytics, fica em Administrador, Fluxos de dados, no fluxo do site. Vazio desliga.', 'rastro' ),
			'padrao'     => '',
			'regex'      => '/^G-[A-Z0-9]{6,14}$/D',
			'maiusculas' => true,
			'exemplo'    => 'G-XXXXXXXXXX',
			'erro'       => __( 'O ID do Google Analytics 4 começa com G- e tem de 6 a 14 letras ou números depois do hífen.', 'rastro' ),
		),
		'ads'                          => array(
			'aba'     => 'geral',
			'tipo'    => 'id',
			'rotulo'  => __( 'ID de conversão do Google Ads', 'rastro' ),
			'ajuda'   => __( 'Começa com AW- (em maiúsculas) e tem só números depois do hífen. Vazio desliga.', 'rastro' ),
			'padrao'  => '',
			'regex'   => '/^AW-[0-9]{6,14}$/D',
			'exemplo' => 'AW-000000000',
			'erro'    => __( 'O ID do Google Ads começa com AW- e tem de 6 a 14 números depois do hífen.', 'rastro' ),
		),
		'ads_rotulo_compra'            => array(
			'aba'    => 'geral',
			'tipo'   => 'id',
			'rotulo' => __( 'Rótulo da conversão de compra do Google Ads', 'rastro' ),
			'ajuda'  => __( 'O trecho depois da barra em AW-000000000/rótulo, na ação de conversão de compra. Sem ele, a compra vai ao Google Ads só como evento purchase.', 'rastro' ),
			'padrao' => '',
			'regex'  => '/^[A-Za-z0-9_-]{6,40}$/D',
			'erro'   => __( 'O rótulo da conversão tem de 6 a 40 letras, números, hífen ou sublinhado.', 'rastro' ),
		),
		'meta'                         => array(
			'aba'     => 'geral',
			'tipo'    => 'id',
			'rotulo'  => __( 'ID do Meta Pixel', 'rastro' ),
			'ajuda'   => __( 'Só números. No Gerenciador de Eventos da Meta, fica embaixo do nome do pixel (conjunto de dados). Vazio desliga.', 'rastro' ),
			'padrao'  => '',
			'regex'   => '/^[0-9]{8,20}$/D',
			'numeros' => true,
			'erro'    => __( 'O ID do Meta Pixel tem só números (de 8 a 20).', 'rastro' ),
		),
		'meta_cabecalho'               => array(
			'aba'    => 'geral',
			'tipo'   => 'booleano',
			'rotulo' => __( 'Código base do Meta Pixel no cabeçalho', 'rastro' ),
			'marca'  => __( 'Pôr o código base do Meta Pixel no <head> e o <noscript> no rodapé, como a Meta pede', 'rastro' ),
			'ajuda'  => __( 'Com consentimento (LGPD), o <head> leva só a fila fbq, sem nenhum pedido à Meta, e o fbevents.js carrega depois do aceite. No modo básico, o código oficial completo vai no <head> e o <noscript> no rodapé. Páginas privadas nunca carregam o pixel pelo cabeçalho.', 'rastro' ),
			'padrao' => '1',
		),
		'tiktok'                       => array(
			'aba'        => 'geral',
			'tipo'       => 'id',
			'rotulo'     => __( 'ID do TikTok Pixel', 'rastro' ),
			'ajuda'      => __( 'Letras e números (de 15 a 30). No TikTok Ads, fica no Gerenciador de Eventos, ao lado do nome do pixel. Vazio desliga.', 'rastro' ),
			'padrao'     => '',
			'regex'      => '/^[A-Z0-9]{15,30}$/D',
			'maiusculas' => true,
			'erro'       => __( 'O ID do TikTok Pixel tem de 15 a 30 letras ou números.', 'rastro' ),
		),
		'openai'                       => array(
			'aba'    => 'geral',
			'tipo'   => 'id',
			'rotulo' => __( 'ID do pixel de anúncios da OpenAI', 'rastro' ),
			'ajuda'  => __( 'O valor de pixelId no código do pixel que a OpenAI fornece. Vazio desliga.', 'rastro' ),
			'padrao' => '',
			'regex'  => '/^[A-Za-z0-9_-]{10,64}$/D',
			'erro'   => __( 'O ID do pixel da OpenAI tem de 10 a 64 letras, números, hífen ou sublinhado.', 'rastro' ),
		),
		'openai_debug'                 => array(
			'aba'    => 'geral',
			'tipo'   => 'booleano',
			'rotulo' => __( 'Modo de teste da OpenAI', 'rastro' ),
			'marca'  => __( 'Ligar o modo de teste (debug) do pixel da OpenAI', 'rastro' ),
			'ajuda'  => __( 'Só para conferir a instalação. Desligue depois de conferir.', 'rastro' ),
			'padrao' => '0',
		),
		'posthog'                      => array(
			'aba'     => 'geral',
			'tipo'    => 'id',
			'rotulo'  => __( 'Chave do projeto do PostHog', 'rastro' ),
			'ajuda'   => __( 'Começa com phc_. No PostHog, fica nas configurações do projeto. Vazio desliga.', 'rastro' ),
			'padrao'  => '',
			'regex'   => '/^phc_[A-Za-z0-9]{20,80}$/D',
			'exemplo' => 'phc_...',
			'erro'    => __( 'A chave do PostHog começa com phc_ e tem de 20 a 80 letras ou números depois.', 'rastro' ),
		),
		'posthog_regiao'               => array(
			'aba'    => 'geral',
			'tipo'   => 'selecao',
			'rotulo' => __( 'Região do PostHog', 'rastro' ),
			'ajuda'  => __( 'A mesma região escolhida ao criar o projeto.', 'rastro' ),
			'padrao' => 'us',
			'opcoes' => array(
				'us' => __( 'Estados Unidos (us)', 'rastro' ),
				'eu' => __( 'Europa (eu)', 'rastro' ),
			),
		),
		'posthog_logs'                 => array(
			'aba'    => 'geral',
			'tipo'   => 'booleano',
			'rotulo' => __( 'Logs do PostHog', 'rastro' ),
			'marca'  => __( 'Enviar os logs do Rastro para o PostHog Logs', 'rastro' ),
			'ajuda'  => __( 'Só com a chave do PostHog. Falhas do Rastro no servidor (sem dado pessoal) e, depois do aceite de análise, no navegador.', 'rastro' ),
			'padrao' => '1',
		),
		'posthog_logs_console'         => array(
			'aba'    => 'geral',
			'tipo'   => 'booleano',
			'rotulo' => __( 'Console do navegador nos logs', 'rastro' ),
			'marca'  => __( 'Enviar também o console do navegador (console.log, warn e error)', 'rastro' ),
			'ajuda'  => __( 'Só para investigar um problema: o console pode levar o que outros scripts escrevem nele. Desligue depois de conferir.', 'rastro' ),
			'padrao' => '0',
		),
		'google_verificacao'           => array(
			'aba'             => 'geral',
			'tipo'            => 'id',
			'rotulo'          => __( 'Verificação do Google (Search Console)', 'rastro' ),
			'ajuda'           => __( 'O valor de content da tag google-site-verification. Pode colar a tag inteira: só o valor é guardado. Vazio desliga.', 'rastro' ),
			'padrao'          => '',
			'regex'           => '/^[A-Za-z0-9_-]{20,100}$/D',
			'extrair_content' => true,
			'erro'            => __( 'O código de verificação do Google tem de 20 a 100 letras, números, hífen ou sublinhado.', 'rastro' ),
		),
		'carregamento'                 => array(
			'aba'    => 'geral',
			'tipo'   => 'selecao',
			'rotulo' => __( 'Quando carregar as tags', 'rastro' ),
			'ajuda'  => __( 'As tags só carregam depois do aceite de cookies. Esta opção escolhe o momento, para a página não atrasar.', 'rastro' ),
			'padrao' => 'ocioso',
			'opcoes' => array(
				'ocioso'    => __( 'Quando o navegador estiver ocioso (recomendado)', 'rastro' ),
				'interacao' => __( 'Na primeira interação do visitante', 'rastro' ),
				'imediato'  => __( 'Logo depois do aceite', 'rastro' ),
			),
		),
		'eventos_loja'                 => array(
			'aba'    => 'eventos',
			'tipo'   => 'booleano',
			'rotulo' => __( 'Eventos da loja', 'rastro' ),
			'marca'  => __( 'Enviar os eventos da loja (WooCommerce)', 'rastro' ),
			'ajuda'  => __( 'Desligado, só a visita de página é medida e nenhum evento abaixo é enviado.', 'rastro' ),
			'padrao' => '1',
		),
	);

	foreach ( rastro_config_eventos() as $evento => $rotulo ) {
		$esquema[ 'evento_' . $evento ] = array(
			'aba'    => 'eventos',
			'tipo'   => 'booleano',
			'rotulo' => $rotulo,
			'marca'  => __( 'Enviar este evento', 'rastro' ),
			'ajuda'  => '',
			'padrao' => '1',
		);
	}

	$esquema += array(
		'valor_compra'                 => array(
			'aba'    => 'woocommerce',
			'tipo'   => 'selecao',
			'rotulo' => __( 'Valor enviado na compra', 'rastro' ),
			'ajuda'  => __( 'O valor que vai no evento de compra para as ferramentas de medição e de anúncio.', 'rastro' ),
			'padrao' => 'produtos',
			'opcoes' => array(
				'produtos' => __( 'Só os produtos, com desconto (sem frete e impostos)', 'rastro' ),
				'total'    => __( 'Total do pedido (com frete e impostos)', 'rastro' ),
			),
		),
		'id_produto'                   => array(
			'aba'    => 'woocommerce',
			'tipo'   => 'selecao',
			'rotulo' => __( 'Identificador do produto', 'rastro' ),
			'ajuda'  => __( 'Use o mesmo identificador do catálogo de produtos dos anúncios.', 'rastro' ),
			'padrao' => 'id',
			'opcoes' => array(
				'id'  => __( 'ID do produto', 'rastro' ),
				'sku' => __( 'SKU (código do produto)', 'rastro' ),
			),
		),
		'status_relatorio'             => array(
			'aba'       => 'woocommerce',
			'tipo'      => 'lista',
			'rotulo'    => __( 'Status contados nos relatórios', 'rastro' ),
			'ajuda'     => __( 'Pedidos nestes status entram nos relatórios de venda. Sem nenhum marcado, volta ao padrão.', 'rastro' ),
			'padrao'    => array( 'processing', 'completed' ),
			'opcoes_de' => 'rastro_config_status_opcoes',
		),
		'atribuicao'                   => array(
			'aba'    => 'woocommerce',
			'tipo'   => 'selecao',
			'rotulo' => __( 'Origem da venda', 'rastro' ),
			'ajuda'  => __( 'A origem da visita (campanha, site de onde veio, página de entrada) é gravada no pedido para os relatórios. A base legal da segunda opção é uma decisão da loja.', 'rastro' ),
			'padrao' => 'aceite',
			'opcoes' => array(
				'aceite'    => __( 'Guardar só depois do aceite de cookies de análise', 'rastro' ),
				'sessao'    => __( 'Guardar durante a visita antes do aceite (interesse legítimo)', 'rastro' ),
				'desligada' => __( 'Não guardar', 'rastro' ),
			),
		),
		'atribuicao_utm_sempre'        => array(
			'aba'    => 'woocommerce',
			'tipo'   => 'booleano',
			'rotulo' => __( 'Visita de campanha', 'rastro' ),
			'marca'  => __( 'Guardar a origem de quem chega por link de campanha (UTM ou ID de clique) mesmo antes do aceite', 'rastro' ),
			'ajuda'  => __( 'Só no próprio site (cookie rastro_origem), nada vai para terceiros. A base legal (interesse legítimo) é uma decisão da loja.', 'rastro' ),
			'padrao' => '1',
		),
		'atribuicao_dias'              => array(
			'aba'    => 'woocommerce',
			'tipo'   => 'inteiro',
			'rotulo' => __( 'Dias de validade da origem', 'rastro' ),
			'ajuda'  => __( 'Por quantos dias a origem da visita fica guardada no navegador (de 1 a 365).', 'rastro' ),
			'padrao' => 90,
			'min'    => 1,
			'max'    => 365,
		),
		'atribuicao_woo_consentimento' => array(
			'aba'    => 'woocommerce',
			'tipo'   => 'booleano',
			'rotulo' => __( 'Atribuição nativa do WooCommerce', 'rastro' ),
			'marca'  => __( 'Esperar o aceite de cookies de análise', 'rastro' ),
			'ajuda'  => __( 'A atribuição de pedidos do próprio WooCommerce só grava os cookies dela depois do aceite.', 'rastro' ),
			'padrao' => '1',
		),
		'modo'                         => array(
			'aba'    => 'consentimento',
			'tipo'   => 'selecao',
			'rotulo' => __( 'Modo de consentimento', 'rastro' ),
			'ajuda'  => __( 'LGPD: nada de medição antes do "Aceitar". Aceite automático: o aviso some sozinho e liga só a análise; parado não é concordar, então este modo não atende a LGPD (risco da loja). Básico: sem aviso, mede todo mundo; só para sites que decidiram não pedir consentimento.', 'rastro' ),
			'padrao' => 'lgpd',
			'opcoes' => array(
				'lgpd'              => __( 'LGPD: pedir consentimento (recomendado)', 'rastro' ),
				'aceite_automatico' => __( 'Aviso com aceite automático da análise (não atende a LGPD)', 'rastro' ),
				'basico'            => __( 'Básico: sem aviso, mede sempre', 'rastro' ),
			),
		),
		'aceite_segundos'              => array(
			'aba'    => 'consentimento',
			'tipo'   => 'inteiro',
			'rotulo' => __( 'Segundos até o aceite automático', 'rastro' ),
			'ajuda'  => __( 'Só no modo de aceite automático: depois desse tempo sem escolha, o aviso some e a análise liga (de 1 a 30). Publicidade nunca liga sozinha.', 'rastro' ),
			'padrao' => 2,
			'min'    => 1,
			'max'    => 30,
		),
		'aviso'                        => array(
			'aba'    => 'consentimento',
			'tipo'   => 'selecao',
			'rotulo' => __( 'Aviso de cookies', 'rastro' ),
			'ajuda'  => __( 'Com a última opção e nenhum aviso externo ativo, nenhuma tag carrega.', 'rastro' ),
			'padrao' => 'automatico',
			'opcoes' => array(
				'automatico' => __( 'Automático: usa o aviso que o site já tem; sem ele, mostra o aviso do Rastro', 'rastro' ),
				'proprio'    => __( 'Sempre o aviso do Rastro', 'rastro' ),
				'externo'    => __( 'Só o aviso que o site já tem', 'rastro' ),
			),
		),
		'aviso_texto'                  => array(
			'aba'    => 'consentimento',
			'tipo'   => 'texto',
			'rotulo' => __( 'Texto do aviso do Rastro', 'rastro' ),
			'ajuda'  => __( 'Até 400 caracteres, sem HTML. Vazio volta ao texto padrão.', 'rastro' ),
			'padrao' => __( 'Usamos cookies para medir o uso do site e os nossos anúncios, só com a sua permissão.', 'rastro' ),
			'max'    => 400,
			'erro'   => __( 'O texto do aviso tem até 400 caracteres e não aceita HTML.', 'rastro' ),
		),
		'aviso_dias'                   => array(
			'aba'    => 'consentimento',
			'tipo'   => 'inteiro',
			'rotulo' => __( 'Dias de validade da escolha', 'rastro' ),
			'ajuda'  => __( 'Depois desse prazo, o aviso pergunta de novo (de 30 a 395 dias).', 'rastro' ),
			'padrao' => 180,
			'min'    => 30,
			'max'    => 395,
		),
		'medir_equipe'                 => array(
			'aba'    => 'consentimento',
			'tipo'   => 'booleano',
			'rotulo' => __( 'Medir a equipe', 'rastro' ),
			'marca'  => __( 'Medir também quem está logado como equipe do site', 'rastro' ),
			'ajuda'  => __( 'Desligado (recomendado), quem pode editar o site ou gerenciar a loja não entra nos números.', 'rastro' ),
			'padrao' => '0',
		),
	);

	$memoria['esquema'] = $esquema;

	return $esquema;
}

/**
 * Valores padrão de todos os campos.
 *
 * @return array<string,mixed>
 */
function rastro_config_padrao(): array {
	$padrao = array();
	foreach ( rastro_config_esquema() as $chave => $campo ) {
		$padrao[ $chave ] = $campo['padrao'];
	}

	return $padrao;
}

/**
 * Status de pedido aceitos em `status_relatorio`: código sem o prefixo
 * `wc-` => rótulo. Vem do WooCommerce quando ele já está pronto (depois do
 * `init`, para não carregar traduções cedo demais); senão, a lista fechada
 * dos status nativos.
 *
 * @return array<string,string>
 */
function rastro_config_status_opcoes(): array {
	$opcoes = array();
	if ( function_exists( 'wc_get_order_statuses' ) && did_action( 'init' ) ) {
		foreach ( (array) wc_get_order_statuses() as $codigo => $rotulo ) {
			$codigo = is_string( $codigo ) ? (string) preg_replace( '/^wc-/', '', strtolower( $codigo ) ) : '';
			if ( 1 === preg_match( '/^[a-z0-9_-]{1,40}$/D', $codigo ) ) {
				$opcoes[ $codigo ] = is_string( $rotulo ) && '' !== $rotulo ? $rotulo : $codigo;
			}
		}
	}
	if ( array() !== $opcoes ) {
		return $opcoes;
	}

	return array(
		'pending'    => __( 'Pagamento pendente', 'rastro' ),
		'processing' => __( 'Processando', 'rastro' ),
		'on-hold'    => __( 'Aguardando', 'rastro' ),
		'completed'  => __( 'Concluído', 'rastro' ),
		'cancelled'  => __( 'Cancelado', 'rastro' ),
		'refunded'   => __( 'Reembolsado', 'rastro' ),
		'failed'     => __( 'Malsucedido', 'rastro' ),
	);
}

/**
 * Opções de um campo de seleção ou de lista: valor => rótulo.
 *
 * @param array $campo Entrada do esquema.
 * @return array<string,string>
 */
function rastro_config_opcoes( array $campo ): array {
	if ( isset( $campo['opcoes'] ) && is_array( $campo['opcoes'] ) ) {
		return $campo['opcoes'];
	}
	if ( isset( $campo['opcoes_de'] ) && is_string( $campo['opcoes_de'] ) && 0 === strpos( $campo['opcoes_de'], 'rastro_' ) && function_exists( $campo['opcoes_de'] ) ) {
		$opcoes = call_user_func( $campo['opcoes_de'] );
		return is_array( $opcoes ) ? $opcoes : array();
	}

	return array();
}

/* Validação ---------------------------------------------------------------- */

/**
 * Valida um campo. Devolve o valor limpo, '' para vazio e null para inválido.
 *
 * @param string $chave Chave do esquema.
 * @param mixed  $bruto Valor digitado ou guardado.
 * @return mixed
 */
function rastro_config_validar_campo( string $chave, $bruto ) {
	$esquema = rastro_config_esquema();
	if ( ! isset( $esquema[ $chave ] ) ) {
		return null;
	}
	$campo = $esquema[ $chave ];

	if ( null === $bruto ) {
		return '';
	}

	switch ( $campo['tipo'] ) {
		case 'booleano':
			if ( true === $bruto || 1 === $bruto || '1' === $bruto ) {
				return '1';
			}
			if ( false === $bruto || 0 === $bruto || '0' === $bruto ) {
				return '0';
			}
			return '' === $bruto ? '' : null;

		case 'inteiro':
			if ( '' === $bruto ) {
				return '';
			}
			if ( is_string( $bruto ) ) {
				$bruto = trim( $bruto );
				if ( '' === $bruto ) {
					return '';
				}
				if ( 1 !== preg_match( '/^[0-9]{1,6}$/D', $bruto ) ) {
					return null;
				}
				$bruto = (int) $bruto;
			}
			if ( ! is_int( $bruto ) ) {
				return null;
			}
			return $bruto >= (int) $campo['min'] && $bruto <= (int) $campo['max'] ? $bruto : null;

		case 'lista':
			if ( is_string( $bruto ) ) {
				$bruto = '' === trim( $bruto ) ? array() : explode( ',', $bruto );
			}
			if ( ! is_array( $bruto ) ) {
				return null;
			}
			if ( array() === $bruto ) {
				return '';
			}
			$enviados = array();
			foreach ( $bruto as $item ) {
				if ( is_string( $item ) ) {
					$enviados[] = (string) preg_replace( '/^wc-/', '', strtolower( trim( $item ) ) );
				}
			}
			// Só o que existe, na ordem das opções e sem repetir.
			$limpos = array_values( array_intersect( array_map( 'strval', array_keys( rastro_config_opcoes( $campo ) ) ), $enviados ) );
			return array() === $limpos ? null : $limpos;
	}

	// Daqui em diante, só texto.
	if ( ! is_scalar( $bruto ) || is_bool( $bruto ) ) {
		return null;
	}
	$valor = trim( (string) $bruto );
	if ( '' === $valor ) {
		return '';
	}

	switch ( $campo['tipo'] ) {
		case 'id':
			// Colaram a tag <meta> inteira: fica só o valor de content.
			if ( ! empty( $campo['extrair_content'] ) && 1 === preg_match( '/\bcontent\s*=\s*(["\'])([^"\']*)\1/i', $valor, $partes ) ) {
				$valor = trim( $partes[2] );
			}
			if ( ! empty( $campo['maiusculas'] ) ) {
				$valor = strtoupper( $valor );
			}
			return 1 === preg_match( (string) $campo['regex'], $valor ) ? $valor : null;

		case 'selecao':
			$valor = strtolower( $valor );
			return array_key_exists( $valor, rastro_config_opcoes( $campo ) ) ? $valor : null;

		case 'texto':
			$valor = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $valor ) : strip_tags( $valor ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- reserva sem WordPress.
			$valor = preg_replace( '/[\x00-\x1F\x7F\s]+/u', ' ', (string) $valor );
			if ( ! is_string( $valor ) ) {
				// Texto que não é UTF-8 válido.
				return null;
			}
			$valor = trim( $valor );
			if ( '' === $valor ) {
				return '';
			}
			$tamanho = function_exists( 'mb_strlen' ) ? mb_strlen( $valor, 'UTF-8' ) : strlen( $valor );
			if ( $tamanho > (int) $campo['max'] || 1 === preg_match( '/[<>]/', $valor ) ) {
				return null;
			}
			return $valor;
	}

	return null;
}

/**
 * Valor que vai para a configuração a partir do resultado da validação:
 * inválido vira o padrão; vazio fica vazio nos IDs e vira o padrão no resto.
 *
 * @param array $campo Entrada do esquema.
 * @param mixed $limpo Saída de rastro_config_validar_campo().
 * @return mixed
 */
function rastro_config_normalizar( array $campo, $limpo ) {
	if ( null === $limpo ) {
		return $campo['padrao'];
	}
	if ( '' === $limpo && 'id' !== $campo['tipo'] ) {
		return $campo['padrao'];
	}

	return $limpo;
}

/**
 * Limpa um array inteiro de configuração. Inválido ou ausente vira o padrão.
 *
 * @param mixed $bruto Array guardado ou enviado.
 * @return array<string,mixed>
 */
function rastro_config_ler( $bruto ): array {
	$bruto = is_array( $bruto ) ? $bruto : array();
	$limpo = array();
	foreach ( rastro_config_esquema() as $chave => $campo ) {
		$valor           = array_key_exists( $chave, $bruto ) ? rastro_config_validar_campo( $chave, $bruto[ $chave ] ) : null;
		$limpo[ $chave ] = rastro_config_normalizar( $campo, $valor );
	}

	return $limpo;
}

/* Leitura e gravação -------------------------------------------------------- */

/**
 * Configuração guardada no banco, revalidada, sem o filtro `rastro_config`
 * (é a que a tela mostra e a que o salvar usa como valor anterior).
 *
 * @return array<string,mixed>
 */
function rastro_config_guardada(): array {
	return rastro_config_ler( get_option( RASTRO_OPCAO, array() ) );
}

/**
 * Configuração em uso: opção lida e validada, com o filtro `rastro_config`.
 * Lida uma vez por requisição (a memória só vale depois do `init`, quando a
 * lista de status do WooCommerce já existe).
 *
 * @return array<string,mixed>
 */
function rastro_config(): array {
	$memoria = &rastro_config_memoria();
	if ( is_array( $memoria['config'] ) ) {
		return $memoria['config'];
	}

	$config = rastro_config_guardada();

	/**
	 * Filtra a configuração em uso (não a guardada). O resultado passa de
	 * novo pela validação.
	 *
	 * @param array $config Configuração validada.
	 */
	$filtrada = apply_filters( 'rastro_config', $config );
	if ( is_array( $filtrada ) && $filtrada !== $config ) {
		$config = rastro_config_ler( $filtrada );
	}

	if ( did_action( 'init' ) ) {
		$memoria['config'] = $config;
	}

	return $config;
}

/**
 * Um campo da configuração em uso (null para chave que não existe).
 *
 * @param string $chave Chave do esquema.
 * @return mixed
 */
function rastro_config_campo( string $chave ) {
	$config = rastro_config();

	return array_key_exists( $chave, $config ) ? $config[ $chave ] : null;
}

/**
 * Um campo de liga e desliga está ligado?
 *
 * @param string $chave Chave do esquema.
 * @return bool
 */
function rastro_config_ligado( string $chave ): bool {
	return '1' === rastro_config_campo( $chave );
}

/**
 * Mensagem de erro de um campo, em pt-BR.
 *
 * @param string $chave Chave do esquema.
 * @return string
 */
function rastro_config_erro( string $chave ): string {
	$esquema = rastro_config_esquema();
	$campo   = isset( $esquema[ $chave ] ) ? $esquema[ $chave ] : array();
	$manteve = __( 'O valor anterior foi mantido.', 'rastro' );

	if ( isset( $campo['erro'] ) && is_string( $campo['erro'] ) && '' !== $campo['erro'] ) {
		return $campo['erro'] . ' ' . $manteve;
	}
	if ( isset( $campo['tipo'], $campo['min'], $campo['max'] ) && 'inteiro' === $campo['tipo'] ) {
		/* translators: 1: rótulo do campo, 2: mínimo, 3: máximo. */
		return sprintf( __( 'O campo "%1$s" aceita um número inteiro de %2$d a %3$d.', 'rastro' ), (string) $campo['rotulo'], (int) $campo['min'], (int) $campo['max'] ) . ' ' . $manteve;
	}

	/* translators: %s: rótulo do campo. */
	return sprintf( __( 'O valor do campo "%s" não é válido.', 'rastro' ), isset( $campo['rotulo'] ) ? (string) $campo['rotulo'] : $chave ) . ' ' . $manteve;
}

/**
 * Grava os campos recebidos (os outros ficam como estão). Campo inválido
 * mantém o valor anterior e devolve a mensagem. Quem chama confere a
 * permissão e o nonce (admin/admin.php).
 *
 * @param array $bruto chave => valor enviado.
 * @return array{config:array<string,mixed>,erros:array<string,string>}
 */
function rastro_config_salvar( array $bruto ): array {
	$esquema = rastro_config_esquema();
	$novo    = rastro_config_guardada();
	$erros   = array();

	foreach ( $bruto as $chave => $valor ) {
		if ( ! is_string( $chave ) || ! isset( $esquema[ $chave ] ) ) {
			continue;
		}
		$limpo = rastro_config_validar_campo( $chave, $valor );
		if ( null === $limpo ) {
			$erros[ $chave ] = rastro_config_erro( $chave );
			continue;
		}
		$novo[ $chave ] = rastro_config_normalizar( $esquema[ $chave ], $limpo );
	}

	update_option( RASTRO_OPCAO, $novo, true );

	$memoria           = &rastro_config_memoria();
	$memoria['config'] = null;

	return array(
		'config' => $novo,
		'erros'  => $erros,
	);
}

/**
 * Há algum destino configurado (ID válido de alguma tag)?
 *
 * @return bool
 */
function rastro_tem_destino(): bool {
	$config = rastro_config();
	foreach ( rastro_config_destinos() as $chave ) {
		if ( isset( $config[ $chave ] ) && is_string( $config[ $chave ] ) && '' !== $config[ $chave ] ) {
			return true;
		}
	}

	return false;
}
