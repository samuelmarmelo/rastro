#!/usr/bin/env node
/**
 * Release do Rastro: zip do plugin, checksum e assinatura, publicados como
 * versão do repositório. Sem dependências: git 2.40+ e Node 18+.
 *
 *   node tools/release.mjs            publica a versão (main limpa = origin/main)
 *   node tools/release.mjs --dry-run  monta, assina e confere, sem escrever na rede
 *
 * Passos (qualquer falha para tudo antes de publicar):
 *  1. Confere a árvore: branch main, sem mudança local, HEAD igual a
 *     origin/main, tag vX.Y.Z ainda livre (local e remota).
 *  2. Confere a versão: cabeçalho `Version` de rastro/rastro.php, constante
 *     RASTRO_VERSAO, `Stable tag` de rastro/readme.txt e seção `## [X.Y.Z]`
 *     do CHANGELOG.md, todos iguais.
 *  3. Roda a suíte (node tests/run.js).
 *  4. Monta rastro-vX.Y.Z.zip com `git archive` do HEAD (pasta rastro/ na
 *     raiz), confere a lista de arquivos (só a pasta do plugin, nada de dev)
 *     e o tamanho (até 5 MB).
 *  5. Escreve rastro-vX.Y.Z.zip.sha256 (`<hex>  rastro-vX.Y.Z.zip`) e
 *     rastro-vX.Y.Z.zip.sig (assinatura Ed25519 em base64 da mensagem da
 *     seção 2 de docs/RELEASE.md). A chave pública da chave usada precisa
 *     estar em rastro/atualizacao/chaves.php, senão os sites recusariam.
 *  6. Cria a versão em rascunho, envia os três arquivos, confere nome,
 *     tamanho e SHA-256 de cada um e só então publica como a mais recente.
 *     Rascunho da mesma tag deixado por falha anterior é reaproveitado.
 *
 * Token (nunca impresso nem gravado): GITHUB_TOKEN ou GH_TOKEN; sem elas, o
 * do Git para github.com (`git credential fill`). Precisa de escrita em
 * Contents do repositório. Chave privada: ver tools/chave.mjs.
 *
 * Contrato: docs/RELEASE.md.
 */
import { spawnSync } from 'node:child_process';
import { createHash, createPublicKey, sign, verify } from 'node:crypto';
import { mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { lerChavePrivada, publicaEmBase64 } from './chave.mjs';

const raiz = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const REPO = 'samuelmarmelo/rastro';
const API = 'https://api.github.com';
const UPLOADS = 'https://uploads.github.com';
const SLUG = 'rastro';
const NOME = 'Rastro';
// Igual a RASTRO_ATUALIZADOR_LIMITE (rastro/atualizacao/atualizador.php).
const LIMITE = 5 * 1024 * 1024;

/** Mensagem assinada (docs/RELEASE.md, seção 2). */
export function mensagemAssinada(tag, arquivo, hex) {
  return `rastro-release-v1\ntag: ${tag}\narquivo: ${arquivo}\nsha256: ${hex}\n`;
}

/** Chaves públicas confiáveis declaradas em rastro/atualizacao/chaves.php. */
export function chavesConfiaveis(php) {
  const corpo = /function rastro_atualizador_chaves\(\)[^{]*\{([\s\S]*?)\n\}/.exec(php);
  if (!corpo) return [];
  return [...corpo[1].matchAll(/'([A-Za-z0-9+/]{43}=)'/g)].map((m) => m[1]);
}

/** Arquivo do repositório que não pode ir no zip (caminho a partir de rastro/). */
export function proibidoNoZip(relativo) {
  return /(^|\/)(tests?|node_modules|\.github|docs|tools)\//.test(relativo)
    || /\.(pem|key|env|zip|sh|ps1|map|log)$/i.test(relativo)
    || /(^|\/)\.[^/]+$/.test(relativo);
}

function falhar(msg) {
  console.error(`release: ${msg}`);
  process.exit(1);
}

function git(args) {
  const r = spawnSync('git', args, { cwd: raiz, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
  if (r.status !== 0) falhar(`git ${args.join(' ')} falhou: ${(r.stderr || '').trim()}`);
  return (r.stdout || '').trim();
}

const ler = (p) => readFileSync(join(raiz, p), 'utf8').replace(/\r\n/g, '\n');

async function principal() {
  const SECO = process.argv.includes('--dry-run');

  // 1. Árvore.
  const branch = git(['rev-parse', '--abbrev-ref', 'HEAD']);
  if (!SECO && branch !== 'main') falhar(`release só sai da main (agora: ${branch})`);
  if (git(['status', '--porcelain'])) falhar('há mudanças locais; commite ou guarde antes');
  const busca = spawnSync('git', ['fetch', '-q', 'origin', '--tags'], { cwd: raiz, encoding: 'utf8' });
  if (busca.status !== 0 && !SECO) falhar('git fetch falhou: sem rede não há release');
  const head = git(['rev-parse', 'HEAD']);
  if (!SECO && head !== git(['rev-parse', 'origin/main'])) falhar('HEAD diferente de origin/main: faça o push antes');

  // 2. Versão.
  const plugin = ler('rastro/rastro.php');
  const versao = (/^ \* Version:\s+(\S+)$/m.exec(plugin) || [])[1] || '';
  if (!/^\d+\.\d+\.\d+$/.test(versao)) falhar(`Version inválida em rastro/rastro.php: "${versao}"`);
  const constante = (/define\( 'RASTRO_VERSAO', '([^']+)' \);/.exec(plugin) || [])[1];
  if (constante !== versao) falhar(`RASTRO_VERSAO (${constante}) diferente de Version (${versao})`);
  const estavel = (/^Stable tag:\s*(\S+)$/m.exec(ler('rastro/readme.txt')) || [])[1];
  if (estavel !== versao) falhar(`Stable tag do readme.txt (${estavel}) diferente de ${versao}`);
  const changelog = ler('CHANGELOG.md');
  const inicio = changelog.indexOf(`## [${versao}]`);
  if (inicio < 0) falhar(`CHANGELOG.md sem a seção ## [${versao}]`);
  const resto = changelog.slice(changelog.indexOf('\n', inicio) + 1);
  const fim = resto.search(/^## \[/m);
  const notas = (fim < 0 ? resto : resto.slice(0, fim)).trim();
  if (!notas) falhar(`seção ## [${versao}] do CHANGELOG.md vazia`);
  const tag = `v${versao}`;
  if (git(['tag', '--list', tag])) falhar(`a tag ${tag} já existe: suba a versão`);
  const remota = spawnSync('git', ['ls-remote', '--tags', 'origin', `refs/tags/${tag}`], { cwd: raiz, encoding: 'utf8' });
  if ((remota.stdout || '').trim()) falhar(`a tag ${tag} já existe no repositório: suba a versão`);

  // 3. Suíte.
  const suite = spawnSync(process.execPath, [join(raiz, 'tests', 'run.js')], { cwd: raiz, encoding: 'utf8' });
  process.stdout.write(suite.stdout || '');
  if (suite.status !== 0) falhar('a suíte falhou; nada foi publicado');

  // 4. Zip.
  const arquivos = git(['ls-tree', '-r', '--name-only', 'HEAD', 'rastro']).split('\n').filter(Boolean);
  for (const a of arquivos) {
    if (!a.startsWith('rastro/')) falhar(`arquivo fora da pasta do plugin: ${a}`);
    if (proibidoNoZip(a.slice('rastro/'.length))) falhar(`arquivo proibido no zip: ${a}`);
  }
  for (const obrigatorio of ['rastro/rastro.php', 'rastro/readme.txt', 'rastro/atualizacao/atualizador.php', 'rastro/atualizacao/chaves.php']) {
    if (!arquivos.includes(obrigatorio)) falhar(`falta no zip: ${obrigatorio}`);
  }
  const pasta = mkdtempSync(join(tmpdir(), 'rastro-release-'));
  const nomeZip = `${SLUG}-${tag}.zip`;
  const caminhoZip = join(pasta, nomeZip);
  git(['archive', '--format=zip', `--prefix=${SLUG}/`, '-o', caminhoZip, 'HEAD:rastro']);
  const zip = readFileSync(caminhoZip);
  if (zip.length > LIMITE) falhar(`zip com ${zip.length} bytes passa do limite de ${LIMITE}`);
  if (zip.length < 1024) falhar('zip pequeno demais');

  // 5. Checksum e assinatura.
  const hex = createHash('sha256').update(zip).digest('hex');
  const conteudoHash = Buffer.from(`${hex}  ${nomeZip}\n`, 'utf8');
  const chave = lerChavePrivada();
  const publica = publicaEmBase64(chave);
  if (!chavesConfiaveis(ler('rastro/atualizacao/chaves.php')).includes(publica)) {
    falhar('a chave pública desta chave privada não está em rastro/atualizacao/chaves.php; os sites recusariam a versão');
  }
  const mensagem = Buffer.from(mensagemAssinada(tag, nomeZip, hex), 'utf8');
  const assinatura = sign(null, mensagem, chave);
  if (!verify(null, mensagem, createPublicKey(chave), assinatura)) falhar('a assinatura não confere com a própria chave');
  const conteudoSig = Buffer.from(`${assinatura.toString('base64')}\n`, 'utf8');
  writeFileSync(join(pasta, `${nomeZip}.sha256`), conteudoHash);
  writeFileSync(join(pasta, `${nomeZip}.sig`), conteudoSig);
  console.log(`release: ${nomeZip} ${zip.length} bytes, ${arquivos.length} arquivos`);
  console.log(`release: sha256 ${hex}`);
  console.log(`release: assinado com a chave ${publica}`);

  if (SECO) {
    console.log(`release: --dry-run, nada publicado. Arquivos em ${pasta}`);
    return;
  }

  // 6. Publicação.
  const TOKEN = (() => {
    if (process.env.GITHUB_TOKEN) return process.env.GITHUB_TOKEN.trim();
    if (process.env.GH_TOKEN) return process.env.GH_TOKEN.trim();
    const r = spawnSync('git', ['credential', 'fill'], { input: 'protocol=https\nhost=github.com\n\n', encoding: 'utf8' });
    const m = /^password=(.+)$/m.exec(r.stdout || '');
    if (!m) falhar('sem token: defina GITHUB_TOKEN');
    return m[1].trim();
  })();

  async function api(metodo, url, corpo, cabecalhos = {}) {
    const r = await fetch(url.startsWith('http') ? url : API + url, {
      method: metodo,
      headers: { Authorization: `Bearer ${TOKEN}`, Accept: 'application/vnd.github+json', 'X-GitHub-Api-Version': '2022-11-28', 'User-Agent': 'rastro-release', ...cabecalhos },
      body: corpo,
    });
    const texto = await r.text();
    let json = null;
    try { json = texto ? JSON.parse(texto) : null; } catch { json = null; }
    if (!r.ok) falhar(`${metodo} ${url.split('?')[0]} respondeu ${r.status}: ${(json && json.message) || ''}`);
    return json;
  }

  const lista = await api('GET', `/repos/${REPO}/releases?per_page=100`);
  let release = (lista || []).find((x) => x.tag_name === tag);
  if (release && !release.draft) falhar(`a versão ${tag} já foi publicada`);
  if (!release) {
    release = await api('POST', `/repos/${REPO}/releases`, JSON.stringify({ tag_name: tag, target_commitish: head, name: `${NOME} ${versao}`, body: notas, draft: true, prerelease: false }), { 'Content-Type': 'application/json' });
  }

  const digestDe = (a) => (a && typeof a.digest === 'string' ? a.digest : '');
  async function enviar(nome, conteudo, tipo) {
    const esperado = `sha256:${createHash('sha256').update(conteudo).digest('hex')}`;
    const atual = (release.assets || []).find((a) => a.name === nome);
    if (atual) {
      if (atual.size === conteudo.length && (!digestDe(atual) || digestDe(atual) === esperado)) return;
      falhar(`o rascunho ${tag} já tem ${nome} diferente do local; confira no GitHub antes de tentar de novo`);
    }
    const asset = await api('POST', `${UPLOADS}/repos/${REPO}/releases/${release.id}/assets?name=${encodeURIComponent(nome)}`, conteudo, { 'Content-Type': tipo, 'Content-Length': String(conteudo.length) });
    if (!asset || asset.name !== nome || asset.size !== conteudo.length) falhar(`envio de ${nome} não conferiu (nome ou tamanho)`);
    if (digestDe(asset) && digestDe(asset) !== esperado) falhar(`${nome} subiu com SHA-256 diferente; a versão ficou em rascunho`);
  }
  await enviar(nomeZip, zip, 'application/zip');
  await enviar(`${nomeZip}.sha256`, conteudoHash, 'text/plain');
  await enviar(`${nomeZip}.sig`, conteudoSig, 'text/plain');

  const conferida = await api('GET', `/repos/${REPO}/releases/${release.id}`);
  for (const nome of [nomeZip, `${nomeZip}.sha256`, `${nomeZip}.sig`]) {
    if (!(conferida.assets || []).some((a) => a.name === nome && a.state === 'uploaded')) falhar(`falta ${nome} no rascunho; nada foi publicado`);
  }
  const publicada = await api('PATCH', `/repos/${REPO}/releases/${release.id}`, JSON.stringify({ draft: false, make_latest: 'true', tag_name: tag, target_commitish: head }), { 'Content-Type': 'application/json' });
  console.log(`release: publicada ${publicada.html_url}`);
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  principal().catch((erro) => falhar(erro && erro.message ? erro.message : String(erro)));
}
