# Segurança

## Como relatar uma falha

Não abra issue pública. Use **Security, Report a vulnerability** neste
repositório (relato privado). Diga a versão, o passo a passo e o impacto.
A resposta inicial sai em até 7 dias.

## Versões com correção

Só a última versão publicada recebe correção. O plugin se atualiza sozinho
(ver `docs/ATUALIZADOR.md`).

## Cadeia de entrega

- Cada versão publica o zip, o `.sha256` e a assinatura Ed25519 (`.sig`).
  O plugin só instala o que confere com as chaves de
  `rastro/atualizacao/chaves.php`.
- A chave privada fica fora do repositório, na máquina de quem publica.
- Versões publicadas e tags `v*` são imutáveis neste repositório; a `main`
  não aceita force-push nem remoção.
- O CI roda com permissão só de leitura e ações fixadas por commit.

## O que o plugin promete

- Nenhuma tag de terceiro antes do aceite da finalidade (modo LGPD).
- Nenhum dado pessoal enviado a terceiros.
- Nenhum token ou dado do site nas consultas de atualização.
