# Rastro no diretório do WordPress.org

Como levar o Rastro para [wordpress.org/plugins](https://wordpress.org/plugins/)
sem perder a distribuição assinada pelo GitHub. Ferramenta:
`tools/wporg.mjs`. Teste: `tests/validate-wporg-contract.js`.

Diretrizes conferidas em 2026-11-16:
[Detailed Plugin Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/),
[Common issues](https://developer.wordpress.org/plugins/wordpress-org/common-issues/),
[readme.txt](https://wordpress.org/plugins/readme.txt) e
[Plugin Check](https://wordpress.org/plugins/plugin-check/).

## 1. Dois pacotes, um código

| Pacote | Onde | Atualiza por |
|---|---|---|
| `rastro-vX.Y.Z.zip` (`tools/release.mjs`) | GitHub Releases | atualizador assinado do próprio Rastro (Ed25519, `docs/ATUALIZADOR.md`) |
| `rastro-wporg-vX.Y.Z.zip` e a pasta `rastro/` (`tools/wporg.mjs`) | WordPress.org (SVN) | atualizador do próprio WordPress |

O item 8 das diretrizes proíbe instalar ou atualizar a partir de outro
servidor, e a página de problemas comuns diz que plugin hospedado lá não
pode consultar outro servidor para atualizar. Por isso o pacote do
WordPress.org sai sem `rastro/atualizacao/` (atualizador, chaves e
instalador) e sem a linha `Update URI` do cabeçalho. O carregador pula
arquivo ausente (SafeWidget), então o resto do plugin funciona igual.

Um site deve usar um dos dois pacotes. Quem instalou pelo GitHub continua
recebendo pelo GitHub (o `Update URI` impede o WordPress de trocar pelo do
diretório).

## 2. Montar o pacote

```bash
node tests/run.js
node tools/wporg.mjs
```

A ferramenta lê o commit (`--ref`, padrão `HEAD`), nunca a cópia de
trabalho, e grava em `dist/wporg/` (ignorado pelo Git): a pasta `rastro/`
para o SVN e `rastro-wporg-vX.Y.Z.zip` para o primeiro envio. Se a pasta já
existir, ela para (nada é apagado): use `--saida dist/wporg-AAAA-MM-DD`.

Recusa o pacote quando sobra algo do atualizador (`Update URI`,
`api.github.com`, `githubusercontent.com`, filtros de atualização), quando
o `Stable tag` difere da `Version`, quando falta a seção `== External
services ==`, com mais de 5 tags ou com resumo acima de 150 caracteres.

## 3. Primeiro envio (feito por quem é dono da conta)

Passos da página oficial
[Developer information](https://wordpress.org/plugins/developers/)
(conferida em 2026-11-16), com o que cabe ao Rastro em cada um:

1. **Conta:** [criar](https://login.wordpress.org/register?locale=en_US) ou
   entrar na conta do WordPress.org e conferir se `Contributors:` do
   `rastro/readme.txt` tem o nome de usuário exato dessa conta (hoje está
   `samuelmarmelo`, ainda não conferido).
2. **Antes de enviar (nosso passo):**
   - `node tools/wporg.mjs` para montar o pacote;
   - passar o `rastro/readme.txt` no
     [validador de readme](https://wordpress.org/plugins/developers/readme-validator/);
   - instalar o [Plugin Check](https://wordpress.org/plugins/plugin-check/)
     num WordPress de teste, ativar o pacote do WordPress.org e corrigir o
     que ele apontar.
3. **Enviar para revisão:** o zip em
   [Add your plugin](https://wordpress.org/plugins/developers/add/). O nome
   pedido vira o endereço (`slug`); em 2026-11-16 a API pública do diretório
   não achava plugin com o slug `rastro`.
4. **Revisão manual:** aprovado, ou um e-mail pedindo mais informação ou
   correções. Responder pelo mesmo e-mail
   ([FAQ](https://developer.wordpress.org/plugins/wordpress-org/plugin-developer-faq/)).
5. **SVN:** aprovado, o WordPress.org entrega um repositório Subversion
   ([como usar](https://developer.wordpress.org/plugins/wordpress-org/how-to-use-subversion/)).
   O diretório é de hospedagem, não de listagem: o plugin só aparece depois
   de enviado ao SVN. A pasta `rastro/` montada vai para `trunk/` e uma cópia
   para `tags/X.Y.Z/`; banner e ícone vão na pasta `assets/` do SVN (fora do
   zip).
6. **Publicado:** pouco depois do envio ao SVN (com o `readme.txt`), o
   plugin aparece na busca do diretório.

### Restrições da página oficial

| Restrição | Rastro |
|---|---|
| Licença compatível com a GPLv2 ou mais nova, com o código-fonte legível | GPL-2.0-or-later; JS e PHP sem etapa de build nem minificação |
| Nada de limite artificial (licença, paywall, teste com prazo, cota) | nenhum |
| Usar o SVN que o WordPress.org entrega | passo 5 |
| Nenhum link externo no site público (como "powered by") sem pedir | o aviso de cookies só liga à política de privacidade do próprio site; nenhum outro link no site público |
| Seguir as [diretrizes detalhadas](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/) | seção 4 abaixo |

## 4. O que a revisão costuma pedir (riscos conhecidos)

| Ponto | Situação no Rastro | O que fazer |
|---|---|---|
| Atualizador próprio | fora do pacote do WordPress.org | nada |
| Serviços externos documentados | seção `== External services ==` com termos e privacidade de cada serviço | conferir os links antes de cada versão |
| Scripts de terceiros | só de serviços documentados, carregados depois do aceite | nada |
| Textos de origem em português | o código usa pt-BR como idioma de origem (`__( 'Texto', 'rastro' )`) | a revisão pode pedir inglês como idioma de origem, com a tradução pt-BR no translate.wordpress.org. **Decisão do Samuel** antes do envio |
| Marcas no nome | o nome é "Rastro"; Google, Meta e TikTok só na descrição | nada |
| `Tested up to` | 6.8 | testar na versão atual do WordPress e subir o número |
| Saída sem escape | IDs validados e escapados (`esc_attr`, `esc_url`, `rastro_json`) | rodar o Plugin Check |

## 5. Versões seguintes

Para cada versão publicada no GitHub: `node tools/wporg.mjs --ref vX.Y.Z`
e copiar a pasta para `trunk/` e `tags/X.Y.Z/` do SVN, com o `Stable tag`
já igual.
