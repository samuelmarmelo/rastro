# Como contribuir com o Rastro

Obrigado pelo interesse. O Rastro mede visitantes de lojas reais, então
privacidade e segurança vêm antes de qualquer recurso novo.

## Antes de abrir uma issue

- **Falha de segurança:** não abra issue pública. Use **Security, Report a
  vulnerability** (ver `SECURITY.md`).
- Confira se o assunto já está nas issues abertas ou fechadas.
- Diga a versão do Rastro, do WordPress, do WooCommerce (se houver) e do
  PHP, o modo de consentimento e o passo a passo.
- Nunca cole IDs reais de produção, chaves, dados de clientes ou pedidos.

## Regras do código

O contrato (`docs/CONTRATO.md`) é a fonte da verdade. Mudou o desenho,
atualize o contrato e o manual da parte no mesmo PR.

- PHP 7.4 ou mais novo, JS puro, sem biblioteca, sem etapa de build, sem CDN.
- Prefixo único: funções `rastro_*`, constantes `RASTRO_*`, opções
  `rastro_*`, metas `_rastro_*`, classes CSS `rastro-*`, text domain `rastro`.
- Nada de terceiro antes do aceite da finalidade certa (modo LGPD).
- Nenhum dado pessoal para terceiros.
- Cada módulo falha fechado e sozinho (`is_readable` e `try/catch`): um erro
  num módulo nunca derruba o site.
- Soft delete: nada é apagado; o que sai de uso é arquivado.
- Textos visíveis em português do Brasil, com acentuação correta e sem
  travessão.
- Arquivos com fim de linha LF.

## Fluxo

1. Uma branch por tarefa: `feat/`, `fix/`, `refactor/`, `docs/`, `chore/`,
   `test/` mais uma descrição curta.
2. Um commit por mudança, no padrão Conventional Commits.
3. Teste de contrato e teste PHP da parte que mudou
   (`tests/validate-rastro-*.js` e `tests/php/rastro-*.test.php`).
4. `node tests/run.js` verde antes de abrir o PR.
5. PR com: o que muda, por quê, como testar, se mexe no contrato e o que
   ficou de fora.

## Licença

Ao contribuir, você concorda que o código entra sob GPL-2.0-or-later.
