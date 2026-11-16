# Rastro: logs no PostHog Logs

Contrato: `docs/CONTRATO.md`, seção 15. Arquivo: `rastro/logs/logs.php`
(servidor) e `rastro/assets/js/tags.js` (navegador). Testes:
`tests/php/rastro-logs.test.php`, `tests/validate-rastro-logs-contract.js` e
o bloco de logs de `tests/validate-rastro-tags-contract.js`.

Documentação do PostHog conferida em 2026-11-16:
[Logs, outras linguagens](https://posthog.com/docs/logs/installation/other),
[Logs no JavaScript](https://posthog.com/docs/logs/installation/javascript) e
[configuração do posthog-js](https://posthog.com/docs/libraries/js/config).

## O que liga

| Campo (aba Geral) | Padrão | Efeito |
|---|---|---|
| Chave do projeto do PostHog (`posthog`) | vazio | sem chave, nada de logs |
| Logs do PostHog (`posthog_logs`) | ligado | logs do servidor e do navegador |
| Console do navegador nos logs (`posthog_logs_console`) | desligado | `captureConsoleLogs` no `posthog.init` |

O console fica desligado por padrão: a documentação do PostHog avisa que ele
pode levar dado pessoal (o que outros scripts escrevem no console). Ligar
só para investigar e desligar depois.

## Servidor (OTLP)

- `rastro_log( string $nivel, string $mensagem, array $atributos = array() ): bool`
  manda um registro OTLP/HTTP JSON para `https://us.i.posthog.com/i/v1/logs`
  (ou `eu`, pela região), com `Authorization: Bearer <chave do projeto>`.
- Níveis: `debug`, `info`, `warn`, `error` (outro vira `info`).
- Recurso: `service.name = rastro`, `service.version` (versão do Rastro) e
  `deployment.environment` (`wp_get_environment_type()`).
- A ação `rastro_falha` (falha isolada de um módulo, SafeWidget) vira um log
  `error` com `modulo` e `erro` (classe). Falhas de módulos que carregam
  antes de `logs/logs.php` (config, regras e saída) não chegam.
- Sem dado pessoal: e-mail (também `%40`), CPF, telefone, número de pedido,
  trecho numérico de caminho (`/view-order/4521/` vira `/view-order/:id/`) e
  números de 8 dígitos ou mais são mascarados; a pasta da instalação sai do
  texto. Atributos só com texto (até 500 caracteres), número ou booleano.
- Envio por `wp_safe_remote_post`, não bloqueante (1 s), sem seguir
  redirecionamento. Até 10 logs por requisição; repetido vai uma vez.
- Não envia sem chave, com o campo desligado, em cópia local
  (`rastro_ambiente_local()`) ou com o filtro `rastro_logs_ativo` em
  `false`. Falha do envio é engolida (e não dispara `rastro_falha`, para
  não entrar em laço).

Os logs do servidor não dependem do aviso de cookies: não medem visitantes,
não gravam nada no navegador e não levam dado pessoal (falhas do site).

## Navegador

- O `posthog.init` leva `logs`: `serviceName: 'rastro'`, `environment`,
  `serviceVersion` e `captureConsoleLogs` (campo do console). O `tags.js`
  confere o formato de novo e tira a chave quando ela vem errada.
- `window.rastro.log( nivel, mensagem, atributos )`: `posthog.captureLog(
  { body, level, attributes } )`. Antes do PostHog carregar, até 20 logs
  esperam numa fila na memória; sem aceite de análise (recusa) a fila é
  descartada e nada sai. Atributos só com valores simples; endereços perdem
  a consulta e a âncora.
- Falha de um carregador de destino no `tags.js` vira log `error`
  ("Falha ao carregar o destino <chave>").
- Segue o PostHog do Rastro: só depois do aceite de análise (modo LGPD).

## No PostHog

Logs → filtrar por `service.name = rastro`. Recomendado no projeto:
descartar o IP do visitante (Settings, Project).
