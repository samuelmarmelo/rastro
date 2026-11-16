/**
 * Contrato da frente T6 do Rastro (relatórios do WooCommerce): estrutura,
 * proibições e o relatorios.js rodando num DOM mínimo falso (SVG por série,
 * legenda, teclado, tabela ordenável, paginação, AJAX, nada de HTML montado
 * a partir de dados). Roda também o teste PHP quando há `php` no PATH.
 *
 * Contrato: docs/CONTRATO.md, seção 8.
 */
'use strict';

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
const HTML_CRU = new RegExp(['inner', 'HTML'].join('') + '|' + ['outer', 'HTML'].join('') + '|insertAdjacentHTML|document\\.write|\\beval\\(|new Function');

// 1. Arquivos da frente.
const arquivos = [
  R + 'relatorios/dados.php',
  R + 'relatorios/relatorios.php',
  R + 'relatorios/xlsx.php',
  R + 'assets/js/relatorios.js',
  R + 'assets/css/relatorios.css',
  'tests/validate-rastro-relatorios-contract.js',
  'tests/php/rastro-relatorios.test.php',
  'docs/RELATORIOS.md',
];
for (const arquivo of arquivos) {
  assert.ok(fs.existsSync(path.join(root, arquivo)), `existe ${arquivo}`);
  semMatch(lerBruto(arquivo), /\r/, `${arquivo} com fim de linha LF`);
  const texto = ler(arquivo);
  semMatch(texto, TRAVESSAO, `${arquivo} sem travessão`);
}
for (const arquivo of arquivos.filter((a) => a.startsWith(R))) {
  const texto = ler(arquivo);
  semMatch(texto, /animalart/i, `${arquivo} sem dependência do Animalart`);
  semMatch(texto, HTML_CRU, `${arquivo} sem HTML montado de texto`);
}

const dados = ler(R + 'relatorios/dados.php');
const tela = ler(R + 'relatorios/relatorios.php');
const js = ler(R + 'assets/js/relatorios.js');
const css = ler(R + 'assets/css/relatorios.css');

// 2. Dados (seção 8): assinaturas, leitura, fórmulas, cache.
for (const php of [dados, tela]) {
  comMatch(php, /^<\?php\n[\s\S]*?\ndefined\( 'ABSPATH' \) \|\| exit;\n/, 'guarda ABSPATH');
  semMatch(php, /\$wpdb|\bSELECT\b|\bINSERT\b|\bUPDATE\s+\w+\s+SET\b/, 'sem SQL próprio');
  semMatch(php, /delete_option\(|delete_transient\(/, 'nada é apagado');
  semMatch(php, /\$_REQUEST\b|\$_COOKIE\b/, 'sem $_REQUEST nem cookies');
  semMatch(php, /function rastro_pedido_origem\b/, 'não redefine a leitura da T5');
}
tem(dados, 'function rastro_relatorio_dados( array $args ): array');
tem(dados, 'function rastro_relatorio_agregar( array $pedidos, string $dimensao');
tem(dados, "function_exists( 'rastro_pedido_origem' )");
tem(dados, "'(sem dados)'");
tem(dados, 'const RASTRO_RELATORIO_PAGINA = 200;');
tem(dados, 'const RASTRO_RELATORIO_MAX_DIAS = 366;');
tem(dados, 'const RASTRO_RELATORIO_SERIES = 8;');
tem(dados, 'const RASTRO_RELATORIO_CACHE_PRAZO = 600;');
tem(dados, 'wc_get_orders( rastro_relatorio_consulta(');
for (const trecho of ["'paginate'     => true", "'type'         => 'shop_order'", "'limit'        => RASTRO_RELATORIO_PAGINA", "$limites[0] . '...' . $limites[1]"]) {
  tem(dados, trecho, `consulta com ${trecho}`);
}
for (const gancho of ['woocommerce_order_status_changed', 'woocommerce_order_refunded', 'woocommerce_refund_deleted']) {
  comMatch(dados, new RegExp(`add_action\\( '${gancho}', 'rastro_relatorio_cache_invalidar'`), `invalida o cache em ${gancho}`);
}
tem(dados, 'update_option( RASTRO_RELATORIO_CACHE_OPCAO, rastro_relatorio_cache_versao() + 1, false )', 'invalidação por versão');
tem(dados, "'liquida' => max( 0, $bruta - $desconto - $itens_reembolsados )", 'fórmula da venda líquida: só os itens reembolsados (revisão de 2026-11-11)');
tem(dados, "$reembolso->get_items( 'line_item' )", 'itens reembolsados pelas linhas de produto de get_refunds()');
tem(dados, "'return'       => 'ids'", 'consulta só com IDs');
tem(dados, '$woo->order_factory->get_orders( $ids, true )', 'pedidos da página em lote pela fábrica do WooCommerce');
tem(dados, '_prime_post_caches( $ids, false, true )', 'caches dos posts preparados sem a fábrica');
tem(dados, 'wp_cache_flush_runtime()', 'memória liberada por página');
tem(dados, 'const RASTRO_RELATORIO_MAX_PAGINAS = 50;', 'até 10 mil pedidos por relatório');
tem(dados, "'total'   => max( 0, $total - $reembolsado )", 'fórmula da venda total');
tem(dados, "get_items( 'line_item' )");
const dimensoes = ['fonte', 'entrada', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];
const listaDim = dados.slice(dados.indexOf('function rastro_relatorio_dimensoes'), dados.indexOf('function rastro_relatorio_modelos'));
assert.deepEqual([...listaDim.matchAll(/'([a-z_]+)'\s+=>/g)].map((m) => m[1]), dimensoes, 'dimensões da seção 8, na ordem');
const listaPer = dados.slice(dados.indexOf('function rastro_relatorio_periodos'), dados.indexOf('function rastro_relatorio_vazio'));
assert.deepEqual([...listaPer.matchAll(/'([a-z0-9_]+)'\s+=>/g)].map((m) => m[1]), ['hoje', 'ontem', '7d', '30d', 'este_mes', 'mes_passado', '90d', '12m', 'personalizado'], 'períodos da seção 8');

// 3. Tela, AJAX e CSV.
comMatch(tela, /add_filter\( 'rastro_admin_abas', 'rastro_relatorios_aba' \)/);
tem(tela, "'ordem'      => 50");
tem(tela, "const RASTRO_RELATORIO_CAPACIDADE = 'manage_woocommerce';");
semMatch(tela, /nopriv/, 'nenhuma rota para visitante');
const ajax = tela.slice(tela.indexOf('function rastro_relatorios_ajax'), tela.indexOf("add_action( 'wp_ajax_' . RASTRO_RELATORIO_ACAO,"));
assert.ok(ajax.indexOf('current_user_can(') < ajax.indexOf('check_ajax_referer(') && ajax.indexOf('check_ajax_referer(') < ajax.indexOf('rastro_relatorio_dados('), 'AJAX: capacidade e nonce antes dos dados');
const autorizar = tela.slice(tela.indexOf('function rastro_relatorio_csv_autorizar'), tela.indexOf('function rastro_relatorio_csv()'));
assert.ok(autorizar.indexOf('current_user_can(') < autorizar.indexOf('check_admin_referer( RASTRO_RELATORIO_ACAO_CSV )'), 'CSV: capacidade e nonce');
const baixar = tela.slice(tela.indexOf('function rastro_relatorio_csv()'), tela.indexOf("add_action( 'wp_ajax_' . RASTRO_RELATORIO_ACAO_CSV"));
assert.ok(baixar.indexOf('rastro_relatorio_csv_autorizar()') < baixar.indexOf('rastro_relatorio_csv_preparar('), 'CSV: autoriza antes de ler');
tem(tela, "add_action( 'wp_ajax_' . RASTRO_RELATORIO_ACAO_CSV, 'rastro_relatorio_csv' );");
tem(tela, "add_action( 'admin_post_' . RASTRO_RELATORIO_ACAO_CSV, 'rastro_relatorio_csv' );");
tem(tela, '"\\xEF\\xBB\\xBF"', 'CSV com BOM');
tem(tela, "fputcsv( $fluxo, array_map( 'strval', array_values( (array) $linha ) ), ';', '\"', '' );", 'CSV com ;');
tem(tela, "array( '=', '+', '-', '@', \"\\t\", \"\\r\" )", 'proteção contra fórmula');
tem(tela, "rastro_registrar_script( 'rastro-relatorios', 'relatorios.js' )", 'script registrado pela base');

// Planilha do Excel (2026-11-13): mesma regra do CSV, nonce próprio, sem biblioteca.
const autorizarXlsx = tela.slice(tela.indexOf('function rastro_relatorio_xlsx_autorizar'), tela.indexOf('function rastro_relatorio_xlsx()'));
assert.ok(autorizarXlsx.indexOf('current_user_can(') >= 0 && autorizarXlsx.indexOf('current_user_can(') < autorizarXlsx.indexOf('check_admin_referer( RASTRO_RELATORIO_ACAO_XLSX )'), 'Excel: capacidade e nonce');
const baixarXlsx = tela.slice(tela.indexOf('function rastro_relatorio_xlsx()'), tela.indexOf("add_action( 'wp_ajax_' . RASTRO_RELATORIO_ACAO_XLSX"));
assert.ok(baixarXlsx.indexOf('rastro_relatorio_xlsx_autorizar()') >= 0 && baixarXlsx.indexOf('rastro_relatorio_xlsx_autorizar()') < baixarXlsx.indexOf('rastro_relatorio_xlsx_preparar('), 'Excel: autoriza antes de ler');
tem(tela, "add_action( 'wp_ajax_' . RASTRO_RELATORIO_ACAO_XLSX, 'rastro_relatorio_xlsx' );");
tem(tela, "add_action( 'admin_post_' . RASTRO_RELATORIO_ACAO_XLSX, 'rastro_relatorio_xlsx' );");
tem(tela, "require_once __DIR__ . '/xlsx.php';", 'gerador do Excel carregado');
tem(tela, "esc_html__( 'Baixar Excel', 'rastro' )", 'botão Baixar Excel');
tem(tela, "esc_html__( 'Baixar CSV', 'rastro' )", 'CSV continua');
const xlsxPhp = ler(R + 'relatorios/xlsx.php');
tem(xlsxPhp, "defined( 'ABSPATH' ) || exit;");
tem(xlsxPhp, 't="inlineStr"', 'texto em linha (nunca fórmula)');
semMatch(xlsxPhp, /<f>|<f |ZipArchive|PhpSpreadsheet|vendor/, 'sem fórmula, sem extensão zip, sem biblioteca');
tem(xlsxPhp, "pack( 'VvvvvvVVVvv', 0x04034b50", 'ZIP montado no PHP');
tem(xlsxPhp, 'ENT_QUOTES | ENT_XML1', 'XML escapado');

tem(tela, "'window.rastroRelatorio=' . rastro_json(", 'configuração por rastro_json');
semMatch(tela, /<script/, 'sem <script> impresso');
tem(tela, "rastro_relatorios_url( 'woocommerce' )", 'link para a aba WooCommerce');

// As partes que o JS procura existem na tela.
const partesJs = [...js.matchAll(/parte\(raiz, '([a-z-]+)'\)/g)].map((m) => m[1]);
assert.ok(partesJs.length >= 10, 'JS procura as partes da tela');
for (const nome of partesJs) {
  tem(tela, `data-rastro-rel="${nome}"`, `a tela tem a parte ${nome}`);
}
for (const [, nome] of js.matchAll(/\[name="([a-z]+)"\]/g)) {
  tem(tela, `name="${nome}"`, `a tela tem o campo ${nome}`);
}
for (const classe of ['rastro-rel-filtros', 'rastro-rel-datas', 'rastro-rel-csv']) {
  tem(tela, classe, `a tela tem ${classe}`);
}

// 4. JS e CSS: sem biblioteca, sem endereço externo além do namespace SVG.
const externosJs = (js.match(/https?:\/\/[^\s'")]+/g) || []).filter((u) => u !== 'http://www.w3.org/2000/svg');
assert.deepEqual(externosJs, [], 'relatorios.js sem endereço externo');
semMatch(js, /\brequire\(|\bimport\s|jQuery|\$\(/, 'JS puro');
tem(js, 'document.createElementNS(SVG,');
tem(js, '.textContent = String(');
semMatch(css, /@import|url\(|https?:/, 'CSS sem recurso externo');
tem(css, 'prefers-reduced-motion');
const node = spawnSync(process.execPath, ['--check', path.join(root, R + 'assets/js/relatorios.js')], { encoding: 'utf8' });
assert.equal(node.status, 0, `node --check relatorios.js:\n${node.stderr}`);

// 5. DOM mínimo falso.
const SVG = 'http://www.w3.org/2000/svg';
let todos = [];

class No {
  constructor(tag, ns, doc) {
    this.localName = String(tag).toLowerCase();
    this.tagName = this.localName.toUpperCase();
    this.namespaceURI = ns;
    this.ownerDocument = doc;
    this.childNodes = [];
    this.parentNode = null;
    this.atributos = {};
    this.texto = '';
    this.ouvintes = {};
    this.hidden = false;
    this.value = '';
    this.clientWidth = 720;
    todos.push(this);
  }
  get firstChild() { return this.childNodes[0] || null; }
  get children() { return this.childNodes; }
  appendChild(filho) {
    if (filho.parentNode) { filho.parentNode.removeChild(filho); }
    filho.parentNode = this;
    this.childNodes.push(filho);
    return filho;
  }
  removeChild(filho) {
    const i = this.childNodes.indexOf(filho);
    assert.ok(i >= 0, 'removeChild de um filho');
    this.childNodes.splice(i, 1);
    filho.parentNode = null;
    return filho;
  }
  setAttribute(nome, valor) { this.atributos[nome] = String(valor); if (nome === 'id') { this.id = String(valor); } }
  getAttribute(nome) { return Object.prototype.hasOwnProperty.call(this.atributos, nome) ? this.atributos[nome] : null; }
  removeAttribute(nome) { delete this.atributos[nome]; }
  hasAttribute(nome) { return Object.prototype.hasOwnProperty.call(this.atributos, nome); }
  get className() { return this.getAttribute('class') || ''; }
  get textContent() { return this.texto + this.childNodes.map((c) => c.textContent).join(''); }
  set textContent(v) { this.childNodes = []; this.texto = String(v); }
  addEventListener(tipo, fn) { (this.ouvintes[tipo] = this.ouvintes[tipo] || []).push(fn); }
  disparar(tipo, extra) {
    const evento = Object.assign({ type: tipo, target: this, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; } }, extra || {});
    for (const fn of this.ouvintes[tipo] || []) { fn.call(this, evento); }
    return evento;
  }
  click() { return this.disparar('click'); }
  focus() { this.ownerDocument.activeElement = this; }
  descendentes() {
    const saida = [];
    for (const c of this.childNodes) { saida.push(c, ...c.descendentes()); }
    return saida;
  }
  confere(seletor) {
    const m = /^([a-z0-9]+)?(?:#([\w-]+))?((?:\.[\w-]+)*)(?:\[([\w-]+)(?:="([^"]*)")?\])?$/i.exec(seletor);
    assert.ok(m, `seletor simples: ${seletor}`);
    if (m[1] && this.localName !== m[1].toLowerCase()) { return false; }
    if (m[2] && this.getAttribute('id') !== m[2]) { return false; }
    if (m[3]) {
      const classes = this.className.split(/\s+/);
      for (const c of m[3].split('.').filter(Boolean)) { if (!classes.includes(c)) { return false; } }
    }
    if (m[4]) {
      if (!this.hasAttribute(m[4])) { return false; }
      if (m[5] !== undefined && this.getAttribute(m[4]) !== m[5]) { return false; }
    }
    return true;
  }
  querySelectorAll(seletor) { return this.descendentes().filter((n) => n.confere(seletor)); }
  querySelector(seletor) { return this.querySelectorAll(seletor)[0] || null; }
}
for (const proibido of ['innerHTML', 'outerHTML']) {
  Object.defineProperty(No.prototype, proibido, {
    get() { throw new Error(`${proibido} usado`); },
    set() { throw new Error(`${proibido} usado`); },
  });
}
No.prototype.insertAdjacentHTML = () => { throw new Error('insertAdjacentHTML usado'); };

function criarDocumento() {
  const doc = {
    readyState: 'complete',
    activeElement: null,
    createElement(tag) { return new No(tag, 'http://www.w3.org/1999/xhtml', doc); },
    createElementNS(ns, tag) { return new No(tag, ns, doc); },
    addEventListener() {},
    getElementById(id) { return doc.body.querySelector('#' + id); },
    write() { throw new Error('document.write usado'); },
  };
  doc.body = doc.createElement('body');
  return doc;
}

function el(doc, pai, tag, atributos, texto) {
  const n = doc.createElement(tag);
  for (const [k, v] of Object.entries(atributos || {})) { n.setAttribute(k, v); }
  if (texto !== undefined) { n.textContent = texto; }
  if (atributos && 'value' in atributos) { n.value = atributos.value; }
  pai.appendChild(n);
  return n;
}

// Monta a mesma estrutura que rastro_relatorios_tela() imprime.
function montarTela(doc) {
  const raiz = el(doc, doc.body, 'div', { id: 'rastro-relatorios', class: 'rastro-relatorios' });
  const nav = el(doc, raiz, 'nav', { class: 'rastro-rel-dimensoes' });
  for (const d of dimensoes) {
    el(doc, nav, 'a', { class: 'rastro-rel-dimensao' + (d === 'fonte' ? ' is-atual' : ''), 'data-dimensao': d, href: '?dimensao=' + d }, d);
  }
  const form = el(doc, raiz, 'form', { class: 'rastro-rel-filtros' });
  el(doc, form, 'input', { type: 'hidden', name: 'dimensao', value: 'fonte' });
  el(doc, form, 'select', { name: 'periodo', value: '30d' });
  const datas = el(doc, form, 'p', { class: 'rastro-rel-campo rastro-rel-datas' });
  el(doc, datas, 'input', { name: 'de', value: '2026-11-01' });
  el(doc, datas, 'input', { name: 'ate', value: '2026-11-05' });
  el(doc, form, 'select', { name: 'modelo', value: 'primeira' });
  el(doc, form, 'a', { class: 'button rastro-rel-xlsx', href: '#' });
  el(doc, form, 'a', { class: 'button rastro-rel-csv', href: '#' });
  for (const parte of ['periodo', 'mensagem', 'totais', 'grafico', 'legenda', 'grafico-tabela', 'tabela', 'paginacao']) {
    el(doc, raiz, 'div', { 'data-rastro-rel': parte });
  }
  el(doc, raiz, 'select', { 'data-rastro-rel': 'metrica', value: 'total' });
  el(doc, raiz, 'select', { 'data-rastro-rel': 'por-pagina', value: '25' });
  return raiz;
}

const DIAS = ['2026-11-01', '2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05'];
const PERIGOSO = '<img src=x onerror=alert(1)>';

function payload(dimensao) {
  const linhas = [];
  for (let i = 0; i < 30; i++) {
    linhas.push({ valor: i === 3 ? PERIGOSO : 'valor-' + i, pedidos: (i * 7) % 11 + 1, bruta: 100000 - i * 1000, liquida: 90000 - i * 1000, total: 110000 - i * 1000 });
  }
  const series = [];
  for (let i = 0; i < 8; i++) {
    series.push({
      nome: i === 7 ? 'Outras' : linhas[i].valor,
      outras: i === 7,
      pedidos: DIAS.map((_, d) => (i + d) % 4),
      total: DIAS.map((_, d) => (i === 0 && d === 2 ? 100000 : (i + 1) * 1000 * (d + 1))),
    });
  }
  return {
    filtros: { dimensao: dimensao || 'fonte', modelo: 'primeira', periodo: '30d', inicio: DIAS[0], fim: DIAS[4] },
    moeda: 'BRL',
    truncado: false,
    erro: '',
    linhas,
    totais: { valores: 30, pedidos: 180, bruta: 2565000, liquida: 2265000, total: 2865000 },
    serie: { dias: DIAS, series },
  };
}

const textos = {
  valores: 'Valores', pedidos: 'Pedidos', bruta: 'Venda bruta', liquida: 'Venda líquida', total: 'Venda total', dia: 'Dia',
  carregando: 'Carregando', pronto: 'Pronto', erro: 'Erro', vazio: 'Vazio', semSerie: 'Tudo oculto', grafico: 'Gráfico',
  ordenar: 'Ordenar por', anterior: 'Anterior', proxima: 'Próxima', pagina: 'Página', de: 'de', truncado: 'Truncado',
  woocommerce: 'Sem WooCommerce', mostrarSerie: 'Mostrar',
};

const pedidosFetch = [];
let respostaFetch = null;
const doc = criarDocumento();
const raiz = montarTela(doc);
const janela = {
  document: doc,
  rastroRelatorio: {
    ajax: 'https://loja.exemplo.com.br/wp-admin/admin-ajax.php', acao: 'rastro_relatorio', nonce: 'n-dados',
    csv: 'https://loja.exemplo.com.br/wp-admin/admin-post.php', csvNonce: 'n-csv', csvAcao: 'rastro_relatorio_csv',
    xlsxNonce: 'n-xlsx', xlsxAcao: 'rastro_relatorio_xlsx',
    inicial: payload(), dimensoes: { fonte: 'Fonte de tráfego', utm_source: 'utm_source' }, idioma: 'pt-BR', textos,
  },
  fetch(url, opcoes) {
    pedidosFetch.push({ url, opcoes });
    return Promise.resolve({ json: () => Promise.resolve(respostaFetch) });
  },
};
const contexto = vm.createContext({ window: janela, document: doc, Intl, Math, Number, String, Object, Array, Promise, parseInt, encodeURIComponent, console });
vm.runInContext(lerBruto(R + 'assets/js/relatorios.js'), contexto, { filename: 'relatorios.js' });

const api = janela.rastro && janela.rastro.relatorios;
assert.ok(api && typeof api.montar === 'function', 'window.rastro.relatorios.montar');
assert.ok(api.atual, 'montou sozinho com #rastro-relatorios e window.rastroRelatorio');
assert.equal(raiz.getAttribute('data-rastro-montado'), '1');
const parte = (nome) => raiz.querySelector(`[data-rastro-rel="${nome}"]`);

// Gráfico: um <svg> e um caminho por série, com o d calculado.
let svg = parte('grafico').querySelector('svg');
assert.ok(svg && svg.namespaceURI === SVG, 'gráfico em SVG com createElementNS');
assert.equal(svg.getAttribute('role'), 'img');
assert.equal(svg.getAttribute('tabindex'), '0', 'gráfico com foco por teclado');
let caminhos = svg.querySelectorAll('path');
assert.equal(caminhos.length, 8, '8 séries: 7 maiores e Outras');
for (const [i, p] of caminhos.entries()) {
  assert.equal(p.namespaceURI, SVG);
  comMatch(p.getAttribute('d'), /^M[\d.]+ [\d.]+( L[\d.]+ [\d.]+){4}$/, 'um ponto por dia');
  tem(p.className, 'rastro-rel-cor-' + i);
  assert.equal(p.getAttribute('fill'), 'none');
}
tem(caminhos[7].className, 'rastro-rel-outras', 'Outras tracejada');
assert.equal(caminhos[0].querySelector('title').textContent, 'valor-0', 'nome da série no <title>');
// O maior valor (100000 no dia 3) toca o topo da área (y = 12).
tem(caminhos[0].getAttribute('d'), ' L' + (72 + 0.5 * (720 - 88)).toFixed(1) + ' 12.0', 'escala pelo maior valor');
assert.equal(api.tetoRedondo(83000), 100000);
assert.equal(api.tetoRedondo(1800), 2000);
assert.equal(api.tetoRedondo(0), 1);
assert.equal(api.caminho([0, 10], { x: 0, y: 0, l: 100, a: 50 }, 10), 'M0.0 50.0 L100.0 0.0');

// Legenda: botões com aria-pressed, clique oculta e mostra.
let botoes = parte('legenda').querySelectorAll('button');
assert.equal(botoes.length, 8, 'legenda com 8 botões');
assert.ok(botoes.every((b) => b.getAttribute('aria-pressed') === 'true' && b.getAttribute('type') === 'button'));
assert.equal(botoes[3].textContent, PERIGOSO, 'nome perigoso só como texto');
botoes[0].click();
caminhos = parte('grafico').querySelectorAll('path');
assert.equal(caminhos.length, 7, 'série oculta sai do gráfico');
assert.ok(!caminhos.some((p) => p.getAttribute('data-serie') === '0'));
botoes = parte('legenda').querySelectorAll('button');
assert.equal(botoes[0].getAttribute('aria-pressed'), 'false');
tem(botoes[0].className, 'is-oculta');
assert.equal(doc.activeElement, botoes[0], 'foco volta para o botão da legenda');
botoes[0].click();
assert.equal(parte('grafico').querySelectorAll('path').length, 8, 'série volta');
for (let i = 0; i < 8; i++) { parte('legenda').querySelectorAll('button')[i].click(); }
assert.equal(parte('grafico').querySelectorAll('path').length, 0);
assert.equal(parte('grafico').textContent, 'Tudo oculto', 'aviso com tudo oculto');
for (let i = 0; i < 8; i++) { parte('legenda').querySelectorAll('button')[i].click(); }
assert.equal(parte('grafico').querySelectorAll('path').length, 8);

// Teclado no gráfico: setas percorrem os dias e a leitura sai em texto.
svg = parte('grafico').querySelector('svg');
let ev = svg.disparar('keydown', { key: 'ArrowRight' });
assert.ok(ev.defaultPrevented, 'seta tratada');
assert.ok(parte('grafico').querySelector('.rastro-rel-cursor'), 'cursor desenhado');
let leitura = parte('grafico').querySelector('.rastro-rel-leitura');
assert.equal(leitura.getAttribute('aria-live'), 'polite');
tem(leitura.textContent, '01/11/2026');
tem(leitura.textContent, 'valor-0: ');
parte('grafico').querySelector('svg').disparar('keydown', { key: 'End' });
tem(parte('grafico').querySelector('.rastro-rel-leitura').textContent, '05/11/2026');
assert.equal(doc.activeElement, parte('grafico').querySelector('svg'), 'foco fica no gráfico');
parte('grafico').querySelector('svg').disparar('keydown', { key: 'Escape' });
assert.equal(parte('grafico').querySelector('.rastro-rel-cursor'), null);
assert.equal(parte('grafico').querySelector('svg').disparar('keydown', { key: 'a' }).defaultPrevented, false, 'outras teclas passam');

// Tabela equivalente do gráfico.
let tg = parte('grafico-tabela').querySelector('table');
assert.equal(tg.querySelector('tbody').querySelectorAll('tr').length, 5, 'uma linha por dia');
assert.equal(tg.querySelector('thead').querySelectorAll('th').length, 9, 'dia e 8 séries');
assert.ok(tg.querySelector('caption'), 'tabela com legenda');
tem(tg.querySelector('tbody').querySelectorAll('tr')[2].textContent, '03/11/2026');
const metrica = parte('metrica');
metrica.value = 'pedidos';
metrica.disparar('change');
tg = parte('grafico-tabela').querySelector('table');
semMatch(tg.querySelector('tbody').textContent, /R\$/, 'pedidos sem moeda');
metrica.value = 'total';
metrica.disparar('change');
tem(parte('grafico-tabela').querySelector('tbody').textContent, 'R$');

// Totais.
const totais = parte('totais').querySelectorAll('li');
assert.equal(totais.length, 5, 'quantidade de valores, pedidos, bruta, líquida, total');
tem(totais[0].textContent, 'Valores: Fonte de tráfego');
tem(totais[0].textContent, '30');
tem(totais[1].textContent, '180');
tem(totais[4].textContent, '28.650,00');

// Tabela principal: ordem, paginação, ordenação.
const linhasTabela = () => parte('tabela').querySelector('tbody').querySelectorAll('tr');
const primeira = () => linhasTabela()[0].querySelector('th').textContent;
assert.equal(linhasTabela().length, 25, '25 linhas por página');
assert.equal(primeira(), 'valor-0', 'padrão: venda total, maior primeiro');
assert.equal(parte('tabela').querySelector('tfoot').querySelector('th').textContent, 'Venda total');
assert.ok(parte('tabela').querySelectorAll('th').some((th) => th.textContent === PERIGOSO), 'valor perigoso como texto');
assert.ok(!todos.some((n) => n.localName === 'img'), 'nenhum <img> criado a partir de dado');
tem(parte('paginacao').textContent, 'Página 1 de 2');
parte('paginacao').querySelector('[data-ir="proxima"]').click();
assert.equal(linhasTabela().length, 5);
tem(parte('paginacao').textContent, 'Página 2 de 2');
assert.equal(parte('paginacao').querySelector('[data-ir="proxima"]').getAttribute('disabled'), 'disabled');
parte('paginacao').querySelector('[data-ir="anterior"]').click();
assert.equal(linhasTabela().length, 25);
const porPagina = parte('por-pagina');
porPagina.value = '50';
porPagina.disparar('change');
assert.equal(linhasTabela().length, 30, '50 por página');
assert.equal(parte('paginacao').querySelectorAll('button').length, 0, 'sem paginação com tudo numa página');
porPagina.value = '7';
porPagina.disparar('change');
assert.equal(linhasTabela().length, 25, 'valor fora da lista volta a 25');

const thDe = (coluna) => parte('tabela').querySelector(`[data-coluna="${coluna}"]`).parentNode;
assert.equal(thDe('total').getAttribute('aria-sort'), 'descending');
assert.equal(thDe('pedidos').getAttribute('aria-sort'), 'none');
parte('tabela').querySelector('[data-coluna="pedidos"]').click();
assert.equal(thDe('pedidos').getAttribute('aria-sort'), 'descending');
assert.equal(thDe('total').getAttribute('aria-sort'), 'none');
const porPedidos = api.ordenarLinhas(payload().linhas, 'pedidos', 'desc');
assert.equal(primeira(), porPedidos[0].valor, 'ordenado por pedidos');
assert.equal(doc.activeElement, parte('tabela').querySelector('[data-coluna="pedidos"]'), 'foco volta para o cabeçalho');
parte('tabela').querySelector('[data-coluna="pedidos"]').click();
assert.equal(thDe('pedidos').getAttribute('aria-sort'), 'ascending');
assert.equal(primeira(), api.ordenarLinhas(payload().linhas, 'pedidos', 'asc')[0].valor);
parte('tabela').querySelector('[data-coluna="liquida"]').click();
assert.equal(primeira(), 'valor-0');
parte('tabela').querySelector('[data-coluna="bruta"]').click();
parte('tabela').querySelector('[data-coluna="bruta"]').click();
assert.equal(primeira(), 'valor-29', 'bruta, menor primeiro');
assert.deepEqual(api.ordenarLinhas([{ valor: 'b', total: 1 }, { valor: 'a', total: 1 }, { valor: 'c', total: 2 }], 'total', 'desc').map((l) => l.valor), ['c', 'a', 'b'], 'empate pelo nome');
assert.deepEqual(api.ordenarLinhas([{ valor: 'x', total: 1 }, { valor: 'y', total: 2 }], 'email', 'desc').map((l) => l.valor), ['y', 'x'], 'coluna fora da lista ordena por total');

// 6. AJAX: troca de dimensão, CSV e abas atualizados; erro vira aviso.
async function esperar() {
  for (let i = 0; i < 6; i++) { await new Promise((r) => setImmediate(r)); }
}

(async () => {
  respostaFetch = { success: true, data: payload('utm_source') };
  const link = raiz.querySelector('[data-dimensao="utm_source"]');
  const clique = link.click();
  assert.ok(clique.defaultPrevented, 'link de dimensão por AJAX');
  assert.equal(pedidosFetch.length, 1);
  const pedido = pedidosFetch[0];
  assert.equal(pedido.url, janela.rastroRelatorio.ajax);
  assert.equal(pedido.opcoes.method, 'POST');
  assert.equal(pedido.opcoes.credentials, 'same-origin');
  const corpo = new URLSearchParams(pedido.opcoes.body);
  assert.equal(corpo.get('action'), 'rastro_relatorio');
  assert.equal(corpo.get('nonce'), 'n-dados');
  assert.equal(corpo.get('dimensao'), 'utm_source');
  assert.equal(corpo.get('periodo'), '30d');
  assert.equal(corpo.get('modelo'), 'primeira');
  assert.equal(corpo.get('de'), null, 'sem datas fora do personalizado');
  assert.equal(raiz.getAttribute('aria-busy'), 'true');
  await esperar();
  assert.equal(raiz.getAttribute('aria-busy'), null);
  assert.equal(parte('mensagem').textContent, 'Pronto');
  assert.equal(link.getAttribute('aria-current'), 'true', 'aba de dimensão atual');
  assert.equal(raiz.querySelector('[data-dimensao="fonte"]').getAttribute('aria-current'), null);
  assert.equal(raiz.querySelector('[name="dimensao"]').value, 'utm_source');
  const csv = new URL(raiz.querySelector('.rastro-rel-csv').getAttribute('href'));
  assert.equal(csv.pathname, '/wp-admin/admin-post.php');
  assert.equal(csv.searchParams.get('action'), 'rastro_relatorio_csv');
  assert.equal(csv.searchParams.get('_wpnonce'), 'n-csv');
  assert.equal(csv.searchParams.get('dimensao'), 'utm_source');
  const xlsx = new URL(raiz.querySelector('.rastro-rel-xlsx').getAttribute('href'));
  assert.equal(xlsx.pathname, '/wp-admin/admin-post.php');
  assert.equal(xlsx.searchParams.get('action'), 'rastro_relatorio_xlsx');
  assert.equal(xlsx.searchParams.get('_wpnonce'), 'n-xlsx');
  assert.equal(xlsx.searchParams.get('dimensao'), 'utm_source', 'Excel com a dimensão');
  tem(parte('totais').textContent, 'Valores: utm_source');
  assert.equal(parte('grafico').querySelectorAll('path').length, 8);

  // Período personalizado: espera o "Aplicar" e manda as datas.
  const periodo = raiz.querySelector('[name="periodo"]');
  periodo.value = 'personalizado';
  periodo.disparar('change');
  assert.equal(raiz.querySelector('.rastro-rel-datas').hidden, false, 'datas aparecem');
  assert.equal(pedidosFetch.length, 1, 'personalizado espera o Aplicar');
  respostaFetch = { success: true, data: Object.assign(payload('utm_source'), { filtros: { dimensao: 'utm_source', modelo: 'primeira', periodo: 'personalizado', inicio: '2026-11-01', fim: '2026-11-05' } }) };
  const envio = raiz.querySelector('form').disparar('submit');
  assert.ok(envio.defaultPrevented, 'formulário por AJAX');
  const corpo2 = new URLSearchParams(pedidosFetch[1].opcoes.body);
  assert.deepEqual([corpo2.get('periodo'), corpo2.get('de'), corpo2.get('ate')], ['personalizado', '2026-11-01', '2026-11-05']);
  await esperar();
  const csv2 = new URL(raiz.querySelector('.rastro-rel-csv').getAttribute('href'));
  assert.deepEqual([csv2.searchParams.get('de'), csv2.searchParams.get('ate')], ['2026-11-01', '2026-11-05'], 'CSV com o período');
  const xlsx2 = new URL(raiz.querySelector('.rastro-rel-xlsx').getAttribute('href'));
  assert.deepEqual([xlsx2.searchParams.get('de'), xlsx2.searchParams.get('ate')], ['2026-11-01', '2026-11-05'], 'Excel com o período');
  tem(parte('periodo').textContent, '01/11/2026 a 05/11/2026');
  periodo.value = '7d';
  respostaFetch = { success: false, data: { mensagem: 'Filtro inválido.' } };
  periodo.disparar('change');
  assert.equal(raiz.querySelector('.rastro-rel-datas').hidden, true, 'datas somem');
  await esperar();
  assert.equal(parte('mensagem').textContent, 'Filtro inválido.', 'erro do servidor como texto');
  assert.equal(parte('grafico').querySelectorAll('path').length, 8, 'dados anteriores ficam');

  // Resposta vazia: aviso no gráfico e na tabela.
  respostaFetch = { success: true, data: Object.assign(payload('fonte'), { linhas: [], totais: { valores: 0, pedidos: 0, bruta: 0, liquida: 0, total: 0 }, serie: { dias: DIAS, series: [] } }) };
  raiz.querySelector('[name="modelo"]').disparar('change');
  await esperar();
  assert.equal(parte('grafico').textContent, 'Vazio');
  assert.equal(parte('tabela').textContent, 'Vazio');
  assert.equal(parte('legenda').querySelectorAll('button').length, 0);

  // Falha de rede.
  janela.fetch = () => Promise.reject(new Error('rede'));
  raiz.querySelector('[name="modelo"]').disparar('change');
  await esperar();
  assert.equal(parte('mensagem').textContent, 'Erro');

  // 7. Manual.
  const manual = ler('docs/RELATORIOS.md');
  for (const trecho of ['rastro_relatorio_dados', 'rastro_relatorio_agregar', 'rastro_pedido_origem', '(sem dados)', 'status_relatorio', 'wc_get_orders', 'date_created', 'paginate', 'Outras', 'Não conferido', 'tests/php/rastro-relatorios.test.php']) {
    tem(manual, trecho, `RELATORIOS.md cita ${trecho}`);
  }

  // 8. php -l e teste PHP.
  const php = spawnSync('php', ['-v'], { encoding: 'utf8' });
  if (php.status === 0) {
    for (const arquivo of [R + 'relatorios/dados.php', R + 'relatorios/relatorios.php', 'tests/php/rastro-relatorios.test.php']) {
      const l = spawnSync('php', ['-l', path.join(root, arquivo)], { encoding: 'utf8' });
      assert.equal(l.status, 0, `php -l ${arquivo}:\n${l.stdout}${l.stderr}`);
    }
    const r = spawnSync('php', [path.join(root, 'tests/php/rastro-relatorios.test.php')], { encoding: 'utf8' });
    assert.equal(r.status, 0, `tests/php/rastro-relatorios.test.php falhou:\n${r.stderr}${r.stdout}`);
    process.stdout.write(r.stdout);
  } else {
    console.log('php não encontrado no PATH: php -l e tests/php/rastro-relatorios.test.php pulados.');
  }

  console.log('Rastro relatórios contract: PASS');
})().catch((erro) => {
  console.error(erro);
  process.exit(1);
});
