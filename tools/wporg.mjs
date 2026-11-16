#!/usr/bin/env node
/**
 * Pacote do Rastro para o diretório de plugins do WordPress.org
 * (docs/WORDPRESS-ORG.md).
 *
 * As diretrizes do WordPress.org (Detailed Plugin Guidelines, item 8) não
 * aceitam plugin que instala ou atualiza a partir de outro servidor. Este
 * pacote sai do mesmo commit, sem o atualizador próprio:
 *
 *  1. Lê os arquivos de rastro/ do commit (git ls-tree e git show, nada da
 *     cópia de trabalho), sem rastro/atualizacao/ (atualizador, chaves e
 *     instalador; o carregador pula arquivo ausente, SafeWidget).
 *  2. Tira a linha `Update URI` do cabeçalho de rastro/rastro.php.
 *  3. Confere: nenhum resto do atualizador (GitHub, filtros de atualização),
 *     Stable tag igual à Version, nenhum arquivo de desenvolvimento.
 *  4. Grava dist/wporg/rastro/ (para o SVN do WordPress.org) e
 *     dist/wporg/rastro-wporg-vX.Y.Z.zip (para o primeiro envio).
 *
 * Uso: node tools/wporg.mjs [--ref <commit>] [--saida <pasta>] [--sem-zip]
 * Nada é publicado: o envio ao WordPress.org é manual (docs/WORDPRESS-ORG.md).
 */
import { spawnSync } from 'node:child_process';
import { mkdirSync, writeFileSync, existsSync } from 'node:fs';
import { dirname, join, resolve, relative, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import { deflateRawSync, crc32 } from 'node:zlib';

const raiz = resolve(dirname(fileURLToPath(import.meta.url)), '..');

/** Arquivos do atualizador próprio que não vão para o WordPress.org. */
export function foraDoWporg(caminho) {
  return /^rastro\/atualizacao\//.test(caminho);
}

/** Arquivo de desenvolvimento que nunca vai no pacote (caminho a partir da raiz). */
export function proibido(caminho) {
  return /(^|\/)(tests?|node_modules|\.github|docs|tools)\//.test(caminho)
    || /\.(pem|key|env|zip|sh|ps1|map|log)$/i.test(caminho)
    || /(^|\/)\.[^/]+$/.test(caminho);
}

/** Tira a linha `Update URI` do cabeçalho do plugin. */
export function semUpdateUri(php) {
  return php.replace(/^ \* Update URI:[^\n]*\n/m, '');
}

/** Restos do atualizador próprio que não podem ficar no pacote. */
export const RESTOS = [/Update URI:/, /api\.github\.com/, /githubusercontent\.com/, /update_plugins_github\.com/, /upgrader_pre_download/, /#rastro-assinado/];

/** Problemas do pacote montado (lista vazia = pronto). */
export function conferir(arquivos) {
  const problemas = [];
  const nomes = Object.keys(arquivos);
  for (const nome of nomes) {
    if (proibido(nome)) problemas.push(`arquivo de desenvolvimento: ${nome}`);
    if (foraDoWporg(nome)) problemas.push(`atualizador próprio no pacote: ${nome}`);
    if (/\.(php|js|txt|css)$/.test(nome)) {
      const texto = arquivos[nome].toString('utf8');
      for (const re of RESTOS) if (re.test(texto)) problemas.push(`${nome}: resto do atualizador (${re})`);
    }
  }
  const plugin = arquivos['rastro/rastro.php'];
  const readme = arquivos['rastro/readme.txt'];
  if (!plugin) problemas.push('sem rastro/rastro.php');
  if (!readme) problemas.push('sem rastro/readme.txt');
  if (plugin && readme) {
    const versao = (/^ \* Version:\s*(\S+)$/m.exec(plugin.toString('utf8')) || [])[1];
    const estavel = (/^Stable tag:\s*(\S+)$/m.exec(readme.toString('utf8')) || [])[1];
    if (!versao || versao !== estavel) problemas.push(`Stable tag (${estavel}) diferente da Version (${versao})`);
    const txt = readme.toString('utf8');
    if (!/^== External services ==$/m.test(txt)) problemas.push('readme.txt sem a seção External services');
    const tags = (/^Tags:\s*(.+)$/m.exec(txt) || [])[1] || '';
    if (tags.split(',').filter((t) => t.trim()).length > 5) problemas.push('readme.txt com mais de 5 tags');
    const resumo = txt.split('\n').find((l, i, a) => i > 0 && a[i - 1] === '' && l && !/^[A-Z][A-Za-z ]+:/.test(l) && !l.startsWith('=='));
    if (resumo && resumo.length > 150) problemas.push('resumo do readme.txt com mais de 150 caracteres');
  }
  return problemas;
}

/** Zip mínimo (deflate), entradas na ordem dada, sem link nem permissão especial. */
export function montarZip(arquivos) {
  const locais = [];
  const centrais = [];
  let deslocamento = 0;
  const data = ((2026 - 1980) << 9) | (1 << 5) | 1;
  for (const [nome, conteudo] of Object.entries(arquivos)) {
    const nomeBuf = Buffer.from(nome, 'utf8');
    const comprimido = deflateRawSync(conteudo, { level: 9 });
    const crc = crc32(conteudo) >>> 0;
    const local = Buffer.alloc(30);
    local.writeUInt32LE(0x04034b50, 0);
    local.writeUInt16LE(20, 4);
    local.writeUInt16LE(0x0800, 6);
    local.writeUInt16LE(8, 8);
    local.writeUInt16LE(0, 10);
    local.writeUInt16LE(data, 12);
    local.writeUInt32LE(crc, 14);
    local.writeUInt32LE(comprimido.length, 18);
    local.writeUInt32LE(conteudo.length, 22);
    local.writeUInt16LE(nomeBuf.length, 26);
    local.writeUInt16LE(0, 28);
    locais.push(local, nomeBuf, comprimido);
    const central = Buffer.alloc(46);
    central.writeUInt32LE(0x02014b50, 0);
    central.writeUInt16LE(0x0314, 4);
    central.writeUInt16LE(20, 6);
    central.writeUInt16LE(0x0800, 8);
    central.writeUInt16LE(8, 10);
    central.writeUInt16LE(0, 12);
    central.writeUInt16LE(data, 14);
    central.writeUInt32LE(crc, 16);
    central.writeUInt32LE(comprimido.length, 20);
    central.writeUInt32LE(conteudo.length, 24);
    central.writeUInt16LE(nomeBuf.length, 28);
    central.writeUInt32LE((0o100644 << 16) >>> 0, 38);
    central.writeUInt32LE(deslocamento, 42);
    centrais.push(central, nomeBuf);
    deslocamento += 30 + nomeBuf.length + comprimido.length;
  }
  const tamanhoCentral = centrais.reduce((t, b) => t + b.length, 0);
  const fim = Buffer.alloc(22);
  fim.writeUInt32LE(0x06054b50, 0);
  fim.writeUInt16LE(Object.keys(arquivos).length, 8);
  fim.writeUInt16LE(Object.keys(arquivos).length, 10);
  fim.writeUInt32LE(tamanhoCentral, 12);
  fim.writeUInt32LE(deslocamento, 16);
  return Buffer.concat([...locais, ...centrais, fim]);
}

function git(args, binario = false) {
  const r = spawnSync('git', args, { cwd: raiz, maxBuffer: 64 * 1024 * 1024, encoding: binario ? 'buffer' : 'utf8' });
  if (r.status !== 0) throw new Error(`git ${args.join(' ')} falhou: ${String(r.stderr || '').trim()}`);
  return r.stdout;
}

/** Monta os arquivos do pacote a partir de um commit. */
export function montar(ref = 'HEAD') {
  const lista = git(['ls-tree', '-r', '--name-only', ref, '--', 'rastro/']).split('\n').filter(Boolean);
  const arquivos = {};
  for (const nome of lista) {
    if (foraDoWporg(nome)) continue;
    let conteudo = git(['show', `${ref}:${nome}`], true);
    if (nome === 'rastro/rastro.php') conteudo = Buffer.from(semUpdateUri(conteudo.toString('utf8')), 'utf8');
    arquivos[nome] = conteudo;
  }
  return arquivos;
}

function opcao(nome) {
  const i = process.argv.indexOf(nome);
  return i > 0 ? process.argv[i + 1] : undefined;
}

function principal() {
  const ref = opcao('--ref') || 'HEAD';
  const saida = resolve(raiz, opcao('--saida') || join('dist', 'wporg'));
  const rel = relative(raiz, saida);
  if (!rel || rel.startsWith('..') || rel.split(sep)[0] !== 'dist') {
    console.error('wporg: a saída precisa ficar dentro de dist/');
    process.exit(1);
  }
  const arquivos = montar(ref);
  const problemas = conferir(arquivos);
  if (problemas.length) {
    console.error('wporg: pacote recusado:\n- ' + problemas.join('\n- '));
    process.exit(1);
  }
  const versao = /^ \* Version:\s*(\S+)$/m.exec(arquivos['rastro/rastro.php'].toString('utf8'))[1];
  const pasta = join(saida, 'rastro');
  // Soft delete: nunca apaga uma montagem anterior; pede outra pasta.
  if (existsSync(pasta)) {
    console.error(`wporg: ${relative(raiz, pasta)} já existe; use --saida dist/wporg-<data> (nada é apagado)`);
    process.exit(1);
  }
  for (const [nome, conteudo] of Object.entries(arquivos)) {
    const destino = join(saida, nome);
    mkdirSync(dirname(destino), { recursive: true });
    writeFileSync(destino, conteudo);
  }
  if (!process.argv.includes('--sem-zip')) {
    const zip = join(saida, `rastro-wporg-v${versao}.zip`);
    writeFileSync(zip, montarZip(arquivos));
    console.log(`wporg: ${Object.keys(arquivos).length} arquivos, ${relative(raiz, zip)}`);
  } else {
    console.log(`wporg: ${Object.keys(arquivos).length} arquivos em ${relative(raiz, pasta)}`);
  }
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  try {
    principal();
  } catch (e) {
    console.error(`wporg: ${e.message}`);
    process.exit(1);
  }
}
