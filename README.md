# Rastro

Plugin de WordPress para rastreamento, consentimento de cookies (LGPD) e
relatórios de venda do WooCommerce por origem, com as tags de terceiros
carregadas fora do caminho crítico da página.

- Consentimento com três modos (`lgpd`, `aceite_automatico`, `basico`),
  Consent Mode v2 do Google, WP Consent API ou o aviso que o site já tem.
- Destinos: Google Tag Manager, GA4, Google Ads, Meta, TikTok, OpenAI Ads e
  PostHog, carregados só depois do aceite da finalidade certa.
- Eventos da loja no formato GA4 (`view_item`, `add_to_cart`,
  `begin_checkout`, `purchase` e outros), sem dado pessoal.
- Origem da visita (UTM e IDs de clique) gravada no pedido e relatório de
  vendas por origem, com CSV e planilha do Excel.
- Sem biblioteca, sem etapa de build, sem CDN. PHP 7.4 ou mais novo,
  WordPress 6.3 ou mais novo. WooCommerce é opcional (eventos e relatórios
  só aparecem com ele).

## Instalação

1. Baixe `rastro-vX.Y.Z.zip` da [última versão](https://github.com/samuelmarmelo/rastro/releases/latest).
2. Confira, se quiser, o SHA-256 com o arquivo `.sha256` da mesma versão.
3. No WordPress: Plugins, Adicionar, Enviar plugin. Ative e configure em
   Rastro.

Depois disso o Rastro se atualiza pelas versões publicadas aqui. Antes de
instalar qualquer versão, o plugin confere o tamanho, o SHA-256 e a
assinatura Ed25519 do pacote com as chaves de `rastro/atualizacao/chaves.php`.
A atualização automática vem ligada (dá para desligar na tela Plugins) e só
instala versão publicada há 48 h ou mais. Para desligar de vez, no
`wp-config.php`:

```php
define( 'RASTRO_ATUALIZAR_AUTO', false );
```

Site que traz o Rastro embutido (tema ou plugin próprio) mostra no painel o
botão **Instalar o Rastro**, que instala o plugin pelo mesmo caminho
conferido. Detalhes em `docs/ATUALIZADOR.md`.

## Estrutura

| Pasta | O que tem |
|---|---|
| `rastro/` | o plugin (é esta pasta que vai no zip) |
| `tests/` | contratos Node (`validate-*.js`) e testes PHP sem WordPress (`tests/php/`) |
| `docs/` | contrato (`CONTRATO.md`), manuais de cada parte, atualizador (`ATUALIZADOR.md`) e releases (`RELEASE.md`) |
| `tools/` | release assinada (`release.mjs`) e chave de assinatura (`chave.mjs`) |

## Testes

```bash
node tests/run.js
```

Os testes PHP rodam quando há `php` no PATH.

## Publicar uma versão

Ver `docs/RELEASE.md`. Resumo: subir a versão, `node tools/release.mjs --dry-run`
e `node tools/release.mjs` na `main` limpa.

## Integração com um site

Tudo que é próprio de um site (aviso de cookies que já existe, PostHog
carregado por outro plugin, páginas privadas a mais) entra por uma ponte no
código do site, só com os ganchos públicos `rastro_*`. Ver
`docs/CONTRATO.md`, seção 9.

## Segurança

Falhas de segurança: relate em particular, como descrito em `SECURITY.md`.

## Licença

GPL-2.0-or-later. Ver `LICENSE`.
