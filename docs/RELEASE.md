# Releases do Rastro

Contrato da publicação. O atualizador do plugin (`docs/ATUALIZADOR.md`) só
instala o que segue este formato. Mudou o formato, mude os dois lados no
mesmo PR e suba a versão do formato (`rastro-release-v2`).

## 1. Arquivos de cada versão

Cada versão `vX.Y.Z` (SemVer, sempre maior que a anterior) publica três
arquivos, enviados com a versão ainda em rascunho:

| Arquivo | Conteúdo |
|---|---|
| `rastro-vX.Y.Z.zip` | o plugin, pasta `rastro/` na raiz, montado com `git archive` do commit da tag |
| `rastro-vX.Y.Z.zip.sha256` | uma linha: `<sha256 em hex minúsculo>  rastro-vX.Y.Z.zip` (formato do `sha256sum`) |
| `rastro-vX.Y.Z.zip.sig` | uma linha: assinatura Ed25519 (64 bytes) em hex minúsculo (128 caracteres) da mensagem da seção 2 |

O repositório tem releases imutáveis e tags `v*` protegidas: depois de
publicada, nenhuma versão muda de arquivo nem de commit.

## 2. Mensagem assinada

Texto UTF-8, linhas terminadas em `\n`, nesta ordem:

```
rastro-release-v1
tag: vX.Y.Z
arquivo: rastro-vX.Y.Z.zip
sha256: <sha256 do zip em hex minúsculo>
```

A tag e o nome do arquivo entram na mensagem: uma assinatura válida para
uma versão não serve para outra.

## 3. Chave de assinatura

- Ed25519. Chave privada fora de qualquer repositório e de pasta
  sincronizada: `RASTRO_CHAVE_ASSINATURA` ou
  `~/.rastro/chave-assinatura-ed25519.pem` (`node tools/chave.mjs gerar`).
  Guarde uma cópia num cofre de senhas.
- Chaves públicas confiáveis (32 bytes em hex minúsculo):
  `rastro_atualizador_chaves()` em `rastro/atualizacao/chaves.php`. A release recusa assinar com chave que
  não esteja lá.
- Troca de chave: publicar uma versão assinada pela chave antiga que já
  traga a nova em `chaves.php`; na versão seguinte, assinar com a nova e
  tirar a antiga da lista.
- Chave vazada: tirar a chave da lista numa versão nova assinada com uma
  chave que continua confiável. Se não houver nenhuma, os sites precisam
  receber o zip novo à mão (Plugins, Adicionar, Enviar plugin).

## 4. Como publicar

```bash
node tests/run.js
node tools/release.mjs --dry-run
node tools/release.mjs
```

Antes: subir `Version` e `RASTRO_VERSAO` em `rastro/rastro.php`, o
`Stable tag` de `rastro/readme.txt` e escrever a seção `## [X.Y.Z]` do
`CHANGELOG.md`, num commit próprio já na `main`.

A ferramenta para em qualquer falha antes de publicar: branch diferente da
`main`, mudança local, HEAD diferente de `origin/main`, tag já usada,
versões diferentes entre os arquivos, suíte vermelha, arquivo de
desenvolvimento no zip, zip acima de 5 MB, chave fora da lista. Depois de
enviar, confere nome, tamanho e SHA-256 de cada arquivo no rascunho e só
então publica como a versão mais recente.

Token: `GITHUB_TOKEN`, `GH_TOKEN` ou o do Git (`git credential fill`), com
escrita em Contents. Nunca é impresso nem gravado.
