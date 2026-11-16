'use strict';

// Contrato dos logs do Rastro no PostHog Logs (docs/LOGS.md; contrato,
// seção 15). Parte estática sempre; php -l e tests/php/rastro-logs.test.php
// só quando há `php` no PATH. O comportamento do navegador fica no bloco de
// logs de tests/validate-rastro-tags-contract.js.

const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const lerBruto = (p) => fs.readFileSync(path.join(root, p), 'utf8');
const ler = (p) => lerBruto(p).replace(/\r\n/g, '\n');
const tem = (t, s, m) => assert.ok(t.includes(s), m || s);
const semMatch = (t, re, m) => assert.ok(!re.test(t), m || String(re));
const TRAVESSAO = new RegExp(String.fromCharCode(0x2014));

const arquivos = ['rastro/logs/logs.php', 'docs/LOGS.md', 'tests/php/rastro-logs.test.php', 'tests/validate-rastro-logs-contract.js'];
for (const arquivo of arquivos) {
  assert.ok(fs.existsSync(path.join(root, arquivo)), `existe ${arquivo}`);
  const bruto = lerBruto(arquivo);
  semMatch(bruto, /\r/, `${arquivo} com fim de linha LF`);
  semMatch(bruto, TRAVESSAO, `${arquivo} sem travessão`);
}

const php = ler('rastro/logs/logs.php');
tem(php, "defined( 'ABSPATH' ) || exit;", 'guarda ABSPATH');
for (const fn of ['rastro_log', 'rastro_logs_limpar_texto', 'rastro_logs_sem_raiz', 'rastro_logs_nivel', 'rastro_logs_corpo', 'rastro_logs_atributos', 'rastro_logs_ambiente', 'rastro_logs_navegador', 'rastro_logs_destino', 'rastro_logs_cota', 'rastro_logs_falha']) {
  assert.ok(new RegExp(`\\nfunction ${fn}\\(`).test(php), `função ${fn}`);
}
tem(php, "add_action( 'rastro_falha', 'rastro_logs_falha', 10, 2 );", 'falha isolada vira log');
tem(php, "'/i/v1/logs'", 'endpoint OTLP do PostHog');
tem(php, "'Authorization' => 'Bearer ' . $destino['chave']", 'chave só no cabeçalho');
tem(php, "'blocking'    => false", 'envio não bloqueante');
tem(php, "'redirection' => 0", 'sem seguir redirecionamento');
tem(php, 'wp_safe_remote_post(', 'envio por wp_safe_remote_post');
tem(php, 'rastro_ambiente_local()', 'cópia local não envia');
tem(php, "apply_filters( 'rastro_logs_ativo', true )", 'filtro para desligar');
semMatch(php, /wp_remote_post\(|curl_|file_get_contents\(/, 'só wp_safe_remote_post');
semMatch(php, /animalart/i, 'sem nome de site');

const rastro = ler('rastro/rastro.php');
tem(rastro, "'nucleo/saida.php',\n\t\t\t'logs/logs.php',", 'módulo na lista, logo depois da base');

const config = ler('rastro/nucleo/config.php');
tem(config, "'posthog_logs'                 => array(", 'campo posthog_logs');
tem(config, "'posthog_logs_console'         => array(", 'campo posthog_logs_console');

const tags = ler('rastro/tags/tags.php');
tem(tags, "function_exists( 'rastro_logs_navegador' )", 'tags.php funciona sem o módulo de logs');

const js = ler('rastro/assets/js/tags.js');
tem(js, 'w.rastro.log = registrarLog;', 'window.rastro.log');
tem(js, 'w.posthog.captureLog( item );', 'logs pelo captureLog');
tem(js, 'opcoes.logs = logsValidos( opcoes.logs );', 'logs conferidos antes do init');

const contrato = ler('docs/CONTRATO.md');
tem(contrato, '## 15. Logs no PostHog', 'contrato com a seção dos logs');

const temPhp = spawnSync('php', ['-v'], { encoding: 'utf8' });
if (temPhp.status === 0) {
  for (const arquivo of ['rastro/logs/logs.php', 'tests/php/rastro-logs.test.php']) {
    const l = spawnSync('php', ['-l', path.join(root, arquivo)], { encoding: 'utf8' });
    assert.equal(l.status, 0, `php -l ${arquivo}:\n${l.stdout}${l.stderr}`);
  }
  const r = spawnSync('php', [path.join(root, 'tests/php/rastro-logs.test.php')], { encoding: 'utf8' });
  assert.equal(r.status, 0, `tests/php/rastro-logs.test.php falhou:\n${r.stderr}${r.stdout}`);
  process.stdout.write(r.stdout);
} else {
  console.log('php não encontrado no PATH: php -l e tests/php/rastro-logs.test.php pulados.');
}

console.log('Rastro logs contract: PASS');
