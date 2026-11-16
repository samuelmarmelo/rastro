'use strict';

// Contrato do arquivo de verificação do Rastro (rastro/verificacao/arquivo.php;
// contrato, seção 5.2; docs/TAGS.md, Verificações de domínio). Parte estática
// sempre; php -l e tests/php/rastro-verificacao.test.php só com `php` no PATH.

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

for (const arquivo of ['rastro/verificacao/arquivo.php', 'tests/php/rastro-verificacao.test.php', 'tests/validate-rastro-verificacao-contract.js']) {
  assert.ok(fs.existsSync(path.join(root, arquivo)), `existe ${arquivo}`);
  const bruto = lerBruto(arquivo);
  semMatch(bruto, /\r/, `${arquivo} com fim de linha LF`);
  semMatch(bruto, TRAVESSAO, `${arquivo} sem travessão`);
}

const php = ler('rastro/verificacao/arquivo.php');
tem(php, "defined( 'ABSPATH' ) || exit;", 'guarda ABSPATH');
tem(php, "add_action( 'parse_request', 'rastro_verificacao_responder', 0 );", 'responde no parse_request');
tem(php, "header( 'Content-Type: text/plain; charset=utf-8' );", 'texto puro');
tem(php, "header( 'X-Content-Type-Options: nosniff' );", 'nosniff');
tem(php, "array( 'GET', 'HEAD' )", 'só GET e HEAD');
tem(php, "rastro_config_validar_campo( 'arquivo_verificacao_nome'", 'nome revalidado na leitura');
tem(php, "rastro_config_validar_campo( 'arquivo_verificacao_conteudo'", 'conteúdo revalidado na leitura');
semMatch(php, /file_put_contents|fopen|unlink|rename\(|WP_Filesystem/, 'nada gravado nem apagado em disco');
semMatch(php, /animalart/i, 'sem nome de site');

const config = ler('rastro/nucleo/config.php');
tem(config, "'regex'   => '/^[A-Za-z0-9_-]{1,100}\\.txt$/D',", 'nome só .txt, sem barra nem ponto extra');
tem(config, "'regex'  => '/^[A-Za-z0-9=:._-]{1,300}$/D',", 'conteúdo só com letras, números e = : . _ -');

tem(ler('rastro/rastro.php'), "'tags/tags.php',\n\t\t\t'verificacao/arquivo.php',", 'módulo na lista, depois das tags');

const temPhp = spawnSync('php', ['-v'], { encoding: 'utf8' });
if (temPhp.status === 0) {
  for (const arquivo of ['rastro/verificacao/arquivo.php', 'tests/php/rastro-verificacao.test.php']) {
    const l = spawnSync('php', ['-l', path.join(root, arquivo)], { encoding: 'utf8' });
    assert.equal(l.status, 0, `php -l ${arquivo}:\n${l.stdout}${l.stderr}`);
  }
  const r = spawnSync('php', [path.join(root, 'tests/php/rastro-verificacao.test.php')], { encoding: 'utf8' });
  assert.equal(r.status, 0, `tests/php/rastro-verificacao.test.php falhou:\n${r.stderr}${r.stdout}`);
  process.stdout.write(r.stdout);
} else {
  console.log('php não encontrado no PATH: php -l e tests/php/rastro-verificacao.test.php pulados.');
}

console.log('Rastro verificação contract: PASS');
