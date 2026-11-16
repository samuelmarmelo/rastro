/**
 * Rastro: tela de relatórios (contrato, seção 8; manual docs/RELATORIOS.md).
 *
 * Monta, a partir de window.rastroRelatorio (JSON da página) e das respostas
 * do AJAX rastro_relatorio: totais, gráfico de linhas por dia em SVG (até 8
 * séries, legenda clicável, foco por teclado), tabela equivalente do
 * gráfico, tabela ordenável com 25, 50 ou 100 linhas e os links do Excel e do CSV.
 *
 * Todo texto entra por textContent e todo nó é criado com createElement ou
 * createElementNS: nenhum dado vira HTML. JS puro, sem biblioteca, sem build.
 */
(function (window, document) {
  'use strict';

  var SVG = 'http://www.w3.org/2000/svg';
  var COLUNAS = ['pedidos', 'bruta', 'liquida', 'total'];
  var POR_PAGINA = [25, 50, 100];

  /* Nós ------------------------------------------------------------------- */

  function no(tag, atributos, texto) {
    var el = document.createElement(tag);
    atribuir(el, atributos);
    if (texto !== undefined && texto !== null) {
      el.textContent = String(texto);
    }
    return el;
  }

  function noSvg(tag, atributos, texto) {
    var el = document.createElementNS(SVG, tag);
    atribuir(el, atributos);
    if (texto !== undefined && texto !== null) {
      el.textContent = String(texto);
    }
    return el;
  }

  function atribuir(el, atributos) {
    if (!atributos) {
      return;
    }
    Object.keys(atributos).forEach(function (nome) {
      if (atributos[nome] !== null && atributos[nome] !== undefined && atributos[nome] !== false) {
        el.setAttribute(nome, String(atributos[nome]));
      }
    });
  }

  function limpar(el) {
    while (el && el.firstChild) {
      el.removeChild(el.firstChild);
    }
  }

  function parte(raiz, nome) {
    return raiz.querySelector('[data-rastro-rel="' + nome + '"]');
  }

  /* Formatos -------------------------------------------------------------- */

  function formatadores(idioma, moeda) {
    var dinheiro = null;
    var dinheiroCurto = null;
    var numero = null;
    try {
      dinheiro = new Intl.NumberFormat(idioma, { style: 'currency', currency: moeda || 'BRL' });
      dinheiroCurto = new Intl.NumberFormat(idioma, { style: 'currency', currency: moeda || 'BRL', maximumFractionDigits: 0, minimumFractionDigits: 0 });
      numero = new Intl.NumberFormat(idioma);
    } catch (e) {
      dinheiro = null;
    }
    function reserva(centavos) {
      var n = Math.round(Number(centavos) || 0);
      var inteiro = Math.floor(Math.abs(n) / 100);
      var resto = String(Math.abs(n) % 100);
      return (n < 0 ? '-' : '') + inteiro + ',' + (resto.length < 2 ? '0' + resto : resto);
    }
    return {
      moeda: function (centavos) {
        return dinheiro ? dinheiro.format((Number(centavos) || 0) / 100) : reserva(centavos);
      },
      moedaCurta: function (centavos) {
        return dinheiroCurto ? dinheiroCurto.format(Math.round((Number(centavos) || 0) / 100)) : String(Math.round((Number(centavos) || 0) / 100));
      },
      numero: function (n) {
        return numero ? numero.format(Number(n) || 0) : String(Number(n) || 0);
      },
      dia: function (ymd) {
        var p = String(ymd).split('-');
        return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : String(ymd);
      },
      diaCurto: function (ymd) {
        var p = String(ymd).split('-');
        return p.length === 3 ? p[2] + '/' + p[1] : String(ymd);
      }
    };
  }

  /* Regras puras ---------------------------------------------------------- */

  /** Linhas ordenadas por uma coluna (empate: pelo valor da dimensão). */
  function ordenarLinhas(linhas, coluna, direcao) {
    var sinal = direcao === 'asc' ? 1 : -1;
    var col = COLUNAS.indexOf(coluna) >= 0 ? coluna : 'total';
    return linhas.slice().sort(function (a, b) {
      var d = ((Number(a[col]) || 0) - (Number(b[col]) || 0)) * sinal;
      if (d !== 0) {
        return d;
      }
      return String(a.valor) < String(b.valor) ? -1 : (String(a.valor) > String(b.valor) ? 1 : 0);
    });
  }

  /** Teto "redondo" para o eixo (1, 2, 2,5 ou 5 vezes uma potência de 10). */
  function tetoRedondo(maximo) {
    if (!(maximo > 0)) {
      return 1;
    }
    var potencia = Math.pow(10, Math.floor(Math.log(maximo) / Math.LN10));
    var passos = [1, 2, 2.5, 5, 10];
    for (var i = 0; i < passos.length; i++) {
      if (passos[i] * potencia >= maximo) {
        return passos[i] * potencia;
      }
    }
    return 10 * potencia;
  }

  /** Atributo d de um caminho SVG a partir dos valores. */
  function caminho(valores, area, teto) {
    var n = valores.length;
    var partes = [];
    for (var i = 0; i < n; i++) {
      var x = n > 1 ? area.x + (i / (n - 1)) * area.l : area.x + area.l / 2;
      var y = area.y + (1 - (Number(valores[i]) || 0) / teto) * area.a;
      partes.push((i === 0 ? 'M' : 'L') + x.toFixed(1) + ' ' + y.toFixed(1));
    }
    return partes.join(' ');
  }

  /* Tela ------------------------------------------------------------------ */

  function montar(raiz, config) {
    var textos = config.textos || {};
    var estado = {
      dados: null,
      coluna: 'total',
      direcao: 'desc',
      porPagina: 25,
      pagina: 1,
      metrica: 'total',
      ocultas: {},
      cursor: -1,
      pedido: 0
    };
    var fmt = formatadores(config.idioma || 'pt-BR', 'BRL');

    var el = {
      totais: parte(raiz, 'totais'),
      grafico: parte(raiz, 'grafico'),
      legenda: parte(raiz, 'legenda'),
      graficoTabela: parte(raiz, 'grafico-tabela'),
      tabela: parte(raiz, 'tabela'),
      paginacao: parte(raiz, 'paginacao'),
      mensagem: parte(raiz, 'mensagem'),
      periodo: parte(raiz, 'periodo'),
      metrica: parte(raiz, 'metrica'),
      porPagina: parte(raiz, 'por-pagina'),
      form: raiz.querySelector('.rastro-rel-filtros'),
      datas: raiz.querySelector('.rastro-rel-datas'),
      csv: raiz.querySelector('.rastro-rel-csv'),
      xlsx: raiz.querySelector('.rastro-rel-xlsx'),
      campoPeriodo: raiz.querySelector('[name="periodo"]'),
      campoDe: raiz.querySelector('[name="de"]'),
      campoAte: raiz.querySelector('[name="ate"]'),
      campoModelo: raiz.querySelector('[name="modelo"]'),
      campoDimensao: raiz.querySelector('[name="dimensao"]'),
      leitura: null
    };

    function avisar(texto) {
      if (el.mensagem) {
        el.mensagem.textContent = texto || '';
      }
    }

    function rotuloDimensao() {
      var dim = estado.dados && estado.dados.filtros ? estado.dados.filtros.dimensao : 'fonte';
      return (config.dimensoes && config.dimensoes[dim]) || dim;
    }

    function formatarMetrica(valor) {
      return estado.metrica === 'pedidos' ? fmt.numero(valor) : fmt.moeda(valor);
    }

    /* Totais */
    function desenharTotais() {
      if (!el.totais) {
        return;
      }
      limpar(el.totais);
      var t = estado.dados.totais;
      var lista = no('ul', { 'class': 'rastro-rel-totais-lista' });
      [
        [textos.valores + ': ' + rotuloDimensao(), fmt.numero(t.valores)],
        [textos.pedidos, fmt.numero(t.pedidos)],
        [textos.bruta, fmt.moeda(t.bruta)],
        [textos.liquida, fmt.moeda(t.liquida)],
        [textos.total, fmt.moeda(t.total)]
      ].forEach(function (par) {
        var item = no('li', { 'class': 'rastro-rel-total' });
        item.appendChild(no('span', { 'class': 'rastro-rel-total-rotulo' }, par[0]));
        item.appendChild(no('strong', { 'class': 'rastro-rel-total-valor' }, par[1]));
        lista.appendChild(item);
      });
      el.totais.appendChild(lista);
    }

    /* Gráfico */
    function seriesVisiveis() {
      var series = estado.dados.serie.series || [];
      var lista = [];
      for (var i = 0; i < series.length; i++) {
        if (!estado.ocultas[series[i].nome]) {
          lista.push({ indice: i, serie: series[i] });
        }
      }
      return lista;
    }

    function lerCursor() {
      if (!el.leitura) {
        return;
      }
      var dias = estado.dados.serie.dias || [];
      if (estado.cursor < 0 || estado.cursor >= dias.length) {
        el.leitura.textContent = '';
        return;
      }
      var partes = [fmt.dia(dias[estado.cursor])];
      seriesVisiveis().forEach(function (item) {
        partes.push(item.serie.nome + ': ' + formatarMetrica((item.serie[estado.metrica] || [])[estado.cursor]));
      });
      el.leitura.textContent = partes.join('; ');
    }

    function desenharGrafico() {
      if (!el.grafico) {
        return;
      }
      limpar(el.grafico);
      var dias = estado.dados.serie.dias || [];
      var visiveis = seriesVisiveis();
      if (!dias.length || !(estado.dados.serie.series || []).length) {
        el.grafico.appendChild(no('p', { 'class': 'rastro-rel-vazio' }, textos.vazio));
        return;
      }
      if (!visiveis.length) {
        el.grafico.appendChild(no('p', { 'class': 'rastro-rel-vazio' }, textos.semSerie));
        return;
      }

      var largura = Math.max(320, Math.round(Number(el.grafico.clientWidth) || 720));
      var altura = 260;
      var area = { x: 72, y: 12, l: largura - 72 - 16, a: altura - 12 - 36 };

      var maximo = 0;
      visiveis.forEach(function (item) {
        (item.serie[estado.metrica] || []).forEach(function (v) {
          maximo = Math.max(maximo, Number(v) || 0);
        });
      });
      var teto = tetoRedondo(maximo);

      var svg = noSvg('svg', {
        'class': 'rastro-rel-svg',
        viewBox: '0 0 ' + largura + ' ' + altura,
        width: '100%',
        height: altura,
        role: 'img',
        tabindex: 0,
        'aria-label': textos.grafico,
        focusable: 'true'
      });

      // Grade e eixo dos valores.
      for (var g = 0; g <= 4; g++) {
        var valor = (teto / 4) * g;
        var y = area.y + (1 - g / 4) * area.a;
        svg.appendChild(noSvg('line', { 'class': 'rastro-rel-grade', x1: area.x, x2: area.x + area.l, y1: y.toFixed(1), y2: y.toFixed(1) }));
        svg.appendChild(noSvg('text', { 'class': 'rastro-rel-eixo', x: area.x - 8, y: (y + 4).toFixed(1), 'text-anchor': 'end' },
          estado.metrica === 'pedidos' ? fmt.numero(Math.round(valor * 10) / 10) : fmt.moedaCurta(valor)));
      }

      // Eixo dos dias: até 7 marcas.
      var marcas = Math.min(7, dias.length);
      var vistos = {};
      for (var m = 0; m < marcas; m++) {
        var i = marcas > 1 ? Math.round((m / (marcas - 1)) * (dias.length - 1)) : 0;
        if (vistos[i]) {
          continue;
        }
        vistos[i] = true;
        var x = dias.length > 1 ? area.x + (i / (dias.length - 1)) * area.l : area.x + area.l / 2;
        svg.appendChild(noSvg('text', { 'class': 'rastro-rel-eixo', x: x.toFixed(1), y: altura - 12, 'text-anchor': 'middle' }, fmt.diaCurto(dias[i])));
      }

      // Uma linha por série visível (a cor segue a posição da série).
      visiveis.forEach(function (item) {
        var valores = item.serie[estado.metrica] || [];
        var classe = 'rastro-rel-linha rastro-rel-cor-' + item.indice + (item.serie.outras ? ' rastro-rel-outras' : '');
        var linha = noSvg('path', { 'class': classe, d: caminho(valores, area, teto), fill: 'none', 'data-serie': item.indice });
        linha.appendChild(noSvg('title', null, item.serie.nome));
        svg.appendChild(linha);
        if (dias.length === 1) {
          svg.appendChild(noSvg('circle', { 'class': 'rastro-rel-ponto rastro-rel-cor-' + item.indice, cx: (area.x + area.l / 2).toFixed(1), cy: (area.y + (1 - (Number(valores[0]) || 0) / teto) * area.a).toFixed(1), r: 4 }));
        }
      });

      // Cursor do teclado e do ponteiro.
      if (estado.cursor >= 0 && estado.cursor < dias.length) {
        var cx = dias.length > 1 ? area.x + (estado.cursor / (dias.length - 1)) * area.l : area.x + area.l / 2;
        svg.appendChild(noSvg('line', { 'class': 'rastro-rel-cursor', x1: cx.toFixed(1), x2: cx.toFixed(1), y1: area.y, y2: area.y + area.a }));
      }

      svg.addEventListener('keydown', function (evento) {
        var tecla = evento.key;
        var ultimo = dias.length - 1;
        var novo = estado.cursor;
        if (tecla === 'ArrowRight' || tecla === 'ArrowUp') {
          novo = Math.min(ultimo, estado.cursor + 1);
        } else if (tecla === 'ArrowLeft' || tecla === 'ArrowDown') {
          novo = estado.cursor < 0 ? ultimo : Math.max(0, estado.cursor - 1);
        } else if (tecla === 'Home') {
          novo = 0;
        } else if (tecla === 'End') {
          novo = ultimo;
        } else if (tecla === 'Escape') {
          novo = -1;
        } else {
          return;
        }
        if (evento.preventDefault) {
          evento.preventDefault();
        }
        estado.cursor = novo;
        desenharGrafico();
        lerCursor();
        var novoSvg = el.grafico.querySelector('svg');
        if (novoSvg && novoSvg.focus) {
          novoSvg.focus();
        }
      });

      svg.addEventListener('pointermove', function (evento) {
        if (!svg.getBoundingClientRect || dias.length < 2) {
          return;
        }
        var caixa = svg.getBoundingClientRect();
        if (!caixa.width) {
          return;
        }
        var px = ((evento.clientX - caixa.left) / caixa.width) * largura;
        var indice = Math.round(((px - area.x) / area.l) * (dias.length - 1));
        indice = Math.max(0, Math.min(dias.length - 1, indice));
        if (indice !== estado.cursor) {
          estado.cursor = indice;
          lerCursor();
          var cursor = svg.querySelector('.rastro-rel-cursor');
          var cxp = (area.x + (indice / (dias.length - 1)) * area.l).toFixed(1);
          if (!cursor) {
            cursor = noSvg('line', { 'class': 'rastro-rel-cursor', y1: area.y, y2: area.y + area.a });
            svg.appendChild(cursor);
          }
          cursor.setAttribute('x1', cxp);
          cursor.setAttribute('x2', cxp);
        }
      });

      el.grafico.appendChild(svg);
      el.leitura = no('p', { 'class': 'rastro-rel-leitura', 'aria-live': 'polite' });
      el.grafico.appendChild(el.leitura);
      lerCursor();
    }

    function desenharLegenda() {
      if (!el.legenda) {
        return;
      }
      limpar(el.legenda);
      var series = estado.dados.serie.series || [];
      if (!series.length) {
        return;
      }
      var lista = no('ul', { 'class': 'rastro-rel-legenda-lista' });
      series.forEach(function (serie, indice) {
        var ligada = !estado.ocultas[serie.nome];
        var item = no('li');
        var botao = no('button', {
          type: 'button',
          'class': 'rastro-rel-legenda-item' + (ligada ? '' : ' is-oculta'),
          'aria-pressed': ligada ? 'true' : 'false',
          'data-serie': indice,
          title: textos.mostrarSerie
        });
        botao.appendChild(no('span', { 'class': 'rastro-rel-amostra rastro-rel-cor-' + indice + (serie.outras ? ' rastro-rel-outras' : ''), 'aria-hidden': 'true' }));
        botao.appendChild(no('span', { 'class': 'rastro-rel-legenda-nome' }, serie.nome));
        botao.addEventListener('click', function () {
          if (estado.ocultas[serie.nome]) {
            delete estado.ocultas[serie.nome];
          } else {
            estado.ocultas[serie.nome] = true;
          }
          desenharGrafico();
          desenharLegenda();
          var mesmo = el.legenda.querySelector('[data-serie="' + indice + '"]');
          if (mesmo && mesmo.focus) {
            mesmo.focus();
          }
        });
        item.appendChild(botao);
        lista.appendChild(item);
      });
      el.legenda.appendChild(lista);
    }

    function desenharTabelaGrafico() {
      if (!el.graficoTabela) {
        return;
      }
      limpar(el.graficoTabela);
      var dias = estado.dados.serie.dias || [];
      var series = estado.dados.serie.series || [];
      if (!dias.length || !series.length) {
        return;
      }
      var tabela = no('table', { 'class': 'widefat striped rastro-rel-tabela-grafico' });
      tabela.appendChild(no('caption', { 'class': 'screen-reader-text' }, (estado.metrica === 'pedidos' ? textos.pedidos : textos.total) + ', ' + rotuloDimensao()));
      var cabeca = no('thead');
      var linha = no('tr');
      linha.appendChild(no('th', { scope: 'col' }, textos.dia));
      series.forEach(function (serie) {
        linha.appendChild(no('th', { scope: 'col', 'class': 'num' }, serie.nome));
      });
      cabeca.appendChild(linha);
      tabela.appendChild(cabeca);
      var corpo = no('tbody');
      dias.forEach(function (dia, i) {
        var tr = no('tr');
        tr.appendChild(no('th', { scope: 'row' }, fmt.dia(dia)));
        series.forEach(function (serie) {
          tr.appendChild(no('td', { 'class': 'num' }, formatarMetrica((serie[estado.metrica] || [])[i])));
        });
        corpo.appendChild(tr);
      });
      tabela.appendChild(corpo);
      el.graficoTabela.appendChild(tabela);
    }

    /* Tabela principal */
    function desenharTabela() {
      if (!el.tabela) {
        return;
      }
      limpar(el.tabela);
      var linhas = ordenarLinhas(estado.dados.linhas || [], estado.coluna, estado.direcao);
      if (!linhas.length) {
        el.tabela.appendChild(no('p', { 'class': 'rastro-rel-vazio' }, textos.vazio));
        desenharPaginacao(0);
        return;
      }
      var paginas = Math.max(1, Math.ceil(linhas.length / estado.porPagina));
      estado.pagina = Math.min(Math.max(1, estado.pagina), paginas);
      var inicio = (estado.pagina - 1) * estado.porPagina;
      var visiveis = linhas.slice(inicio, inicio + estado.porPagina);

      var tabela = no('table', { 'class': 'widefat striped rastro-rel-tabela' });
      var cabeca = no('thead');
      var tr = no('tr');
      tr.appendChild(no('th', { scope: 'col' }, rotuloDimensao()));
      COLUNAS.forEach(function (coluna) {
        var atual = estado.coluna === coluna;
        var th = no('th', { scope: 'col', 'class': 'num', 'aria-sort': atual ? (estado.direcao === 'asc' ? 'ascending' : 'descending') : 'none' });
        var botao = no('button', { type: 'button', 'class': 'rastro-rel-ordenar', 'data-coluna': coluna, 'aria-label': textos.ordenar + ' ' + textos[coluna] }, textos[coluna]);
        if (atual) {
          botao.appendChild(no('span', { 'class': 'rastro-rel-seta', 'aria-hidden': 'true' }, estado.direcao === 'asc' ? ' ↑' : ' ↓'));
        }
        botao.addEventListener('click', function () {
          if (estado.coluna === coluna) {
            estado.direcao = estado.direcao === 'asc' ? 'desc' : 'asc';
          } else {
            estado.coluna = coluna;
            estado.direcao = 'desc';
          }
          estado.pagina = 1;
          desenharTabela();
          var mesmo = el.tabela.querySelector('[data-coluna="' + coluna + '"]');
          if (mesmo && mesmo.focus) {
            mesmo.focus();
          }
        });
        th.appendChild(botao);
        tr.appendChild(th);
      });
      cabeca.appendChild(tr);
      tabela.appendChild(cabeca);

      var corpo = no('tbody');
      visiveis.forEach(function (linha) {
        var l = no('tr');
        l.appendChild(no('th', { scope: 'row', 'class': 'rastro-rel-valor' }, linha.valor));
        l.appendChild(no('td', { 'class': 'num' }, fmt.numero(linha.pedidos)));
        l.appendChild(no('td', { 'class': 'num' }, fmt.moeda(linha.bruta)));
        l.appendChild(no('td', { 'class': 'num' }, fmt.moeda(linha.liquida)));
        l.appendChild(no('td', { 'class': 'num' }, fmt.moeda(linha.total)));
        corpo.appendChild(l);
      });
      tabela.appendChild(corpo);

      var t = estado.dados.totais;
      var pe = no('tfoot');
      var lt = no('tr');
      lt.appendChild(no('th', { scope: 'row' }, textos.total));
      lt.appendChild(no('td', { 'class': 'num' }, fmt.numero(t.pedidos)));
      lt.appendChild(no('td', { 'class': 'num' }, fmt.moeda(t.bruta)));
      lt.appendChild(no('td', { 'class': 'num' }, fmt.moeda(t.liquida)));
      lt.appendChild(no('td', { 'class': 'num' }, fmt.moeda(t.total)));
      pe.appendChild(lt);
      tabela.appendChild(pe);

      el.tabela.appendChild(tabela);
      desenharPaginacao(paginas);
    }

    function desenharPaginacao(paginas) {
      if (!el.paginacao) {
        return;
      }
      limpar(el.paginacao);
      if (paginas <= 1) {
        return;
      }
      var anterior = no('button', { type: 'button', 'class': 'button', 'data-ir': 'anterior', disabled: estado.pagina <= 1 ? 'disabled' : null }, textos.anterior);
      var proxima = no('button', { type: 'button', 'class': 'button', 'data-ir': 'proxima', disabled: estado.pagina >= paginas ? 'disabled' : null }, textos.proxima);
      anterior.addEventListener('click', function () {
        estado.pagina = Math.max(1, estado.pagina - 1);
        desenharTabela();
      });
      proxima.addEventListener('click', function () {
        estado.pagina = Math.min(paginas, estado.pagina + 1);
        desenharTabela();
      });
      el.paginacao.appendChild(anterior);
      el.paginacao.appendChild(no('span', { 'class': 'rastro-rel-pagina' }, textos.pagina + ' ' + estado.pagina + ' ' + textos.de + ' ' + paginas));
      el.paginacao.appendChild(proxima);
    }

    function desenharPeriodo() {
      if (!el.periodo || !estado.dados.filtros) {
        return;
      }
      var f = estado.dados.filtros;
      el.periodo.textContent = f.inicio === f.fim ? fmt.dia(f.inicio) : fmt.dia(f.inicio) + ' a ' + fmt.dia(f.fim);
    }

    function desenhar() {
      fmt = formatadores(config.idioma || 'pt-BR', estado.dados.moeda || 'BRL');
      desenharPeriodo();
      desenharTotais();
      desenharGrafico();
      desenharLegenda();
      desenharTabelaGrafico();
      desenharTabela();
      if (estado.dados.erro === 'woocommerce') {
        avisar(textos.woocommerce);
      } else if (estado.dados.truncado) {
        avisar(textos.truncado);
      }
    }

    /* Filtros e AJAX */
    function filtrosDoFormulario() {
      var f = estado.dados && estado.dados.filtros ? estado.dados.filtros : {};
      return {
        dimensao: el.campoDimensao ? el.campoDimensao.value : (f.dimensao || 'fonte'),
        periodo: el.campoPeriodo ? el.campoPeriodo.value : (f.periodo || '30d'),
        de: el.campoDe ? el.campoDe.value : '',
        ate: el.campoAte ? el.campoAte.value : '',
        modelo: el.campoModelo ? el.campoModelo.value : (f.modelo || 'primeira')
      };
    }

    function parametrosDe(filtros) {
      var p = { dimensao: filtros.dimensao, periodo: filtros.periodo, modelo: filtros.modelo };
      if (filtros.periodo === 'personalizado') {
        p.de = filtros.de || filtros.inicio || '';
        p.ate = filtros.ate || filtros.fim || '';
      }
      return p;
    }

    function codificar(objeto) {
      return Object.keys(objeto).map(function (k) {
        return encodeURIComponent(k) + '=' + encodeURIComponent(objeto[k]);
      }).join('&');
    }

    function atualizarEnderecos() {
      var p = parametrosDe(estado.dados.filtros);
      if (el.csv && config.csv) {
        var csv = { action: config.csvAcao, _wpnonce: config.csvNonce };
        Object.keys(p).forEach(function (k) { csv[k] = p[k]; });
        el.csv.setAttribute('href', config.csv + (config.csv.indexOf('?') >= 0 ? '&' : '?') + codificar(csv));
      }
      if (el.xlsx && config.csv && config.xlsxAcao) {
        var xlsx = { action: config.xlsxAcao, _wpnonce: config.xlsxNonce };
        Object.keys(p).forEach(function (k) { xlsx[k] = p[k]; });
        el.xlsx.setAttribute('href', config.csv + (config.csv.indexOf('?') >= 0 ? '&' : '?') + codificar(xlsx));
      }
      var links = raiz.querySelectorAll('[data-dimensao]');
      for (var i = 0; i < links.length; i++) {
        var atual = links[i].getAttribute('data-dimensao') === estado.dados.filtros.dimensao;
        if (atual) {
          links[i].setAttribute('aria-current', 'true');
        } else {
          links[i].removeAttribute('aria-current');
        }
        links[i].setAttribute('class', 'rastro-rel-dimensao' + (atual ? ' is-atual' : ''));
      }
      if (window.history && window.history.replaceState && window.location && typeof window.URL === 'function') {
        try {
          var url = new window.URL(window.location.href);
          ['dimensao', 'periodo', 'modelo', 'de', 'ate'].forEach(function (k) {
            if (p[k]) {
              url.searchParams.set(k, p[k]);
            } else {
              url.searchParams.delete(k);
            }
          });
          window.history.replaceState(null, '', url.toString());
        } catch (e) {
          // Endereço não atualizado; o relatório segue.
        }
      }
    }

    function aplicar(dados) {
      estado.dados = dados;
      estado.pagina = 1;
      estado.cursor = -1;
      estado.ocultas = {};
      if (el.campoDimensao && dados.filtros) {
        el.campoDimensao.value = dados.filtros.dimensao;
      }
      desenhar();
      atualizarEnderecos();
    }

    function carregar(filtros) {
      if (typeof window.fetch !== 'function') {
        return null;
      }
      var numero = ++estado.pedido;
      avisar(textos.carregando);
      raiz.setAttribute('aria-busy', 'true');
      var corpo = parametrosDe(filtros);
      corpo.action = config.acao;
      corpo.nonce = config.nonce;
      return window.fetch(config.ajax, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: codificar(corpo)
      }).then(function (resposta) {
        return resposta.json();
      }).then(function (json) {
        if (numero !== estado.pedido) {
          return;
        }
        raiz.removeAttribute('aria-busy');
        if (!json || !json.success || !json.data) {
          avisar(json && json.data && json.data.mensagem ? json.data.mensagem : textos.erro);
          return;
        }
        avisar(textos.pronto);
        aplicar(json.data);
      }).catch(function () {
        if (numero === estado.pedido) {
          raiz.removeAttribute('aria-busy');
          avisar(textos.erro);
        }
      });
    }

    function mostrarDatas() {
      if (el.datas && el.campoPeriodo) {
        el.datas.hidden = el.campoPeriodo.value !== 'personalizado';
      }
    }

    if (el.form) {
      el.form.addEventListener('submit', function (evento) {
        if (typeof window.fetch !== 'function') {
          return;
        }
        evento.preventDefault();
        carregar(filtrosDoFormulario());
      });
    }
    if (el.campoPeriodo) {
      el.campoPeriodo.addEventListener('change', function () {
        mostrarDatas();
        if (el.campoPeriodo.value !== 'personalizado') {
          carregar(filtrosDoFormulario());
        }
      });
    }
    if (el.campoModelo) {
      el.campoModelo.addEventListener('change', function () {
        carregar(filtrosDoFormulario());
      });
    }
    var links = raiz.querySelectorAll('[data-dimensao]');
    for (var i = 0; i < links.length; i++) {
      links[i].addEventListener('click', function (evento) {
        if (typeof window.fetch !== 'function') {
          return;
        }
        evento.preventDefault();
        var filtros = filtrosDoFormulario();
        filtros.dimensao = this.getAttribute('data-dimensao');
        carregar(filtros);
      });
    }
    if (el.metrica) {
      el.metrica.addEventListener('change', function () {
        estado.metrica = el.metrica.value === 'pedidos' ? 'pedidos' : 'total';
        desenharGrafico();
        desenharTabelaGrafico();
      });
    }
    if (el.porPagina) {
      el.porPagina.addEventListener('change', function () {
        var n = parseInt(el.porPagina.value, 10);
        estado.porPagina = POR_PAGINA.indexOf(n) >= 0 ? n : 25;
        estado.pagina = 1;
        desenharTabela();
      });
    }

    mostrarDatas();
    if (config.inicial) {
      aplicar(config.inicial);
    }

    return { estado: estado, aplicar: aplicar, carregar: carregar, desenhar: desenhar };
  }

  /* Início ---------------------------------------------------------------- */

  window.rastro = window.rastro || {};
  window.rastro.relatorios = {
    montar: montar,
    ordenarLinhas: ordenarLinhas,
    caminho: caminho,
    tetoRedondo: tetoRedondo
  };

  function iniciar() {
    var raiz = document.getElementById('rastro-relatorios');
    if (raiz && window.rastroRelatorio && !raiz.getAttribute('data-rastro-montado')) {
      raiz.setAttribute('data-rastro-montado', '1');
      window.rastro.relatorios.atual = montar(raiz, window.rastroRelatorio);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', iniciar);
  } else {
    iniciar();
  }
}(window, document));
