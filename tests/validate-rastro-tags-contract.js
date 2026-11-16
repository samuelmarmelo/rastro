'use strict';

// Contrato das tags do Rastro (T3): destinos, tags.js, verificação do
// Google, avisos de duplicidade e hosts de CSP.
// Contrato: docs/CONTRATO.md (seções
// 1, 2.1, 5, 6, 10 e 12). Manual: docs/TAGS.md. Parte estática e o
// tags.js num DOM mínimo falso (relógio falso) rodam sempre no node; php -l e
// tests/php/rastro-tags.test.php rodam só quando há `php` no PATH.

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
// Valores de outro contexto do vm viram dados simples para comparar.
const j = (v) => JSON.parse(JSON.stringify(v === undefined ? null : v));
const args = (a) => j(Array.from(a));

const R = 'rastro/';
// Travessão (U+2014) montado pelo código.
const TRAVESSAO = new RegExp(String.fromCharCode(0x2014));

/* 1. Arquivos da frente ------------------------------------------------------ */

const arquivosT3 = [
  R + 'tags/tags.php',
  R + 'assets/js/tags.js',
  'tests/validate-rastro-tags-contract.js',
  'tests/php/rastro-tags.test.php',
  'docs/TAGS.md',
];
for (const arquivo of arquivosT3) {
  assert.ok(fs.existsSync(path.join(root, arquivo)), `existe ${arquivo}`);
  const bruto = lerBruto(arquivo);
  semMatch(bruto, /\r/, `${arquivo} com fim de linha LF`);
  semMatch(bruto, TRAVESSAO, `${arquivo} sem travessão`);
}
for (const arquivo of [R + 'tags/tags.php', R + 'assets/js/tags.js']) {
  const texto = ler(arquivo);
  semMatch(texto, /animalart/i, `${arquivo} sem dependência do Animalart`);
  semMatch(texto, /innerHTML|outerHTML|insertAdjacentHTML|document\.write/, `${arquivo} sem HTML montado por texto`);
}

/* 2. tags.php ----------------------------------------------------------------- */

const php = ler(R + 'tags/tags.php');
comMatch(php, /^<\?php\n[\s\S]*?\ndefined\( 'ABSPATH' \) \|\| exit;\n/, 'tags.php com guarda ABSPATH');
for (const fn of [
  'rastro_destinos', 'rastro_destinos_ativos', 'rastro_destinos_montar', 'rastro_destinos_aplicar_filtro',
  'rastro_tags_finalidades', 'rastro_tags_config_navegador', 'rastro_tags_navegador', 'rastro_tags_enfileirar',
  'rastro_google_verificacao', 'rastro_tags_meta_html', 'rastro_tags_meta_verificacao', 'rastro_tags_avisos',
  'rastro_tags_regra_avisos', 'rastro_tags_plugins_conhecidos', 'rastro_tags_plugins_ativos', 'rastro_tags_posthog_host',
]) {
  comMatch(php, new RegExp(`\\nfunction ${fn}\\(`), `função ${fn}`);
}
tem(php, "apply_filters( 'rastro_destinos', $destinos )", 'filtro rastro_destinos');
comMatch(php, /'gtm'\s*=> array\(\s*'finalidade'\s*=> 'publicidade',\s*'carrega_com' => array\( 'publicidade' \),/, 'GTM só com publicidade (LGPD)');
tem(php, "add_filter( 'rastro_config_navegador', 'rastro_tags_navegador', 10, 1 );");
tem(php, "add_action( 'wp_enqueue_scripts', 'rastro_tags_enfileirar', 20 );");
tem(php, "add_action( 'wp_head', 'rastro_tags_meta_verificacao', 1 );");
tem(php, "add_filter( 'rastro_admin_avisos', 'rastro_tags_avisos', 10, 1 );");
tem(php, "rastro_registrar_script( 'rastro-tags', 'tags.js', $deps )", 'registro pela base');
tem(php, "wp_script_is( 'rastro-tags', 'enqueued' )", 'chave tags só com o script na página');
const enfileirar = php.slice(php.indexOf('function rastro_tags_enfileirar'));
assert.ok(enfileirar.indexOf('rastro_deve_medir()') < enfileirar.indexOf('rastro_registrar_script('), 'tags.js só quando a página mede');
tem(php, '\'<meta name="google-site-verification" content="\' . esc_attr( $limpo ) . \'" />\'', 'meta com esc_attr');
tem(php, "rastro_tags_id( 'google_verificacao', $valor )", 'verificação validada de novo na impressão');
const metaFn = php.slice(php.indexOf('function rastro_tags_meta_verificacao'), php.indexOf("add_action( 'wp_head', 'rastro_tags_meta_verificacao'"));
tem(metaFn, 'rastro_requisicao_restrita()', 'meta fora do painel e do login');
semMatch(metaFn, /rastro_deve_medir|consent/, 'meta não depende da medição nem do consentimento');
semMatch(php, /wp_json_encode|json_encode/, 'JSON só pela base (rastro_json)');
semMatch(php, /\$_(GET|POST|COOKIE|REQUEST|SERVER)\b/, 'tags.php não lê a requisição direto');
for (const nome of ['PYS_FREE_VERSION', 'pixelyoursite/facebook-pixel-master.php', 'WC_GOOGLE_ANALYTICS_INTEGRATION_VERSION', 'WC_Google_Analytics_Integration', 'GOOGLESITEKIT_VERSION', 'GTM4WP_VERSION', 'MONSTERINSIGHTS_VERSION', 'WC_Facebook_Loader', 'tiktok-for-business/tiktok-for-woocommerce.php', 'WC_GLA_VERSION']) {
  tem(php, `'${nome}'`, `plugin de medição reconhecido por ${nome}`);
}

/* 3. tags.js: estrutura e proibições ----------------------------------------- */

const arquivoJs = path.join(root, R + 'assets/js/tags.js');
const js = ler(R + 'assets/js/tags.js');
const check = spawnSync(process.execPath, ['--check', arquivoJs], { encoding: 'utf8' });
assert.equal(check.status, 0, `node --check tags.js:\n${check.stderr}`);
semMatch(js, /\beval\(|new Function|\bimport\b|\brequire\(|setInterval\(/, 'tags.js sem eval, sem módulos e sem intervalos');
semMatch(js, /localStorage|sessionStorage|document\.cookie|d\.cookie/, 'tags.js não guarda nada no navegador');
semMatch(js, /console\./, 'tags.js sem console');
tem(js, "'rastro:consent'");
tem(js, "'rastro:event'");
tem(js, 'requestIdleCallback');
for (const tipo of ['pointerdown', 'keydown', 'scroll', 'touchstart']) {
  tem(js, `'${tipo}'`, `interação ${tipo}`);
}
tem(js, 'LIMITE_OCIOSO = 2000');
tem(js, 'LIMITE_INTERACAO = 8000');
tem(js, "'preconnect'");
tem(js, "'basico' === cfg.consent.modo", 'modo básico (seção 2.1)');

// Hosts: só os oficiais da seção 5.
const HOSTS_PERMITIDOS = ['www.googletagmanager.com', 'connect.facebook.net', 'analytics.tiktok.com', 'bzrcdn.openai.com', 'us.i.posthog.com', 'eu.i.posthog.com'];
for (const arquivo of [R + 'tags/tags.php', R + 'assets/js/tags.js']) {
  for (const url of ler(arquivo).match(/https?:\/\/[A-Za-z0-9.-]+/g) || []) {
    const host = url.replace(/^https?:\/\//, '');
    assert.ok(url.startsWith('https://'), `${arquivo}: ${url} com https`);
    assert.ok(HOSTS_PERMITIDOS.includes(host), `${arquivo}: host ${host} fora da lista da seção 5`);
  }
}

/* 4. Manual e CSP -------------------------------------------------------------- */

const manual = ler('docs/TAGS.md');
const HOSTS_CSP = [
  'https://www.googletagmanager.com', 'https://*.google-analytics.com', 'https://*.g.doubleclick.net',
  'https://www.google.com', 'https://www.googleadservices.com', 'https://googleads.g.doubleclick.net',
  'https://pagead2.googlesyndication.com', 'https://ad.doubleclick.net',
  'https://connect.facebook.net', 'https://www.facebook.com',
  'https://analytics.tiktok.com',
  'https://bzrcdn.openai.com', 'https://bzr.openai.com',
  'https://us.i.posthog.com', 'https://us-assets.i.posthog.com', 'https://eu.i.posthog.com', 'https://eu-assets.i.posthog.com',
];
const csp = manual.slice(manual.indexOf('## CSP'));
assert.ok(manual.includes('## CSP'), 'TAGS.md com a seção CSP');
for (const host of HOSTS_CSP) {
  tem(csp, host, `TAGS.md lista o host ${host} na CSP`);
}
for (const trecho of ['Não conferido', 'rastro_destinos', 'window.rastro.event', 'carregado', 'parar', 'rastro_admin_avisos', 'google-site-verification', 'basico', 'aceite_automatico', 'tests/php/rastro-tags.test.php']) {
  tem(manual, trecho, `TAGS.md cita ${trecho}`);
}

/* 5. tags.js num DOM mínimo falso ---------------------------------------------- */

const IDS = {
  gtm: 'GTM-ABC1234',
  ga4: 'G-ABC1234',
  ads: 'AW-123456789',
  meta: '123456789012345',
  tiktok: 'ABCDEFGHIJ12345678',
  openai: 'oai_pixel_123',
  posthog: 'phc_ABCDEFGHIJKLMNOPQRSTUVWXYZ0123',
};
const URL_PAGINA = 'https://loja.exemplo.com.br/produto/racao/';

function destinosCheios(sobre = {}) {
  return Object.assign(
    {
      gtm: { carrega_com: ['publicidade'], id: IDS.gtm },
      ga4: { carrega_com: ['analise'], id: IDS.ga4, send_page_view: true, page_location: URL_PAGINA },
      ads: { carrega_com: ['publicidade'], id: IDS.ads, rotulo_compra: 'AbC-123_xy' },
      meta: { carrega_com: ['publicidade'], id: IDS.meta },
      tiktok: { carrega_com: ['publicidade'], id: IDS.tiktok },
      openai: { carrega_com: ['publicidade'], id: IDS.openai, debug: false },
      posthog: {
        carrega_com: ['analise'],
        id: IDS.posthog,
        config: { api_host: 'https://us.i.posthog.com', defaults: '2026-08-30', person_profiles: 'identified_only', session_recording: { maskAllInputs: true } },
      },
    },
    sobre
  );
}

function ambiente(opcoes = {}) {
  let agora = 0;
  let seq = 0;
  const timers = [];
  const ocioso = [];
  const inseridos = [];

  function alvo() {
    const l = {};
    return {
      _l: l,
      addEventListener(tipo, fn) { (l[tipo] = l[tipo] || []).push(fn); },
      removeEventListener(tipo, fn) { l[tipo] = (l[tipo] || []).filter((f) => f !== fn); },
      dispatchEvent(ev) { (l[ev.type] || []).slice().forEach((fn) => fn(ev)); return true; },
    };
  }
  function criar(tag) {
    const el = {
      tagName: String(tag).toUpperCase(),
      attrs: {},
      children: [],
      parentNode: null,
      setAttribute(k, v) { this.attrs[k] = String(v); },
      getAttribute(k) { return this.attrs[k]; },
      appendChild(c) { c.parentNode = this; this.children.push(c); inseridos.push(c); return c; },
      insertBefore(c, ref) {
        c.parentNode = this;
        const i = this.children.indexOf(ref);
        this.children.splice(i < 0 ? this.children.length : i, 0, c);
        inseridos.push(c);
        return c;
      },
    };
    if (opcoes.criarFalha && opcoes.criarFalha(tag)) {
      throw new Error('falha de teste');
    }
    return el;
  }
  const head = criar('head');
  const proprio = criar('script');
  proprio.src = 'https://loja.exemplo.com.br/wp-content/plugins/rastro/assets/js/tags.js';
  proprio.parentNode = head;
  head.children.push(proprio);
  const html = criar('html');

  const documento = Object.assign(alvo(), {
    readyState: opcoes.readyState || 'complete',
    head,
    documentElement: html,
    createElement: criar,
    getElementsByTagName(tag) {
      const saida = [];
      const andar = (el) => el.children.forEach((c) => { if (c.tagName === String(tag).toUpperCase()) { saida.push(c); } andar(c); });
      andar(head);
      return saida;
    },
  });

  class CustomEvent {
    constructor(type, init) { this.type = type; this.detail = init ? init.detail : undefined; }
  }
  const janela = Object.assign(alvo(), {
    document: documento,
    CustomEvent,
    setTimeout(fn, ms) { const id = ++seq; timers.push({ id, at: agora + (ms || 0), fn }); return id; },
    clearTimeout(id) { const i = timers.findIndex((t) => t.id === id); if (i >= 0) { timers.splice(i, 1); } },
    rastroConfig: {
      versao: '0.1.0',
      pagina: Object.assign({ tipo: 'produto', privada: false, url: URL_PAGINA }, opcoes.pagina || {}),
      carregamento: opcoes.carregamento || 'imediato',
      tags: { destinos: opcoes.destinos || destinosCheios() },
    },
  });
  if (opcoes.consentCfg) {
    janela.rastroConfig.consent = opcoes.consentCfg;
  }
  if (opcoes.semIdle !== true) {
    janela.requestIdleCallback = (fn, op) => { ocioso.push({ fn, op }); return ocioso.length; };
    janela.cancelIdleCallback = (id) => { if (ocioso[id - 1]) { ocioso[id - 1].cancelado = true; } };
  }
  if (opcoes.location) {
    janela.location = Object.assign({}, opcoes.location);
    janela.history = {
      trocas: [],
      replaceState(estado, titulo, url) { this.trocas.push({ url, scripts: inseridos.filter((e) => e.tagName === 'SCRIPT').length }); janela.location.pathname = url; janela.location.search = ''; },
    };
  }
  if (opcoes.antes) {
    opcoes.antes(janela);
  }
  janela.window = janela;
  const ctx = vm.createContext(janela);
  vm.runInContext(js, ctx, { filename: 'tags.js' });

  return {
    w: janela,
    d: documento,
    timers,
    ocioso,
    consent(estado) { documento.dispatchEvent(new CustomEvent('rastro:consent', { detail: estado })); },
    avancar(ms) {
      const fim = agora + ms;
      for (;;) {
        timers.sort((a, b) => a.at - b.at || a.id - b.id);
        const t = timers[0];
        if (!t || t.at > fim) { break; }
        timers.shift();
        agora = t.at;
        t.fn();
      }
      agora = fim;
    },
    rodarOcioso() { ocioso.splice(0).forEach((o) => { if (!o.cancelado) { o.fn(); } }); },
    scripts() { return inseridos.filter((e) => e.tagName === 'SCRIPT'); },
    chaves() {
      return inseridos.filter((e) => e.tagName === 'SCRIPT').map((e) => e.attrs['data-rastro-tag'] || (/posthog\.com\/static\/array\.js$/.test(e.src) ? 'posthog' : '?'));
    },
    links() { return inseridos.filter((e) => e.tagName === 'LINK'); },
    gtag() { return j((janela.dataLayer || []).filter((x) => Object.prototype.toString.call(x) === '[object Arguments]').map(args)); },
    camada() { return j((janela.dataLayer || []).filter((x) => Object.prototype.toString.call(x) !== '[object Arguments]')); },
    fbq() { return janela.fbq && janela.fbq.queue ? j(janela.fbq.queue.map(args)) : []; },
    ttq() { return janela.ttq ? j(janela.ttq.filter((x) => Array.isArray(x))) : []; },
    oaiq() { return janela.oaiq ? j(janela.oaiq.q.map(args)) : []; },
    posthog() { return janela.posthog && Array.isArray(janela.posthog) ? j(janela.posthog.filter((x) => Array.isArray(x))) : []; },
  };
}

const TUDO = { analise: true, publicidade: true, decidido: true };
const SO_ANALISE = { analise: true, publicidade: false, decidido: true };
const NADA = { analise: false, publicidade: false, decidido: true };
const PUBLICIDADE = ['meta', 'tiktok', 'openai'];
const COMPRA = {
  transaction_id: '1001',
  value: 150.5,
  currency: 'BRL',
  tax: 0,
  shipping: 20,
  coupon: 'BEMVINDO',
  items: [
    { item_id: '11', item_name: 'Ração', price: 50, quantity: 2, item_category: 'Cães' },
    { item_id: '12', item_name: 'Petisco', price: 30.5, quantity: 1 },
  ],
};

// 5.1 Nada antes do consentimento.
{
  const a = ambiente();
  assert.deepEqual(a.scripts().length, 0, 'nenhum script antes do aceite');
  assert.deepEqual(a.links().length, 0, 'nenhum preconnect antes do aceite');
  for (const global of ['dataLayer', 'gtag', 'fbq', '_fbq', 'ttq', 'oaiq', 'posthog']) {
    assert.equal(a.w[global], undefined, `sem ${global} antes do aceite`);
  }
  assert.equal(typeof a.w.rastro.event, 'function', 'window.rastro.event');
  assert.equal(a.w.rastro.tags.carregado(), false, 'nada carregado');
  let avisado = null;
  a.d.addEventListener('rastro:event', (e) => { avisado = j(e.detail); });
  assert.equal(a.w.rastro.event('view_item', { items: [{ item_id: '11' }] }), true, 'evento na fila antes do aceite');
  assert.deepEqual(avisado, { nome: 'view_item', params: { items: [{ item_id: '11' }] }, opcoes: {} }, 'rastro:event no document');
  assert.equal(a.w.dataLayer, undefined, 'evento não sai antes do aceite');
  a.consent({ analise: false, publicidade: false, decidido: false });
  assert.equal(a.scripts().length, 0, 'sem escolha, nada carrega');
}

// 5.2 Só análise: GA4 e PostHog; nada de publicidade (nem o GTM, LGPD).
{
  const a = ambiente();
  a.consent(SO_ANALISE);
  assert.deepEqual(a.chaves().sort(), ['gtag', 'posthog'], 'análise carrega gtag (GA4) e PostHog');
  for (const chave of ['ga4', 'posthog']) {
    assert.equal(a.w.rastro.tags.carregado(chave), true, `${chave} carregado com análise`);
  }
  for (const chave of ['gtm', 'ads', ...PUBLICIDADE]) {
    assert.equal(a.w.rastro.tags.carregado(chave), false, `${chave} não carrega só com análise`);
  }
  assert.equal(a.w.fbq, undefined, 'sem Meta');
  assert.equal(a.w.ttq, undefined, 'sem TikTok');
  assert.equal(a.w.oaiq, undefined, 'sem OpenAI');
  const gtag = a.scripts().find((s) => s.attrs['data-rastro-tag'] === 'gtag');
  assert.equal(gtag.src, 'https://www.googletagmanager.com/gtag/js?id=G-ABC1234', 'gtag/js com o ID do GA4');
  assert.equal(gtag.async, true, 'script async');
  assert.equal(a.camada().filter((x) => 'gtm.start' in x).length, 0, 'sem gtm.start só com análise');
  assert.deepEqual(a.gtag().filter((x) => x[0] === 'config'), [['config', 'G-ABC1234', { send_page_view: true, page_location: URL_PAGINA }]], 'config do GA4 com page_location e sem Ads');
  assert.equal(a.gtag().filter((x) => x[0] === 'js').length, 1, "gtag('js') uma vez");
  const posthog = a.scripts().find((s) => /array\.js$/.test(s.src));
  assert.equal(posthog.src, 'https://us-assets.i.posthog.com/static/array.js', 'PostHog da região');
  const init = a.w.posthog._i[0];
  assert.equal(init[0], IDS.posthog, 'posthog.init com a chave');
  assert.equal(init[1].person_profiles, 'identified_only', 'person_profiles');
  assert.equal(typeof init[1].before_send, 'function', 'before_send');
  assert.equal(typeof init[1].session_recording.maskCapturedNetworkRequestFn, 'function', 'máscara das requisições');
  assert.deepEqual(a.links().map((l) => l.href).sort(), ['https://us-assets.i.posthog.com', 'https://www.googletagmanager.com'], 'preconnect só dos hosts carregados, uma vez');

  // Depois aceita publicidade: o resto carrega, nada repete, um só gtag/js.
  a.consent(TUDO);
  assert.deepEqual(a.chaves().sort(), ['gtag', 'gtm', 'meta', 'openai', 'posthog', 'tiktok'], 'publicidade acrescenta Meta, TikTok e OpenAI');
  assert.equal(a.scripts().filter((s) => s.attrs['data-rastro-tag'] === 'gtag').length, 1, 'um só gtag/js para GA4 e Ads');
  assert.deepEqual(a.gtag().filter((x) => x[0] === 'config').map((x) => x[1]), ['G-ABC1234', 'AW-123456789'], 'config do Ads no mesmo gtag');
  assert.equal(a.w.rastro.tags.carregado('ads'), true, 'Ads carregado');
  assert.equal(a.scripts().find((s) => s.attrs['data-rastro-tag'] === 'gtm').src, 'https://www.googletagmanager.com/gtm.js?id=GTM-ABC1234', 'gtm.js com o ID, só com publicidade');
  assert.ok(a.camada().some((x) => 'gtm.start' in x && x.event === 'gtm.js'), 'gtm.start');
}

// 5.3 Tudo aceito: cada destino uma vez.
{
  const a = ambiente();
  a.consent(TUDO);
  a.consent(TUDO);
  a.consent(Object.assign({}, TUDO));
  assert.deepEqual(a.chaves().sort(), ['gtag', 'gtm', 'meta', 'openai', 'posthog', 'tiktok'], 'todos carregam');
  const contagem = {};
  a.chaves().forEach((c) => { contagem[c] = (contagem[c] || 0) + 1; });
  assert.ok(Object.values(contagem).every((n) => n === 1), 'cada script uma vez');
  for (const chave of Object.keys(IDS)) {
    assert.equal(a.w.rastro.tags.carregado(chave), true, `${chave} carregado`);
  }
  assert.equal(a.scripts().find((s) => s.attrs['data-rastro-tag'] === 'gtag').src, 'https://www.googletagmanager.com/gtag/js?id=G-ABC1234', 'gtag/js único');
  assert.deepEqual(a.fbq(), [['set', 'autoConfig', false, IDS.meta], ['init', IDS.meta], ['track', 'PageView']], 'Meta: autoConfig desligado antes do init, init e PageView uma vez');
  assert.equal(a.scripts().find((s) => s.attrs['data-rastro-tag'] === 'meta').src, 'https://connect.facebook.net/en_US/fbevents.js', 'fbevents.js');
  assert.deepEqual(a.ttq(), [['page']], 'TikTok: page uma vez');
  assert.equal(a.scripts().find((s) => s.attrs['data-rastro-tag'] === 'tiktok').src, 'https://analytics.tiktok.com/i18n/pixel/events.js?sdkid=ABCDEFGHIJ12345678&lib=ttq', 'events.js do TikTok com o ID');
  assert.ok(a.w.ttq._i[IDS.tiktok], 'ttq.load com o ID');
  assert.deepEqual(a.oaiq(), [['init', { pixelId: IDS.openai, debug: false }], ['measure', 'page_viewed', { type: 'contents' }]], 'OpenAI: init e page_viewed');
  assert.equal(a.scripts().find((s) => s.attrs['data-rastro-tag'] === 'openai').src, 'https://bzrcdn.openai.com/sdk/oaiq.min.js', 'oaiq.min.js');
  assert.equal(a.w.posthog._i.length, 1, 'posthog.init uma vez');
  assert.equal(a.links().length, new Set(a.links().map((l) => l.href)).size, 'um preconnect por host');
}

// 5.4 Agenda: imediato, ocioso, interação.
{
  // ocioso: depois do load, em requestIdleCallback com limite de 2 s.
  const a = ambiente({ carregamento: 'ocioso', readyState: 'loading' });
  a.consent(TUDO);
  assert.equal(a.scripts().length, 0, 'ocioso: nada antes do load');
  assert.equal(a.ocioso.length, 0, 'ocioso: ocioso só depois do load');
  a.w.dispatchEvent({ type: 'load' });
  assert.equal(a.ocioso.length, 1, 'ocioso: um pedido de ocioso');
  assert.equal(a.ocioso[0].op.timeout, 2000, 'ocioso: limite de 2 s');
  assert.equal(a.scripts().length, 0, 'ocioso: nada antes do ocioso');
  a.rodarOcioso();
  assert.equal(a.w.rastro.tags.carregado('meta'), true, 'ocioso: carrega no ocioso');
  assert.equal((a.w._l.pointerdown || []).length, 0, 'ocioso: escuta de interação removida');
}
{
  // ocioso: a primeira interação vem antes.
  const a = ambiente({ carregamento: 'ocioso', readyState: 'loading' });
  a.consent(TUDO);
  a.w.dispatchEvent({ type: 'touchstart' });
  assert.equal(a.w.rastro.tags.carregado(), true, 'ocioso: interação antes do load carrega');
  a.w.dispatchEvent({ type: 'load' });
  a.rodarOcioso();
  assert.equal(a.chaves().length, 6, 'ocioso: nada repete depois');
}
{
  // ocioso sem requestIdleCallback: logo depois do load.
  const a = ambiente({ carregamento: 'ocioso', readyState: 'complete', semIdle: true });
  a.consent(SO_ANALISE);
  assert.equal(a.scripts().length, 0, 'ocioso sem idle: ainda não');
  a.avancar(1);
  assert.equal(a.w.rastro.tags.carregado('ga4'), true, 'ocioso sem idle: carrega no próximo ciclo');
}
{
  // interação: limite de 8 s.
  const a = ambiente({ carregamento: 'interacao', readyState: 'complete' });
  a.consent(TUDO);
  a.rodarOcioso();
  a.avancar(7999);
  assert.equal(a.scripts().length, 0, 'interação: nada antes de 8 s');
  a.avancar(1);
  assert.equal(a.w.rastro.tags.carregado('ga4'), true, 'interação: carrega em 8 s');
}
{
  const a = ambiente({ carregamento: 'interacao' });
  a.consent(TUDO);
  a.avancar(100);
  for (const tipo of ['pointerdown', 'keydown', 'scroll', 'touchstart']) {
    assert.equal((a.w._l[tipo] || []).length, 1, `interação: escuta ${tipo}`);
  }
  a.w.dispatchEvent({ type: 'keydown' });
  assert.equal(a.w.rastro.tags.carregado('meta'), true, 'interação: carrega na primeira tecla');
  assert.equal(a.timers.length, 0, 'interação: limite cancelado');
  for (const tipo of ['pointerdown', 'keydown', 'scroll', 'touchstart']) {
    assert.equal((a.w._l[tipo] || []).length, 0, `interação: escuta ${tipo} removida`);
  }
  a.avancar(9000);
  assert.equal(a.chaves().length, 6, 'interação: nada repete');
}
{
  const a = ambiente({ carregamento: 'imediato', readyState: 'loading' });
  a.consent(TUDO);
  assert.equal(a.chaves().length, 6, 'imediato: carrega na hora do aceite');
  assert.equal(a.timers.length + a.ocioso.length, 0, 'imediato: sem agenda');
}
{
  const a = ambiente({ carregamento: 'qualquer' });
  a.consent(TUDO);
  assert.equal(a.scripts().length, 0, 'carregamento desconhecido vale como ocioso');
  a.rodarOcioso();
  assert.equal(a.chaves().length, 6, 'carregamento desconhecido: carrega no ocioso');
}

// 5.5 Fila de eventos e mapa por destino.
{
  const a = ambiente();
  a.w.rastro.event('view_item', { currency: 'BRL', value: 50, items: [COMPRA.items[0]] });
  a.w.rastro.event('purchase', COMPRA);
  a.w.rastro.event('search', { search_term: 'ração' });
  a.w.rastro.event('view_cart', { currency: 'BRL', value: 1, items: [COMPRA.items[0]] });
  a.w.rastro.event('add_payment_info', { currency: 'BRL', value: 1, payment_type: 'pix', items: [COMPRA.items[0]] });
  a.consent(TUDO);

  // GTM: ecommerce null antes de cada evento de loja.
  const camada = a.camada().filter((x) => !('gtm.start' in x));
  const i = camada.findIndex((x) => x.event === 'purchase');
  assert.deepEqual(camada[i - 1], { ecommerce: null }, 'GTM: ecommerce null antes da compra');
  assert.deepEqual(camada[i], { event: 'purchase', ecommerce: j(COMPRA) }, 'GTM: compra no dataLayer');
  assert.deepEqual(camada.find((x) => x.event === 'search'), { search_term: 'ração', event: 'search' }, 'GTM: busca sem ecommerce');

  // GA4 e Ads pelo gtag com send_to.
  const eventos = a.gtag().filter((x) => x[0] === 'event');
  assert.deepEqual(eventos.find((x) => x[1] === 'purchase' && x[2].send_to === 'G-ABC1234'), ['event', 'purchase', Object.assign(j(COMPRA), { send_to: 'G-ABC1234' })], 'GA4: purchase');
  assert.deepEqual(eventos.find((x) => x[1] === 'purchase' && x[2].send_to === 'AW-123456789'), ['event', 'purchase', Object.assign(j(COMPRA), { send_to: 'AW-123456789' })], 'Ads: purchase');
  assert.deepEqual(eventos.filter((x) => x[1] === 'conversion'), [['event', 'conversion', { send_to: 'AW-123456789/AbC-123_xy', value: 150.5, currency: 'BRL', transaction_id: '1001' }]], 'Ads: conversion com send_to AW-ID/rótulo');
  assert.equal(eventos.filter((x) => x[2].send_to === 'AW-123456789' && (x[1] === 'view_cart' || x[1] === 'add_payment_info')).length, 0, 'Ads: sem view_cart e add_payment_info');
  assert.equal(eventos.filter((x) => x[2].send_to === 'G-ABC1234').length, 5, 'GA4: todos os eventos');
  assert.equal(eventos.filter((x) => x[2].send_to === 'AW-123456789').length, 3, 'Ads: view_item, purchase e search');

  // Meta.
  const fbq = a.fbq().filter((x) => x[0] === 'track' && x[1] !== 'PageView');
  assert.deepEqual(fbq.find((x) => x[1] === 'Purchase'), ['track', 'Purchase', {
    value: 150.5, currency: 'BRL', content_type: 'product', content_ids: ['11', '12'], contents: [{ id: '11', quantity: 2 }, { id: '12', quantity: 1 }],
  }, { eventID: 'rastro-1001' }], 'Meta: Purchase com eventID');
  assert.deepEqual(fbq.find((x) => x[1] === 'ViewContent'), ['track', 'ViewContent', { value: 50, currency: 'BRL', content_type: 'product', content_ids: ['11'], contents: [{ id: '11', quantity: 2 }] }], 'Meta: ViewContent');
  assert.deepEqual(fbq.find((x) => x[1] === 'Search'), ['track', 'Search', { search_string: 'ração' }], 'Meta: Search');
  assert.deepEqual(fbq.find((x) => x[1] === 'AddPaymentInfo')[1], 'AddPaymentInfo', 'Meta: AddPaymentInfo');
  assert.equal(fbq.length, 4, 'Meta: sem view_cart');

  // TikTok.
  const ttq = a.ttq().filter((x) => x[0] === 'track');
  assert.deepEqual(ttq.find((x) => x[1] === 'Purchase'), ['track', 'Purchase', {
    value: 150.5, currency: 'BRL', content_type: 'product',
    contents: [
      { content_id: '11', content_type: 'product', quantity: 2, content_name: 'Ração', price: 50 },
      { content_id: '12', content_type: 'product', quantity: 1, content_name: 'Petisco', price: 30.5 },
    ],
  }, { event_id: 'rastro-1001' }], 'TikTok: Purchase com event_id');
  assert.deepEqual(ttq.map((x) => x[1]).sort(), ['AddPaymentInfo', 'Purchase', 'Search', 'ViewContent'], 'TikTok: só os eventos do mapa');

  // PostHog: só value, currency e quantidade_itens.
  const capturas = a.posthog().filter((x) => x[0] === 'capture');
  assert.deepEqual(j(capturas.find((x) => x[1] === 'purchase')), ['capture', 'purchase', { value: 150.5, currency: 'BRL', quantidade_itens: 3 }], 'PostHog: compra resumida');
  assert.deepEqual(j(capturas.find((x) => x[1] === 'search')), ['capture', 'search', {}], 'PostHog: busca sem o termo');
  assert.equal(capturas.length, 5, 'PostHog: todos os eventos');

  // OpenAI: só page_viewed.
  assert.deepEqual(a.oaiq().map((x) => x[0]), ['init', 'measure'], 'OpenAI: sem eventos da loja');

  // Depois da carga: entrega na hora, sem repetir a fila.
  a.consent(TUDO);
  a.w.rastro.event('add_to_cart', { currency: 'BRL', value: 50, items: [COMPRA.items[0]] }, { event_id: 'carrinho-1' });
  assert.equal(a.fbq().filter((x) => x[1] === 'Purchase').length, 1, 'compra entregue uma vez só');
  assert.deepEqual(a.fbq().find((x) => x[1] === 'AddToCart')[3], { eventID: 'carrinho-1' }, 'Meta: event_id de opcoes');
  assert.deepEqual(a.ttq().find((x) => x[1] === 'AddToCart')[3], { event_id: 'carrinho-1' }, 'TikTok: event_id de opcoes');
  assert.ok(a.gtag().some((x) => x[0] === 'event' && x[1] === 'add_to_cart' && x[2].send_to === 'AW-123456789'), 'Ads: add_to_cart na hora');

  // Nomes inválidos.
  assert.equal(a.w.rastro.event('Purchase', {}), false, 'nome fora do formato');
  assert.equal(a.w.rastro.event('', {}), false, 'nome vazio');
  const antes = a.fbq().length;
  assert.equal(a.w.rastro.event('constructor', {}), true, 'nome de propriedade herdada não quebra');
  assert.equal(a.fbq().length, antes, 'nome de propriedade herdada não vai para a Meta');
}
{
  // Fila guardada: o que entrou antes vai para o destino que carregar depois.
  const a = ambiente();
  a.w.rastro.event('purchase', COMPRA);
  a.consent(SO_ANALISE);
  assert.equal(a.w.fbq, undefined, 'só análise: compra não vai para a Meta');
  a.consent(TUDO);
  assert.equal(a.fbq().filter((x) => x[1] === 'Purchase').length, 1, 'publicidade aceita depois: a compra da fila chega');
}
{
  // Sem ID de compra válido, sem eventID.
  const a = ambiente();
  a.consent(TUDO);
  a.w.rastro.event('purchase', { value: 1, currency: 'BRL', transaction_id: '1 OR 1' });
  assert.equal(a.fbq().find((x) => x[1] === 'Purchase').length, 3, 'Meta: sem eventID para número inválido');
}

// 5.6 Sem aceite, a fila é descartada.
{
  const a = ambiente();
  a.w.rastro.event('purchase', COMPRA);
  a.consent(NADA);
  assert.equal(a.w.rastro.event('view_item', {}), false, 'recusado: evento não entra');
  a.consent(TUDO);
  assert.equal(a.fbq().filter((x) => x[1] === 'Purchase').length, 0, 'recusado antes: a compra não chega depois');
  assert.equal(a.camada().filter((x) => x.event === 'purchase').length, 0, 'recusado antes: nada no dataLayer');
}

// 5.7 Revogação e parar().
{
  const a = ambiente();
  a.consent(TUDO);
  a.consent(NADA);
  assert.equal(a.w.rastro.tags.carregado(), false, 'revogado: nada conta como carregado');
  assert.ok(a.posthog().some((x) => x[0] === 'opt_out_capturing'), 'revogado: PostHog para de capturar');
  assert.deepEqual(a.oaiq().pop(), ['consent', false], 'revogado: OpenAI sem consentimento');
  assert.equal(a.w.rastro.event('purchase', COMPRA), false, 'revogado: evento não entra');
  assert.equal(a.fbq().filter((x) => x[1] === 'Purchase').length, 0, 'revogado: nada chega');
  const total = a.scripts().length;
  a.consent(TUDO);
  assert.equal(a.scripts().length, total, 'revogado: nada carrega de novo nesta página');
}
{
  // Perder só a publicidade também para (a página recarrega pelo consentimento).
  const a = ambiente();
  a.consent(TUDO);
  a.consent(SO_ANALISE);
  assert.equal(a.w.rastro.tags.carregado(), false, 'publicidade revogada: parado');
}
{
  const a = ambiente({ carregamento: 'interacao' });
  a.consent(TUDO);
  a.w.rastro.tags.parar();
  a.avancar(9000);
  a.w.dispatchEvent({ type: 'pointerdown' });
  assert.equal(a.scripts().length, 0, 'parar() antes da carga: nada carrega');
  assert.equal(a.w.rastro.event('view_item', {}), false, 'parar(): sem eventos');
}

// 5.8 Modos de consentimento (seção 2.1).
{
  const a = ambiente({ consentCfg: { modo: 'basico' } });
  assert.equal(a.chaves().length, 6, 'básico: tudo carrega sem evento de consentimento');
  assert.equal(a.w.rastro.tags.carregado('tiktok'), true, 'básico: publicidade incluída');
}
{
  const a = ambiente({ consentCfg: { modo: 'aceite_automatico' } });
  assert.equal(a.scripts().length, 0, 'aceite automático: nada antes do tempo');
  a.consent(SO_ANALISE);
  assert.deepEqual(a.chaves().sort(), ['gtag', 'posthog'], 'aceite automático: só análise');
  for (const chave of ['gtm', 'ads', ...PUBLICIDADE]) {
    assert.equal(a.w.rastro.tags.carregado(chave), false, `aceite automático: ${chave} espera a publicidade`);
  }
}
{
  const a = ambiente({ consentCfg: { modo: 'lgpd' } });
  assert.equal(a.scripts().length, 0, 'LGPD: nada sem evento');
}
{
  // Escolha já feita antes do tags.js: window.rastro.consent.get().
  const a = ambiente({ antes: (w) => { w.rastro = { consent: { get: () => ({ analise: true, publicidade: true, decidido: true }) } }; } });
  assert.equal(a.chaves().length, 6, 'consent.get() na carga: carrega');
  const b = ambiente({ antes: (w) => { w.rastro = { consent: { get: () => ({ analise: false, publicidade: false, decidido: false }) } }; } });
  assert.equal(b.scripts().length, 0, 'consent.get() sem escolha: nada');
  const c = ambiente({ antes: (w) => { w.rastro = { consent: { get: () => { throw new Error('x'); } } }; } });
  assert.equal(c.scripts().length, 0, 'consent.get() com erro: nada e sem quebrar');
  c.consent(TUDO);
  assert.equal(c.chaves().length, 6, 'consent.get() com erro: o evento ainda vale');
}

// 5.9 Um destino com erro não derruba os outros.
{
  const a = ambiente({ antes: (w) => { w.fbq = function () { throw new Error('quebrado'); }; } });
  a.consent(TUDO);
  assert.equal(a.w.rastro.tags.carregado('meta'), false, 'Meta com erro não conta como carregada');
  for (const chave of ['gtm', 'ga4', 'ads', 'tiktok', 'openai', 'posthog']) {
    assert.equal(a.w.rastro.tags.carregado(chave), true, `${chave} carrega mesmo com a Meta quebrada`);
  }
  assert.equal(a.w.rastro.event('purchase', COMPRA), true, 'eventos seguem com um destino quebrado');
}
{
  const a = ambiente({ criarFalha: (tag) => tag === 'link' });
  a.consent(TUDO);
  assert.equal(a.chaves().length, 6, 'preconnect com erro não impede a carga');
}

// 5.10 Configuração inválida é ignorada.
{
  const a = ambiente({
    destinos: destinosCheios({
      gtm: { carrega_com: ['analise'], id: 'GTM-<x>' },
      ga4: { carrega_com: ['tudo'], id: IDS.ga4 },
      posthog: { carrega_com: ['analise'], id: IDS.posthog, config: { api_host: 'https://coleta.exemplo.com' } },
      meta: { carrega_com: ['analise'], id: IDS.meta },
    }),
  });
  a.consent(SO_ANALISE);
  assert.equal(a.w.rastro.tags.carregado('gtm'), false, 'ID inválido não carrega');
  assert.equal(a.w.rastro.tags.carregado('ga4'), false, 'finalidade fora da lista não carrega');
  assert.equal(a.w.rastro.tags.carregado('posthog'), false, 'host do PostHog fora da lista não carrega');
  assert.equal(a.w.rastro.tags.carregado('meta'), true, 'carrega_com vem do servidor (a regra fixa é do tags.php)');
}
{
  const a = ambiente({ destinos: {} });
  a.consent(TUDO);
  assert.equal(a.scripts().length, 0, 'sem destinos: nada');
}

// 5.11 PostHog: endereços sem consulta; em página privada, o endereço do servidor.
{
  const privada = 'https://loja.exemplo.com.br/finalizar-compra/pedido-recebido/';
  const a = ambiente({ pagina: { tipo: 'pedido_recebido', privada: true, url: privada } });
  a.consent(SO_ANALISE);
  const config = a.w.posthog._i[0][1];
  const ev = config.before_send({ properties: { $current_url: privada + '123/?key=wc_order_x', $pathname: '/finalizar-compra/pedido-recebido/123/', $referrer: 'https://busca.exemplo/?q=x' } });
  assert.deepEqual(j(ev.properties), { $current_url: privada, $pathname: '/finalizar-compra/pedido-recebido/', $referrer: 'https://busca.exemplo/' }, 'página privada: endereço saneado');
  const rede = config.session_recording.maskCapturedNetworkRequestFn({ name: 'https://loja.exemplo.com.br/?wc-ajax=x&key=y' });
  assert.equal(rede.name, 'https://loja.exemplo.com.br/', 'requisição gravada sem consulta');
  const b = ambiente();
  b.consent(SO_ANALISE);
  const ev2 = b.w.posthog._i[0][1].before_send({ properties: { $current_url: URL_PAGINA + '?gclid=1', $pathname: '/produto/racao/' } });
  assert.deepEqual(j(ev2.properties), { $current_url: URL_PAGINA, $pathname: '/produto/racao/' }, 'página pública: sem consulta');
  assert.equal(b.w.posthog._i[0][1].before_send(null), null, 'before_send sem evento');
}

// 5.11b Pedido recebido: chave fora do Google (set antes do config) e da barra
// de endereço antes de GTM, Meta, TikTok e OpenAI.
{
  const privada = 'https://loja.exemplo.com.br/finalizar-compra/pedido-recebido/';
  const local = { pathname: '/finalizar-compra/pedido-recebido/123/', search: '?key=wc_order_x', protocol: 'https:', hostname: 'loja.exemplo.com.br' };
  const a = ambiente({ pagina: { tipo: 'pedido_recebido', privada: true, url: privada }, location: local });
  a.consent(SO_ANALISE);
  const g = a.gtag();
  const iSet = g.findIndex((x) => x[0] === 'set' && x[1] === 'page_location');
  const iRef = g.findIndex((x) => x[0] === 'set' && x[1] === 'page_referrer');
  const iConfig = g.findIndex((x) => x[0] === 'config');
  assert.deepEqual(g[iSet], ['set', 'page_location', privada], "página privada: gtag('set', 'page_location') saneado");
  assert.deepEqual(g[iRef], ['set', 'page_referrer', ''], "página privada: gtag('set', 'page_referrer', '')");
  assert.ok(iSet < iConfig && iRef < iConfig, 'set antes de qualquer config');
  assert.equal(a.w.history.trocas.length, 0, 'só análise (GA4 com page_location saneado): a barra não muda');
  a.consent(TUDO);
  assert.equal(a.w.history.trocas.length, 1, 'publicidade: replaceState uma vez');
  assert.equal(a.w.history.trocas[0].url, '/finalizar-compra/pedido-recebido/', 'replaceState com o caminho saneado');
  assert.equal(a.w.history.trocas[0].scripts, 2, 'replaceState antes de gtm.js, fbevents.js, events.js e oaiq.min.js');
  assert.equal(a.gtag().filter((x) => x[0] === 'set').length, 2, 'set uma vez só');
}
{
  // Tudo de uma vez: a troca vem antes do primeiro script (o GTM é o primeiro da ordem).
  const local = { pathname: '/finalizar-compra/pedido-recebido/123/', search: '?key=wc_order_x' };
  const a = ambiente({ pagina: { tipo: 'pedido_recebido', privada: true, url: 'https://loja.exemplo.com.br/finalizar-compra/pedido-recebido/' }, location: local });
  a.consent(TUDO);
  assert.deepEqual(j(a.w.history.trocas), [{ url: '/finalizar-compra/pedido-recebido/', scripts: 0 }], 'replaceState antes de qualquer script');
}
{
  // Sem nada a tirar da barra, nada muda; página pública, sem set nem troca.
  const a = ambiente({ pagina: { tipo: 'pedido_recebido', privada: true, url: 'https://loja.exemplo.com.br/finalizar-compra/pedido-recebido/' }, location: { pathname: '/finalizar-compra/pedido-recebido/', search: '' } });
  a.consent(TUDO);
  assert.equal(a.w.history.trocas.length, 0, 'endereço já limpo: sem replaceState');
  const b = ambiente({ location: { pathname: '/produto/racao/', search: '?utm_source=x' } });
  b.consent(TUDO);
  assert.equal(b.w.history.trocas.length, 0, 'página pública: sem replaceState');
  assert.equal(b.gtag().filter((x) => x[0] === 'set').length, 0, 'página pública: sem set');
  const c = ambiente({ pagina: { tipo: 'checkout', privada: true, url: 'https://loja.exemplo.com.br/finalizar-compra/' }, location: { pathname: '/finalizar-compra/', search: '?x=1' } });
  c.consent(TUDO);
  assert.equal(c.w.history.trocas.length, 0, 'replaceState só no pedido recebido');
  assert.deepEqual(c.gtag().find((x) => x[0] === 'set'), ['set', 'page_location', 'https://loja.exemplo.com.br/finalizar-compra/'], 'checkout (privada): set do endereço saneado');
}

// 5.11c Revogação: Meta com consent revoke; algumCarregado() ignora o parar().
{
  const a = ambiente();
  a.consent(TUDO);
  a.consent(NADA);
  assert.deepEqual(a.fbq().pop(), ['consent', 'revoke'], "revogado: fbq('consent', 'revoke')");
  assert.equal(a.w.rastro.tags.carregado(), false, 'revogado: carregado() falso');
  assert.equal(a.w.rastro.tags.algumCarregado(), true, 'revogado: algumCarregado() ainda sabe que algo carregou');
  const b = ambiente();
  b.consent(SO_ANALISE);
  b.consent(NADA);
  assert.equal(b.w.fbq, undefined, 'sem Meta carregada, sem fbq na revogação');
  assert.equal(b.w.rastro.tags.algumCarregado(), true, 'só análise carregada também conta');
  const c = ambiente();
  assert.equal(c.w.rastro.tags.algumCarregado(), false, 'nada carregado');
}

// 5.12 Segunda cópia do tags.js não refaz nada.
{
  const a = ambiente();
  const evento = a.w.rastro.event;
  vm.runInContext(js, vm.createContext(a.w));
  assert.equal(a.w.rastro.event, evento, 'segunda cópia não troca a API');
}

/* 6. php -l e teste PHP ------------------------------------------------------------- */

const temPhp = spawnSync('php', ['-v'], { encoding: 'utf8' });
if (temPhp.status === 0) {
  for (const arquivo of [R + 'tags/tags.php', 'tests/php/rastro-tags.test.php']) {
    const l = spawnSync('php', ['-l', path.join(root, arquivo)], { encoding: 'utf8' });
    assert.equal(l.status, 0, `php -l ${arquivo}:\n${l.stdout}${l.stderr}`);
  }
  const r = spawnSync('php', [path.join(root, 'tests/php/rastro-tags.test.php')], { encoding: 'utf8' });
  assert.equal(r.status, 0, `tests/php/rastro-tags.test.php falhou:\n${r.stderr}${r.stdout}`);
  process.stdout.write(r.stdout);
} else {
  console.log('php não encontrado no PATH: php -l e tests/php/rastro-tags.test.php pulados.');
}

// Fila window.rastroFilaEventos (eventos.js da loja roda antes): esvaziada na
// ordem, e push depois do carregamento também vira evento.
{
  const vistos = [];
  const a = ambiente({
    antes(w) {
      w.rastroFilaEventos = [['view_item', { currency: 'BRL', value: 10, items: [] }, {}], ['add_to_cart', { currency: 'BRL', value: 10, items: [] }]];
      w.document.addEventListener('rastro:event', (e) => vistos.push(e.detail && (e.detail.nome || e.detail.name || e.detail.evento)));
    },
  });
  assert.equal(a.w.rastroFilaEventos.length, 0, 'fila antiga esvaziada');
  a.w.rastroFilaEventos.push(['begin_checkout', { currency: 'BRL', value: 10, items: [] }, {}]);
  assert.equal(vistos.length, 3, 'três eventos entregues (dois da fila e um push depois)');
  assert.equal(a.w.rastroFilaEventos.length, 0, 'push depois do carregamento não acumula');
}

// Logs do PostHog (docs/LOGS.md): chave `logs` validada no posthog.init,
// window.rastro.log em fila até o PostHog carregar, nada sem aceite, nada
// com os logs desligados, falha de carregador vira log.
{
  const LOGS = { serviceName: 'rastro', environment: 'production', serviceVersion: '1.0.0', captureConsoleLogs: false };
  const comLogs = (logs) => destinosCheios({
    posthog: {
      carrega_com: ['analise'],
      id: IDS.posthog,
      config: { api_host: 'https://us.i.posthog.com', defaults: '2026-08-30', person_profiles: 'identified_only', logs },
    },
  });

  const a = ambiente({ destinos: comLogs(LOGS) });
  assert.equal(typeof a.w.rastro.log, 'function', 'window.rastro.log');
  assert.equal(a.w.rastro.log('warn', 'antes do aceite', { modulo: 'teste', url: 'https://x.exemplo/?email=a' }), true, 'log na fila antes do aceite');
  assert.equal(a.w.posthog, undefined, 'log não carrega o PostHog');
  a.consent(SO_ANALISE);
  const init = a.w.posthog._i[0][1];
  assert.deepEqual(j(init.logs), LOGS, 'posthog.init com logs');
  const capturados = a.posthog().filter((x) => x[0] === 'captureLog').map((x) => x[1]);
  assert.deepEqual(capturados, [{ body: 'antes do aceite', level: 'warn', attributes: { modulo: 'teste', url: 'https://x.exemplo/' } }], 'fila entregue pelo captureLog, sem consulta');
  a.w.rastro.log('nivel-estranho', 'depois', { lista: [1], ok: true });
  const ultimo = a.posthog().filter((x) => x[0] === 'captureLog').pop()[1];
  assert.deepEqual(ultimo, { body: 'depois', level: 'info', attributes: { ok: true } }, 'nível desconhecido vira info; só valores simples');
  a.w.rastro.log('error', 'falhou para ana@exemplo.com, cpf 123.456.789-09, pedido 4521, /view-order/4521/', { quem: 'ana%40exemplo.com' });
  const mascarado = a.posthog().filter((x) => x[0] === 'captureLog').pop()[1];
  assert.equal(mascarado.body, 'falhou para [e-mail], cpf [cpf], pedido [número], /view-order/:id/', 'log do navegador mascarado');
  assert.equal(mascarado.attributes.quem, '[e-mail]', 'atributo mascarado');

  const b = ambiente({ destinos: comLogs(LOGS) });
  b.w.rastro.log('error', 'some sem aceite');
  b.consent(NADA);
  assert.equal(b.w.rastro.log('error', 'depois da recusa'), false, 'recusa: log não entra');
  assert.equal(b.w.posthog, undefined, 'recusa: nada do PostHog');

  const c = ambiente({ destinos: comLogs({ serviceName: 'Rastro <script>', captureConsoleLogs: 'sim' }) });
  assert.equal(c.w.rastro.log('info', 'x'), false, 'logs inválidos: desligados');
  c.consent(SO_ANALISE);
  assert.equal(c.w.posthog._i[0][1].logs, undefined, 'logs inválidos não vão ao init');

  const d = ambiente({ destinos: destinosCheios() });
  assert.equal(d.w.rastro.log('info', 'x'), false, 'sem logs na configuração: nada');

  // Falha de um carregador (criar <script> quebra) vira log de erro.
  let quebrar = false;
  const e = ambiente({ destinos: comLogs(LOGS), criarFalha: (tag) => quebrar && tag === 'script' });
  e.consent(SO_ANALISE);
  quebrar = true;
  e.consent(TUDO);
  const erros = e.posthog().filter((x) => x[0] === 'captureLog').map((x) => x[1]).filter((x) => x.level === 'error');
  assert.ok(erros.length > 0 && /^Falha ao carregar o destino /.test(erros[0].body), 'falha de carregador vira log de erro');
}

console.log('Rastro tags contract: PASS');
