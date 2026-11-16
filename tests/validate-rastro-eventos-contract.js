'use strict';

// Contrato dos eventos da loja do Rastro (T4): loja/eventos.php e
// assets/js/eventos.js. Contrato: docs/CONTRATO.md
// (seções 1, 2 D3 e D4, 3.2, 6, 10, 12 e 13.2). Manual: docs/EVENTOS.md.
// Parte estática e o eventos.js num DOM mínimo falso rodam sempre no node;
// php -l e o harness tests/php/rastro-eventos.test.php rodam só quando há
// `php` no PATH.

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
// Compara objetos vindos do contexto do vm (outro realm) pelo conteúdo.
const igual = (obtido, esperado, m) => assert.deepEqual(JSON.parse(JSON.stringify(obtido)), esperado, m);

const R = 'rastro/';
const PHP = R + 'loja/eventos.php';
const JS = R + 'assets/js/eventos.js';
// Travessão (U+2014) montado pelo código, para este arquivo não conter o caractere.
const TRAVESSAO = new RegExp(String.fromCharCode(0x2014));

// 1. Arquivos da frente (todos com fim de linha LF).
const arquivos = [PHP, JS, 'tests/validate-rastro-eventos-contract.js', 'tests/php/rastro-eventos.test.php', 'docs/EVENTOS.md'];
for (const arquivo of arquivos) {
  assert.ok(fs.existsSync(path.join(root, arquivo)), `existe ${arquivo}`);
  const bruto = lerBruto(arquivo);
  semMatch(bruto, /\r/, `${arquivo} com fim de linha LF`);
  semMatch(bruto, TRAVESSAO, `${arquivo} sem travessão`);
}
const php = ler(PHP);
const js = ler(JS);
for (const [arquivo, texto] of [[PHP, php], [JS, js]]) {
  semMatch(texto, /animalart/i, `${arquivo} sem dependência do Animalart`);
  semMatch(texto, /innerHTML|outerHTML|insertAdjacentHTML|document\.write/, `${arquivo} sem escrever HTML`);
}

// 2. PHP: guarda, ganchos, eventos canônicos, compra segura (seção 6).
comMatch(php, /^<\?php\n[\s\S]*?\ndefined\( 'ABSPATH' \) \|\| exit;\n/, 'guarda ABSPATH');
tem(php, "add_filter( 'rastro_config_navegador', 'rastro_eventos_config_navegador', 20 );", 'dados pelo filtro rastro_config_navegador');
tem(php, "add_action( 'wp_enqueue_scripts', 'rastro_eventos_script', 20 );");
tem(php, "rastro_registrar_script( 'rastro-eventos', 'eventos.js' )", 'script pelo registro da base (fora dos otimizadores)');
tem(php, "rastro_config_ligado( 'eventos_loja' )", 'liga geral eventos_loja');
tem(php, "rastro_config_ligado( 'evento_' . $evento )", 'cada evento pela chave evento_*');
tem(php, "function_exists( 'WC' ) && function_exists( 'wc_get_product' )", 'só com WooCommerce');
tem(php, "rastro_config_campo( 'id_produto' )");
tem(php, "rastro_config_campo( 'valor_compra' )");
for (const evento of ['view_item_list', 'view_item', 'add_to_cart', 'remove_from_cart', 'view_cart', 'begin_checkout', 'add_payment_info', 'purchase', 'search']) {
  tem(php, `'${evento}'`, `evento ${evento} no PHP`);
}
for (const chave of ['item_id', 'item_name', 'price', 'quantity', 'item_category', 'item_variant', 'transaction_id', 'value', 'currency', 'tax', 'shipping', 'coupon', 'items', 'item_list_id', 'item_list_name', 'search_term']) {
  tem(php, `'${chave}'`, `parâmetro GA4 ${chave}`);
}
tem(php, "const RASTRO_META_COMPRA = '_rastro_compra_enviada';");
tem(php, 'const RASTRO_EVENTOS_MAX_LISTA = 20;', 'até 20 itens na lista');
tem(php, "array( 'processing', 'completed', 'on-hold' )", 'status que geram compra');
tem(php, "'event_id' => 'rastro-' . (int) $pedido->get_id()", 'event_id para deduplicar');
tem(php, "'composite_parent' => '_composite_cart_key'", 'filho do composto pelas chaves documentadas');
tem(php, "apply_filters( 'woocommerce_thankyou_order_key'", 'mesma leitura da chave que o WooCommerce');
const urlPedido = php.slice(php.indexOf('function rastro_eventos_pedido_da_url'), php.indexOf('function rastro_eventos_compra()'));
assert.ok(urlPedido.indexOf("'' === $chave") < urlPedido.indexOf('wc_get_order('), 'sem chave, o pedido nem é buscado');
assert.ok(urlPedido.indexOf('key_is_valid( $chave )') > urlPedido.indexOf('wc_get_order('), 'chave conferida no pedido buscado');
const compra = php.slice(php.indexOf('function rastro_eventos_compra()'), php.indexOf('/* Montagem para o navegador'));
assert.ok(compra.indexOf('rastro_eventos_pedido_da_url()') < compra.indexOf('has_status('), 'chave antes do status');
assert.ok(compra.indexOf('has_status(') < compra.indexOf('get_meta( RASTRO_META_COMPRA'), 'status antes da meta');
assert.ok(compra.indexOf('get_meta( RASTRO_META_COMPRA') < compra.indexOf('rastro_eventos_itens_pedido('), 'meta antes de ler os itens');
tem(compra, '$pedido->update_meta_data( RASTRO_META_COMPRA, gmdate( \'c\' ) );', 'meta em data ISO pelo pedido (HPOS)');
tem(compra, '$pedido->save_meta_data();');
semMatch(php, /update_post_meta|get_post_meta|add_post_meta/, 'meta só pelos métodos do pedido (HPOS)');
semMatch(php, /get_billing_|get_shipping_(?:first_name|last_name|company|address|city|state|postcode|country|phone)|get_customer_id|get_user_id|get_customer_ip|get_formatted_(?:billing|shipping)|get_order_key|get_current_user_id|wp_get_current_user/, 'sem leitura de dado pessoal');
semMatch(php, /\$_COOKIE|\$_POST|\$_REQUEST|HTTP_HOST|REMOTE_ADDR/, 'sem cookie, POST, host do cabeçalho ou IP');
tem(php, "do_action( 'rastro_falha', 'loja/eventos', $erro );", 'SafeWidget');
tem(php, 'rastro_deve_medir()', 'só nas páginas medidas');
tem(php, "apply_filters( 'rastro_eventos_carrinho_em_toda_pagina', false )", 'carrinho fora do carrinho só por filtro (cache)');
tem(php, "apply_filters( 'rastro_eventos_status_compra', $padrao )", 'status da compra por filtro (Pix pendente fica fora por padrão)');
tem(php, "apply_filters( 'rastro_eventos_enviar_cupom', false )", 'cupom só com o filtro (padrão false)');
tem(php, "add_option( $nome, time(), '', false )", 'trava curta da marcação da compra, sem carga automática');
tem(php, '$pedido->read_meta_data( true )', 'meta da compra lida de novo do banco depois da trava');

// 3. JS: sem dependência, sem envio próprio, fila combinada com as tags (seção 5).
semMatch(js, /\bfetch\(|XMLHttpRequest|sendBeacon|https?:\/\//, 'eventos.js não envia nada sozinho');
semMatch(js, /document\.cookie|localStorage/, 'eventos.js sem cookie e sem localStorage');
semMatch(js, /(^|[^.\w])(jQuery|\$)\(/m, 'jQuery só pelo window, quando existe');
tem(js, "'function' === typeof w.rastro.event", 'usa window.rastro.event quando existe');
tem(js, 'w.rastroFilaEventos = w.rastroFilaEventos || [];', 'fila de reserva');
tem(js, 'w.rastroFilaEventos.push( args );');
tem(js, "'rastro:evento:'", 'marca de sessão própria');
tem(js, "on( 'added_to_cart'", 'adicionar por AJAX do WooCommerce');
tem(js, "'a[href*=\"remove_item=\"]'", 'remover pelo link do WooCommerce (carrinho e minicart)');
tem(js, "'payment_method'", 'campo de pagamento do checkout clássico');

// 4. eventos.js num DOM mínimo falso.
class Lista {
  constructor() { this.itens = new Set(); }
  contains(c) { return this.itens.has(c); }
  add(c) { this.itens.add(c); }
}
function casaSimples(el, sel) {
  const m = /^([a-z]+)?((?:\.[\w-]+)*)((?:\[[^\]]+\])*)$/i.exec(sel.trim());
  if (!m) throw new Error('seletor não suportado no DOM falso: ' + sel);
  if (m[1] && m[1].toLowerCase() !== el.tag) return false;
  for (const c of (m[2] || '').split('.').filter(Boolean)) if (!el.classList.contains(c)) return false;
  for (const [, nome, op, valor] of (m[3] || '').matchAll(/\[([\w-]+)(?:([*^]?=)"([^"]*)")?\]/g)) {
    const v = el.getAttribute(nome);
    if (v === null) return false;
    if (op === '=' && v !== valor) return false;
    if (op === '^=' && !v.startsWith(valor)) return false;
    if (op === '*=' && !v.includes(valor)) return false;
  }
  return true;
}
class El {
  constructor(tag, attrs = {}, filhos = []) {
    this.tag = tag;
    this.attrs = {};
    this.classList = new Lista();
    this.filhos = [];
    this.pai = null;
    this.checked = false;
    this.valido = true;
    for (const [k, v] of Object.entries(attrs)) {
      if (k === 'class') v.split(/\s+/).filter(Boolean).forEach((c) => this.classList.add(c));
      else if (k === 'value') this.value = v;
      else if (k === 'checked') this.checked = v;
      else this.attrs[k] = String(v);
    }
    if (this.value === undefined) this.value = '';
    filhos.forEach((f) => { f.pai = this; this.filhos.push(f); });
  }
  getAttribute(n) {
    if (n === 'class') return [...this.classList.itens].join(' ');
    return Object.prototype.hasOwnProperty.call(this.attrs, n) ? this.attrs[n] : null;
  }
  matches(sel) { return sel.split(',').some((s) => casaSimples(this, s)); }
  closest(sel) { for (let e = this; e; e = e.pai) if (e.matches(sel)) return e; return null; }
  todos() { return this.filhos.flatMap((f) => [f, ...f.todos()]); }
  querySelectorAll(sel) { return this.todos().filter((e) => e.matches(sel)); }
  querySelector(sel) { return this.querySelectorAll(sel)[0] || null; }
  checkValidity() { return this.valido; }
}
function armazemFalso(quebrado = false) {
  const m = new Map();
  if (quebrado) {
    return { get length() { throw new Error('bloqueado'); }, getItem() { throw new Error('bloqueado'); }, setItem() { throw new Error('bloqueado'); }, key() { throw new Error('bloqueado'); }, removeItem() { throw new Error('bloqueado'); } };
  }
  return {
    get length() { return m.size; },
    key: (i) => [...m.keys()][i] ?? null,
    getItem: (k) => (m.has(k) ? m.get(k) : null),
    setItem: (k, v) => m.set(k, String(v)),
    removeItem: (k) => m.delete(k),
    mapa: m,
  };
}
const codigo = js;
/**
 * Roda o eventos.js numa página falsa. Opções: eventos (rastroConfig.eventos),
 * armazem, comTags (window.rastro.event registrando), jquery (falso).
 */
function pagina({ eventos, armazem = armazemFalso(), comTags = false, jquery = false, corpo = [] }) {
  const ouvintes = {};
  const body = new El('body', {}, corpo);
  const document = {
    body,
    addEventListener: (tipo, fn) => { (ouvintes[tipo] = ouvintes[tipo] || []).push(fn); },
  };
  const enviados = [];
  const jq = { handlers: {} };
  const ctx = {
    document,
    sessionStorage: armazem,
    console: { warn() {} },
    rastroConfig: { versao: '0.1.0', eventos },
    addEventListener: (tipo, fn) => { (ouvintes['window:' + tipo] = ouvintes['window:' + tipo] || []).push(fn); },
  };
  ctx.window = ctx;
  if (comTags) ctx.rastro = { event: (nome, params, opcoes) => enviados.push([nome, params, opcoes]) };
  if (jquery) {
    ctx.jQuery = (alvo) => ({
      on: (nome, fn) => { assert.equal(alvo, body, 'jQuery no body'); (jq.handlers[nome] = jq.handlers[nome] || []).push(fn); },
    });
  }
  vm.runInNewContext(codigo, ctx, { filename: 'eventos.js' });
  return {
    ctx,
    body,
    enviados,
    fila: () => ctx.rastroFilaEventos || [],
    disparar(tipo, alvo, extra = {}) { (ouvintes[tipo] || []).forEach((fn) => fn({ type: tipo, target: alvo, isTrusted: true, ...extra })); },
    ouvintes,
    jqTrigger(nome, args) { (jq.handlers[nome] || []).forEach((fn) => fn({ type: nome }, ...args)); },
    tagsChegam() { ctx.rastro = { event: (nome, params, opcoes) => enviados.push([nome, params, opcoes]) }; },
  };
}
const TODOS = ['view_item_list', 'view_item', 'add_to_cart', 'remove_from_cart', 'view_cart', 'begin_checkout', 'add_payment_info', 'purchase', 'search'];
const RACAO = { item_id: '10', item_name: 'Ração', price: 89.9, quantity: 1, item_category: 'Cães' };
const ROSA = { item_id: '22', item_name: 'Coleira', price: 35.5, quantity: 1, item_variant: 'Rosa, G' };
const COMEDOURO = { item_id: '40', item_name: 'Comedouro', price: 62, quantity: 1 };

// 4.1 Sem rastroConfig.eventos: nada acontece.
{
  const p = pagina({ eventos: undefined });
  assert.equal(Object.keys(p.ouvintes).length, 0, 'sem eventos, sem ouvintes');
  assert.equal(p.fila().length, 0);
}

// 4.2 Produto: view_item na carga, na fila enquanto as tags não chegam.
{
  const eventos = {
    moeda: 'BRL', id_produto: 'id', ligados: TODOS, produto: 10,
    carga: [{ nome: 'view_item', params: { currency: 'BRL', value: 89.9, items: [RACAO] } }],
    produtos: { 10: RACAO, 40: COMEDOURO }, variacoes: { 22: ROSA },
  };
  const qtd = new El('input', { name: 'quantity', value: '3' });
  const botao = new El('button', { type: 'submit', name: 'add-to-cart', value: '10' });
  const form = new El('form', { class: 'cart' }, [qtd, botao]);
  const p = pagina({ eventos, corpo: [form] });
  igual(p.fila(), [['view_item', { currency: 'BRL', value: 89.9, items: [RACAO] }, {}]], 'view_item na fila de reserva');
  assert.equal(p.enviados.length, 0);

  // add_to_cart no envio do formulário (ainda na fila).
  p.disparar('submit', form);
  igual(p.fila()[1], ['add_to_cart', { currency: 'BRL', value: 269.7, items: [{ ...RACAO, quantity: 3 }] }, {}], 'add_to_cart com a quantidade');
  p.disparar('submit', form);
  assert.equal(p.fila().length, 2, 'envio repetido em seguida não conta duas vezes');

  // As tags chegam: daqui em diante vai direto para window.rastro.event.
  p.tagsChegam();
  form.valido = false;
  const form2 = new El('form', { class: 'cart' }, [new El('input', { name: 'quantity', value: '1' }), new El('input', { type: 'hidden', name: 'add-to-cart', value: '40' })]);
  p.body.filhos.push(form2); form2.pai = p.body;
  form2.valido = false;
  p.disparar('submit', form2);
  assert.equal(p.enviados.length, 0, 'formulário inválido não conta');
  form2.valido = true;
  p.disparar('submit', form2);
  igual(p.enviados, [['add_to_cart', { currency: 'BRL', value: 62, items: [COMEDOURO] }, {}]], 'produto composto (comedouro) como um item só');
}

// 4.3 Variável: variação escolhida, incompleta e fora do mapa.
{
  const eventos = { moeda: 'BRL', id_produto: 'id', ligados: TODOS, produto: 20, carga: [], produtos: { 20: { item_id: '20', item_name: 'Coleira', price: 30, quantity: 1 } }, variacoes: { 22: ROSA } };
  const variacao = new El('input', { type: 'hidden', name: 'variation_id', value: '22' });
  const form = new El('form', { class: 'cart variations_form' }, [
    new El('select', { name: 'attribute_pa_cor', value: 'azul' }),
    new El('select', { name: 'attribute_pa_tamanho', value: 'p' }),
    new El('input', { name: 'quantity', value: '2' }),
    new El('input', { type: 'hidden', name: 'add-to-cart', value: '20' }),
    variacao,
  ]);
  const p = pagina({ eventos, comTags: true, corpo: [form] });
  p.disparar('submit', form);
  igual(p.enviados[0], ['add_to_cart', { currency: 'BRL', value: 71, items: [{ ...ROSA, quantity: 2 }] }, {}], 'variação do mapa com item_variant');

  const p2 = pagina({ eventos, comTags: true, corpo: [form] });
  variacao.value = '0';
  p2.disparar('submit', form);
  assert.equal(p2.enviados.length, 0, 'variação não escolhida não conta');

  const p3 = pagina({ eventos, comTags: true, corpo: [form] });
  variacao.value = '23';
  p3.disparar('submit', form);
  igual(p3.enviados[0][1].items, [{ item_id: '23', item_name: 'Coleira', price: 30, quantity: 2, item_variant: 'azul, p' }], 'variação fora do mapa: dados do pai com a escolha');
}

// 4.4 Produto agrupado: um item por quantidade preenchida.
{
  const eventos = { moeda: 'BRL', id_produto: 'id', ligados: TODOS, produto: 30, carga: [], produtos: { 10: RACAO, 22: ROSA } };
  const form = new El('form', { class: 'cart grouped_form' }, [
    new El('input', { name: 'quantity[10]', value: '2' }),
    new El('input', { name: 'quantity[22]', value: '0' }),
    new El('button', { type: 'submit', name: 'add-to-cart', value: '30' }),
  ]);
  const p = pagina({ eventos, comTags: true, corpo: [form] });
  p.disparar('submit', form);
  igual(p.enviados[0][1].items, [{ ...RACAO, quantity: 2 }], 'agrupado');
}

// 4.5 add_to_cart por AJAX nas listas (jQuery quando existe; sem jQuery, sem erro).
{
  const eventos = { moeda: 'BRL', id_produto: 'sku', ligados: TODOS, carga: [], produtos: { 10: RACAO } };
  const botaoLista = new El('a', { class: 'add_to_cart_button ajax_add_to_cart', 'data-product_id': '10', 'data-quantity': '1', 'data-product_sku': 'RAC-1' });
  const botaoDesconhecido = new El('a', { class: 'add_to_cart_button', 'data-product_id': '99', 'data-quantity': '2', 'data-product_sku': 'X-99' });
  const botaoForm = new El('button', { name: 'add-to-cart', value: '10' });
  const form = new El('form', { class: 'cart' }, [botaoForm]);
  const p = pagina({ eventos, comTags: true, jquery: true, corpo: [botaoLista, botaoDesconhecido, form] });
  p.jqTrigger('added_to_cart', [{}, 'hash', [botaoLista]]);
  p.jqTrigger('added_to_cart', [{}, 'hash', [botaoDesconhecido]]);
  p.jqTrigger('added_to_cart', [{}, 'hash', [botaoForm]]);
  p.jqTrigger('added_to_cart', [{}, 'hash', null]);
  igual(p.enviados, [
    ['add_to_cart', { currency: 'BRL', value: 89.9, items: [RACAO] }, {}],
    ['add_to_cart', { currency: 'BRL', value: 0, items: [{ item_id: 'X-99', quantity: 2 }] }, {}],
  ], 'botões das listas; o do formulário já contou no envio');
  const semJq = pagina({ eventos, comTags: true });
  assert.ok((semJq.ouvintes['window:load'] || []).length === 1, 'sem jQuery: tenta de novo no load');
  semJq.ouvintes['window:load'][0]();
}

// 4.6 remove_from_cart no carrinho e no minicart.
{
  const CHAVE = 'a1b2c3';
  const eventos = { moeda: 'BRL', id_produto: 'id', ligados: TODOS, carga: [], carrinho: { [CHAVE]: { ...COMEDOURO, quantity: 2 } } };
  const icone = new El('span', { class: 'icone' });
  const minicart = new El('a', { class: 'remover', href: '/carrinho/?remove_item=' + CHAVE + '&_wpnonce=abc', 'data-cart-key': CHAVE }, [icone]);
  const nativo = new El('a', { class: 'remove', href: '/carrinho/?remove_item=zz9&_wpnonce=abc', 'data-product_id': '10', 'data-product_sku': 'RAC-1' });
  const outro = new El('a', { href: '/carrinho/' });
  const p = pagina({ eventos, comTags: true, corpo: [minicart, nativo, outro] });
  p.disparar('click', icone);
  p.disparar('click', minicart);
  p.disparar('click', outro);
  p.disparar('click', nativo);
  igual(p.enviados, [
    ['remove_from_cart', { currency: 'BRL', value: 124, items: [{ ...COMEDOURO, quantity: 2 }] }, {}],
    ['remove_from_cart', { currency: 'BRL', value: 0, items: [{ item_id: '10', quantity: 1 }] }, {}],
  ], 'remover: item do carrinho pela chave, uma vez; sem dados, o mínimo do link');
}

// 4.7 Checkout: begin_checkout uma vez por sessão, add_payment_info uma vez.
{
  const params = { currency: 'BRL', value: 151, coupon: 'PRIMEIRA', items: [COMEDOURO] };
  const eventos = {
    moeda: 'BRL', id_produto: 'id', ligados: TODOS,
    carga: [{ nome: 'begin_checkout', params, guarda: 'begin_checkout:abc123' }],
    checkout: { params, assinatura: 'abc123' },
  };
  const pix = new El('input', { type: 'radio', name: 'payment_method', value: 'pix' });
  const cartao = new El('input', { type: 'radio', name: 'payment_method', value: 'cartao', checked: true });
  const form = new El('form', { class: 'checkout woocommerce-checkout', name: 'checkout' }, [pix, cartao]);
  const armazem = armazemFalso();
  const p = pagina({ eventos, armazem, comTags: true, corpo: [form] });
  igual(p.enviados, [['begin_checkout', params, {}]], 'begin_checkout na carga');
  assert.equal(armazem.getItem('rastro:evento:begin_checkout:abc123'), '1', 'marca na sessão');
  p.disparar('change', pix, { isTrusted: false });
  assert.equal(p.enviados.length, 1, 'mudança feita por script não conta');
  p.disparar('change', pix);
  p.disparar('change', cartao);
  p.disparar('submit', form);
  igual(p.enviados.slice(1), [['add_payment_info', { ...params, payment_type: 'pix' }, {}]], 'add_payment_info uma vez, na primeira escolha');

  // Recarga do checkout na mesma sessão: nada se repete.
  const p2 = pagina({ eventos, armazem, comTags: true, corpo: [form] });
  p2.disparar('change', cartao);
  p2.disparar('submit', form);
  assert.equal(p2.enviados.length, 0, 'recarga: begin_checkout e add_payment_info não repetem');

  // Sessão nova: forma já marcada vale no envio do checkout.
  const p3 = pagina({ eventos, comTags: true, corpo: [form] });
  p3.disparar('submit', form);
  igual(p3.enviados[1], ['add_payment_info', { ...params, payment_type: 'cartao' }, {}], 'forma já marcada no envio');

  // Carrinho mudou (assinatura nova): é outra sessão de checkout.
  const p4 = pagina({ eventos: { ...eventos, carga: [{ nome: 'begin_checkout', params, guarda: 'begin_checkout:def456' }] }, armazem, comTags: true });
  assert.equal(p4.enviados.length, 1, 'carrinho novo: begin_checkout de novo');

  // Armazenamento bloqueado: uma vez por página, sem erro.
  const p5 = pagina({ eventos, armazem: armazemFalso(true), comTags: true, corpo: [form] });
  p5.disparar('change', pix);
  p5.disparar('change', pix);
  assert.equal(p5.enviados.length, 2, 'sem sessionStorage: begin_checkout e add_payment_info uma vez cada');
}

// 4.8 Compra: uma vez por pedido na sessão; limpa as marcas do checkout.
{
  const params = { transaction_id: 'A-1077', value: 162, currency: 'BRL', tax: 0, shipping: 20.9, coupon: 'PRIMEIRA', items: [COMEDOURO] };
  const eventos = { moeda: 'BRL', id_produto: 'id', ligados: TODOS, carga: [{ nome: 'purchase', params, opcoes: { event_id: 'rastro-77' }, guarda: 'purchase:rastro-77' }] };
  const armazem = armazemFalso();
  armazem.setItem('rastro:evento:begin_checkout:abc123', '1');
  armazem.setItem('rastro:evento:add_payment_info:abc123', '1');
  armazem.setItem('outra:chave', '1');
  const p = pagina({ eventos, armazem });
  igual(p.fila(), [['purchase', params, { event_id: 'rastro-77' }]], 'purchase com event_id (na fila até as tags)');
  igual([...armazem.mapa.keys()].sort(), ['outra:chave', 'rastro:evento:purchase:rastro-77'], 'marcas do checkout limpas; a da compra fica');
  const p2 = pagina({ eventos, armazem, comTags: true });
  assert.equal(p2.enviados.length + p2.fila().length, 0, 'recarga: compra não repete');
}

// 4.9 Liga e desliga no navegador.
{
  const eventos = { moeda: 'BRL', id_produto: 'id', ligados: ['view_item'], produto: 10, carga: [{ nome: 'view_item', params: {} }, { nome: 'purchase', params: {} }], produtos: { 10: RACAO }, carrinho: { k: RACAO } };
  const form = new El('form', { class: 'cart' }, [new El('button', { name: 'add-to-cart', value: '10' })]);
  const link = new El('a', { href: '/?remove_item=k' });
  const p = pagina({ eventos, comTags: true, corpo: [form, link] });
  p.disparar('submit', form);
  p.disparar('click', link);
  igual(p.enviados.map((e) => e[0]), ['view_item'], 'só os eventos ligados');
}

// 5. Manual.
const manual = ler('docs/EVENTOS.md');
for (const trecho of ['rastroFilaEventos', 'window.rastro.event', '_rastro_compra_enviada', 'key_is_valid', 'rastro_eventos_carrinho_em_toda_pagina', 'rastro_eventos_chaves_filho', 'composite_parent', 'blocos', 'Não conferido', 'tests/php/rastro-eventos.test.php']) {
  tem(manual, trecho, `EVENTOS.md cita ${trecho}`);
}

// 6. php -l e harness PHP.
const temPhp = spawnSync('php', ['-v'], { encoding: 'utf8' });
if (temPhp.status === 0) {
  for (const arquivo of [PHP, 'tests/php/rastro-eventos.test.php']) {
    const l = spawnSync('php', ['-l', path.join(root, arquivo)], { encoding: 'utf8' });
    assert.equal(l.status, 0, `php -l ${arquivo}:\n${l.stdout}${l.stderr}`);
  }
  const r = spawnSync('php', [path.join(root, 'tests/php/rastro-eventos.test.php')], { encoding: 'utf8' });
  assert.equal(r.status, 0, `tests/php/rastro-eventos.test.php falhou:\n${r.stderr}${r.stdout}`);
  process.stdout.write(r.stdout);
} else {
  console.log('php não encontrado no PATH: php -l e tests/php/rastro-eventos.test.php pulados.');
}

console.log('Rastro eventos contract: PASS');
