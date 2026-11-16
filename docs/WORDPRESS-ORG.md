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

1. Criar ou entrar na conta em [wordpress.org](https://login.wordpress.org/)
   e conferir se `Contributors:` do `rastro/readme.txt` tem o nome de
   usuário exato dessa conta (hoje está `samuelmarmelo`, ainda não
   conferido).
2. Instalar o [Plugin Check](https://wordpress.org/plugins/plugin-check/)
   num WordPress de teste, ativar o pacote do WordPress.org e rodar a
   checagem. Corrigir o que ela apontar antes de enviar.
3. Enviar o zip em [Add your plugin](https://wordpress.org/plugins/developers/add/).
   O nome pedido vira o endereço (`slug`); em 2026-11-16 a API pública do
   diretório não achava plugin com o slug `rastro`.
4. Esperar a revisão por e-mail e responder pelo mesmo e-mail.
5. Aprovado, publicar pelo SVN: a pasta `rastro/` montada vai para
   `trunk/` e uma cópia para `tags/X.Y.Z/`. Banner e ícone vão na pasta
   `assets/` do SVN (fora do zip).

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
