'use strict';

// Contrato do consentimento do Rastro (T2): motor de consentimento, aviso
// próprio, fila do Consent Mode v2, provedores (próprio, WP Consent API e
// ponte) e os três modos (lgpd, aceite_automatico, basico).
// Contrato: docs/CONTRATO.md (seções
// 2.1 e 4). Manual: docs/CONSENTIMENTO.md. Parte estática e o
// consent.js num DOM mínimo falso rodam sempre no node; php -l e o teste
// tests/php/rastro-consentimento.test.php só quando há `php` no PATH.

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
// Travessão (U+2014) montado pelo código.
const TRAVESSAO = new RegExp(String.fromCharCode(0x2014));

// 1. Arquivos da frente.
const PHP_CONSENT = R + 'consentimento/consentimento.php';
const PHP_AVISO = R + 'consentimento/aviso.php';
const JS = R + 'assets/js/consent.js';
const CSS = R + 'assets/css/aviso.css';
const TESTE_PHP = 'tests/php/rastro-consentimento.test.php';
const DOC = 'docs/CONSENTIMENTO.md';
const arquivos = [PHP_CONSENT, PHP_AVISO, JS, CSS, 'tests/validate-rastro-consentimento-contract.js', TESTE_PHP, DOC];
for (const arquivo of arquivos) {
  assert.ok(fs.existsSync(path.join(root, arquivo)), `existe ${arquivo}`);
  const bruto = lerBruto(arquivo);
  semMatch(bruto, /\r/, `${arquivo} com fim de linha LF`);
  semMatch(bruto, TRAVESSAO, `${arquivo} sem travessão`);
}
for (const arquivo of [PHP_CONSENT, PHP_AVISO, JS, CSS]) {
  const texto = ler(arquivo);
  semMatch(texto, /animalart/i, `${arquivo} sem dependência do site`);
  semMatch(texto, /innerHTML|outerHTML|insertAdjacentHTML|document\.write/, `${arquivo} sem HTML montado no navegador`);
  semMatch(texto, /\beval\(|new Function\(/, `${arquivo} sem eval`);
  const externos = (texto.match(/https?:\/\/[^\s'")]+/g) || []);
  assert.deepEqual(externos, [], `${arquivo} sem endereço externo (nada de CDN)`);
}

// 2. PHP do motor (seções 2.1 e 4).
const consent = ler(PHP_CONSENT);
for (const php of [consent, ler(PHP_AVISO)]) {
  comMatch(php, /^<\?php\n[\s\S]*?\ndefined\( 'ABSPATH' \) \|\| exit;\n/, 'guarda ABSPATH');
  const chamadas = php.match(/\b(?:__|_e|_x|_n|esc_html__|esc_attr__|esc_html_e|esc_attr_e)\(/g) || [];
  const comDominio = php.match(/\b(?:__|_e|esc_html__|esc_attr__|esc_html_e|esc_attr_e)\(\s*'(?:[^'\\]|\\.)*'\s*,\s*'rastro'\s*\)/g) || [];
  assert.equal(comDominio.length, chamadas.length, "toda tradução com o text domain 'rastro'");
  semMatch(php, /\$_REQUEST\b|base64_decode\(|delete_option\(/, 'sem $_REQUEST, base64_decode e delete_option');
}
for (const assinatura of [
  'function rastro_consent_provedor(): string',
  'function rastro_consent_finalidades(): array',
  'function rastro_consent_servidor(): array',
  'function rastro_consent_modo(): string',
  'function rastro_consent_regra_provedor( string $aviso, array $pontes_ativas, bool $wp_consent_api, string $modo = \'lgpd\' ): string',
  'function rastro_consent_regra_finalidades( array $config ): array',
  'function rastro_consent_regra_cookie( $valor ): array',
  'function rastro_consent_regra_fila( string $modo ): string',
]) {
  tem(consent, assinatura, `assinatura: ${assinatura}`);
}
tem(consent, "const RASTRO_CONSENT_COOKIE = 'rastro_consent';");
tem(consent, "apply_filters( 'rastro_consent_provedores', array() )", 'pontes pelo filtro');
tem(consent, "function_exists( 'wp_has_consent' )", 'WP Consent API só quando existe');
tem(consent, "add_action( 'wp_head', 'rastro_consent_fila', 2 );", 'fila no wp_head, prioridade 2');
tem(consent, "rastro_script_inline( 'rastro-fila',", 'fila inline pela base');
tem(consent, "add_filter( 'rastro_config_navegador', 'rastro_consent_filtro_navegador', 10, 1 );");
tem(consent, "add_filter( 'rastro_precisa_saida', 'rastro_consent_filtro_precisa_saida', 10, 1 );");
tem(consent, "add_filter( 'rastro_admin_avisos', 'rastro_consent_filtro_avisos', 10, 1 );");
tem(consent, "rastro_registrar_script( 'rastro-consent', 'consent.js' )");
tem(consent, "$padrao['wait_for_update'] = 500;");
tem(consent, 'Este modo não atende a LGPD: ficar parado ou não ser perguntado não é consentimento. A escolha e o risco são do site.');
for (const chave of ['ga4', 'posthog', 'gtm', 'ads', 'meta', 'tiktok', 'openai']) {
  tem(consent, `'${chave}'`, `finalidade a partir de ${chave}`);
}
const servidor = consent.slice(consent.indexOf('function rastro_consent_servidor'), consent.indexOf('function rastro_consent_dias'));
assert.equal((consent.match(/\$_COOKIE/g) || []).length, (servidor.match(/\$_COOKIE/g) || []).length, 'cookie do visitante só lido em rastro_consent_servidor (HTML igual para todos)');

// 3. Aviso: os seletores do JS existem no HTML do PHP.
const aviso = ler(PHP_AVISO);
const js = ler(JS);
const seletores = [...new Set([...js.matchAll(/'\[(data-rastro-[a-z-]+)\]'/g)].map((m) => m[1]))];
assert.ok(seletores.length >= 8, 'consent.js usa os atributos do aviso');
for (const atributo of seletores) {
  tem(aviso, atributo, `aviso.php tem ${atributo}`);
}
tem(aviso, "add_shortcode( 'rastro_preferencias', 'rastro_aviso_shortcode' );");
tem(aviso, 'function rastro_aviso_botao_preferencias( array $args = array() ): string');
tem(aviso, "apply_filters( 'rastro_aviso_botao_flutuante', 'proprio' === $provedor, $provedor )", 'botão fixo: padrão só no provedor próprio');
tem(aviso, 'get_privacy_policy_url()', 'link para a política de privacidade do WordPress');
tem(aviso, 'data-rastro-aviso hidden role="region"', 'aviso escondido no HTML');
for (const botao of ['recusar', 'personalizar', 'aceitar']) {
  tem(aviso, `<button type="button" class="rastro-aviso__botao" data-rastro-aviso-${botao}`, `botão ${botao} com a mesma classe`);
}
semMatch(aviso, /checked/, 'caixas desmarcadas por padrão');
semMatch(aviso, /<script/, 'aviso sem script inline');

// 4. CSS só pelas variáveis do contrato.
const css = ler(CSS);
const permitidas = new Set(['--rastro-fundo', '--rastro-texto', '--rastro-borda', '--rastro-destaque', '--rastro-raio', '--rastro-fonte']);
const usadas = [...css.matchAll(/var\((--[a-z-]+)/g)].map((m) => m[1]);
assert.ok(usadas.length > 0, 'CSS usa variáveis');
for (const v of usadas) {
  assert.ok(permitidas.has(v), `variável permitida: ${v}`);
}
semMatch(css, /--(?!rastro-)[a-z-]+\s*:/, 'nenhuma variável fora de --rastro-*');
tem(css, '@media (prefers-reduced-motion: reduce)');
semMatch(css, /@import|url\(/, 'CSS sem recurso externo');
semMatch(css, /\binset:\s*0|height:\s*100vh;|top:\s*0;/, 'não cobre a página inteira');
comMatch(css, /\.rastro-aviso\[hidden\][\s\S]*?display: none !important/, 'hidden vence o tema');

// 5. consent.js num DOM mínimo falso.
const codigo = lerBruto(JS);
semMatch(js, /\b(?:let|const|class)\s|=>/, 'consent.js em ES5 (sem build)');

class Evento {
  constructor(type, init = {}) {
    this.type = type;
    this.detail = init.detail;
    this.key = init.key;
    this.currentTarget = null;
  }
}

class Elemento {
  constructor(doc, attrs = {}, filhos = []) {
    this.doc = doc;
    this.attrs = Object.assign({}, attrs);
    this.hidden = Object.prototype.hasOwnProperty.call(this.attrs, 'hidden');
    delete this.attrs.hidden;
    this.checked = false;
    this.ouvintes = {};
    this.pai = null;
    this.filhos = [];
    this.cliques = 0;
    for (const f of filhos) this.anexar(f);
  }
  anexar(f) { f.pai = this; this.filhos.push(f); return f; }
  getAttribute(n) { return Object.prototype.hasOwnProperty.call(this.attrs, n) ? this.attrs[n] : null; }
  setAttribute(n, v) { this.attrs[n] = String(v); }
  descendentes() { const l = []; for (const f of this.filhos) { l.push(f, ...f.descendentes()); } return l; }
  casa(sel) {
    const m = /^\[([a-z0-9-]+)(?:="([^"]*)")?\]$/.exec(sel);
    if (!m) throw new Error('seletor não suportado no DOM falso: ' + sel);
    return Object.prototype.hasOwnProperty.call(this.attrs, m[1]) && (m[2] === undefined || this.attrs[m[1]] === m[2]);
  }
  querySelectorAll(sel) { return this.descendentes().filter((e) => e.casa(sel)); }
  querySelector(sel) { return this.querySelectorAll(sel)[0] || null; }
  addEventListener(t, fn) { (this.ouvintes[t] = this.ouvintes[t] || []).push(fn); }
  removeEventListener(t, fn) { this.ouvintes[t] = (this.ouvintes[t] || []).filter((f) => f !== fn); }
  dispatchEvent(ev, bolha = false) {
    let alvo = this;
    do {
      ev.currentTarget = alvo;
      for (const fn of (alvo.ouvintes[ev.type] || []).slice()) fn.call(alvo, ev);
      alvo = bolha ? alvo.pai : null;
    } while (alvo);
    return true;
  }
  contains(e) { for (let x = e; x; x = x.pai) { if (x === this) return true; } return false; }
  focus() { this.doc.activeElement = this; this.dispatchEvent(new Evento('focusin'), true); }
  click() { this.cliques++; this.dispatchEvent(new Evento('click'), true); }
}

function criarArmazenamento(inicial = {}) {
  const dados = new Map(Object.entries(inicial));
  return {
    dados,
    get length() { return dados.size; },
    key(i) { return [...dados.keys()][i] ?? null; },
    getItem(k) { return dados.has(k) ? dados.get(k) : null; },
    setItem(k, v) { dados.set(k, String(v)); },
    removeItem(k) { dados.delete(k); },
  };
}

/** Aviso falso com os mesmos atributos de consentimento/aviso.php. */
function montarAviso(doc, finalidades) {
  const el = (attrs, filhos) => new Elemento(doc, attrs, filhos);
  const caixas = finalidades.map((f) => el({ type: 'checkbox', 'data-rastro-finalidade': f }));
  const painel = el({ 'data-rastro-aviso-preferencias': '', hidden: '' }, [...caixas, el({ 'data-rastro-aviso-salvar': '' })]);
  const acoes = el({}, [
    el({ 'data-rastro-aviso-recusar': '' }),
    el({ 'data-rastro-aviso-personalizar': '', 'aria-expanded': 'false' }),
    el({ 'data-rastro-aviso-aceitar': '' }),
  ]);
  return el({ 'data-rastro-aviso': '', hidden: '', role: 'region' }, [el({}, [painel, acoes])]);
}

function ambiente(opcoes = {}) {
  const consentCfg = Object.assign({
    modo: 'lgpd',
    provedor: 'proprio',
    finalidades: ['analise', 'publicidade'],
    cookie: 'rastro_consent',
    visita: 'rastro_consent_visita',
    dias: 180,
    segundos: 2,
    flutuante: true,
    medir: true,
  }, opcoes.consent || {});
  const jarra = new Map(Object.entries(opcoes.cookies || {}));
  const escritas = [];
  const doc = new Elemento(null);
  doc.doc = doc;
  doc.readyState = 'complete';
  doc.activeElement = null;
  Object.defineProperty(doc, 'cookie', {
    get() { return [...jarra].map(([k, v]) => `${k}=${v}`).join('; '); },
    set(texto) {
      escritas.push(texto);
      const partes = texto.split(';').map((p) => p.trim());
      const [nome, ...resto] = partes[0].split('=');
      const valor = resto.join('=');
      const attrs = Object.fromEntries(partes.slice(1).map((p) => { const [k, ...v] = p.split('='); return [k.toLowerCase(), v.join('=')]; }));
      const apagar = ('max-age' in attrs && Number(attrs['max-age']) <= 0) || ('expires' in attrs && Date.parse(attrs.expires) < Date.now());
      if (apagar) jarra.delete(nome); else jarra.set(nome, valor);
    },
  });
  const corpo = doc.anexar(new Elemento(doc, { body: '' }));
  const outroFoco = corpo.anexar(new Elemento(doc, { id: 'busca' }));
  if (opcoes.aviso !== false && consentCfg.provedor === 'proprio' && consentCfg.modo !== 'basico') {
    corpo.anexar(montarAviso(doc, consentCfg.finalidades));
    corpo.anexar(new Elemento(doc, { 'data-rastro-preferencias': '', 'data-rastro-flutuante': '', hidden: '' }));
    corpo.anexar(new Elemento(doc, { 'data-rastro-preferencias': '', id: 'rodape' }));
  }
  const botaoSite = corpo.anexar(new Elemento(doc, { 'data-meusite-cookies-abrir': '' }));

  let relogio = 0;
  let proximo = 1;
  const timers = new Map();
  const win = {
    document: doc,
    rastroConfig: { versao: '0.1.0', consent: consentCfg },
    location: {
      protocol: opcoes.protocolo || 'https:',
      hostname: 'loja.exemplo.com.br',
      recargas: 0,
      reload() { this.recargas++; },
    },
    localStorage: criarArmazenamento(opcoes.local),
    sessionStorage: criarArmazenamento(opcoes.sessao),
    CustomEvent: Evento,
    setTimeout(fn, ms) { const id = proximo++; timers.set(id, { fn, quando: relogio + (ms || 0) }); return id; },
    clearTimeout(id) { timers.delete(id); },
    ouvintes: {},
    addEventListener(t, fn) { (this.ouvintes[t] = this.ouvintes[t] || []).push(fn); },
    Date, Object, String, parseInt, isNaN, decodeURIComponent,
  };
  if (opcoes.semFila !== true) {
    win.dataLayer = [];
    win.gtag = function () { win.dataLayer.push(arguments); };
  }
  Object.assign(win, opcoes.window || {});
  win.window = win;

  const eventos = [];
  doc.addEventListener('rastro:consent', (ev) => eventos.push(ev.detail));

  const amb = {
    win, doc, jarra, escritas, eventos, botaoSite, outroFoco,
    aviso: () => doc.querySelector('[data-rastro-aviso]'),
    q: (sel) => doc.querySelector(sel),
    avancar(ms) {
      const fim = relogio + ms;
      for (;;) {
        const devidos = [...timers].filter(([, t]) => t.quando <= fim).sort((a, b) => a[1].quando - b[1].quando);
        if (!devidos.length) break;
        const [id, t] = devidos[0];
        timers.delete(id);
        relogio = t.quando;
        t.fn();
      }
      relogio = fim;
    },
    rodar() { vm.runInNewContext(codigo, win, { filename: JS }); amb.avancar(0); return amb; },
    updates: () => Array.from(win.dataLayer).filter((a) => a[0] === 'consent' && a[1] === 'update').map((a) => Object.assign({}, a[2])),
    gravados: () => escritas.filter((t) => t.startsWith('rastro_consent=') && !t.includes('1970')),
  };
  return amb;
}

const TODOS_NEGADOS = { ad_storage: 'denied', analytics_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied' };
const TODOS_CONCEDIDOS = { ad_storage: 'granted', analytics_storage: 'granted', ad_user_data: 'granted', ad_personalization: 'granted' };
const SO_ANALISE = { ad_storage: 'denied', analytics_storage: 'granted', ad_user_data: 'denied', ad_personalization: 'denied' };
const MEDICAO = {
  _ga: '1', _ga_ABC123: '1', _gid: '1', _gcl_au: '1', _gcl_aw: '1', _fbp: '1', _fbc: '1', _ttp: '1', _tt_enable_cookie: '1',
  ph_phc_teste_posthog: '1', __ph_opt_in_out_phc_teste: '1', rastro_origem: 'x',
};

// 5.1 Padrão: tudo negado, aviso visível sem roubar o foco.
{
  const a = ambiente();
  a.outroFoco.focus();
  a.rodar();
  const api = a.win.rastro.consent;
  assert.deepEqual({ ...api.get() }, { analise: false, publicidade: false, decidido: false }, 'padrão negado');
  assert.equal(api.provedor, 'proprio');
  assert.equal(api.modo, 'lgpd');
  assert.equal(a.aviso().hidden, false, 'sem escolha: aviso aparece');
  assert.equal(a.doc.activeElement, a.outroFoco, 'aparecer sozinho não tira o foco');
  assert.equal(a.q('[data-rastro-flutuante]').hidden, true, 'botão fixo escondido com o aviso aberto');
  assert.deepEqual(a.eventos, [], 'sem escolha: nenhum rastro:consent na carga');
  assert.deepEqual(a.updates(), [], 'sem escolha: nenhum update do Consent Mode');
  assert.deepEqual(a.escritas, [], 'sem escolha: nenhum cookie gravado');
  const get = api.get();
  get.analise = true;
  assert.equal(api.get().analise, false, 'get() devolve cópia');
}

// 5.2 Aceitar tudo.
{
  const a = ambiente().rodar();
  a.q('[data-rastro-aviso-aceitar]').click();
  const gravado = a.gravados();
  assert.equal(gravado.length, 1, 'aceitar grava uma vez');
  comMatch(gravado[0], /^rastro_consent=a1p1; expires=[^;]+ GMT; max-age=15552000; path=\/; SameSite=Lax; Secure$/, 'cookie a1p1 com prazo, path, SameSite e Secure');
  assert.deepEqual(a.eventos.map((e) => ({ ...e })), [{ analise: true, publicidade: true, decidido: true }], 'rastro:consent com o estado');
  assert.deepEqual(a.updates(), [TODOS_CONCEDIDOS], 'Consent Mode: tudo concedido');
  assert.equal(a.aviso().hidden, true, 'aviso fecha');
  assert.equal(a.q('[data-rastro-flutuante]').hidden, false, 'botão fixo aparece depois da escolha');
}

// 5.3 Parcial (só análise) pelo Personalizar.
{
  const a = ambiente({ cookies: { _fbp: '1', _ga: '1' } }).rodar();
  const personalizar = a.q('[data-rastro-aviso-personalizar]');
  personalizar.click();
  const painel = a.q('[data-rastro-aviso-preferencias]');
  assert.equal(painel.hidden, false, 'Personalizar abre as finalidades');
  assert.equal(personalizar.getAttribute('aria-expanded'), 'true');
  const caixas = a.doc.querySelectorAll('[data-rastro-finalidade]');
  assert.deepEqual(caixas.map((c) => c.checked), [false, false], 'caixas desmarcadas por padrão');
  caixas[0].checked = true;
  a.q('[data-rastro-aviso-salvar]').click();
  comMatch(a.gravados()[0], /^rastro_consent=a1p0;/, 'cookie a1p0');
  assert.deepEqual({ ...a.win.rastro.consent.get() }, { analise: true, publicidade: false, decidido: true });
  assert.deepEqual(a.updates(), [SO_ANALISE], 'analytics concedido, as três de anúncio negadas');
  assert.equal(a.jarra.has('_fbp'), false, 'publicidade negada: cookie de anúncio apagado');
  assert.equal(a.jarra.has('_ga'), true, 'análise aceita: cookie de análise fica');
  assert.equal(a.eventos.length, 1);
}

// 5.4 Recusar: limpeza da lista conhecida, sem recarga quando nada carregou.
{
  const a = ambiente({
    protocolo: 'http:',
    cookies: Object.assign({ sessao_loja: 'x', outro: 'y' }, MEDICAO),
    local: { ph_phc_teste_posthog: '{}', __ph_x: '1', carrinho: '2' },
    sessao: { ph_sessao: '1', aba: '3' },
  }).rodar();
  a.q('[data-rastro-aviso-recusar]').click();
  comMatch(a.gravados()[0], /^rastro_consent=a0p0; .*SameSite=Lax$/, 'cookie a0p0 sem Secure em http');
  for (const nome of Object.keys(MEDICAO)) {
    assert.equal(a.jarra.has(nome), false, `revogação apaga ${nome}`);
  }
  assert.equal(a.jarra.get('sessao_loja'), 'x', 'cookie de outro uso fica');
  assert.deepEqual([...a.win.localStorage.dados.keys()], ['carrinho'], 'localStorage do PostHog limpo, o resto fica');
  assert.deepEqual([...a.win.sessionStorage.dados.keys()], ['aba'], 'sessionStorage do PostHog limpo, o resto fica');
  assert.ok(a.escritas.some((t) => t.startsWith('_ga=;') && t.includes('domain=.exemplo.com.br')), 'apaga também no domínio pai');
  assert.ok(a.escritas.some((t) => t.startsWith('_ga=;') && t.includes('domain=loja.exemplo.com.br')), 'apaga no domínio do site');
  assert.equal(a.win.location.recargas, 0, 'sem tag carregada: sem recarga');
  assert.deepEqual(a.eventos.map((e) => ({ ...e })), [{ analise: false, publicidade: false, decidido: true }], 'recusa também é escolha (evento)');
  assert.deepEqual(a.updates(), [TODOS_NEGADOS], 'recusa confirma o negado no Consent Mode');
}

// 5.5 Revogação depois do aceite, com tag carregada: nega, apaga e recarrega.
{
  const a = ambiente({
    cookies: Object.assign({ rastro_consent: 'a1p1' }, MEDICAO),
    window: { rastro: { tags: { carregado: () => true } } },
  });
  a.rodar();
  assert.deepEqual(a.updates(), [TODOS_CONCEDIDOS], 'escolha guardada: update antes de qualquer tag');
  assert.deepEqual(a.eventos.map((e) => ({ ...e })), [{ analise: true, publicidade: true, decidido: true }], 'rastro:consent uma vez na carga');
  assert.equal(a.aviso().hidden, true, 'com escolha: aviso fechado');
  a.win.rastro.consent.set({ analise: false, publicidade: false });
  assert.deepEqual(a.updates()[1], TODOS_NEGADOS, 'revogação nega no Consent Mode');
  assert.equal(a.jarra.has('_ga'), false, 'revogação apaga _ga');
  assert.equal(a.jarra.has('rastro_origem'), false, 'revogação apaga rastro_origem');
  // Com a visita de campanha ligada (manterOrigem), recusar só mantém o cookie
  // gravado como campanha e com os dois toques de campanha; na dúvida, apaga.
  const b64 = (o) => Buffer.from(JSON.stringify(o), 'utf8').toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  const atob = (s) => Buffer.from(s, 'base64').toString('binary');
  const CAMP = { f: 'instagram', s: 'instagram', c: 'black', e: '/', q: 1799999900 };
  const DIRETO = { f: 'direto', e: '/', q: 1799999000 };
  const casosOrigem = [
    ['cookie inválido', 'x', true, false],
    ['campanha com os dois toques de campanha', b64({ v: 1, p: CAMP, u: Object.assign({}, CAMP, { k: 'gclid' }), a: 1799999900, o: 'campanha' }), true, true],
    ['campanha só com a primeira visita', b64({ v: 1, p: CAMP, a: 1799999900, o: 'campanha' }), true, true],
    ['campanha com primeira visita direta', b64({ v: 1, p: DIRETO, u: CAMP, a: 1799999900, o: 'campanha' }), true, false],
    ['campanha com última visita direta', b64({ v: 1, p: CAMP, u: DIRETO, a: 1799999900, o: 'campanha' }), true, false],
    ['aceite com toques de campanha', b64({ v: 1, p: CAMP, u: CAMP, a: 1799999900, o: 'aceite' }), true, false],
    ['sessão', b64({ v: 1, p: CAMP, u: CAMP, a: 1799999900, o: 'sessao' }), true, false],
    ['versão desconhecida', b64({ v: 2, p: CAMP, u: CAMP, a: 1799999900, o: 'campanha' }), true, false],
    ['campanha sem manterOrigem', b64({ v: 1, p: CAMP, u: CAMP, a: 1799999900, o: 'campanha' }), false, false],
  ];
  for (const [nome, valor, manter, fica] of casosOrigem) {
    const c = ambiente({ cookies: Object.assign({}, MEDICAO, { rastro_consent: 'a1p1', rastro_origem: valor }), consent: { manterOrigem: manter }, window: { atob } });
    c.rodar();
    c.win.rastro.consent.set({ analise: false, publicidade: false });
    assert.equal(c.jarra.has('_ga'), false, `${nome}: _ga apagado`);
    assert.equal(c.jarra.has('rastro_origem'), fica, `${nome}: rastro_origem ${fica ? 'fica' : 'apagado'}`);
  }
  {
    // Sem atob no navegador: na dúvida, apaga.
    const c = ambiente({ cookies: { rastro_consent: 'a1p1', rastro_origem: casosOrigem[1][1] }, consent: { manterOrigem: true } });
    c.rodar();
    c.win.rastro.consent.set({ analise: false, publicidade: false });
    assert.equal(c.jarra.has('rastro_origem'), false, 'sem atob: rastro_origem apagado');
  }
  {
    // Nome do cookie vindo da atribuição.
    const c = ambiente({ cookies: { rastro_consent: 'a1p1', origem_x: 'x', rastro_origem: 'y' }, window: { atob } });
    c.win.rastroConfig.atribuicao = { cookie: 'origem_x' };
    c.rodar();
    c.win.rastro.consent.set({ analise: false, publicidade: false });
    assert.equal(c.jarra.has('origem_x'), false, 'cookie de origem pelo nome da atribuição');
  }
  // Cookies de primeira parte do TikTok (ajuda oficial do TikTok).
  {
    const c = ambiente({ cookies: { rastro_consent: 'a1p1', ttcsid: '1', ttcsid_ABCDEFGHIJ12345678: '1', ttclid: '1' } });
    c.rodar();
    c.win.rastro.consent.set({ analise: true, publicidade: false });
    assert.deepEqual(['ttcsid', 'ttcsid_ABCDEFGHIJ12345678', 'ttclid'].filter((n) => c.jarra.has(n)), [], 'revogação apaga ttcsid, ttcsid_* e ttclid');
  }
  assert.equal(a.win.location.recargas, 1, 'tag carregada: recarrega');
  assert.equal(a.eventos.length, 2);
  // tags.carregado com erro não quebra.
  const b = ambiente({ cookies: { rastro_consent: 'a1p1' }, window: { rastro: { tags: { carregado: () => { throw new Error('x'); } } } } }).rodar();
  b.win.rastro.consent.set({ analise: false });
  assert.equal(b.win.location.recargas, 0, 'carregado() com erro: sem recarga, sem quebra');
  // O tags.js para tudo ao ouvir rastro:consent (carregado() vira falso): a
  // leitura vem antes do evento, então a página recarrega.
  for (const api of ['carregado', 'algumCarregado']) {
    const tags = { parado: false };
    tags.carregado = () => !tags.parado;
    if (api === 'algumCarregado') {
      tags.algumCarregado = () => true;
    }
    const c = ambiente({ cookies: { rastro_consent: 'a1p1' }, window: { rastro: { tags } } });
    c.doc.addEventListener('rastro:consent', (ev) => { if (!ev.detail.analise) { tags.parado = true; } });
    c.rodar();
    c.win.rastro.consent.set({ analise: false, publicidade: false });
    assert.equal(tags.parado, true, `${api}: o tags.js parou`);
    assert.equal(c.win.location.recargas, 1, `${api}: recarrega mesmo com o tags.js parado pelo evento`);
  }
  // Nada carregado (algumCarregado falso): sem recarga.
  const d = ambiente({ cookies: { rastro_consent: 'a1p1' }, window: { rastro: { tags: { carregado: () => true, algumCarregado: () => false } } } }).rodar();
  d.win.rastro.consent.set({ analise: false, publicidade: false });
  assert.equal(d.win.location.recargas, 0, 'algumCarregado falso: sem recarga');
}

// 5.6 Esc com foco dentro: recusa só nesta visita.
{
  const a = ambiente().rodar();
  a.outroFoco.focus();
  a.doc.dispatchEvent(new Evento('keydown', { key: 'Escape' }));
  assert.equal(a.aviso().hidden, false, 'Esc fora do aviso não é escolha');
  a.q('[data-rastro-aviso-recusar]').focus();
  a.doc.dispatchEvent(new Evento('keydown', { key: 'Escape' }));
  assert.equal(a.aviso().hidden, true, 'Esc dentro fecha');
  assert.equal(a.win.sessionStorage.getItem('rastro_consent_visita'), '1', 'recusa da visita no sessionStorage');
  assert.deepEqual(a.gravados(), [], 'Esc não grava cookie');
  assert.equal(a.win.rastro.consent.get().decidido, false);
  const b = ambiente({ sessao: { rastro_consent_visita: '1' } }).rodar();
  assert.equal(b.aviso().hidden, true, 'na mesma visita o aviso não volta');
}

// 5.7 abrir() e botão de preferências.
{
  const a = ambiente({ cookies: { rastro_consent: 'a1p0' } }).rodar();
  const rodape = a.q('[id="rodape"]');
  rodape.focus();
  rodape.click();
  assert.equal(a.aviso().hidden, false, 'Preferências reabre o aviso');
  assert.equal(a.q('[data-rastro-aviso-preferencias]').hidden, false, 'com escolha: abre nas finalidades');
  const caixas = a.doc.querySelectorAll('[data-rastro-finalidade]');
  assert.deepEqual(caixas.map((c) => c.checked), [true, false], 'caixas mostram a escolha atual');
  assert.equal(a.doc.activeElement, caixas[0], 'aberto pelo botão: foco na primeira caixa');
  a.doc.dispatchEvent(new Evento('keydown', { key: 'Escape' }));
  assert.equal(a.aviso().hidden, true, 'Esc fecha');
  assert.equal(a.doc.activeElement, rodape, 'foco volta ao botão');
  assert.equal(a.win.sessionStorage.getItem('rastro_consent_visita'), null, 'com escolha, Esc não marca a visita');
  assert.equal(a.win.rastro.consent.abrir(), true);
}

// 5.8 Ponte: cookie e evento do aviso que o site já tem.
const PONTE = {
  cookie: 'meusite_consentimento',
  mapa: {
    analise: { analise: true, publicidade: false },
    publicidade: { analise: true, publicidade: true },
    recusado: { analise: false, publicidade: false },
  },
  evento: 'meusite:consentimento',
  campo: 'escolha',
  abrir: '[data-meusite-cookies-abrir]',
};
{
  const a = ambiente({ consent: { provedor: 'meusite', externo: PONTE, flutuante: false }, cookies: { meusite_consentimento: 'analise', _fbp: '1' } }).rodar();
  const api = a.win.rastro.consent;
  assert.equal(api.provedor, 'meusite');
  assert.deepEqual({ ...api.get() }, { analise: true, publicidade: false, decidido: true }, 'ponte: cookie analise vira só análise');
  assert.deepEqual(a.updates(), [SO_ANALISE], 'ponte: Consent Mode na carga');
  assert.deepEqual(a.eventos.length, 1, 'ponte: rastro:consent na carga');
  assert.equal(a.aviso(), null, 'ponte: sem aviso próprio');
  a.jarra.set('meusite_consentimento', 'publicidade');
  a.doc.dispatchEvent(new Evento('meusite:consentimento', { detail: { escolha: 'publicidade' } }));
  assert.deepEqual({ ...api.get() }, { analise: true, publicidade: true, decidido: true }, 'ponte: evento publicidade vira as duas');
  assert.deepEqual(a.updates()[1], TODOS_CONCEDIDOS);
  a.doc.dispatchEvent(new Evento('meusite:consentimento', { detail: { escolha: 'recusado' } }));
  assert.deepEqual({ ...api.get() }, { analise: false, publicidade: false, decidido: true }, 'ponte: recusado nega');
  assert.deepEqual(a.updates()[2], TODOS_NEGADOS);
  assert.equal(a.eventos.length, 3, 'ponte: um rastro:consent por mudança');
  a.doc.dispatchEvent(new Evento('meusite:consentimento', { detail: { escolha: 'recusado' } }));
  assert.equal(a.eventos.length, 3, 'ponte: sem mudança, sem evento');
  a.jarra.set('meusite_consentimento', 'analise');
  a.doc.dispatchEvent(new Evento('meusite:consentimento', { detail: {} }));
  assert.equal(api.get().analise, true, 'ponte: sem campo no detail, lê o cookie');
  a.doc.dispatchEvent(new Evento('meusite:consentimento', { detail: { escolha: 'inventado' } }));
  assert.deepEqual({ ...api.get() }, { analise: false, publicidade: false, decidido: false }, 'ponte: valor fora do mapa nega');
  assert.equal(api.abrir(), true, 'ponte: abrir clica no botão do site');
  assert.equal(a.botaoSite.cliques, 1);
  assert.deepEqual({ ...api.set({ analise: true, publicidade: true }) }, { analise: false, publicidade: false, decidido: false }, 'ponte: set não escolhe pelo site');
  assert.deepEqual(a.gravados(), [], 'ponte: nunca grava o cookie próprio');
  const b = ambiente({ consent: { provedor: 'meusite', externo: PONTE }, cookies: {} }).rodar();
  assert.deepEqual(b.eventos, [], 'ponte sem escolha: sem evento na carga');
  const c = ambiente({ consent: { provedor: 'meusite' } }).rodar();
  assert.equal(c.win.rastro.consent, undefined, 'ponte sem configuração: falha fechada');
}

// 5.9 WP Consent API.
{
  const categorias = { statistics: 'allow', marketing: 'deny' };
  const definidos = [];
  const janela = {
    wp_consent_type: 'optin',
    consent_api: { cookie_prefix: 'wp_consent' },
    wp_has_consent: (c) => categorias[c] === 'allow',
    wp_set_consent: (c, v) => { definidos.push([c, v]); categorias[c] = v; },
  };
  const a = ambiente({ consent: { provedor: 'wp_consent_api', flutuante: false }, cookies: { wp_consent_statistics: 'allow', wp_consent_marketing: 'deny' }, window: janela }).rodar();
  const api = a.win.rastro.consent;
  assert.deepEqual({ ...api.get() }, { analise: true, publicidade: false, decidido: true }, 'WP Consent API: statistics vira análise');
  categorias.marketing = 'allow';
  a.doc.dispatchEvent(new Evento('wp_listen_for_consent_change', { detail: { marketing: 'allow' } }));
  assert.deepEqual({ ...api.get() }, { analise: true, publicidade: true, decidido: true }, 'WP Consent API: marketing vira publicidade');
  assert.deepEqual(a.updates(), [SO_ANALISE, TODOS_CONCEDIDOS]);
  api.set({ analise: false, publicidade: false });
  assert.deepEqual(definidos, [['statistics', 'deny'], ['marketing', 'deny']], 'set passa pela API');
  assert.equal(api.get().analise, false);
  assert.equal(api.abrir(), false, 'WP Consent API: o Rastro não abre aviso');
  const b = ambiente({ consent: { provedor: 'wp_consent_api' }, window: { wp_has_consent: () => true } }).rodar();
  assert.deepEqual({ ...b.win.rastro.consent.get() }, { analise: false, publicidade: false, decidido: false }, 'sem tipo de consentimento: falha fechada');
  b.win.wp_consent_type = 'optout';
  b.doc.dispatchEvent(new Evento('wp_consent_type_defined'));
  assert.deepEqual({ ...b.win.rastro.consent.get() }, { analise: true, publicidade: true, decidido: true }, 'tipo definido depois: lê de novo');
  const c = ambiente({ consent: { provedor: 'wp_consent_api' } }).rodar();
  assert.equal(c.win.rastro.consent.get().analise, false, 'sem a função: negado');
}

// 5.10 Aceite automático.
{
  const a = ambiente({ consent: { modo: 'aceite_automatico', segundos: 2 }, cookies: { _fbp: '1' } }).rodar();
  assert.equal(a.aviso().hidden, false, 'automático: aviso aparece');
  a.avancar(1999);
  assert.deepEqual(a.gravados(), [], 'automático: antes do tempo, nada');
  a.avancar(1);
  comMatch(a.gravados()[0] || '', /^rastro_consent=a1p0;/, 'automático: grava a1p0 no fim do tempo');
  assert.deepEqual({ ...a.win.rastro.consent.get() }, { analise: true, publicidade: false, decidido: true }, 'automático: só análise');
  assert.deepEqual(a.updates(), [SO_ANALISE], 'automático: publicidade nunca liga sozinha');
  assert.equal(a.aviso().hidden, true, 'automático: aviso some');
  assert.equal(a.eventos.length, 1, 'automático: rastro:consent');
  assert.equal(a.q('[data-rastro-flutuante]').hidden, false, 'automático: preferências continua disponível');
  assert.equal(a.jarra.has('_fbp'), false, 'automático: publicidade negada limpa');
  for (const tipo of ['pointerenter', 'focusin', 'touchstart']) {
    const b = ambiente({ consent: { modo: 'aceite_automatico', segundos: 2 } }).rodar();
    b.avancar(1000);
    b.aviso().dispatchEvent(new Evento(tipo));
    b.avancar(60000);
    assert.deepEqual(b.gravados(), [], `automático: ${tipo} no aviso para a contagem`);
    assert.equal(b.aviso().hidden, false, `automático: depois de ${tipo} o aviso fica`);
    assert.equal(b.win.rastro.consent.get().decidido, false);
  }
  const c = ambiente({ consent: { modo: 'aceite_automatico', segundos: 5 } }).rodar();
  c.avancar(1000);
  c.q('[data-rastro-aviso-recusar]').click();
  c.avancar(60000);
  assert.equal(c.gravados().length, 1, 'automático: Recusar antes do tempo vale');
  comMatch(c.gravados()[0], /^rastro_consent=a0p0;/);
  assert.equal(c.win.rastro.consent.get().analise, false, 'automático: a recusa não é trocada');
  const d = ambiente({ consent: { modo: 'aceite_automatico' } }).rodar();
  d.q('[data-rastro-aviso-personalizar]').click();
  d.avancar(60000);
  assert.deepEqual(d.gravados(), [], 'automático: Personalizar para a contagem');
  const e = ambiente({ consent: { modo: 'aceite_automatico' }, cookies: { rastro_consent: 'a0p0' } }).rodar();
  e.avancar(60000);
  assert.deepEqual(e.gravados(), [], 'automático: com escolha guardada, sem contagem');
  const f = ambiente({ consent: { modo: 'aceite_automatico', finalidades: ['publicidade'] } }).rodar();
  f.avancar(60000);
  assert.deepEqual(f.gravados(), [], 'automático: sem destino de análise, nada a aceitar sozinho');
  const g = ambiente({ consent: { modo: 'aceite_automatico', segundos: 99 } }).rodar();
  g.avancar(1999);
  assert.deepEqual(g.gravados(), [], 'automático: segundos fora do limite voltam a 2');
  g.avancar(1);
  assert.equal(g.gravados().length, 1);
}

// 5.11 Básico.
{
  const a = ambiente({ consent: { modo: 'basico', provedor: 'meusite', externo: PONTE }, cookies: { meusite_consentimento: 'recusado' } }).rodar();
  const api = a.win.rastro.consent;
  assert.equal(api.provedor, 'proprio', 'básico ignora a ponte');
  assert.deepEqual({ ...api.get() }, { analise: true, publicidade: true, decidido: true }, 'básico: tudo concedido desde a carga');
  assert.deepEqual(a.eventos.map((e) => ({ ...e })), [{ analise: true, publicidade: true, decidido: true }], 'básico: rastro:consent uma vez');
  assert.deepEqual(a.escritas, [], 'básico: nenhum cookie gravado');
  assert.deepEqual(a.updates(), [], 'básico: o padrão do <head> já é concedido');
  assert.equal(api.abrir(), false, 'básico: nada a abrir');
  assert.deepEqual({ ...api.set({ analise: false }) }, { analise: true, publicidade: true, decidido: true }, 'básico: set não muda');
  a.doc.dispatchEvent(new Evento('meusite:consentimento', { detail: { escolha: 'recusado' } }));
  assert.equal(a.eventos.length, 1, 'básico: evento da ponte ignorado');
}

// 5.12 Falha fechada, carga dupla e fila ausente.
{
  const a = ambiente({ consent: { provedor: 'nenhum' } }).rodar();
  assert.equal(a.win.rastro.consent, undefined, 'provedor nenhum: nada carrega');
  const b = ambiente({ cookies: { rastro_consent: 'a1p1' } }).rodar();
  const primeiro = b.win.rastro.consent;
  vm.runInNewContext(codigo, b.win);
  b.avancar(0);
  assert.equal(b.win.rastro.consent, primeiro, 'carga dupla: mesma API');
  assert.equal(b.eventos.length, 1, 'carga dupla: um evento só');
  const c = ambiente({ semFila: true, cookies: { rastro_consent: 'a0p1' } }).rodar();
  assert.ok(Array.isArray(c.win.dataLayer) && typeof c.win.gtag === 'function', 'sem a fila: cria dataLayer e gtag');
  assert.deepEqual(c.updates(), [{ ad_storage: 'granted', analytics_storage: 'denied', ad_user_data: 'granted', ad_personalization: 'granted' }]);
  const d = ambiente({ cookies: { rastro_consent: '"><img>' } }).rodar();
  assert.equal(d.aviso().hidden, false, 'cookie inválido: pergunta de novo');
  const e = ambiente({ window: { sessionStorage: { getItem() { throw new Error('bloqueado'); }, setItem() { throw new Error('bloqueado'); } } } }).rodar();
  e.q('[data-rastro-aviso-recusar]').focus();
  e.doc.dispatchEvent(new Evento('keydown', { key: 'Escape' }));
  assert.equal(e.aviso().hidden, true, 'sessionStorage bloqueado não quebra');
  const f = ambiente({ consent: { finalidades: ['analise'] } }).rodar();
  f.q('[data-rastro-aviso-aceitar]').click();
  comMatch(f.gravados()[0], /^rastro_consent=a1p0;/, 'Aceitar só vale para as finalidades com destino');
}

// 6. Manual.
const manual = ler(DOC);
for (const trecho of ['rastro_consent_provedores', 'rastro_aviso_botao_flutuante', '[rastro_preferencias]', 'rastro:consent', 'wp_listen_for_consent_change', 'aceite_automatico', 'basico', 'Não conferido', 'tests/php/rastro-consentimento.test.php']) {
  tem(manual, trecho, `CONSENTIMENTO.md cita ${trecho}`);
}

// 7. php -l e teste PHP.
const php = spawnSync('php', ['-v'], { encoding: 'utf8' });
if (php.status === 0) {
  for (const arquivo of [PHP_CONSENT, PHP_AVISO, TESTE_PHP]) {
    const l = spawnSync('php', ['-l', path.join(root, arquivo)], { encoding: 'utf8' });
    assert.equal(l.status, 0, `php -l ${arquivo}:\n${l.stdout}${l.stderr}`);
  }
  const r = spawnSync('php', [path.join(root, TESTE_PHP)], { encoding: 'utf8' });
  assert.equal(r.status, 0, `${TESTE_PHP} falhou:\n${r.stderr}${r.stdout}`);
  process.stdout.write(r.stdout);
} else {
  console.log('php não encontrado no PATH: php -l e tests/php/rastro-consentimento.test.php pulados.');
}

console.log('Rastro consentimento contract: PASS');
