<?php
/**
 * Rastro: planilha do Excel (.xlsx) sem biblioteca (2026-11-13).
 *
 * Pedido original: a loja usa o Microsoft Excel e prefere baixar os
 * relatórios em planilha do Excel, não só em CSV. O .xlsx abre direto no
 * Excel, com acento, moeda em reais e números que somam, sem a tela de
 * importação do CSV.
 *
 * Como é feito: o .xlsx é um pacote ZIP com alguns arquivos XML (padrão Office
 * Open XML). Este arquivo monta os XML de uma planilha só e o ZIP sem
 * compressão (método "store"), com funções do próprio PHP (`pack`, `crc32`).
 * Não depende da extensão zip do servidor nem de biblioteca externa.
 *
 * Segurança: todo texto vai como "texto em linha" (`inlineStr`), que o Excel
 * nunca trata como fórmula; caracteres que o XML não aceita são retirados.
 *
 * @package Rastro
 */

defined( 'ABSPATH' ) || exit;

/** Tipo MIME do .xlsx. */
const RASTRO_XLSX_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

/**
 * Estilos da planilha (índice em `cellXfs` de rastro_xlsx_estilos()).
 * Negrito para o cabeçalho e a linha do total.
 */
const RASTRO_XLSX_ESTILOS = array(
	'texto'          => 0,
	'texto_negrito'  => 1,
	'moeda'          => 2,
	'moeda_negrito'  => 3,
	'inteiro'        => 4,
	'inteiro_negrito' => 5,
);

/**
 * Texto seguro para XML: sem caracteres de controle e com as entidades.
 *
 * @param mixed $valor Valor.
 * @return string
 */
function rastro_xlsx_xml( $valor ): string {
	if ( ! is_scalar( $valor ) ) {
		return '';
	}
	$texto = (string) $valor;
	if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $texto, 'UTF-8' ) ) {
		$texto = function_exists( 'mb_convert_encoding' ) ? (string) mb_convert_encoding( $texto, 'UTF-8', 'ISO-8859-1' ) : '';
	}
	$limpo = preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $texto );

	return htmlspecialchars( null === $limpo ? '' : $limpo, ENT_QUOTES | ENT_XML1, 'UTF-8' );
}

/**
 * Letra da coluna: 0 => A, 25 => Z, 26 => AA.
 *
 * @param int $indice Índice a partir de zero.
 * @return string
 */
function rastro_xlsx_coluna( int $indice ): string {
	$letras = '';
	$indice = max( 0, $indice );
	do {
		$letras = chr( 65 + ( $indice % 26 ) ) . $letras;
		$indice = intdiv( $indice, 26 ) - 1;
	} while ( $indice >= 0 );

	return $letras;
}

/**
 * Uma célula em XML.
 *
 * Aceita texto/número simples (texto) ou array
 * `array( 'valor' => ..., 'tipo' => 'texto'|'moeda'|'inteiro', 'negrito' => bool )`.
 * Moeda e inteiro só viram número quando o valor é numérico; senão viram
 * texto.
 *
 * @param mixed  $celula     Célula.
 * @param string $referencia Referência ("B3").
 * @return string
 */
function rastro_xlsx_celula( $celula, string $referencia ): string {
	$dados   = is_array( $celula ) ? $celula : array( 'valor' => $celula );
	$valor   = $dados['valor'] ?? '';
	$tipo    = isset( $dados['tipo'] ) && in_array( $dados['tipo'], array( 'texto', 'moeda', 'inteiro' ), true ) ? $dados['tipo'] : 'texto';
	$negrito = ! empty( $dados['negrito'] );

	if ( 'texto' !== $tipo && is_numeric( $valor ) && is_finite( (float) $valor ) ) {
		$estilo = RASTRO_XLSX_ESTILOS[ $tipo . ( $negrito ? '_negrito' : '' ) ];
		$numero = 'inteiro' === $tipo ? (string) (int) $valor : rtrim( rtrim( sprintf( '%.2F', (float) $valor ), '0' ), '.' );

		return '<c r="' . $referencia . '" s="' . $estilo . '"><v>' . ( '' === $numero || '-' === $numero ? '0' : $numero ) . '</v></c>';
	}

	$estilo = $negrito ? RASTRO_XLSX_ESTILOS['texto_negrito'] : RASTRO_XLSX_ESTILOS['texto'];

	return '<c r="' . $referencia . '" t="inlineStr"' . ( $estilo ? ' s="' . $estilo . '"' : '' ) . '><is><t xml:space="preserve">' . rastro_xlsx_xml( $valor ) . '</t></is></c>';
}

/**
 * XML da planilha (folha 1).
 *
 * @param array $linhas   Linhas de células.
 * @param array $larguras Largura de cada coluna (caracteres), opcional.
 * @return string
 */
function rastro_xlsx_folha( array $linhas, array $larguras = array() ): string {
	$xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
		. '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

	// Cabeçalho fixo ao rolar.
	$xml .= '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';

	if ( $larguras ) {
		$xml .= '<cols>';
		foreach ( array_values( $larguras ) as $i => $largura ) {
			$largura = max( 4, min( 100, (float) $largura ) );
			$xml    .= '<col min="' . ( $i + 1 ) . '" max="' . ( $i + 1 ) . '" width="' . $largura . '" customWidth="1"/>';
		}
		$xml .= '</cols>';
	}

	$xml .= '<sheetData>';
	foreach ( array_values( $linhas ) as $l => $linha ) {
		$numero = $l + 1;
		$xml   .= '<row r="' . $numero . '">';
		foreach ( array_values( (array) $linha ) as $c => $celula ) {
			$xml .= rastro_xlsx_celula( $celula, rastro_xlsx_coluna( $c ) . $numero );
		}
		$xml .= '</row>';
	}

	return $xml . '</sheetData></worksheet>';
}

/**
 * XML dos estilos: fonte normal e negrito, moeda "R$ 1.234,56" e inteiro com
 * milhar (o separador segue o Excel de quem abre).
 *
 * @return string
 */
function rastro_xlsx_estilos(): string {
	return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
		. '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
		. '<numFmts count="1"><numFmt numFmtId="164" formatCode="&quot;R$&quot;\ #,##0.00"/></numFmts>'
		. '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
		. '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
		. '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
		. '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
		. '<cellXfs count="6">'
		. '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
		. '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
		. '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
		. '<xf numFmtId="164" fontId="1" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/>'
		. '<xf numFmtId="3" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
		. '<xf numFmtId="3" fontId="1" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/>'
		. '</cellXfs>'
		. '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
		. '</styleSheet>';
}

/**
 * Nome da aba: até 31 caracteres, sem : \ / ? * [ ].
 *
 * @param string $nome Nome.
 * @return string
 */
function rastro_xlsx_nome_aba( string $nome ): string {
	$limpo = trim( (string) preg_replace( '/[:\\\\\/\?\*\[\]]+/u', ' ', $nome ) );
	if ( function_exists( 'mb_substr' ) ) {
		$limpo = mb_substr( $limpo, 0, 31, 'UTF-8' );
	} else {
		$limpo = substr( $limpo, 0, 31 );
	}

	return '' === $limpo ? 'Planilha' : $limpo;
}

/**
 * Arquivos do pacote .xlsx (caminho => conteúdo).
 *
 * @param array  $linhas   Linhas de células.
 * @param string $aba      Nome da aba.
 * @param array  $larguras Largura de cada coluna.
 * @return array<string,string>
 */
function rastro_xlsx_arquivos( array $linhas, string $aba = 'Relatório', array $larguras = array() ): array {
	$cabecalho = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";

	return array(
		'[Content_Types].xml'        => $cabecalho
			. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
			. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
			. '<Default Extension="xml" ContentType="application/xml"/>'
			. '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
			. '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
			. '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
			. '</Types>',
		'_rels/.rels'                => $cabecalho
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
			. '</Relationships>',
		'xl/workbook.xml'            => $cabecalho
			. '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
			. '<bookViews><workbookView/></bookViews>'
			. '<sheets><sheet name="' . rastro_xlsx_xml( rastro_xlsx_nome_aba( $aba ) ) . '" sheetId="1" r:id="rId1"/></sheets>'
			. '</workbook>',
		'xl/_rels/workbook.xml.rels' => $cabecalho
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
			. '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
			. '</Relationships>',
		'xl/worksheets/sheet1.xml'   => rastro_xlsx_folha( $linhas, $larguras ),
		'xl/styles.xml'              => rastro_xlsx_estilos(),
	);
}

/**
 * Pacote ZIP sem compressão (método 0, "store"), nomes em UTF-8.
 *
 * @param array<string,string> $arquivos Caminho => conteúdo.
 * @param int                  $quando   Data dos arquivos (Unix).
 * @return string
 */
function rastro_xlsx_zip( array $arquivos, int $quando = 0 ): string {
	// Data em UTC, entre 1980 (início do ZIP) e 2107 (fim do campo de 7 bits).
	$quando = $quando > 0 ? $quando : time();
	$partes = array_map( 'intval', explode( ' ', gmdate( 'Y n j G i s', $quando ) ) );
	if ( $partes[0] < 1980 ) {
		$partes = array( 1980, 1, 1, 0, 0, 0 );
	} elseif ( $partes[0] > 2107 ) {
		$partes = array( 2107, 12, 31, 23, 59, 58 );
	}
	$hora = ( $partes[3] << 11 ) | ( $partes[4] << 5 ) | intdiv( $partes[5], 2 );
	$data = ( ( $partes[0] - 1980 ) << 9 ) | ( $partes[1] << 5 ) | $partes[2];

	$corpo   = '';
	$central = '';
	$total   = 0;
	foreach ( $arquivos as $nome => $conteudo ) {
		$nome     = (string) $nome;
		$conteudo = (string) $conteudo;
		$crc      = crc32( $conteudo );
		$tamanho  = strlen( $conteudo );
		$posicao  = strlen( $corpo );

		// Cabeçalho local: assinatura, versão 2.0, bit 11 (nome UTF-8), store.
		$corpo .= pack( 'VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 0, $hora, $data, $crc, $tamanho, $tamanho, strlen( $nome ), 0 ) . $nome . $conteudo;

		$central .= pack( 'VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, 0, $hora, $data, $crc, $tamanho, $tamanho, strlen( $nome ), 0, 0, 0, 0, 0, $posicao ) . $nome;
		++$total;
	}

	return $corpo . $central . pack( 'VvvvvVVv', 0x06054b50, 0, 0, $total, $total, strlen( $central ), strlen( $corpo ), 0 );
}

/**
 * Planilha .xlsx pronta para baixar.
 *
 * @param array  $linhas   Linhas de células (ver rastro_xlsx_celula()).
 * @param string $aba      Nome da aba.
 * @param array  $larguras Largura de cada coluna.
 * @param int    $quando   Data dos arquivos dentro do pacote.
 * @return string Bytes do arquivo.
 */
function rastro_xlsx( array $linhas, string $aba = 'Relatório', array $larguras = array(), int $quando = 0 ): string {
	return rastro_xlsx_zip( rastro_xlsx_arquivos( $linhas, $aba, $larguras ), $quando );
}
