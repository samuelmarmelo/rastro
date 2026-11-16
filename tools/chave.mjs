#!/usr/bin/env node
/**
 * Chave de assinatura das releases do Rastro (Ed25519).
 *
 *   node tools/chave.mjs gerar     cria a chave privada (uma vez) e mostra a pública
 *   node tools/chave.mjs publica   mostra a chave pública da chave privada existente
 *
 * A chave privada fica FORA de qualquer repositório e fora de pasta
 * sincronizada: RASTRO_CHAVE_ASSINATURA (caminho) ou, sem ela,
 * ~/.rastro/chave-assinatura-ed25519.pem. Nunca é impressa. Quem tiver a
 * chave privada consegue publicar uma versão que os sites aceitam: guarde uma
 * cópia num cofre de senhas e não envie para ninguém.
 *
 * A chave pública (32 bytes em base64) vai para rastro/atualizacao/chaves.php.
 * Contrato: docs/RELEASE.md.
 */
import { createPrivateKey, createPublicKey, generateKeyPairSync } from 'node:crypto';
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { homedir } from 'node:os';
import { dirname, join, relative, resolve, isAbsolute } from 'node:path';
import { fileURLToPath } from 'node:url';

const raiz = resolve(dirname(fileURLToPath(import.meta.url)), '..');

export function caminhoDaChave() {
  const escolhido = process.env.RASTRO_CHAVE_ASSINATURA || join(homedir(), '.rastro', 'chave-assinatura-ed25519.pem');
  const absoluto = resolve(escolhido);
  const rel = relative(raiz, absoluto);
  if (rel === '' || (!rel.startsWith('..') && !isAbsolute(rel))) {
    throw new Error('a chave privada não pode ficar dentro do repositório');
  }
  return absoluto;
}

/** Chave pública crua (32 bytes) em base64, a partir de um KeyObject. */
export function publicaEmBase64(chave) {
  const der = createPublicKey(chave).export({ type: 'spki', format: 'der' });
  // SPKI do Ed25519: 12 bytes de cabeçalho + 32 bytes da chave.
  if (der.length !== 44) throw new Error('chave pública inesperada (não é Ed25519)');
  return der.subarray(12).toString('base64');
}

export function lerChavePrivada() {
  const caminho = caminhoDaChave();
  if (!existsSync(caminho)) throw new Error(`chave privada não encontrada (${caminho}). Rode: node tools/chave.mjs gerar`);
  const chave = createPrivateKey(readFileSync(caminho));
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
    writeFileSync(caminho, privateKey.export({ type: 'pkcs8', format: 'pem' }), { mode: 0o600, flag: 'wx' });
    console.log(`chave privada criada em ${caminho}`);
    console.log(`chave pública: ${publicaEmBase64(privateKey)}`);
    return;
  }
  if (comando === 'publica') {
    console.log(publicaEmBase64(lerChavePrivada()));
    return;
  }
  console.log('uso: node tools/chave.mjs gerar | publica');
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
