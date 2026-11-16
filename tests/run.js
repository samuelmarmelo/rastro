'use strict';

// Roda todos os contratos (tests/validate-*.js, menos *.archived.js) e
// imprime um resumo. Uso: node tests/run.js. Sai com código 1 se algum falhar.
const { spawnSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');

const raiz = path.resolve(__dirname, '..');
const pasta = path.join(raiz, 'tests');
const arquivos = fs.readdirSync(pasta).filter((f) => /^validate-.*\.js$/.test(f) && !f.endsWith('.archived.js')).sort();
const falhas = [];
for (const arquivo of arquivos) {
  const rodada = spawnSync(process.execPath, [path.join(pasta, arquivo)], { cwd: raiz, encoding: 'utf8' });
  if (rodada.status !== 0) {
    const saida = `${rodada.stderr || ''}\n${rodada.stdout || ''}`;
    const motivo = saida.split('\n').find((l) => /Error|assert|expected|falh/i.test(l)) || '';
    falhas.push(`${arquivo}: ${motivo.trim().slice(0, 300)}`);
  }
}
console.log(`${arquivos.length - falhas.length}/${arquivos.length} contratos verdes`);
for (const f of falhas) console.log(`FALHOU ${f}`);
process.exit(falhas.length ? 1 : 0);
