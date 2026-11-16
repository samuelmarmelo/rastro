/**
 * Rastro: captura da origem da visita (contrato, seções 2.1 e 7; manual
 * docs/ATRIBUICAO.md). JS puro, sem biblioteca, sem build.
 *
 * Na primeira página da visita lê os utm_*, qual ID de clique veio (só o
 * nome, nunca o valor), o host do referrer externo e o caminho da página de
 * entrada. Guarda a primeira e a última visita no cookie rastro_origem
 * (JSON compacto em base64url, até 1,5 KB, SameSite=Lax, Secure em https).
 *
 * Quando grava:
 * - aceite de análise (evento rastro:consent ou window.rastro.consent.get())
 *   ou modo básico: cookie com prazo (atribuicao_dias);
 * - visita de campanha (utm_* ou ID de clique) com atribuicao_utm_sempre:
 *   cookie com prazo, mesmo antes do aceite;
 * - modo sessao, sem aceite: cookie de sessão;
 * - modo aceite, sem aceite: o toque fica na memória e é gravado se o aceite
 *   vier nesta mesma página;
 * - modo desligada: nada.
 *
 * Também libera a atribuição nativa do WooCommerce com o aceite de análise
 * (window.wc_order_attribution.setOrderTracking), quando a loja pede.
 */
(function (w, d) {
  'use strict';

  var raiz = w.rastroConfig || {};
  var cfg = raiz.atribuicao;
  if (!cfg || typeof cfg !== 'object') {
    return;
  }

  var COOKIE = typeof cfg.cookie === 'string' && /^[a-z_]+$/.test(cfg.cookie) ? cfg.cookie : 'rastro_origem';
  var LIMITE = typeof cfg.max === 'number' && cfg.max > 0 ? cfg.max : 1536;
  var INATIVIDADE = 30 * 60;
  var DIAS = Math.max(1, Math.min(365, parseInt(cfg.dias, 10) || 90));
  var MODO = cfg.modo === 'sessao' || cfg.modo === 'desligada' ? cfg.modo : 'aceite';

  var UTM = [['utm_source', 's'], ['utm_medium', 'm'], ['utm_campaign', 'c'], ['utm_content', 'n'], ['utm_term', 't'], ['utm_id', 'i']];
  var CLIQUES = [['gclid', 'google'], ['gbraid', 'google'], ['wbraid', 'google'], ['fbclid', 'facebook'], ['ttclid', 'tiktok'], ['msclkid', 'bing']];
  var BASES = ['aceite', 'sessao', 'campanha', 'basico'];

  /* Limpeza (as mesmas regras que o servidor confere) ------------------------ */

  // Sem @ (e-mail): o mesmo conjunto que o servidor aceita.
  var NAO_TEXTO;
  try {
    NAO_TEXTO = new RegExp('[^\\p{L}\\p{N} ._~:/()+,!|%-]', 'gu');
  } catch (e) {
    NAO_TEXTO = /[^A-Za-z0-9 ._~:/()+,!|%-]/g;
  }

  function cortar(texto, max) {
    return Array.from ? Array.from(texto).slice(0, max).join('') : texto.slice(0, max);
  }

  function texto(valor, max) {
    if (typeof valor !== 'string') {
      return '';
    }
    return cortar(valor.replace(/\s+/g, ' ').replace(NAO_TEXTO, '').trim(), max).trim();
  }

  /**
   * Valor de campanha (utm_*): vazio quando parece dado pessoal, antes de
   * limpar: arroba (e-mail) ou oito dígitos seguidos (CPF, telefone, CEP).
   */
  function campanhaTexto(valor, max) {
    if (typeof valor !== 'string' || valor.indexOf('@') !== -1 || /[0-9]{8,}/.test(valor)) {
      return '';
    }
    return texto(valor, max);
  }

  function caminho(valor) {
    var c = typeof valor === 'string' ? valor.replace(/[^A-Za-z0-9._~%/+:@!,-]/g, '') : '';
    if (c.charAt(0) !== '/') {
      c = '/' + c;
    }
    return cortar(c, 200);
  }

  function semWww(host) {
    return host.indexOf('www.') === 0 ? host.slice(4) : host;
  }

  function hostDe(endereco) {
    var m = /^https?:\/\/([^/?#:@]+)/i.exec(typeof endereco === 'string' ? endereco : '');
    if (!m) {
      return '';
    }
    var host = m[1].toLowerCase();
    return /^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/.test(host) && host.length <= 100 ? host : '';
  }

  function agora() {
    return Math.floor(Date.now() / 1000);
  }

  /* Captura ----------------------------------------------------------------- */

  /**
   * Toque desta página. `nova`: a página abre uma visita (não veio de outra
   * página do site). `campanha`: veio com utm_* ou ID de clique.
   */
  function capturar() {
    var busca;
    try {
      busca = new URLSearchParams(w.location.search || '');
    } catch (e) {
      busca = { get: function () { return null; } };
    }

    var toque = {};
    var campanha = false;
    UTM.forEach(function (par) {
      var v = campanhaTexto(busca.get(par[0]) || '', 100);
      if (v !== '') {
        toque[par[1]] = v;
        campanha = true;
      }
    });

    var fonteClique = '';
    for (var i = 0; i < CLIQUES.length; i++) {
      var bruto = busca.get(CLIQUES[i][0]);
      if (bruto !== null && bruto !== '') {
        // Só o nome do parâmetro; o valor nunca é lido para fora daqui.
        toque.k = CLIQUES[i][0];
        fonteClique = CLIQUES[i][1];
        campanha = true;
        break;
      }
    }

    var esteHost = semWww(String(w.location.hostname || '').toLowerCase());
    var ref = hostDe(d.referrer || '');
    var interno = ref !== '' && semWww(ref) === esteHost;
    if (ref !== '' && !interno) {
      toque.r = ref;
    }

    if (toque.s) {
      toque.f = texto(toque.s.toLowerCase(), 100);
    } else if (fonteClique) {
      toque.f = fonteClique;
    } else if (toque.r) {
      toque.f = semWww(toque.r);
    } else {
      toque.f = 'direto';
    }
    toque.e = caminho(w.location.pathname || '/');
    toque.q = agora();

    return {
      toque: toque,
      campanha: campanha,
      identificada: campanha || !!toque.r,
      nova: campanha || !interno
    };
  }

  /* Cookie ------------------------------------------------------------------ */

  function utf8(texto) {
    if (typeof TextEncoder === 'function') {
      var bytes = new TextEncoder().encode(texto);
      var bin = '';
      for (var i = 0; i < bytes.length; i++) {
        bin += String.fromCharCode(bytes[i]);
      }
      return bin;
    }
    return unescape(encodeURIComponent(texto));
  }

  function deUtf8(bin) {
    if (typeof TextDecoder === 'function') {
      var bytes = new Uint8Array(bin.length);
      for (var i = 0; i < bin.length; i++) {
        bytes[i] = bin.charCodeAt(i);
      }
      return new TextDecoder().decode(bytes);
    }
    return decodeURIComponent(escape(bin));
  }

  function codificar(estado) {
    return w.btoa(utf8(JSON.stringify(estado))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }

  function decodificar(valor) {
    if (!/^[A-Za-z0-9_-]+$/.test(valor) || valor.length > LIMITE) {
      return null;
    }
    var b64 = valor.replace(/-/g, '+').replace(/_/g, '/');
    while (b64.length % 4) {
      b64 += '=';
    }
    var dado = JSON.parse(deUtf8(w.atob(b64)));
    if (!dado || dado.v !== 1 || !dado.p || typeof dado.p !== 'object' || typeof dado.p.f !== 'string') {
      return null;
    }
    return dado;
  }

  function lerCookie() {
    try {
      var partes = String(d.cookie || '').split(';');
      for (var i = 0; i < partes.length; i++) {
        var p = partes[i].replace(/^\s+/, '');
        if (p.indexOf(COOKIE + '=') === 0) {
          return decodificar(p.slice(COOKIE.length + 1));
        }
      }
    } catch (e) {
      return null;
    }
    return null;
  }

  /** Corta os textos dos toques até caber no limite (ou desiste). */
  function caber(estado) {
    var tentativas = [0, 60, 30, 15];
    for (var t = 0; t < tentativas.length; t++) {
      var copia = JSON.parse(JSON.stringify(estado));
      if (tentativas[t] > 0) {
        ['p', 'u'].forEach(function (lado) {
          var toque = copia[lado];
          if (!toque) {
            return;
          }
          Object.keys(toque).forEach(function (k) {
            if (typeof toque[k] === 'string' && k !== 'k') {
              toque[k] = k === 'e' ? cortar(toque[k], tentativas[t] * 2) : cortar(toque[k], tentativas[t]).trim();
            }
          });
          if (!toque.f) {
            toque.f = 'direto';
          }
        });
      }
      var valor = codificar(copia);
      if (valor.length <= LIMITE) {
        return valor;
      }
    }
    return '';
  }

  function gravarCookie(estado, comPrazo) {
    var valor = caber(estado);
    if (valor === '') {
      return false;
    }
    var partes = [COOKIE + '=' + valor, 'path=/', 'SameSite=Lax'];
    if (comPrazo) {
      partes.push('max-age=' + DIAS * 86400);
    }
    if (w.location.protocol === 'https:') {
      partes.push('Secure');
    }
    d.cookie = partes.join('; ');
    return true;
  }

  /* Primeira e última visita -------------------------------------------------- */

  /**
   * Junta o toque desta página ao que já estava guardado. A primeira visita
   * não muda; a última só troca por uma visita com origem identificada, ou
   * depois de 30 minutos sem atividade.
   */
  function combinar(atual, cap, momento) {
    if (!atual) {
      return { v: 1, p: cap.toque, u: cap.toque, a: momento };
    }
    var inativo = typeof atual.a !== 'number' || momento - atual.a > INATIVIDADE;
    var ultima = atual.u || atual.p;
    if ((cap.identificada && cap.nova) || inativo) {
      ultima = cap.toque;
    }
    return { v: 1, p: atual.p, u: ultima, a: momento, o: atual.o };
  }

  /* Consentimento -------------------------------------------------------------- */

  function basico() {
    return cfg.basico === true || !!(raiz.consent && raiz.consent.modo === 'basico');
  }

  function estadoInicial() {
    try {
      var api = w.rastro && w.rastro.consent;
      if (api && typeof api.get === 'function') {
        var e = api.get();
        if (e && typeof e === 'object') {
          return e;
        }
      }
    } catch (e) {
      return null;
    }
    return null;
  }

  var consentido = basico();
  var revogado = false;
  var inicial = estadoInicial();
  if (inicial && inicial.analise === true) {
    consentido = true;
  }

  /* Gravação ------------------------------------------------------------------ */

  var cap = capturar();
  var guardado = lerCookie();
  var estado = combinar(guardado, cap, agora());
  var pendente = false;

  function gravar() {
    if (MODO === 'desligada' || revogado) {
      return false;
    }
    var anterior = guardado && BASES.indexOf(guardado.o) !== -1 ? guardado.o : '';
    if (consentido) {
      estado.o = basico() ? 'basico' : 'aceite';
      return gravarCookie(estado, true);
    }
    if (cap.campanha && cfg.utmSempre === true) {
      estado.o = 'campanha';
      return gravarCookie(estado, true);
    }
    if (MODO === 'sessao') {
      // Origem de campanha já guardada mantém o prazo dela.
      estado.o = anterior === 'campanha' ? 'campanha' : 'sessao';
      return gravarCookie(estado, anterior === 'campanha');
    }
    pendente = true;
    return false;
  }

  /* Atribuição nativa do WooCommerce ------------------------------------------ */

  function woo(permitir) {
    if (cfg.woo !== true) {
      return;
    }
    var wc = w.wc_order_attribution;
    if (!wc || typeof wc !== 'object') {
      return;
    }
    try {
      if (typeof wc.setOrderTracking === 'function') {
        wc.setOrderTracking(permitir);
      } else if (wc.params && typeof wc.params === 'object') {
        // O script do WooCommerce ainda não rodou (depende deste): começa certo.
        wc.params.allowTracking = permitir;
      }
    } catch (e) {
      /* falha fechada: o WooCommerce segue como estava */
    }
  }

  var wooLiberado = false;

  function aoConsentir(detalhe) {
    if (!detalhe || typeof detalhe !== 'object') {
      return;
    }
    if (detalhe.analise === true) {
      consentido = true;
      revogado = false;
      pendente = false;
      // Grava o toque que estava na memória (ou renova a base para aceite).
      gravar();
      if (!wooLiberado) {
        wooLiberado = true;
        woo(true);
      }
    } else if (detalhe.decidido === true && !basico()) {
      if (consentido) {
        // Revogação nesta página: nada mais é gravado (o consentimento apaga o cookie).
        revogado = true;
      }
      consentido = false;
      pendente = false;
      if (wooLiberado) {
        wooLiberado = false;
        woo(false);
      }
    }
  }

  d.addEventListener('rastro:consent', function (evento) {
    aoConsentir(evento && evento.detail);
  });

  gravar();
  if (consentido) {
    wooLiberado = true;
    woo(true);
  } else if (cfg.woo === true && w.wc_order_attribution && w.wc_order_attribution.params && typeof w.wc_order_attribution.setOrderTracking !== 'function') {
    w.wc_order_attribution.params.allowTracking = false;
  }

  w.rastro = w.rastro || {};
  w.rastro.atribuicao = {
    /** Estado desta página (para depuração; sem dado pessoal). */
    estado: function () {
      return JSON.parse(JSON.stringify({ toque: cap.toque, campanha: cap.campanha, pendente: pendente, consentido: consentido }));
    }
  };
})(window, document);
