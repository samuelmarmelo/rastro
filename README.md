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

## Estrutura

| Pasta | O que tem |
|---|---|
| `rastro/` | o plugin (é esta pasta que vai no zip) |
| `tests/` | contratos Node (`validate-*.js`) e testes PHP sem WordPress (`tests/php/`) |
| `docs/` | contrato (`CONTRATO.md`) e manuais de cada parte |

## Testes

```bash
node tests/run.js
```

Os testes PHP rodam quando há `php` no PATH.

## Integração com um site

Tudo que é próprio de um site (aviso de cookies que já existe, PostHog
carregado por outro plugin, páginas privadas a mais) entra por uma ponte no
código do site, só com os ganchos públicos `rastro_*`. Ver
`docs/CONTRATO.md`, seção 9.

## Licença

GPL-2.0-or-later. Ver `LICENSE`.
