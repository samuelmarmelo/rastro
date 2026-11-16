'use strict';

// Contrato da atribuição de origem do Rastro (T5): captura no navegador,
// cookie rastro_origem, meta _rastro_origem no pedido, atribuição nativa do
// WooCommerce depois do aceite, caixa e coluna no pedido, leitura unificada.
// Contrato: docs/CONTRATO.md (seções
// 2.1 e 7). Manual: docs/ATRIBUICAO.md. O atribuicao.js roda num DOM
// mínimo falso (location, referrer, cookies, relógio). php -l, o teste
// tests/php/rastro-atribuicao.test.php e a ida e volta do cookie entre o JS
// e o PHP rodam só quando há `php` no PATH.

const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.resolve(__dirname, '..');
const lerBruto = (p) => fs.readFileSync(path.join(root, p), 'utf8');
const ler = (p) => lerBruto(p).replace(/\r\n/g, '\n');
const comMatch = (t, re, m) => assert.ok(re.test(t), m || String(re));
const semMatch = (t, re, m) => assert.ok(!re.test(t), m || String(re));
const tem = (t, s, m) => assert.ok(t.includes(s), m || s);

const R = 'rastro/';
const TRAVESSAO = new RegExp(String.fromCharCode(0x2014));

// 1. Arquivos da frente.
const arquivos = [
  R + 'atribuicao/atribuicao.php',
  R + 'atribuicao/pedido.php',
  R + 'assets/js/atribuicao.js',
  'tests/validate-rastro-atribuicao-contract.js',
  'tests/php/rastro-atribuicao.test.php',
  'docs/ATRIBUICAO.md',
];
for (const arquivo of arquivos) {
  assert.ok(fs.existsSync(path.join(root, arquivo)), `existe ${arquivo}`);
  const bruto = lerBruto(arquivo);
  semMatch(bruto, /\r/, `${arquivo} com fim de linha LF`);
  semMatch(bruto, TRAVESSAO, `${arquivo} sem travessão`);
}
for (const arquivo of arquivos.slice(0, 3)) {
  const texto = ler(arquivo);
  semMatch(texto, /animalart/i, `${arquivo} sem dependência do Animalart`);
  semMatch(texto, /innerHTML|outerHTML|insertAdjacentHTML|document\.write/, `${arquivo} sem HTML montado no navegador`);
  semMatch(texto, /\beval\(|base64_decode\(|\$_REQUEST\b|HTTP_HOST/, `${arquivo} sem eval, base64_decode, $_REQUEST e host do cabeçalho`);
  const externos = (texto.match(/https?:\/\/[^\s'")]+/g) || []);
  assert.deepEqual(externos, [], `${arquivo} sem endereço externo`);
}

// 2. PHP: ganchos, meta e regras (seção 7).
const php = ler(R + 'atribuicao/atribuicao.php');
comMatch(php, /^<\?php\n[\s\S]*?\ndefined\( 'ABSPATH' \) \|\| exit;\n/, 'atribuicao.php com guarda ABSPATH');
tem(php, "add_action( 'woocommerce_checkout_order_created', 'rastro_atribuicao_gravar_pedido', 20, 1 );", 'grava no checkout clássico');
tem(php, "add_action( 'woocommerce_store_api_checkout_order_processed', 'rastro_atribuicao_gravar_pedido', 20, 1 );", 'grava no checkout de blocos');
tem(php, "const RASTRO_ORIGEM_META = '_rastro_origem';", 'meta protegida _rastro_origem');
tem(php, "const RASTRO_ORIGEM_COOKIE = 'rastro_origem';", 'cookie rastro_origem');
tem(php, 'const RASTRO_ORIGEM_MAX = 1536;', 'cookie até 1,5 KB');
tem(php, '$pedido->update_meta_data( RASTRO_ORIGEM_META', 'meta pelo objeto do pedido (HPOS)');
semMatch(php, /update_post_meta|add_post_meta|get_post_meta/, 'nada de metas de post (HPOS)');
tem(php, "add_filter( 'wc_order_attribution_allow_tracking', 'rastro_atribuicao_woo_permitir', 20, 1 );", 'filtro conferido do WooCommerce');
tem(php, "'wc_order_attribution_tracking_field_prefix', 'wc_order_attribution_'", 'prefixo das metas nativas com o filtro do WooCommerce');
tem(php, "const RASTRO_ORIGEM_WOO_MINIMA = '8.6.0';", 'versão mínima conferida');
tem(php, "'pys_enrich_data'", 'lê o PixelYourSite');
semMatch(php, /update_meta_data\(\s*'pys_|delete_meta_data/, 'PixelYourSite só leitura, nada apagado');
tem(php, "function rastro_pedido_origem( WC_Order $pedido, string $modelo = 'primeira' ): array {", 'assinatura da leitura unificada');
for (const chave of ['gclid', 'gbraid', 'wbraid', 'fbclid', 'ttclid', 'msclkid']) {
  tem(php, `'${chave}'`, `clique ${chave} na lista fechada`);
}
for (const fonte of ["'rastro'", "'pys'", "'woocommerce'", "'nenhum'", "'(sem dados)'", "'(sem consentimento)'"]) {
  tem(php, fonte, `leitura cita ${fonte}`);
}
for (const base of ['aceite', 'sessao', 'campanha', 'basico']) {
  tem(php, `'${base}'`, `base ${base}`);
}
for (const [, delim, flags] of php.matchAll(/'([/#])\^(?:[^'\\]|\\.)*?\$\1([a-zA-Z]*)'/g)) {
  assert.ok(flags.includes('D'), `expressão com ${delim}^...$${delim} sem /D`);
}

const pedido = ler(R + 'atribuicao/pedido.php');
comMatch(pedido, /^<\?php\n[\s\S]*?\ndefined\( 'ABSPATH' \) \|\| exit;\n/, 'pedido.php com guarda ABSPATH');
tem(pedido, "const RASTRO_PEDIDO_CAPACIDADE = 'manage_woocommerce';", 'só manage_woocommerce');
tem(pedido, "add_meta_box( 'rastro-origem', __( 'Origem da venda', 'rastro' )", 'caixa Origem da venda');
tem(pedido, "return array( 'shop_order', $hpos );", 'telas clássica e HPOS');
tem(pedido, "'woocommerce_page_wc-orders'", 'tela do HPOS');
for (const gancho of ['"manage_{$tela}_columns"', '"manage_edit-{$tela}_columns"', '"manage_{$tela}_custom_column"', '"manage_{$tela}_posts_custom_column"']) {
  tem(pedido, gancho, `coluna pelo gancho ${gancho}`);
}
for (const linha of pedido.split('\n').filter((l) => /\becho\b/.test(l))) {
  comMatch(linha, /echo\s+(?:'[^']*'\s*\.\s*)?esc_html(?:__)?\(|echo\s+'<\/?(?:p|table|tbody)[^']*>';|echo '<p><strong>' \. esc_html|echo '<tr><th scope="row">' \. esc_html|echo '<p class="description">' \. esc_html\($/, `saída escapada: ${linha.trim()}`);
}
semMatch(pedido, /woocommerce_email|woocommerce_order_details|woocommerce_thankyou/, 'nada para o cliente nem nos e-mails');

// 3. JS: proibições.
const jsTexto = ler(R + 'assets/js/atribuicao.js');
semMatch(jsTexto, /localStorage|sessionStorage|indexedDB/, 'origem só no cookie próprio');
semMatch(jsTexto, /fetch\(|XMLHttpRequest|sendBeacon|new Image/, 'nada sai da página');
semMatch(jsTexto, /\bimport\b|\brequire\(/, 'JS puro, sem módulo');
tem(jsTexto, "'rastro:consent'", 'escuta o evento de consentimento');
tem(jsTexto, 'setOrderTracking', 'libera a atribuição nativa pelo WooCommerce');

// 4. atribuicao.js num DOM mínimo falso.
const codigo = jsTexto;
const DIA = 86400;

function criarPotes(inicial) {
  const pote = new Map();
  const escritas = [];
  for (const [nome, valor] of Object.entries(inicial || {})) {
    pote.set(nome, { valor, atributos: [] });
  }
  return {
    pote,
    escritas,
    get cookie() {
      return [...pote].map(([n, c]) => `${n}=${c.valor}`).join('; ');
    },
    set cookie(texto) {
      escritas.push(texto);
      const [par, ...atributos] = texto.split(/;\s*/);
      const i = par.indexOf('=');
      const nome = par.slice(0, i);
      const maxAge = atributos.find((a) => /^max-age=/i.test(a));
      if (maxAge && Number(maxAge.split('=')[1]) <= 0) {
        pote.delete(nome);
      } else {
        pote.set(nome, { valor: par.slice(i + 1), atributos });
      }
    },
  };
}

function rodar(op) {
  const relogio = { agora: op.agora || 1_800_000_000_000 };
  const ouvintes = {};
  const potes = criarPotes(op.cookies);
  const url = new URL(op.url || 'https://loja.exemplo.com.br/');
  const documento = {
    referrer: op.referrer || '',
    addEventListener(tipo, fn) {
      (ouvintes[tipo] = ouvintes[tipo] || []).push(fn);
    },
    get cookie() {
      return potes.cookie;
    },
    set cookie(v) {
      potes.cookie = v;
    },
  };
  const janela = {
    rastroConfig: op.rastroConfig || {
      atribuicao: Object.assign({ modo: 'aceite', dias: 90, utmSempre: true, basico: false, woo: false, cookie: 'rastro_origem', max: 1536 }, op.cfg || {}),
    },
    location: { search: url.search, hostname: url.hostname, pathname: url.pathname, protocol: url.protocol },
    btoa: (s) => Buffer.from(s, 'latin1').toString('base64'),
    atob: (s) => Buffer.from(s, 'base64').toString('latin1'),
  };
  if (op.consent) {
    janela.rastro = { consent: { get: () => op.consent } };
  }
  if (op.wc) {
    janela.wc_order_attribution = op.wc;
  }
  const contexto = vm.createContext({
    window: janela,
    document: documento,
    URLSearchParams,
    TextEncoder,
    TextDecoder,
    Date: { now: () => relogio.agora },
  });
  vm.runInContext(codigo, contexto, { filename: 'atribuicao.js' });
  return {
    janela,
    potes,
    relogio,
    disparar(detalhe) {
      for (const fn of ouvintes['rastro:consent'] || []) {
        fn({ detail: detalhe });
      }
    },
    bruto() {
      const c = potes.pote.get('rastro_origem');
      return c ? c.valor : null;
    },
    atributos() {
      const c = potes.pote.get('rastro_origem');
      return c ? c.atributos : null;
    },
    origem() {
      const v = this.bruto();
      return v ? JSON.parse(Buffer.from(v.replace(/-/g, '+').replace(/_/g, '/'), 'base64').toString('utf8')) : null;
    },
  };
}
const ACEITO = { analise: true, publicidade: false, decidido: true };

// 4.1 Página de campanha com aceite: utm_* e página de entrada sem query.
let r = rodar({ url: 'https://loja.exemplo.com.br/produto/racao/?utm_source=Google&utm_medium=cpc&utm_campaign=promo%C3%A7%C3%A3o+natal&utm_content=banner&utm_term=racao&utm_id=123&x=1', consent: ACEITO });
let o = r.origem();
assert.deepEqual(o.p, { s: 'Google', m: 'cpc', c: 'promoção natal', n: 'banner', t: 'racao', i: '123', f: 'google', e: '/produto/racao/', q: 1_800_000_000 }, 'utm na primeira página');
assert.deepEqual(o.u, o.p, 'primeira visita também é a última');
assert.equal(o.o, 'aceite', 'base aceite');
assert.equal(o.v, 1, 'versão 1');
assert.ok(r.atributos().includes('SameSite=Lax') && r.atributos().includes('path=/') && r.atributos().includes('Secure'), 'SameSite=Lax, path=/ e Secure em https');
assert.ok(r.atributos().includes(`max-age=${90 * DIA}`), 'prazo de atribuicao_dias');
assert.ok(r.bruto().length <= 1536 && /^[A-Za-z0-9_-]+$/.test(r.bruto()), 'base64url curto');

// 4.1b Campanha que parece dado pessoal sai vazia: e-mail (arroba) ou oito
// dígitos seguidos; arroba nunca entra no texto.
r = rodar({ url: 'https://loja.exemplo.com.br/?utm_source=cliente%40exemplo.com&utm_medium=email&utm_campaign=cpf12345678901&utm_content=tel+21+9876&utm_term=a%40b', consent: ACEITO });
o = r.origem();
assert.equal(o.p.s, undefined, 'utm_source com e-mail: vazio');
assert.equal(o.p.f, 'direto', 'fonte sem o e-mail');
assert.equal(o.p.m, 'email', 'outro utm fica');
assert.equal(o.p.c, undefined, 'utm_campaign com 8 dígitos seguidos: vazio');
assert.equal(o.p.n, 'tel 21 9876', 'dígitos separados ficam');
assert.equal(o.p.t, undefined, 'utm_term com arroba: vazio');
assert.ok(!JSON.stringify(o).includes('@') && !JSON.stringify(o).includes('exemplo.com') && !JSON.stringify(o).includes('12345678'), 'nada do dado pessoal no cookie');

// 4.2 ID de clique: só o nome.
r = rodar({ url: 'https://loja.exemplo.com.br/?gclid=Cj0KCQSEGREDOdoCLIQUE&fbclid=IwARoutro', consent: ACEITO });
o = r.origem();
assert.equal(o.p.k, 'gclid', 'qual clique veio');
assert.equal(o.p.f, 'google', 'clique do Google vira fonte google');
assert.ok(!r.potes.escritas.join('').includes('SEGREDO') && !JSON.stringify(o).includes('SEGREDO') && !JSON.stringify(o).includes('IwAR'), 'valor do clique nunca gravado');
for (const [param, fonte] of [['gbraid', 'google'], ['wbraid', 'google'], ['fbclid', 'facebook'], ['ttclid', 'tiktok'], ['msclkid', 'bing']]) {
  assert.equal(rodar({ url: `https://loja.exemplo.com.br/?${param}=abc`, consent: ACEITO }).origem().p.f, fonte, `${param} vira ${fonte}`);
}
assert.equal(rodar({ url: 'https://loja.exemplo.com.br/?gclid=abc&utm_source=newsletter', consent: ACEITO }).origem().p.f, 'newsletter', 'utm_source vence o clique');

// 4.3 Referrer externo: só o host; fonte sem www.
r = rodar({ referrer: 'https://www.facebook.com/grupo/123?ref=abc', consent: ACEITO });
o = r.origem();
assert.equal(o.p.r, 'www.facebook.com', 'host do referrer sem caminho');
assert.equal(o.p.f, 'facebook.com', 'fonte sem www.');
assert.ok(!JSON.stringify(o).includes('grupo'), 'caminho do referrer fora');

// 4.4 Direto.
o = rodar({ consent: ACEITO }).origem();
assert.equal(o.p.f, 'direto', 'sem nada: direto');
assert.equal(o.p.r, undefined, 'direto sem referrer');

// Cookie de uma visita guardada, para os próximos casos.
function guardado(estado) {
  return Buffer.from(JSON.stringify(estado), 'utf8').toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}
const campanha = { s: 'google', m: 'cpc', f: 'google', e: '/', q: 1_799_999_000 };
const base = { v: 1, p: campanha, u: campanha, a: 1_799_999_900, o: 'aceite' };

// 4.5 Referrer do próprio site não é visita nova.
r = rodar({ referrer: 'https://www.loja.exemplo.com.br/categoria/', url: 'https://loja.exemplo.com.br/produto/', cookies: { rastro_origem: guardado(base) }, consent: ACEITO });
o = r.origem();
assert.deepEqual([o.p, o.u], [campanha, campanha], 'página interna não troca os toques');
assert.equal(o.a, 1_800_000_000, 'atividade renovada');

// 4.6 Primeira e última visita, regra dos 30 minutos.
r = rodar({ referrer: 'https://www.bing.com/', cookies: { rastro_origem: guardado(base) }, consent: ACEITO });
o = r.origem();
assert.deepEqual(o.p, campanha, 'primeira visita não muda');
assert.equal(o.u.f, 'bing.com', 'origem identificada troca a última');
r = rodar({ cookies: { rastro_origem: guardado(base) }, consent: ACEITO });
assert.equal(r.origem().u.f, 'google', 'direto dentro de 30 minutos não apaga a campanha');
r = rodar({ cookies: { rastro_origem: guardado(base) }, consent: ACEITO, agora: (1_799_999_900 + 31 * 60) * 1000 });
o = r.origem();
assert.equal(o.u.f, 'direto', 'direto depois de 30 minutos parado troca a última');
assert.deepEqual(o.p, campanha, 'e a primeira continua');
r = rodar({ referrer: 'https://loja.exemplo.com.br/x/', cookies: { rastro_origem: guardado(base) }, consent: ACEITO, agora: (1_799_999_900 + 29 * 60) * 1000 });
assert.equal(r.origem().u.f, 'google', '29 minutos: segue a mesma visita');
r = rodar({ cookies: { rastro_origem: 'lixo%%' }, consent: ACEITO });
assert.equal(r.origem().p.f, 'direto', 'cookie estragado: começa de novo');

// 4.7 Modo aceite: grava só com análise aceita; antes, fica na memória.
r = rodar({ referrer: 'https://www.bing.com/' });
assert.equal(r.bruto(), null, 'aceite sem consentimento: nada gravado');
assert.equal(r.janela.rastro.atribuicao.estado().pendente, true, 'toque na memória');
r.disparar({ analise: false, publicidade: true, decidido: true });
assert.equal(r.bruto(), null, 'só publicidade: nada gravado');
r.disparar(ACEITO);
o = r.origem();
assert.equal(o.p.f, 'bing.com', 'aceite na mesma página grava o toque da memória');
assert.equal(o.o, 'aceite', 'base aceite');
r = rodar({ referrer: 'https://www.bing.com/', consent: { analise: false, publicidade: false, decidido: true } });
r.disparar({ analise: false, publicidade: false, decidido: true });
assert.equal(r.bruto(), null, 'recusa: nada gravado');
r = rodar({ consent: ACEITO });
r.disparar({ analise: false, publicidade: false, decidido: true });
r.potes.pote.delete('rastro_origem');
r.disparar({ analise: false, publicidade: false, decidido: true });
assert.equal(r.bruto(), null, 'revogação: nada volta a ser gravado na página');

// 4.8 Visita de campanha antes do aceite (seção 2.1).
r = rodar({ url: 'https://loja.exemplo.com.br/?utm_source=instagram&utm_campaign=lancamento' });
o = r.origem();
assert.equal(o.o, 'campanha', 'campanha antes do aceite: base campanha');
assert.equal(o.p.f, 'instagram', 'campanha gravada');
assert.ok(r.atributos().includes(`max-age=${90 * DIA}`), 'campanha com prazo');
assert.equal(rodar({ url: 'https://loja.exemplo.com.br/?ttclid=abc' }).origem().o, 'campanha', 'ID de clique também é campanha');
r = rodar({ url: 'https://loja.exemplo.com.br/?utm_source=instagram', cfg: { utmSempre: false } });
assert.equal(r.bruto(), null, 'atribuicao_utm_sempre desligado: campanha espera o aceite');
r.disparar(ACEITO);
assert.equal(r.origem().o, 'aceite', 'e grava com o aceite');
r = rodar({ url: 'https://loja.exemplo.com.br/?utm_source=instagram', cookies: { rastro_origem: guardado(Object.assign({}, base, { o: 'campanha' })) } });
assert.equal(r.origem().u.f, 'instagram', 'nova campanha antes do aceite troca a última');
r = rodar({ referrer: 'https://www.bing.com/', cookies: { rastro_origem: guardado(Object.assign({}, base, { o: 'campanha' })) } });
assert.equal(r.origem().u.f, 'google', 'visita sem campanha segue a regra do modo aceite (nada gravado)');

// 4.9 Modo desligada: nunca grava.
for (const op of [
  { url: 'https://loja.exemplo.com.br/?utm_source=x', cfg: { modo: 'desligada' } },
  { cfg: { modo: 'desligada' }, consent: ACEITO },
  { cfg: { modo: 'desligada', basico: true } },
]) {
  r = rodar(op);
  r.disparar(ACEITO);
  assert.equal(r.potes.escritas.length, 0, 'desligada: nenhuma escrita');
}

// 4.10 Modo básico: consentido desde a carga.
o = rodar({ cfg: { basico: true }, referrer: 'https://www.bing.com/' }).origem();
assert.equal(o.o, 'basico', 'básico pela configuração da atribuição');
o = rodar({
  rastroConfig: {
    consent: { modo: 'basico' },
    atribuicao: { modo: 'aceite', dias: 30, utmSempre: true, basico: false, woo: false, cookie: 'rastro_origem', max: 1536 },
  },
}).origem();
assert.equal(o.o, 'basico', 'básico por rastroConfig.consent.modo, sem o evento');
r = rodar({ cfg: { basico: true } });
r.disparar({ analise: true, publicidade: true, decidido: true });
assert.equal(r.origem().o, 'basico', 'evento do básico mantém a base');

// 4.11 Modo sessão: cookie de sessão antes do aceite, prazo depois.
r = rodar({ cfg: { modo: 'sessao' }, referrer: 'https://www.bing.com/' });
assert.equal(r.origem().o, 'sessao', 'sessão antes do aceite');
assert.ok(!r.atributos().some((a) => /^max-age=/i.test(a)), 'cookie de sessão');
r.disparar(ACEITO);
assert.equal(r.origem().o, 'aceite', 'aceite troca a base');
assert.ok(r.atributos().includes(`max-age=${90 * DIA}`), 'com prazo depois do aceite');
r = rodar({ cfg: { modo: 'sessao' }, cookies: { rastro_origem: guardado(Object.assign({}, base, { o: 'campanha' })) } });
assert.equal(r.origem().o, 'campanha', 'sessão não rebaixa a campanha');
assert.ok(r.atributos().includes(`max-age=${90 * DIA}`), 'campanha mantém o prazo');
r = rodar({ cfg: { modo: 'sessao' }, url: 'http://loja.exemplo.com.br/' });
assert.ok(!r.atributos().includes('Secure'), 'sem Secure em http');

// 4.12 Limite de 1,5 KB e limpeza dos caracteres.
const longo = 'a'.repeat(300);
const grande = { v: 1, p: { s: 'b'.repeat(100), m: 'c'.repeat(100), c: 'd'.repeat(100), n: 'e'.repeat(100), t: 'f'.repeat(100), i: 'g'.repeat(100), f: 'h'.repeat(100), r: 'x'.repeat(90) + '.com.br', e: '/' + 'p'.repeat(199), q: 1_799_999_000 }, a: 1_799_999_900, o: 'aceite' };
grande.u = grande.p;
r = rodar({ url: `https://loja.exemplo.com.br/${'q'.repeat(400)}/?utm_source=${longo}&utm_medium=${longo}&utm_campaign=${longo}&utm_content=${longo}&utm_term=${longo}&utm_id=${longo}`, cookies: { rastro_origem: guardado(grande).slice(0, 1536) }, consent: ACEITO });
assert.ok(r.bruto() !== null && r.bruto().length <= 1536, `cookie até 1,5 KB (${r.bruto() && r.bruto().length})`);
o = r.origem();
assert.ok(o.u.s.length <= 100 && o.u.e.length <= 200, 'campos cortados');
r = rodar({ url: 'https://loja.exemplo.com.br/?utm_campaign=%3Cscript%3Ealert(1)%3C%2Fscript%3E&utm_source=a%22b%27c%3Bd%3De%26f%5Cg', consent: ACEITO });
o = r.origem();
assert.equal(o.p.c, 'scriptalert(1)/script', 'sem < e > na campanha');
assert.equal(o.p.s, 'abcdefg', 'sem aspas, ;, =, & e barra invertida');

// 4.13 Atribuição nativa do WooCommerce.
let wc = { params: { allowTracking: true } };
rodar({ cfg: { woo: true }, wc });
assert.equal(wc.params.allowTracking, false, 'sem aceite: o WooCommerce começa negado');
wc = { params: { allowTracking: false } };
rodar({ cfg: { woo: true }, wc, consent: ACEITO });
assert.equal(wc.params.allowTracking, true, 'aceite já dado: o WooCommerce começa liberado');
const chamadas = [];
wc = { params: { allowTracking: false }, setOrderTracking: (v) => chamadas.push(v) };
r = rodar({ cfg: { woo: true }, wc });
r.disparar({ analise: false, publicidade: true, decidido: true });
r.disparar(ACEITO);
r.disparar(ACEITO);
r.disparar({ analise: false, publicidade: false, decidido: true });
assert.deepEqual(chamadas, [true, false], 'setOrderTracking com o aceite de análise e na revogação');
wc = { params: { allowTracking: true } };
rodar({ cfg: { woo: false }, wc });
assert.equal(wc.params.allowTracking, true, 'opção desligada: o WooCommerce fica como está');
wc = { params: { allowTracking: false } };
rodar({ cfg: { woo: true, modo: 'desligada' }, wc, consent: ACEITO });
assert.equal(wc.params.allowTracking, true, 'desligada não impede liberar o WooCommerce');

// 4.14 Sem configuração: nada roda.
const vazio = criarPotes();
vm.runInContext(codigo, vm.createContext({ window: { rastroConfig: {} }, document: { get cookie() { return vazio.cookie; }, set cookie(v) { vazio.cookie = v; }, addEventListener() {} } }));
assert.equal(vazio.escritas.length, 0, 'sem rastroConfig.atribuicao: nada');

// 5. Manual.
const manual = ler('docs/ATRIBUICAO.md');
for (const trecho of ['rastro_pedido_origem', '_rastro_origem', 'rastro_origem', 'wc_order_attribution_allow_tracking', 'setOrderTracking', '_wc_order_attribution_', 'pys_enrich_data', 'atribuicao_utm_sempre', 'campanha', 'basico', 'Não conferido']) {
  tem(manual, trecho, `ATRIBUICAO.md cita ${trecho}`);
}

// 6. php -l, teste PHP e ida e volta do cookie entre o JS e o PHP.
const temPhp = spawnSync('php', ['-v'], { encoding: 'utf8' }).status === 0;
if (temPhp) {
  for (const arquivo of [R + 'atribuicao/atribuicao.php', R + 'atribuicao/pedido.php', 'tests/php/rastro-atribuicao.test.php']) {
    const l = spawnSync('php', ['-l', path.join(root, arquivo)], { encoding: 'utf8' });
    assert.equal(l.status, 0, `php -l ${arquivo}:\n${l.stdout}${l.stderr}`);
  }
  const t = spawnSync('php', [path.join(root, 'tests/php/rastro-atribuicao.test.php')], { encoding: 'utf8' });
  assert.equal(t.status, 0, `tests/php/rastro-atribuicao.test.php falhou:\n${t.stderr}${t.stdout}`);
  process.stdout.write(t.stdout);

  const agoraReal = Date.now();
  const amostras = [
    rodar({ url: 'https://loja.exemplo.com.br/produto/ração/?utm_source=Google&utm_campaign=promo%C3%A7%C3%A3o+natal&gclid=x', consent: ACEITO, agora: agoraReal }).bruto(),
    rodar({ referrer: 'https://www.facebook.com/a', agora: agoraReal, cfg: { modo: 'sessao' } }).bruto(),
    rodar({ url: `https://loja.exemplo.com.br/?utm_source=${longo}&utm_medium=${longo}&utm_campaign=%3Cb%3E${longo}`, agora: agoraReal }).bruto(),
  ];
  const stubs = path.join(root, 'tests/php/stubs-rastro.php').replace(/\\/g, '/');
  const plugin = path.join(root, R, 'rastro.php').replace(/\\/g, '/');
  const roteiro = `<?php
require '${stubs}';
require '${plugin}';
$saida = array();
foreach ( json_decode( stream_get_contents( STDIN ), true ) as $bruto ) {
  $saida[] = rastro_atribuicao_ler_cookie( $bruto );
}
echo json_encode( $saida, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
`;
  const arquivoRoteiro = path.join(require('node:os').tmpdir(), `rastro-ida-volta-${process.pid}.php`);
  fs.writeFileSync(arquivoRoteiro, roteiro);
  try {
    const ida = spawnSync('php', [arquivoRoteiro], { encoding: 'utf8', input: JSON.stringify(amostras) });
    assert.equal(ida.status, 0, `ida e volta: ${ida.stderr}${ida.stdout}`);
    const lidos = JSON.parse(ida.stdout);
    assert.equal(lidos[0].primeira.fonte, 'google', 'PHP lê o cookie do JS (campanha)');
    assert.equal(lidos[0].primeira.utm_campaign, 'promoção natal', 'acentos chegam ao PHP');
    assert.equal(lidos[0].primeira.clique, 'gclid', 'clique só pelo nome no PHP');
    assert.equal(lidos[0].primeira.entrada, '/produto/ra%C3%A7%C3%A3o/', 'entrada codificada aceita');
    assert.equal(lidos[1].consentimento, 'sessao', 'PHP lê a base sessão');
    assert.equal(lidos[1].ultima.referrer, 'www.facebook.com', 'PHP lê o referrer');
    assert.equal(lidos[2].consentimento, 'campanha', 'PHP lê a base campanha');
    assert.equal(lidos[2].primeira.utm_campaign.length, 100, 'campanha cortada aceita pelo PHP');
  } finally {
    fs.unlinkSync(arquivoRoteiro);
  }
} else {
  console.log('php não encontrado no PATH: php -l, tests/php/rastro-atribuicao.test.php e a ida e volta do cookie pulados.');
}

console.log('Rastro atribuição contract: PASS');
