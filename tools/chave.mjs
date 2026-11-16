#!/usr/bin/env node
/**
 * Chave de assinatura das releases do Rastro (Ed25519).
 *
 *   node tools/chave.mjs gerar     cria a chave privada (uma vez) e mostra a pública
 *   node tools/chave.mjs publica   mostra a chave pública da chave privada existente
 *   node tools/chave.mjs proteger  cifra a chave existente com RASTRO_CHAVE_SENHA (16+ caracteres)
 *
 * Com RASTRO_CHAVE_SENHA definida, `gerar` já cria a chave cifrada
 * (PKCS#8, AES-256) e a release pede a mesma variável para assinar.
 *
 * A chave privada fica FORA de qualquer repositório e fora de pasta
 * sincronizada: RASTRO_CHAVE_ASSINATURA (caminho) ou, sem ela,
 * ~/.rastro/chave-assinatura-ed25519.pem. Nunca é impressa. Quem tiver a
 * chave privada consegue publicar uma versão que os sites aceitam: guarde uma
 * cópia num cofre de senhas e não envie para ninguém.
 *
 * A chave pública (32 bytes em hex, 64 caracteres) vai para
 * rastro/atualizacao/chaves.php.
 * Contrato: docs/RELEASE.md.
 */
import { createPrivateKey, createPublicKey, generateKeyPairSync } from 'node:crypto';
import { existsSync, mkdirSync, readFileSync, realpathSync, renameSync, writeFileSync } from 'node:fs';
import { homedir } from 'node:os';
import { dirname, join, relative, resolve, isAbsolute } from 'node:path';
import { fileURLToPath } from 'node:url';

const raiz = resolve(dirname(fileURLToPath(import.meta.url)), '..');

/** Caminho real (resolve junction e link) da parte do caminho que já existe. */
function real(caminho) {
  let atual = resolve(caminho);
  const resto = [];
  while (!existsSync(atual)) {
    const pai = dirname(atual);
    if (pai === atual) break;
    resto.unshift(relative(pai, atual));
    atual = pai;
  }
  return join(existsSync(atual) ? realpathSync.native(atual) : atual, ...resto);
}

function dentro(caminho, pasta) {
  const rel = relative(pasta, caminho);
  return rel === '' || (!rel.startsWith('..') && !isAbsolute(rel));
}

export function caminhoDaChave() {
  const escolhido = process.env.RASTRO_CHAVE_ASSINATURA || join(homedir(), '.rastro', 'chave-assinatura-ed25519.pem');
  const absoluto = resolve(escolhido);
  if (dentro(absoluto, raiz) || dentro(real(absoluto), real(raiz))) {
    throw new Error('a chave privada não pode ficar dentro do repositório');
  }
  if (/google ?drive|meu drive|onedrive|dropbox|icloud/i.test(real(absoluto))) {
    throw new Error('a chave privada não pode ficar em pasta sincronizada com a nuvem');
  }
  return absoluto;
}

/** Senha da chave (RASTRO_CHAVE_SENHA), ou '' para chave sem senha. */
function senha() {
  return process.env.RASTRO_CHAVE_SENHA || '';
}

/** Chave pública crua (32 bytes) em hex minúsculo, a partir de um KeyObject. */
export function publicaEmHex(chave) {
  const der = createPublicKey(chave).export({ type: 'spki', format: 'der' });
  // SPKI do Ed25519: 12 bytes de cabeçalho + 32 bytes da chave.
  if (der.length !== 44) throw new Error('chave pública inesperada (não é Ed25519)');
  return der.subarray(12).toString('hex');
}

export function lerChavePrivada() {
  const caminho = caminhoDaChave();
  if (!existsSync(caminho)) throw new Error(`chave privada não encontrada (${caminho}). Rode: node tools/chave.mjs gerar`);
  const pem = readFileSync(caminho);
  const cifrada = pem.toString('utf8').includes('ENCRYPTED PRIVATE KEY');
  if (cifrada && !senha()) throw new Error('a chave privada tem senha: defina RASTRO_CHAVE_SENHA');
  const chave = createPrivateKey(cifrada ? { key: pem, passphrase: senha() } : pem);
  if (!cifrada) console.warn('chave: a chave privada está sem senha. Proteja com RASTRO_CHAVE_SENHA e node tools/chave.mjs proteger');
  if (chave.asymmetricKeyType !== 'ed25519') throw new Error('a chave privada não é Ed25519');
  return chave;
}

function principal() {
  const comando = process.argv[2];
  if (comando === 'gerar') {
    const caminho = caminhoDaChave();
    if (existsSync(caminho)) throw new Error(`já existe uma chave em ${caminho}; nada foi alterado`);
    const { privateKey } = generateKeyPairSync('ed25519');
    mkdirSync(dirname(caminho), { recursive: true });
    const exportada = senha()
      ? privateKey.export({ type: 'pkcs8', format: 'pem', cipher: 'aes-256-cbc', passphrase: senha() })
      : privateKey.export({ type: 'pkcs8', format: 'pem' });
    writeFileSync(caminho, exportada, { mode: 0o600, flag: 'wx' });
    console.log(`chave privada criada em ${caminho}`);
    console.log(`chave pública: ${publicaEmHex(privateKey)}`);
    return;
  }
  if (comando === 'proteger') {
    if (senha().length < 16) throw new Error('defina RASTRO_CHAVE_SENHA com 16 caracteres ou mais');
    const caminho = caminhoDaChave();
    const pem = readFileSync(caminho, 'utf8');
    if (pem.includes('ENCRYPTED PRIVATE KEY')) throw new Error('a chave já tem senha; nada foi alterado');
    const chave = createPrivateKey(pem);
    const cifrada = chave.export({ type: 'pkcs8', format: 'pem', cipher: 'aes-256-cbc', passphrase: senha() });
    if (publicaEmHex(createPrivateKey({ key: cifrada, passphrase: senha() })) !== publicaEmHex(chave)) throw new Error('conferência da chave cifrada falhou; nada foi alterado');
    const temporario = `${caminho}.novo`;
    writeFileSync(temporario, cifrada, { mode: 0o600, flag: 'wx' });
    renameSync(temporario, caminho);
    console.log(`chave protegida com senha em ${caminho} (chave pública ${publicaEmHex(chave)})`);
    return;
  }
  if (comando === 'publica') {
    console.log(publicaEmHex(lerChavePrivada()));
    return;
  }
  console.log('uso: node tools/chave.mjs gerar | publica | proteger');
  process.exitCode = 1;
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  try {
    principal();
  } catch (erro) {
    console.error(`chave: ${erro.message}`);
    process.exitCode = 1;
  }
}
