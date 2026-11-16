'use strict';

// Contrato da base do Rastro (T1): cabeçalho de plugin, carregador, esquema
// da configuração, regras de onde medir, saída para o navegador e painel.
// Contrato: docs/CONTRATO.md (seções
// 1, 3.1 a 3.4, 10 e 12). Manual: docs/BASE.md. Parte estática no
// node (sempre); php -l e o harness tests/php/rastro-base.test.php rodam só
// quando há `php` no PATH.

const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
// Fim de linha: confere o que vai para o Git (o índice), não a cópia em disco,
// que no Windows com core.autocrlf=true sai em CRLF. Arquivo ainda não
// adicionado ao índice é lido do disco.
const lerBruto = (p) => {
  try {
    return require('child_process').execFileSync('git', ['show', `:${p}`], { cwd: root, encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] });
  } catch (e) {
    return fs.readFileSync(path.join(root, p), 'utf8');
  }
};
const ler = (p) => fs.readFileSync(path.join(root, p), 'utf8').replace(/\r\n/g, '\n');
const comMatch = (t, re, m) => assert.ok(re.test(t), m || String(re));
const semMatch = (t, re, m) => assert.ok(!re.test(t), m || String(re));
const tem = (t, s, m) => assert.ok(t.includes(s), m || s);

const R = 'rastro/';
// Travessão (U+2014) montado pelo código, para este arquivo não conter o caractere.
const TRAVESSAO = new RegExp(String.fromCharCode(0x2014));
// O nome da plataforma de código, montado em partes pelo mesmo motivo.
const PLATAFORMA = new RegExp('Git' + 'Hub', 'i');

// 1. Arquivos da frente (todos com fim de linha LF).
const arquivosT1 = [
  R + 'rastro.php',
  R + 'nucleo/config.php',
  R + 'nucleo/regras.php',
  R + 'nucleo/saida.php',
  R + 'admin/admin.php',
  R + 'assets/css/admin.css',
  'tests/validate-rastro-base-contract.js',
  'tests/php/rastro-base.test.php',
  'tests/php/stubs-rastro.php',
  'docs/BASE.md',
];
for (const arquivo of arquivosT1) {
  assert.ok(fs.existsSync(path.join(root, arquivo)), `existe ${arquivo}`);
  semMatch(lerBruto(arquivo), /\r/, `${arquivo} com fim de linha LF`);
}

// Todos os arquivos dentro da pasta do Rastro (as outras frentes também
// passam por estas regras gerais do contrato, seção 1).
function listar(pasta) {
  const saida = [];
  for (const item of fs.readdirSync(path.join(root, pasta), { withFileTypes: true })) {
    const rel = pasta + item.name;
    if (item.isDirectory()) {
      saida.push(...listar(rel + '/'));
    } else {
      saida.push(rel);
    }
  }
  return saida;
}
const doRastro = listar(R);
const phpDoRastro = doRastro.filter((f) => f.endsWith('.php'));
// Os seis manuais do plugin (telas e textos que chegam ao WordPress). Os
// documentos do repositório (README, CONTRATO, ATUALIZADOR, RELEASE,
// SECURITY) citam a plataforma de código de propósito: é de lá que vêm as
// versões publicadas.
const docsRastro = ['BASE', 'CONSENTIMENTO', 'TAGS', 'EVENTOS', 'ATRIBUICAO', 'RELATORIOS'].map((n) => `docs/${n}.md`).filter((f) => fs.existsSync(path.join(root, f)));
const testesRastro = fs.readdirSync(path.join(root, 'tests')).filter((f) => /^validate-rastro-.*\.js$/.test(f)).map((f) => 'tests/' + f)
  .concat(fs.readdirSync(path.join(root, 'tests/php')).filter((f) => /^(rastro-.*\.test|stubs-rastro)\.php$/.test(f)).map((f) => 'tests/php/' + f));

for (const arquivo of doRastro) {
  const texto = ler(arquivo);
  semMatch(texto, /animalart/i, `${arquivo} sem dependência do Animalart`);
  semMatch(texto, /HTTP_HOST/, `${arquivo} sem o host do cabeçalho da requisição`);
  semMatch(texto, /innerHTML/, `${arquivo} sem innerHTML`);
}
for (const arquivo of [...doRastro, ...docsRastro, ...testesRastro]) {
  const texto = ler(arquivo);
  semMatch(texto, TRAVESSAO, `${arquivo} sem travessão`);
  semMatch(texto, PLATAFORMA, `${arquivo} sem o nome da plataforma de código`);
}
for (const arquivo of phpDoRastro) {
  const texto = ler(arquivo);
  comMatch(texto, /^<\?php\n[\s\S]*?\ndefined\( 'ABSPATH' \) \|\| exit;\n/, `${arquivo} com guarda ABSPATH`);
  // Text domain sempre 'rastro'.
  const chamadas = texto.match(/\b(?:__|_e|_x|_n|esc_html__|esc_attr__|esc_html_e|esc_attr_e)\(/g) || [];
  const comDominio = texto.match(/\b(?:__|_e|esc_html__|esc_attr__|esc_html_e|esc_attr_e)\(\s*'(?:[^'\\]|\\.)*'\s*,\s*'rastro'\s*\)|\b_x\(\s*'(?:[^'\\]|\\.)*'\s*,\s*'(?:[^'\\]|\\.)*'\s*,\s*'rastro'\s*\)|\b_n\([^;]*?,\s*'rastro'\s*\)/g) || [];
  assert.equal(comDominio.length, chamadas.length, `${arquivo}: toda tradução com o text domain 'rastro'`);
  // Toda expressão com âncora de fim usa /D (sem aceitar quebra de linha no fim).
  for (const [, delim, flags] of texto.matchAll(/'([/#])\^(?:[^'\\]|\\.)*?\$\1([a-zA-Z]*)'/g)) {
    assert.ok(flags.includes('D'), `${arquivo}: expressão com ${delim}^...$${delim} sem /D`);
  }
  // Única exceção: a trava curta da compra (opção técnica rastro_compra_trava_<id>,
  // revisão de 2026-11-11), tirada depois de marcar o pedido.
  const semTrava = arquivo.endsWith('loja/eventos.php') ? texto.replace(/delete_option\( \$(trava|nome) \);/g, '') : texto;
  semMatch(semTrava, /delete_option\(/, `${arquivo}: nada é apagado (soft delete)`);
  semMatch(texto, /\beval\(|base64_decode\(|\$_REQUEST\b/, `${arquivo} sem eval, base64_decode e $_REQUEST`);
}

// 2. Cabeçalho de plugin, constantes e carregador (seção 3).
const plugin = ler(R + 'rastro.php');
for (const [campo, re] of [
  ['Plugin Name', /^ \* Plugin Name:\s+Rastro$/m],
  ['Description', /^ \* Description:\s+\S.*(consentimento|relatórios).*$/m],
  ['Version', /^ \* Version:\s+0\.1\.0$/m],
  ['Requires PHP', /^ \* Requires PHP:\s+7\.4$/m],
  ['Text Domain', /^ \* Text Domain:\s+rastro$/m],
  ['License', /^ \* License:\s+GPL-2\.0-or-later$/m],
  ['Author', /^ \* Author:\s+Samuel Neves$/m],
]) {
  comMatch(plugin, re, `cabeçalho: ${campo}`);
}
const guarda = plugin.indexOf("if ( defined( 'RASTRO_VERSAO' ) ) {\n\treturn;\n}");
assert.ok(guarda > 0, 'guarda do plugin próprio');
assert.ok(guarda < plugin.indexOf("define( 'RASTRO_VERSAO', '0.1.0' );"), 'guarda antes de definir a versão');
assert.ok(guarda < plugin.indexOf('function rastro_modulos'), 'guarda antes das funções');
tem(plugin, "if ( ! function_exists( 'rastro_modulos' ) ) {", 'funções do carregador condicionais (duas cópias sem erro fatal)');
tem(plugin, "define( 'RASTRO_ARQUIVO', __FILE__ );");
tem(plugin, "define( 'RASTRO_DIR', __DIR__ . '/' );");
tem(plugin, "define( 'RASTRO_URL', rtrim( (string) plugins_url( '', RASTRO_ARQUIVO ), '/' ) . '/' );");
const lista = plugin.slice(plugin.indexOf('$modulos = array('), plugin.indexOf("apply_filters( 'rastro_modulos'"));
const modulos = [...lista.matchAll(/'([a-z/._-]+\.php)'/g)].map((m) => m[1]);
assert.deepEqual(modulos, [
  'nucleo/config.php', 'nucleo/regras.php', 'nucleo/saida.php',
  'consentimento/consentimento.php', 'consentimento/aviso.php',
  'tags/tags.php', 'loja/eventos.php',
  'atribuicao/atribuicao.php', 'atribuicao/pedido.php',
  'relatorios/dados.php', 'relatorios/relatorios.php',
  'admin/admin.php',
], 'lista completa de módulos, na ordem do contrato');
comMatch(lista, /if \( is_admin\(\) \) \{\n\t+\$modulos\[\] = 'admin\/admin\.php';/, 'painel só no painel');
const carregarArquivo = plugin.slice(plugin.indexOf('function rastro_carregar_arquivo'), plugin.indexOf('function rastro_carregar()'));
assert.ok(carregarArquivo.indexOf('is_readable( $caminho )') < carregarArquivo.indexOf('require_once $caminho;'), 'is_readable antes de carregar');
tem(carregarArquivo, 'catch ( \\Throwable $erro )');
tem(carregarArquivo, "do_action( 'rastro_falha', $modulo, $erro );");
tem(plugin, "do_action( 'rastro_carregado' );");
comMatch(plugin, /\nrastro_carregar\(\);\n$/, 'carga imediata ao incluir');

// 3. Configuração (seção 3.1).
const config = ler(R + 'nucleo/config.php');
for (const assinatura of [
  'function rastro_config_esquema(): array',
  'function rastro_config_padrao(): array',
  'function rastro_config_validar_campo( string $chave, $bruto )',
  'function rastro_config_ler( $bruto ): array',
  'function rastro_config(): array',
  'function rastro_config_campo( string $chave )',
  'function rastro_config_salvar( array $bruto ): array',
  'function rastro_tem_destino(): bool',
  'function rastro_config_redefinir_cache(): void',
]) {
  tem(config, assinatura, `assinatura: ${assinatura}`);
}
tem(config, "const RASTRO_OPCAO = 'rastro_config';");
tem(config, 'update_option( RASTRO_OPCAO, $novo, true );', 'uma opção só, com autoload');
tem(config, "apply_filters( 'rastro_config', $config )");
for (const chave of ['gtm', 'ga4', 'ads', 'ads_rotulo_compra', 'meta', 'tiktok', 'openai', 'openai_debug', 'posthog', 'posthog_regiao', 'google_verificacao', 'carregamento', 'eventos_loja', 'valor_compra', 'id_produto', 'status_relatorio', 'atribuicao', 'atribuicao_utm_sempre', 'atribuicao_dias', 'atribuicao_woo_consentimento', 'modo', 'aceite_segundos', 'aviso', 'aviso_texto', 'aviso_dias', 'medir_equipe']) {
  comMatch(config, new RegExp(`\\n\\t\\t'${chave}'\\s+=> array\\(`), `campo ${chave} no esquema`);
}
for (const evento of ['view_item_list', 'view_item', 'add_to_cart', 'remove_from_cart', 'view_cart', 'begin_checkout', 'add_payment_info', 'purchase', 'search']) {
  comMatch(config, new RegExp(`'${evento}'\\s+=> __\\(`), `evento ${evento}`);
}
for (const regra of [
  "'/^GTM-[A-Z0-9]{4,12}$/D'", "'/^G-[A-Z0-9]{6,14}$/D'", "'/^AW-[0-9]{6,14}$/D'", "'/^[A-Za-z0-9_-]{6,40}$/D'",
  "'/^[0-9]{8,20}$/D'", "'/^[A-Z0-9]{15,30}$/D'", "'/^[A-Za-z0-9_-]{10,64}$/D'", "'/^phc_[A-Za-z0-9]{20,80}$/D'",
  "'/^[A-Za-z0-9_-]{20,100}$/D'",
]) {
  tem(config, regra, `regra ${regra}`);
}
tem(config, "function_exists( 'wc_get_order_statuses' )", 'status pelo WooCommerce quando existe');
for (const status of ['pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed']) {
  comMatch(config, new RegExp(`'${status}'\\s+=> __\\(`), `status de reserva ${status}`);
}
for (const [nome, texto] of Object.entries({ config, plugin })) {
  // IDs reais da produção nunca no código.
  semMatch(texto, /GTM-W9V6V93S|G-2NLEEHC9G6|AW-706555815|1533395456954642/, `${nome} sem ID real fixo`);
}

// 4. Onde medir (seção 3.2).
const regras = ler(R + 'nucleo/regras.php');
for (const assinatura of [
  'function rastro_regra_local( string $host, string $ambiente ): bool',
  'function rastro_regra_medir( array $c ): bool',
  'function rastro_regra_url_limpa( string $uri, bool $privada, string $tipo ): string',
  'function rastro_ambiente_local(): bool',
  'function rastro_e_equipe(): bool',
  'function rastro_requisicao_restrita(): bool',
  'function rastro_pagina_tipo(): string',
  'function rastro_pagina_privada(): bool',
  'function rastro_deve_medir(): bool',
  'function rastro_url_limpa(): string',
  'function rastro_regra_url_campanha( string $uri, bool $privada, string $tipo ): string',
  'function rastro_regra_valor_campanha( $valor ): string',
  'function rastro_url_campanha(): string',
]) {
  tem(regras, assinatura, `assinatura: ${assinatura}`);
}
tem(regras, "defined( 'RASTRO_LOCAL' ) && true === RASTRO_LOCAL");
tem(regras, "apply_filters( 'rastro_e_equipe', $equipe )");
tem(regras, "apply_filters( 'rastro_pagina_privada', $privada )");
tem(regras, "apply_filters( 'rastro_deve_medir', rastro_regra_medir( $contexto ), $contexto )");
tem(regras, "current_user_can( 'edit_posts' ) || current_user_can( 'manage_woocommerce' )");
tem(regras, "esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) )", 'REQUEST_URI com wp_unslash e esc_url_raw');
tem(regras, "wp_parse_url( home_url( '/' ) )", 'host do endereço do site');
const medir = regras.slice(regras.indexOf('function rastro_regra_medir'), regras.indexOf('function rastro_regra_tipos'));
tem(medir, '! array_key_exists( $chave, $c )', 'falha fechada: chave ausente não mede');
for (const pergunta of ['is_order_received_page', 'is_checkout', 'is_cart', 'is_account_page', 'is_product', 'is_product_category', 'is_shop']) {
  tem(regras, `$woo( '${pergunta}' )`, `${pergunta} só quando existe`);
}
tem(regras, 'return function_exists( $pergunta ) && (bool) call_user_func( $pergunta );');

// 5. Saída para o navegador (seção 3.3).
const saida = ler(R + 'nucleo/saida.php');
for (const assinatura of [
  'function rastro_config_navegador(): array',
  'function rastro_precisa_saida(): bool',
  'function rastro_head_config(): void',
  'function rastro_script_inline( string $id, string $js ): void',
  'function rastro_registrar_script( string $handle, string $arquivo_relativo, array $deps = array() ): bool',
  'function rastro_json( $dado ): string',
]) {
  tem(saida, assinatura, `assinatura: ${assinatura}`);
}
tem(saida, "add_action( 'wp_head', 'rastro_head_config', 1 );");
tem(saida, 'JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES');
tem(saida, "'window.rastroConfig=' . $json . ';window.rastro=window.rastro||{};'");
tem(saida, "apply_filters( 'rastro_config_navegador', $base )");
tem(saida, "apply_filters( 'rastro_precisa_saida', false )");
tem(saida, 'wp_print_inline_script_tag( $js, $atributos );', 'inline pela função do WordPress (nonce de CSP)');
for (const atributo of ["'data-no-optimize' => '1'", "'data-no-defer'    => '1'", "'data-cfasync'     => 'false'", "'class'            => 'nowprocket'"]) {
  tem(saida, atributo, `atributo ${atributo}`);
}
tem(saida, "'in_footer' => true,");
tem(saida, "'strategy'  => 'defer',");
tem(saida, 'filemtime( $arquivo )');
tem(saida, "RASTRO_URL . 'assets/js/' . $arquivo_relativo");
tem(saida, "add_filter( 'wp_script_attributes', 'rastro_filtro_atributos_script', 10, 1 );", 'WordPress 7: scripts com src pelo filtro de atributos');
tem(saida, "add_filter( 'wp_inline_script_attributes', 'rastro_filtro_atributos_inline', 10, 1 );");
tem(saida, "add_filter( 'script_loader_tag', 'rastro_filtro_tag_script', 10, 2 );");
const otimizadores = saida.slice(saida.indexOf('function rastro_otimizadores_registrar'));
for (const [constante, ganchos] of [
  ['LSCWP_V', ['litespeed_optm_js_defer_exc', 'litespeed_optimize_js_excludes']],
  ['WP_ROCKET_VERSION', ['rocket_delay_js_exclusions', 'rocket_exclude_defer_js', 'rocket_exclude_js']],
  ['AUTOPTIMIZE_PLUGIN_VERSION', ['autoptimize_filter_js_exclude']],
]) {
  const bloco = otimizadores.slice(otimizadores.indexOf(`defined( '${constante}' )`));
  assert.ok(otimizadores.includes(`defined( '${constante}' )`), `${constante} conferido antes`);
  for (const gancho of ganchos) {
    assert.ok(bloco.indexOf(`'${gancho}'`) > 0 && bloco.indexOf(`'${gancho}'`) < (bloco.indexOf('}') > 0 ? bloco.indexOf('}') : Infinity), `${gancho} dentro do bloco de ${constante}`);
  }
}
semMatch(saida, /add_filter\( '(litespeed|rocket|autoptimize)_[a-z_]+', '[a-z_]+'(?:, \d+, \d+)? \);\n(?!\t)/, 'filtro de otimizador fora da conferência de existência');

// 6. Painel (seção 3.4).
const admin = ler(R + 'admin/admin.php');
tem(admin, "const RASTRO_ADMIN_SLUG = 'rastro';");
tem(admin, "const RASTRO_ADMIN_ACAO = 'rastro_salvar';");
tem(admin, "const RASTRO_ADMIN_CAPACIDADE = 'manage_options';");
tem(admin, "'dashicons-chart-line'");
tem(admin, "add_action( 'admin_menu', 'rastro_admin_menu' );");
tem(admin, "apply_filters( 'rastro_admin_abas', $abas )");
tem(admin, "apply_filters( 'rastro_admin_avisos', array() )");
tem(admin, "add_action( 'admin_post_' . RASTRO_ADMIN_ACAO, 'rastro_admin_salvar' );");
tem(admin, 'wp_nonce_field( RASTRO_ADMIN_ACAO );');
tem(admin, '<input type="hidden" name="aba" value="');
tem(admin, 'role="status"');
tem(admin, 'autocomplete="off" spellcheck="false"');
tem(admin, 'class="form-table"');
tem(admin, 'nav-tab-wrapper');
tem(admin, "function_exists( 'rastro_consent_provedor' )", 'provedor só se o módulo existir');
tem(admin, 'aguardando o módulo de consentimento');
for (const aba of ['geral', 'eventos', 'woocommerce', 'consentimento']) {
  comMatch(admin, new RegExp(`'${aba}'\\s+=> __\\(`), `aba ${aba}`);
}
const salvar = admin.slice(admin.indexOf('function rastro_admin_salvar()'));
assert.ok(salvar.indexOf('current_user_can( RASTRO_ADMIN_CAPACIDADE )') < salvar.indexOf('check_admin_referer( RASTRO_ADMIN_ACAO )'), 'capacidade antes do nonce');
assert.ok(salvar.indexOf('check_admin_referer(') < salvar.indexOf('rastro_admin_salvar_dados('), 'nonce antes de gravar');
tem(salvar, 'set_transient( rastro_admin_chave_erros(), $resultado[\'erros\'], 5 * MINUTE_IN_SECONDS );', 'erros por campo em transient curto');
tem(salvar, "rastro_admin_url( $aba, array( 'rastro_aviso' => $aviso ) )", 'PRG de volta à aba');
tem(admin, "'rastro_admin_erros_' . (int) get_current_user_id()", 'transient por usuário');
const estilo = admin.slice(admin.indexOf('function rastro_admin_estilo'), admin.indexOf("add_action( 'admin_enqueue_scripts'"));
assert.ok(estilo.indexOf('$tela !== $sufixo') < estilo.indexOf('wp_enqueue_style('), 'admin.css só nas telas do Rastro');
semMatch(admin, /<script|wp_enqueue_script/, 'painel sem JS');
const css = ler(R + 'assets/css/admin.css');
semMatch(css, /@import|url\(/, 'admin.css sem recurso externo');

// 7. Só os arquivos desta frente: nenhum endereço externo além da licença.
for (const arquivo of [R + 'rastro.php', R + 'nucleo/config.php', R + 'nucleo/regras.php', R + 'nucleo/saida.php', R + 'admin/admin.php', R + 'assets/css/admin.css']) {
  const externos = (ler(arquivo).match(/https?:\/\/[^\s'")]+/g) || []).filter((u) => u !== 'https://www.gnu.org/licenses/gpl-2.0.html');
  assert.deepEqual(externos, [], `${arquivo} sem endereço externo`);
}

// 8. Manual.
const manual = ler('docs/BASE.md');
for (const trecho of ['rastro_modulos', 'rastro_config_navegador', 'rastro_admin_abas', 'rastro_admin_avisos', 'rastro_precisa_saida', 'RASTRO_LOCAL', 'tests/php/stubs-rastro.php', 'Não conferido']) {
  tem(manual, trecho, `BASE.md cita ${trecho}`);
}

// 9. php -l e harness PHP.
const php = spawnSync('php', ['-v'], { encoding: 'utf8' });
if (php.status === 0) {
  for (const arquivo of [...phpDoRastro, 'tests/php/rastro-base.test.php', 'tests/php/stubs-rastro.php']) {
    const l = spawnSync('php', ['-l', path.join(root, arquivo)], { encoding: 'utf8' });
    assert.equal(l.status, 0, `php -l ${arquivo}:\n${l.stdout}${l.stderr}`);
  }
  const r = spawnSync('php', [path.join(root, 'tests/php/rastro-base.test.php')], { encoding: 'utf8' });
  assert.equal(r.status, 0, `tests/php/rastro-base.test.php falhou:\n${r.stderr}${r.stdout}`);
  process.stdout.write(r.stdout);
} else {
  console.log('php não encontrado no PATH: php -l e tests/php/rastro-base.test.php pulados.');
}

console.log('Rastro base contract: PASS');
