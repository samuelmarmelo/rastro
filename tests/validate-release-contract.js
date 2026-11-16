'use strict';

// Contrato da release (docs/RELEASE.md): formato dos três arquivos, mensagem
// assinada, chave fora do repositório, lista do zip e passos que param antes
// de publicar. Só Node, sem rede.

const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');
const crypto = require('node:crypto');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { pathToFileURL } = require('node:url');

const root = path.resolve(__dirname, '..');
const ler = (p) => fs.readFileSync(path.join(root, p), 'utf8').replace(/\r\n/g, '\n');
const tem = (t, s, m) => assert.ok(t.includes(s), m || s);

(async () => {
  const rel = await import(pathToFileURL(path.join(root, 'tools/release.mjs')).href);
  const chave = await import(pathToFileURL(path.join(root, 'tools/chave.mjs')).href);

  // 1. Mensagem assinada: formato exato da seção 2.
  assert.equal(
    rel.mensagemAssinada('v1.2.3', 'rastro-v1.2.3.zip', 'ab'.repeat(32)),
    `rastro-release-v1\ntag: v1.2.3\narquivo: rastro-v1.2.3.zip\nsha256: ${'ab'.repeat(32)}\n`
  );

  // 2. Lista de chaves lida só de dentro de rastro_atualizador_chaves().
  const K = 'ab'.repeat(32);
  const php = `<?php
// '${'cd'.repeat(32)}' fora
function rastro_atualizador_chaves() {
	return array(
		'${K}',
		'abc',
		'${'EF'.repeat(32)}',
	);
}
`;
  assert.deepEqual(rel.chavesConfiaveis(php), [K], 'só hex minúsculo de 64, só dentro da função');
  assert.deepEqual(rel.chavesConfiaveis('<?php'), []);

  // 3. Nada de desenvolvimento no zip.
  for (const proibido of ['tests/a.php', 'x/test/b.js', 'docs/a.md', '.github/w.yml', 'a/.env', 'chave.pem', 'a.key', 'velho.zip', '.htaccess', 'node_modules/x.js', 'a.js.map']) {
    assert.ok(rel.proibidoNoZip(proibido), `proibido no zip: ${proibido}`);
  }
  for (const permitido of ['rastro.php', 'readme.txt', 'nucleo/config.php', 'assets/js/consent.js', 'atualizacao/chaves.php']) {
    assert.ok(!rel.proibidoNoZip(permitido), `permitido no zip: ${permitido}`);
  }

  // 4. Assinatura Ed25519 de ida e volta com a chave pública crua de 32 bytes.
  const { privateKey } = crypto.generateKeyPairSync('ed25519');
  const publica = chave.publicaEmHex(privateKey);
  assert.match(publica, /^[0-9a-f]{64}$/);
  const msg = Buffer.from(rel.mensagemAssinada('v9.9.9', 'rastro-v9.9.9.zip', '0'.repeat(64)));
  const sig = crypto.sign(null, msg, privateKey);
  assert.equal(sig.length, 64);
  const spki = Buffer.concat([Buffer.from('302a300506032b6570032100', 'hex'), Buffer.from(publica, 'hex')]);
  const pub = crypto.createPublicKey({ key: spki, format: 'der', type: 'spki' });
  assert.ok(crypto.verify(null, msg, pub, sig), 'assinatura confere com a chave pública crua');
  assert.ok(!crypto.verify(null, Buffer.from(rel.mensagemAssinada('v9.9.8', 'rastro-v9.9.9.zip', '0'.repeat(64))), pub, sig), 'outra tag não confere');

  // 5. Chave privada nunca dentro do repositório.
  const dentro = spawnSync(process.execPath, [path.join(root, 'tools/chave.mjs'), 'publica'], { cwd: root, encoding: 'utf8', env: { ...process.env, RASTRO_CHAVE_ASSINATURA: path.join(root, 'tools', 'x.pem') } });
  assert.notEqual(dentro.status, 0);
  tem(dentro.stderr, 'não pode ficar dentro do repositório');
  const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'rastro-chave-'));
  const caminho = path.join(tmp, 'k.pem');
  const gerada = spawnSync(process.execPath, [path.join(root, 'tools/chave.mjs'), 'gerar'], { encoding: 'utf8', env: { ...process.env, RASTRO_CHAVE_ASSINATURA: caminho } });
  assert.equal(gerada.status, 0, gerada.stderr);
  assert.ok(!gerada.stdout.includes('PRIVATE KEY'), 'a chave privada nunca é impressa');
  const denovo = spawnSync(process.execPath, [path.join(root, 'tools/chave.mjs'), 'gerar'], { encoding: 'utf8', env: { ...process.env, RASTRO_CHAVE_ASSINATURA: caminho } });
  assert.notEqual(denovo.status, 0, 'gerar não sobrescreve chave existente');
  fs.rmSync(tmp, { recursive: true, force: true });

  // 6. Passos que param antes de publicar.
  const fonte = ler('tools/release.mjs');
  for (const trecho of [
    "branch !== 'main'",
    "git(['status', '--porcelain'])",
    "git(['rev-parse', 'origin/main'])",
    'RASTRO_VERSAO',
    'Stable tag',
    "'tests', 'run.js'",
    '`--prefix=${SLUG}/`',
    'LIMITE = 5 * 1024 * 1024',
    'chavesConfiaveis(',
    'draft: true',
    "x.state === 'uploaded'",
    'entradasDoZip(zip)',
    "'export-ignore', 'export-subst'",
    'baixarDoRascunho(',
    '7. Conferência pública',
    'versaoValida(versao)',
    "make_latest: 'true'",
  ]) {
    tem(fonte, trecho, `release.mjs: ${trecho}`);
  }
  assert.ok(!/console\.(log|error)\([^)]*TOKEN/.test(fonte), 'o token nunca é impresso');
  const limpo = rel.ambienteLimpo({ RASTRO_CHAVE_SENHA: 'x', GITHUB_TOKEN: 'y', GH_TOKEN: 'z', PATH: 'p' });
  assert.deepEqual(limpo, { PATH: 'p' }, 'processos filhos sem senha e sem token');
  assert.equal((fonte.match(/spawnSync\(/g) || []).length, (fonte.match(/env: ambienteLimpo\(\)/g) || []).length, 'todo spawnSync com ambiente limpo');
  tem(fonte, "'check-attr', '--stdin', 'export-ignore', 'export-subst'", 'atributos de exportação de qualquer origem');
  tem(fonte, 'tentativa <= 3', 'conferência pública com novas tentativas');

  // Chave cifrada: ida e volta, senha errada e senha curta.
  const { privateKey: kc } = crypto.generateKeyPairSync('ed25519');
  const cifrada = chave.cifrarChave(kc, 'senha-de-teste-bem-longa');
  assert.ok(!cifrada.includes('PRIVATE KEY'), 'arquivo cifrado sem PEM aberto');
  assert.match(cifrada, /"formato": "rastro-chave-v1"/);
  assert.equal(chave.publicaEmHex(chave.decifrarChave(cifrada, 'senha-de-teste-bem-longa')), chave.publicaEmHex(kc));
  assert.throws(() => chave.decifrarChave(cifrada, 'outra-senha-bem-longa-xx'), /senha/);
  assert.throws(() => chave.cifrarChave(kc, 'curta'), /16/);
  assert.ok(!/writeFileSync\([^)]*TOKEN/.test(fonte), 'o token nunca é gravado');

  // 7. Segredos fora do Git e documentação do formato.
  const ignorar = ler('.gitignore');
  for (const p of ['*.pem', '*.key', '.env']) tem(ignorar, p, `.gitignore: ${p}`);
  const doc = ler('docs/RELEASE.md');
  for (const t of ['rastro-release-v1', 'tag: vX.Y.Z', 'arquivo: rastro-vX.Y.Z.zip', 'sha256: <sha256', 'rastro-vX.Y.Z.zip.sha256', 'rastro-vX.Y.Z.zip.sig', 'node tools/release.mjs --dry-run']) {
    tem(doc, t, `RELEASE.md: ${t}`);
  }
  const readme = ler('rastro/readme.txt');
  assert.match(readme, /^Stable tag: \d+\.\d+\.\d+$/m);
  const versao = /^ \* Version:\s+(\S+)$/m.exec(ler('rastro/rastro.php'))[1];
  assert.equal(/^Stable tag: (\S+)$/m.exec(readme)[1], versao, 'Stable tag igual à Version');
  tem(ler('CHANGELOG.md'), `## [${versao}]`, 'CHANGELOG com a versão atual');

  console.log('release: contrato verde');
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
