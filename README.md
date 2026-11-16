# Rastro: rastreamento com LGPD para WordPress e WooCommerce

[![testes](https://github.com/samuelmarmelo/rastro/actions/workflows/testes.yml/badge.svg)](https://github.com/samuelmarmelo/rastro/actions/workflows/testes.yml)
[![versão](https://img.shields.io/github/v/release/samuelmarmelo/rastro?label=vers%C3%A3o)](https://github.com/samuelmarmelo/rastro/releases/latest)
[![licença](https://img.shields.io/badge/licen%C3%A7a-GPL--2.0--or--later-blue)](LICENSE)
![PHP 7.4+](https://img.shields.io/badge/PHP-7.4%2B-777bb4)
![WordPress 6.3+](https://img.shields.io/badge/WordPress-6.3%2B-21759b)

Plugin de WordPress para **rastreamento de marketing**, **consentimento de
cookies (LGPD)** e **relatórios de venda do WooCommerce por origem (UTM)**,
com as tags de terceiros carregadas só depois do aceite e fora do caminho
crítico da página. Sem biblioteca, sem etapa de build, sem CDN.

> **English summary:** Rastro is a privacy-first WordPress and WooCommerce
> tracking plugin built for Brazil's LGPD (similar to GDPR). It adds a cookie
> consent banner with Google Consent Mode v2, loads Google Tag Manager, GA4,
> Google Ads, Meta Pixel, TikTok Pixel, OpenAI Ads and PostHog only after
> consent and off the critical rendering path, sends GA4-style ecommerce
> events without personal data, stores UTM/click-ID attribution on each order
> and reports WooCommerce sales by traffic source (CSV and Excel). Updates
> are signed with Ed25519. Interface in Brazilian Portuguese.

## Recursos

**Consentimento (LGPD)**
- Três modos: `lgpd` (padrão, nada antes do "Aceitar"), `aceite_automatico`
  e `basico`. O painel avisa que só o modo LGPD atende a LGPD.
- Consent Mode v2 do Google, WP Consent API ou o aviso de cookies que o site
  já tem (ponte pelos ganchos `rastro_*`).
- Revogação apaga os cookies de medição conhecidos e recarrega a página.

**Tags de marketing e análise**
- Google Tag Manager, Google Analytics 4, Google Ads (com conversão de
  compra), Meta Pixel, TikTok Pixel, OpenAI Ads e PostHog.
- Carregadas só com a finalidade certa aceita (análise ou publicidade),
  quando o navegador fica ocioso, na primeira interação ou logo após o aceite.
- Meta Pixel com o código base no `<head>` como a Meta pede (só a fila, sem
  pedido à Meta antes do aceite) e o `<noscript>` no rodapé no modo básico.
- TikTok Pixel com o código base no `<head>` no modo básico e o modo de
  consentimento oficial na revogação.

**Eventos do WooCommerce**
- `view_item_list`, `view_item`, `add_to_cart`, `remove_from_cart`,
  `view_cart`, `begin_checkout`, `add_payment_info`, `purchase` e `search`,
  no formato do GA4 e traduzidos para Meta, TikTok e PostHog.
- Sem dado pessoal; compra deduplicada por pedido.

**Origem da venda e relatórios**
- UTM e IDs de clique (`gclid`, `fbclid`, `ttclid`...) gravados no pedido,
  com primeira e última visita.
- Relatório de vendas por fonte, página de entrada e cada campo UTM, com
  gráfico, CSV e planilha do Excel. Lê também pedidos antigos do
  PixelYourSite e a atribuição nativa do WooCommerce.

**Verificações de domínio**
- Google Search Console, Meta (Facebook) e Pinterest por meta tag.
- Arquivo `.txt` de verificação na raiz do site (assinatura do TikTok for
  Developers e outros serviços).

**Observabilidade**
- Logs do Rastro no PostHog Logs: falhas no servidor (OTLP, sem dado
  pessoal) e no navegador depois do aceite.

**Desempenho e segurança**
- Scripts fora do atraso de LiteSpeed Cache, WP Rocket e Autoptimize, com o
  atraso próprio do Rastro igual em qualquer hospedagem.
- HTML igual para todo visitante (cache de página seguro).
- Atualizações assinadas com Ed25519, conferidas por tamanho e SHA-256.

## Instalação

1. Baixe `rastro-vX.Y.Z.zip` da [última versão](https://github.com/samuelmarmelo/rastro/releases/latest).
2. Confira, se quiser, o SHA-256 com o arquivo `.sha256` da mesma versão.
3. No WordPress: Plugins, Adicionar, Enviar plugin. Ative e configure em
   Rastro.

Requisitos: PHP 7.4 ou mais novo, WordPress 6.3 ou mais novo. WooCommerce é
opcional (eventos e relatórios só aparecem com ele).

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

## Documentação

| Documento | Assunto |
|---|---|
| [`docs/CONTRATO.md`](docs/CONTRATO.md) | contrato do plugin: regras, decisões, arquitetura, ganchos |
| [`docs/BASE.md`](docs/BASE.md) | configuração, onde medir, saída para o navegador, painel |
| [`docs/CONSENTIMENTO.md`](docs/CONSENTIMENTO.md) | modos, aviso, provedores, Consent Mode v2 |
| [`docs/TAGS.md`](docs/TAGS.md) | destinos, carga, verificações de domínio, CSP |
| [`docs/EVENTOS.md`](docs/EVENTOS.md) | eventos do WooCommerce |
| [`docs/ATRIBUICAO.md`](docs/ATRIBUICAO.md) | origem da visita no pedido |
| [`docs/RELATORIOS.md`](docs/RELATORIOS.md) | relatórios de venda por origem |
| [`docs/LOGS.md`](docs/LOGS.md) | logs no PostHog Logs |
| [`docs/ATUALIZADOR.md`](docs/ATUALIZADOR.md) | atualizador assinado e instalador |
| [`docs/RELEASE.md`](docs/RELEASE.md) | como publicar uma versão |
| [`docs/WORDPRESS-ORG.md`](docs/WORDPRESS-ORG.md) | pacote e envio ao diretório do WordPress.org |

## Estrutura

| Pasta | O que tem |
|---|---|
| `rastro/` | o plugin (é esta pasta que vai no zip) |
| `tests/` | contratos Node (`validate-*.js`) e testes PHP sem WordPress (`tests/php/`) |
| `docs/` | contrato e manuais de cada parte |
| `tools/` | release assinada (`release.mjs`), chave de assinatura (`chave.mjs`) e pacote do WordPress.org (`wporg.mjs`) |

## Testes

```bash
node tests/run.js
```

Os testes PHP rodam quando há `php` no PATH. O CI roda em PHP 7.4 e 8.4.

## Publicar uma versão

Ver `docs/RELEASE.md`. Resumo: subir a versão, `node tools/release.mjs --dry-run`
e `node tools/release.mjs` na `main` limpa.

## Integração com um site

Tudo que é próprio de um site (aviso de cookies que já existe, PostHog
carregado por outro plugin, páginas privadas a mais) entra por uma ponte no
código do site, só com os ganchos públicos `rastro_*`. Ver
`docs/CONTRATO.md`, seção 9.

## Contribuir

Ver [`CONTRIBUTING.md`](CONTRIBUTING.md). Falhas de segurança: relate em
particular, como descrito em [`SECURITY.md`](SECURITY.md).

## Licença

GPL-2.0-or-later. Ver `LICENSE`.
