'use strict';

// Contrato do atualizador do Rastro (docs/ATUALIZADOR.md): só no plugin
// próprio, sem token, sem seguir redirecionamento, versão assinada com as
// chaves de rastro/atualizacao/chaves.php, mesmos nomes e limites da
// release (tools/release.mjs). Roda o teste PHP e uma conferência cruzada:
// assina no Node e confere no PHP.

const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');
const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const { pathToFileURL } = require('node:url');

const root = path.resolve(__dirname, '..');
const ler = (p) => fs.readFileSync(path.join(root, p), 'utf8').replace(/\r\n/g, '\n');
const tem = (t, s, m) => assert.ok(t.includes(s), m || s);
const semMatch = (t, re, m) => assert.ok(!re.test(t), m || String(re));
const temPhp = spawnSync('php', ['-v'], { encoding: 'utf8' }).status === 0;

(async () => {
  const A = 'rastro/atualizacao/atualizador.php';
  const fonte = ler(A);
  const chaves = ler('rastro/atualizacao/chaves.php');
  const release = ler('tools/release.mjs');
  const rel = await import(pathToFileURL(path.join(root, 'tools/release.mjs')).href);

  // 1. Arquivos, LF e carga.
  for (const f of [A, 'rastro/atualizacao/chaves.php', 'tests/php/atualizador.test.php', 'docs/ATUALIZADOR.md']) {
    assert.ok(fs.existsSync(path.join(root, f)), `existe ${f}`);
    semMatch(fs.readFileSync(path.join(root, f), 'utf8'), /\r/, `${f} com LF`);
  }
  const plugin = ler('rastro/rastro.php');
  tem(plugin, "'atualizacao/chaves.php',\n\t\t\t'atualizacao/atualizador.php',", 'módulos do atualizador na lista, chaves antes');
  assert.match(plugin, /^ \* Update URI:\s+https:\/\/github\.com\/samuelmarmelo\/rastro$/m, 'Update URI (o WordPress não procura outro plugin com o mesmo slug)');

  // 2. Ganchos só no plugin próprio, todos dentro do mesmo if.
  const bloco = fonte.slice(fonte.indexOf('if ( rastro_atualizador_plugin_proprio() ) {'));
  for (const g of ["'update_plugins_github.com'", "'upgrader_pre_download'", "'upgrader_source_selection'", "'auto_update_plugin'", "'plugins_api'", "'load-update-core.php'", "'admin_init'"]) {
    tem(bloco, g, `gancho ${g} dentro do if do plugin próprio`);
  }
  assert.equal((fonte.match(/\badd_(?:filter|action)\(/g) || []).length, 7, 'nenhum gancho fora do bloco');

  // 3. Rede: só wp_safe_remote_get, nunca com token, nunca seguindo redirecionamento.
  semMatch(fonte, /\bwp_remote_(?:get|post|request)\(|download_url\(|curl_|file_get_contents\(\s*['"]https?:/, 'só wp_safe_remote_get');
  const codigoSemComentario = fonte.split('\n').filter((l) => !/^\s*(\*|\/\*|\/\/)/.test(l)).join('\n');
  semMatch(codigoSemComentario, /Authorization|_TOKEN|token/i, 'sem token no código');
  const chamadas = fonte.split('wp_safe_remote_get(').slice(1);
  assert.equal(chamadas.length, 3, 'três pedidos: versão, endereço público, destino');
  for (const c of chamadas) tem(c.slice(0, 400), "'redirection'         => 0,", 'sem seguir redirecionamento');
  tem(fonte, "'user-agent'          => rastro_atualizador_agente(),", 'User-Agent próprio');
  tem(fonte, "return 'Rastro/' . ( defined( 'RASTRO_VERSAO' ) ? RASTRO_VERSAO : '0' ) . '; WordPress';", 'User-Agent sem endereço do site');

  // 4. Mesmos nomes, endereços e limites da release.
  tem(fonte, "const RASTRO_ATUALIZADOR_ULTIMA = 'https://api.github.com/repos/samuelmarmelo/rastro/releases/latest';");
  tem(fonte, "const RASTRO_ATUALIZADOR_DOWNLOAD = 'https://github.com/samuelmarmelo/rastro/releases/download/';");
  tem(release, "const REPO = 'samuelmarmelo/rastro';", 'release no mesmo repositório');
  tem(fonte, 'const RASTRO_ATUALIZADOR_LIMITE       = 5242880;');
  tem(release, 'const LIMITE = 5 * 1024 * 1024;', 'mesmo limite do zip');
  tem(fonte, 'return "rastro-release-v1\\ntag: {$tag}\\narquivo: {$arquivo}\\nsha256: {$hex}\\n";', 'mesma mensagem assinada');

  // 5. Chaves: sem filtro, hex de 64, iguais às que a release aceita.
  semMatch(chaves, /apply_filters|get_option|define\(/, 'chaves fixas no código');
  const lista = rel.chavesConfiaveis(chaves);
  assert.ok(lista.length >= 1, 'pelo menos uma chave');
  for (const k of lista) assert.match(k, /^[0-9a-f]{64}$/);

  // 6. Textos da tela sem travessão.
  const traducoes = fonte.match(/\b(?:__|esc_html__|esc_attr__)\(\s*'(?:[^'\\]|\\.)*'/g) || [];
  assert.ok(traducoes.length >= 9, 'textos traduzíveis');
  for (const t of traducoes) {
    semMatch(t, new RegExp(String.fromCharCode(0x2014)), `texto sem travessão: ${t}`);
  }
  semMatch(fonte, /\beval\(|base64_decode\(|\$_REQUEST\b|delete_option\(/, 'sem eval, base64_decode, $_REQUEST e delete_option');

  // 7. Teste PHP e conferência cruzada Node -> PHP.
  if (temPhp) {
    for (const f of [A, 'rastro/atualizacao/chaves.php']) {
      const l = spawnSync('php', ['-l', f], { cwd: root, encoding: 'utf8' });
      assert.equal(l.status, 0, `php -l ${f}: ${l.stdout}${l.stderr}`);
    }
    const t = spawnSync('php', ['tests/php/atualizador.test.php'], { cwd: root, encoding: 'utf8' });
    assert.equal(t.status, 0, `tests/php/atualizador.test.php\n${t.stdout}\n${t.stderr}`);

    const { privateKey, publicKey } = crypto.generateKeyPairSync('ed25519');
    const pub = publicKey.export({ type: 'spki', format: 'der' }).subarray(12).toString('hex');
    const hex = crypto.createHash('sha256').update('zip de teste').digest('hex');
    const msg = rel.mensagemAssinada('v2.0.0', 'rastro-v2.0.0.zip', hex);
    const sig = crypto.sign(null, Buffer.from(msg), privateKey).toString('hex');
    const codigo = [
      "define('ABSPATH', getenv('R') . '/');",
      "require getenv('R') . '/rastro/atualizacao/atualizador.php';",
      "$m = rastro_atualizador_mensagem('v2.0.0', 'rastro-v2.0.0.zip', getenv('H'));",
      "echo $m === getenv('M') ? 'msg-igual ' : 'msg-diferente ';",
      "echo rastro_atualizador_assinatura_valida($m, getenv('S') . \"\\n\", array(getenv('K'))) ? 'ok ' : 'falhou ';",
      "echo rastro_atualizador_assinatura_valida(rastro_atualizador_mensagem('v2.0.1', 'rastro-v2.0.0.zip', getenv('H')), getenv('S'), array(getenv('K'))) ? 'aceitou-outra' : 'recusou-outra';",
    ].join('');
    const cruz = spawnSync('php', ['-r', codigo], { encoding: 'utf8', env: { ...process.env, R: root, H: hex, M: msg, S: sig, K: pub } });
    assert.equal(cruz.stdout.trim(), 'msg-igual ok recusou-outra', `conferência cruzada: ${cruz.stdout}${cruz.stderr}`);
  }

  // 8. Instalador do plugin próprio: só na cópia embutida, só no painel, só com clique.
  const I = 'rastro/atualizacao/instalador.php';
  const inst = ler(I);
  tem(plugin, "$modulos[] = 'admin/admin.php';\n\t\t\t$modulos[] = 'atualizacao/instalador.php';", 'instalador só no painel');
  tem(inst, "if ( ! function_exists( 'rastro_atualizador_plugin_proprio' ) || rastro_atualizador_plugin_proprio() ) {\n\treturn;\n}", 'instalador parado no plugin próprio');
  assert.deepEqual(inst.match(/add_(?:action|filter)\( '[^']+'/g), ["add_filter( 'upgrader_source_selection'", "add_action( 'admin_notices'", "add_action( 'network_admin_notices'", "add_action( 'admin_post_'"], 'só aviso, admin-post e a conferência temporária da pasta');
  tem(inst, 'check_admin_referer( RASTRO_INSTALADOR_ACAO );', 'nonce no clique');
  tem(inst, "current_user_can( 'install_plugins' )", 'capacidade de instalar');
  tem(inst, "current_user_can( 'activate_plugins' )", 'capacidade de ativar');
  tem(inst, 'rastro_atualizador_baixar_conferido( $release )', 'mesmo download conferido do atualizador');
  tem(inst, 'rastro_atualizador_preparar_pasta( $source, $remote_source, $esperada )', 'mesma conferência da pasta');
  semMatch(inst, /\bwp_(?:safe_)?remote_|download_url\(/, 'instalador não baixa nada por conta própria');
  for (const t of inst.match(/\b(?:__|esc_html__|esc_attr__)\(\s*'(?:[^'\\]|\\.)*'/g) || []) {
    semMatch(t, new RegExp(String.fromCharCode(0x2014)), `texto do instalador sem travessão: ${t}`);
  }
  if (temPhp) {
    const l = spawnSync('php', ['-l', I], { cwd: root, encoding: 'utf8' });
    assert.equal(l.status, 0, `php -l ${I}`);
    const t = spawnSync('php', ['tests/php/instalador.test.php'], { cwd: root, encoding: 'utf8' });
    assert.equal(t.status, 0, `tests/php/instalador.test.php\n${t.stdout}\n${t.stderr}`);
  }

  // 9. Documentação.
  const doc = ler('docs/ATUALIZADOR.md');
  for (const t of ['RASTRO_ATUALIZAR_AUTO', '#rastro-assinado', 'githubusercontent.com', 'upgrader_source_selection', 'cópia embutida', 'Update URI']) tem(doc, t, `ATUALIZADOR.md: ${t}`);

  console.log('atualizador: contrato verde');
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
