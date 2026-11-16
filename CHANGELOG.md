# Changelog

Formato: [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/).
Versões: [SemVer](https://semver.org/lang/pt-BR/).

## [Não publicado]

## [1.0.0] - 2026-11-16

Primeira versão pública, distribuída pelo repositório público e atualizada
por versões assinadas.

### Adicionado
- Atualizador do plugin: oferece a última versão publicada, baixa sem
  token e sem seguir redirecionamento, confere tamanho, SHA-256 e a
  assinatura Ed25519 antes de instalar; atualização automática com opção
  de desligar (`RASTRO_ATUALIZAR_AUTO`).
- Instalador do plugin próprio para sites com o Rastro embutido: aviso com
  o botão Instalar ou Ativar, mesmo download conferido.
- Cabeçalho `Update URI`: o WordPress não confunde o Rastro com outro
  plugin de mesmo nome.
- Release assinada (`tools/release.mjs`, `tools/chave.mjs`), CI em PHP 7.4
  e 8.4 e política de segurança (`SECURITY.md`).

## [0.1.0] - 2026-11-15

### Adicionado
- Importação do Rastro como plugin próprio: consentimento (LGPD, aceite
  automático e básico), Consent Mode v2, GTM, GA4, Google Ads, Meta,
  TikTok, OpenAI Ads e PostHog fora do caminho crítico, eventos da loja,
  origem da visita no pedido e relatórios de venda por origem (CSV e Excel).
