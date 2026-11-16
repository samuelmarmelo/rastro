'use strict';

// Contrato do pacote do WordPress.org (tools/wporg.mjs; docs/WORDPRESS-ORG.md):
// sem o atualizador próprio, sem Update URI, readme no formato do
// diretório e zip legível. Monta o pacote do commit atual em memória.

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { pathToFileURL } = require('node:url');
const { inflateRawSync } = require('node:zlib');

const root = path.resolve(__dirname, '..');
const ler = (p) => fs.readFileSync(path.join(root, p), 'utf8').replace(/\r\n/g, '\n');
const TRAVESSAO = new RegExp(String.fromCharCode(0x2014));

for (const arquivo of ['tools/wporg.mjs', 'docs/WORDPRESS-ORG.md', 'tests/validate-wporg-contract.js']) {
  const bruto = fs.readFileSync(path.join(root, arquivo), 'utf8');
  assert.ok(!/\r/.test(bruto), `${arquivo} com fim de linha LF`);
  assert.ok(!TRAVESSAO.test(bruto), `${arquivo} sem travessão`);
}

const fonte = ler('tools/wporg.mjs');
assert.ok(!/rmSync|unlinkSync|rmdirSync/.test(fonte), 'ferramenta nunca apaga (soft delete)');
assert.ok(/git\(\['ls-tree'/.test(fonte) && /`\$\{ref\}:\$\{nome\}`/.test(fonte), 'lê do commit, não da cópia de trabalho');

// readme.txt no formato do diretório.
const readme = ler('rastro/readme.txt');
assert.match(readme, /^=== Rastro ===$/m);
for (const campo of ['Contributors', 'Tags', 'Requires at least', 'Tested up to', 'Requires PHP', 'Stable tag', 'License', 'License URI']) {
  assert.match(readme, new RegExp(`^${campo}: \\S`, 'm'), `readme.txt: ${campo}`);
}
assert.ok((/^Tags: (.+)$/m.exec(readme)[1]).split(',').length <= 5, 'até 5 tags');
for (const secao of ['Description', 'Installation', 'Frequently Asked Questions', 'External services', 'Changelog']) {
  assert.match(readme, new RegExp(`^== ${secao} ==$`, 'm'), `readme.txt: seção ${secao}`);
}
const servicos = readme.slice(readme.indexOf('== External services =='), readme.indexOf('== Changelog =='));
for (const host of ['www.googletagmanager.com', 'connect.facebook.net', 'analytics.tiktok.com', 'bzrcdn.openai.com', 'i.posthog.com']) {
  assert.ok(servicos.includes(host), `serviço externo documentado: ${host}`);
}
assert.equal((servicos.match(/\[Terms\]\(https:\/\//g) || []).length, 5, 'termos de cada serviço');
assert.equal((servicos.match(/\[Privacy Policy\]\(https:\/\//g) || []).length, 5, 'privacidade de cada serviço');

(async () => {
  const w = await import(pathToFileURL(path.join(root, 'tools/wporg.mjs')).href);

  // Regras puras.
  assert.equal(w.foraDoWporg('rastro/atualizacao/atualizador.php'), true);
  assert.equal(w.foraDoWporg('rastro/tags/tags.php'), false);
  assert.equal(w.semUpdateUri(' * Version: 1.0.0\n * Update URI:        https://github.com/x/y\n * @package Rastro\n'), ' * Version: 1.0.0\n * @package Rastro\n');
  assert.deepEqual(w.conferir({ 'rastro/rastro.php': Buffer.from(' * Version: 1.0.0\n * Update URI: x\n'), 'rastro/readme.txt': Buffer.from('Stable tag: 1.0.1\n') }).length >= 3, true, 'conferir acha Update URI, versão e seção faltando');
  assert.ok(w.conferir({ 'rastro/rastro.php': Buffer.from(' * Version: 1.0.0\n'), 'rastro/readme.txt': Buffer.from('Stable tag: 1.0.0\n\n== External services ==\n'), 'rastro/x.js': Buffer.from('fetch("https://api.github.com/")') }).some((p) => /resto do atualizador/.test(p)), 'resto do GitHub recusado');

  // Pacote do commit atual. Antes do primeiro commit desta frente o HEAD
  // pode não ter o readme novo: monta a partir da cópia em memória.
  const arquivos = w.montar('HEAD');
  arquivos['rastro/readme.txt'] = Buffer.from(readme, 'utf8');
  arquivos['rastro/rastro.php'] = Buffer.from(w.semUpdateUri(ler('rastro/rastro.php')), 'utf8');
  assert.deepEqual(w.conferir(arquivos), [], 'pacote do WordPress.org pronto');
  assert.ok(!Object.keys(arquivos).some((n) => n.startsWith('rastro/atualizacao/')), 'sem rastro/atualizacao/');
  assert.ok(!/Update URI/.test(arquivos['rastro/rastro.php'].toString('utf8')), 'sem Update URI');
  for (const modulo of ['rastro/nucleo/config.php', 'rastro/tags/tags.php', 'rastro/consentimento/consentimento.php']) {
    assert.ok(arquivos[modulo], `módulo no pacote: ${modulo}`);
  }

  // CRC-32 de referência (o zip usa o do Node ou a tabela padrão).
  assert.equal(w.crc32(Buffer.from('123456789')), 0xcbf43926, 'CRC-32 de referência');

  // Zip legível: diretório central, nomes e conteúdo inflado igual.
  const amostra = { 'rastro/a.txt': Buffer.from('olá, rastro\n'), 'rastro/b/c.php': Buffer.from('<?php echo 1;\n') };
  const zip = w.montarZip(amostra);
  let fim = -1;
  for (let i = zip.length - 22; i >= 0; i--) { if (zip.readUInt32LE(i) === 0x06054b50) { fim = i; break; } }
  assert.ok(fim >= 0, 'zip com fim do diretório central');
  assert.equal(zip.readUInt16LE(fim + 10), 2, 'duas entradas');
  let p = 0;
  for (const [nome, conteudo] of Object.entries(amostra)) {
    assert.equal(zip.readUInt32LE(p), 0x04034b50, 'cabeçalho local');
    const tamanhoComp = zip.readUInt32LE(p + 18);
    const lenNome = zip.readUInt16LE(p + 26);
    assert.equal(zip.subarray(p + 30, p + 30 + lenNome).toString('utf8'), nome, `nome ${nome}`);
    const dados = zip.subarray(p + 30 + lenNome, p + 30 + lenNome + tamanhoComp);
    assert.deepEqual(inflateRawSync(dados), conteudo, `conteúdo de ${nome}`);
    p += 30 + lenNome + tamanhoComp;
  }

  console.log('Rastro WordPress.org contract: PASS');
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
