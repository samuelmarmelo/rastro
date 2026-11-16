/**
 * Rastro: consentimento no navegador (contrato, seções 2.1 e 4; manual
 * docs/CONSENTIMENTO.md).
 *
 * Estado: { analise, publicidade, decidido }. API:
 * window.rastro.consent.get(), .set( { analise, publicidade } ), .abrir().
 * Evento `rastro:consent` no document a cada mudança (detail = estado) e
 * uma vez na carga quando já há escolha (no DOMContentLoaded, depois que
 * todos os scripts com defer já ligaram os seus ouvintes).
 *
 * Provedores (window.rastroConfig.consent.provedor):
 * - proprio: aviso do Rastro e cookie rastro_consent (a1p1, a1p0, a0p1,
 *   a0p0), com prazo de `dias`, path=/, SameSite=Lax e Secure em https.
 * - wp_consent_api: wp_has_consent( 'statistics' ) para análise e
 *   wp_has_consent( 'marketing' ) para publicidade; ouve
 *   wp_listen_for_consent_change e wp_consent_type_defined. Sem tipo de
 *   consentimento definido a API concede tudo; aqui isso vale como negado.
 * - ponte (config.externo): cookie, mapa (valor do cookie para estado), evento e campo
 *   do detail, seletor do botão que reabre o aviso do site.
 *
 * Modos: lgpd (nada antes da escolha), aceite_automatico (sem escolha
 * depois de `segundos`, liga só a análise e grava a1p0; a contagem para de
 * vez quando a pessoa interage com o aviso) e basico (tudo concedido desde a
 * carga, sem aviso e sem cookie de escolha; provedores externos ignorados).
 *
 * Revogação: Consent Mode negado, cookies de medição apagados no domínio do
 * site e no pai, armazenamento do PostHog limpo e recarga da página quando
 * alguma tag já tinha carregado (lido antes do evento). O cookie de origem
 * só fica quando é de campanha (origemFica()).
 *
 * Sem biblioteca, sem dado pessoal. Armazenamento bloqueado nunca quebra a
 * página.
 */
( function ( w, d ) {
	'use strict';

	var config = w.rastroConfig && 'object' === typeof w.rastroConfig ? w.rastroConfig.consent : null;
	w.rastro = w.rastro || {};
	if ( ! config || 'object' !== typeof config || w.rastro.consent ) {
		return;
	}

	var FINALIDADES = [ 'analise', 'publicidade' ];
	var NOME = /^[A-Za-z0-9_-]{1,64}$/;
	var modo = 'aceite_automatico' === config.modo || 'basico' === config.modo ? config.modo : 'lgpd';
	var provedor = 'string' === typeof config.provedor ? config.provedor : 'nenhum';
	if ( 'basico' === modo ) {
		provedor = 'proprio';
	}
	var externo = null;
	if ( 'proprio' !== provedor && 'wp_consent_api' !== provedor ) {
		var ext = config.externo;
		externo = ext && 'object' === typeof ext && 'string' === typeof ext.cookie && NOME.test( ext.cookie ) && ext.mapa && 'object' === typeof ext.mapa ? ext : null;
		if ( ! externo ) {
			// Ponte sem configuração: falha fechada.
			provedor = 'nenhum';
		}
	}
	if ( 'nenhum' === provedor ) {
		return;
	}

	var finalidades = [];
	for ( var f = 0; f < FINALIDADES.length; f++ ) {
		if ( config.finalidades && 'function' === typeof config.finalidades.indexOf && -1 !== config.finalidades.indexOf( FINALIDADES[ f ] ) ) {
			finalidades.push( FINALIDADES[ f ] );
		}
	}
	var cookieNome = 'string' === typeof config.cookie && NOME.test( config.cookie ) ? config.cookie : 'rastro_consent';
	var visitaChave = 'string' === typeof config.visita && NOME.test( config.visita ) ? config.visita : 'rastro_consent_visita';
	var dias = inteiro( config.dias, 30, 395, 180 );
	var segundos = inteiro( config.segundos, 1, 30, 2 );

	// Cookies de medição conhecidos, por finalidade (site e domínio pai). O
	// cookie de origem (rastro_origem) tem regra própria: origemFica().
	var COOKIES = {
		analise: /^(_ga(_.+)?|_gid|ph_.+|__ph_.+)$/,
		publicidade: /^(_gcl_.+|_fbp|_fbc|_ttp|_tt_.+|ttcsid(_.+)?|ttclid)$/
	};
	var POSTHOG = /^(__)?ph_/;
	var atribuicaoCfg = w.rastroConfig.atribuicao && 'object' === typeof w.rastroConfig.atribuicao ? w.rastroConfig.atribuicao : {};
	var ORIGEM = 'string' === typeof atribuicaoCfg.cookie && /^[a-z_]{1,64}$/.test( atribuicaoCfg.cookie ) ? atribuicaoCfg.cookie : 'rastro_origem';
	// Campos de campanha de um toque do atribuicao.js (utm_* e ID de clique).
	var CAMPANHA = [ 's', 'm', 'c', 'n', 't', 'i', 'k' ];

	var estado = negado();
	var emitidos = 0;

	function inteiro( valor, min, max, padrao ) {
		var n = parseInt( valor, 10 );
		return isNaN( n ) || n < min || n > max ? padrao : n;
	}

	function negado() {
		return { analise: false, publicidade: false, decidido: false };
	}

	function copia( e ) {
		return { analise: true === e.analise, publicidade: true === e.publicidade, decidido: true === e.decidido };
	}

	function tem( finalidade ) {
		return -1 !== finalidades.indexOf( finalidade );
	}

	/* Cookies e armazenamento ------------------------------------------- */

	function lerCookie( nome ) {
		var partes = String( d.cookie || '' ).split( ';' );
		for ( var i = 0; i < partes.length; i++ ) {
			var p = partes[ i ].replace( /^\s+/, '' );
			if ( 0 === p.indexOf( nome + '=' ) ) {
				var valor = p.slice( nome.length + 1 );
				try {
					return decodeURIComponent( valor );
				} catch ( erro ) {
					return valor;
				}
			}
		}
		return '';
	}

	function gravarCookie( valor ) {
		var segundosPrazo = dias * 86400;
		var expira = new Date( Date.now() + segundosPrazo * 1000 ).toUTCString();
		var seguro = w.location && 'https:' === w.location.protocol ? '; Secure' : '';
		d.cookie = cookieNome + '=' + valor + '; expires=' + expira + '; max-age=' + segundosPrazo + '; path=/; SameSite=Lax' + seguro;
	}

	function dominios() {
		var host = w.location && w.location.hostname ? String( w.location.hostname ) : '';
		var lista = [ '' ];
		if ( host ) {
			lista.push( host );
			var partes = host.split( '.' );
			for ( var p = 1; p < partes.length - 1; p++ ) {
				lista.push( '.' + partes.slice( p ).join( '.' ) );
			}
		}
		return lista;
	}

	function apagarCookies( regra ) {
		var nomes = String( d.cookie || '' ).split( ';' );
		var lista = dominios();
		for ( var i = 0; i < nomes.length; i++ ) {
			var nome = nomes[ i ].split( '=' )[ 0 ].replace( /^\s+|\s+$/g, '' );
			if ( ! nome || ! regra.test( nome ) ) {
				continue;
			}
			for ( var j = 0; j < lista.length; j++ ) {
				d.cookie = nome + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; max-age=0; path=/' + ( lista[ j ] ? '; domain=' + lista[ j ] : '' );
			}
		}
	}

	function limparArmazenamento( regra ) {
		var areas = [ 'localStorage', 'sessionStorage' ];
		for ( var a = 0; a < areas.length; a++ ) {
			try {
				var area = w[ areas[ a ] ];
				for ( var k = area.length - 1; k >= 0; k-- ) {
					var chave = area.key( k );
					if ( chave && regra.test( chave ) ) {
						area.removeItem( chave );
					}
				}
			} catch ( erro ) {
				// Armazenamento bloqueado: nada a limpar.
			}
		}
	}

	function toqueDeCampanha( toque ) {
		if ( ! toque || 'object' !== typeof toque ) {
			return false;
		}
		for ( var i = 0; i < CAMPANHA.length; i++ ) {
			if ( 'string' === typeof toque[ CAMPANHA[ i ] ] && '' !== toque[ CAMPANHA[ i ] ] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * O cookie de origem fica depois de negar a análise? Só com a visita de
	 * campanha ligada (config.manterOrigem, contrato 2.1), cookie gravado como
	 * campanha (`o: 'campanha'`, JSON em base64url do atribuicao.js) e os dois
	 * toques (primeira e última visita) de campanha. Na dúvida, apaga.
	 */
	function origemFica() {
		if ( true !== config.manterOrigem ) {
			return false;
		}
		try {
			var valor = lerCookie( ORIGEM );
			if ( ! /^[A-Za-z0-9_-]{1,1536}$/.test( valor ) || 'function' !== typeof w.atob ) {
				return false;
			}
			var b64 = valor.replace( /-/g, '+' ).replace( /_/g, '/' );
			while ( b64.length % 4 ) {
				b64 += '=';
			}
			var dado = JSON.parse( decodeURIComponent( escape( w.atob( b64 ) ) ) );
			return !! dado && 'object' === typeof dado && 1 === dado.v && 'campanha' === dado.o && toqueDeCampanha( dado.p ) && toqueDeCampanha( dado.u || dado.p );
		} catch ( erro ) {
			return false;
		}
	}

	/** Apaga o que as finalidades negadas deixaram. */
	function limpar( e ) {
		if ( ! e.analise ) {
			apagarCookies( COOKIES.analise );
			limparArmazenamento( POSTHOG );
			if ( '' !== lerCookie( ORIGEM ) && ! origemFica() ) {
				apagarCookies( new RegExp( '^' + ORIGEM + '$' ) );
			}
		}
		if ( ! e.publicidade ) {
			apagarCookies( COOKIES.publicidade );
		}
	}

	function recusouNestaVisita() {
		try {
			return '1' === w.sessionStorage.getItem( visitaChave );
		} catch ( erro ) {
			return false;
		}
	}

	function marcarVisita() {
		try {
			w.sessionStorage.setItem( visitaChave, '1' );
		} catch ( erro ) {
			// Sem sessionStorage: o aviso volta na próxima página.
		}
	}

	/* Consent Mode, evento e tags ---------------------------------------- */

	function gtag() {
		w.dataLayer = w.dataLayer || [];
		if ( 'function' !== typeof w.gtag ) {
			w.gtag = function () {
				w.dataLayer.push( arguments );
			};
		}
		try {
			w.gtag.apply( w, arguments );
		} catch ( erro ) {
			// Fila com erro não quebra o aviso.
		}
	}

	function consentMode( e ) {
		var anuncio = e.publicidade ? 'granted' : 'denied';
		gtag( 'consent', 'update', {
			ad_storage: anuncio,
			analytics_storage: e.analise ? 'granted' : 'denied',
			ad_user_data: anuncio,
			ad_personalization: anuncio
		} );
	}

	function emitir() {
		emitidos++;
		try {
			if ( 'function' === typeof w.CustomEvent && 'function' === typeof d.dispatchEvent ) {
				d.dispatchEvent( new w.CustomEvent( 'rastro:consent', { detail: copia( estado ) } ) );
			}
		} catch ( erro ) {
			// Ouvinte com erro não quebra o aviso.
		}
	}

	// Alguma tag carregou nesta página? algumCarregado() vale mesmo depois do
	// parar() do tags.js; carregado() fica para versões antigas do tags.js.
	function tagsCarregadas() {
		try {
			var tags = w.rastro.tags;
			if ( ! tags ) {
				return false;
			}
			if ( 'function' === typeof tags.algumCarregado ) {
				return !! tags.algumCarregado();
			}
			return 'function' === typeof tags.carregado && !! tags.carregado();
		} catch ( erro ) {
			return false;
		}
	}

	/**
	 * Troca o estado. `explicita`: veio de uma escolha (botão, evento do
	 * aviso externo); limpa o que ficou negado. Na revogação, lê se alguma tag
	 * carregou ANTES do evento (o tags.js para tudo ao ouvir o evento) e
	 * recarrega a página. Aceitar de novo na mesma página espera a recarga:
	 * o tags.js parado não carrega nada até lá.
	 */
	function aplicar( novo, explicita ) {
		var antes = estado;
		estado = copia( novo );
		var mudou = antes.analise !== estado.analise || antes.publicidade !== estado.publicidade || antes.decidido !== estado.decidido;
		var revogou = ( antes.analise && ! estado.analise ) || ( antes.publicidade && ! estado.publicidade );
		var recarregar = revogou && tagsCarregadas();
		if ( mudou ) {
			consentMode( estado );
		}
		if ( explicita || revogou ) {
			limpar( estado );
		}
		if ( mudou ) {
			emitir();
		}
		if ( recarregar && w.location && 'function' === typeof w.location.reload ) {
			w.location.reload();
		}
	}

	/* Leitura de cada provedor -------------------------------------------- */

	function lerProprio() {
		var m = /^a([01])p([01])$/.exec( lerCookie( cookieNome ) );
		return m ? { analise: '1' === m[ 1 ], publicidade: '1' === m[ 2 ], decidido: true } : negado();
	}

	function lerPonte( valor ) {
		var mapa = externo.mapa;
		if ( 'string' === typeof valor && Object.prototype.hasOwnProperty.call( mapa, valor ) && mapa[ valor ] && 'object' === typeof mapa[ valor ] ) {
			return { analise: true === mapa[ valor ].analise, publicidade: true === mapa[ valor ].publicidade, decidido: true };
		}
		return negado();
	}

	function lerWpConsent() {
		if ( 'function' !== typeof w.wp_has_consent ) {
			return negado();
		}
		var tipo = w.wp_consent_type || w.wp_fallback_consent_type;
		if ( ! tipo ) {
			// Sem tipo, a API concede tudo: falha fechada.
			return negado();
		}
		var a = false;
		var p = false;
		try {
			a = true === w.wp_has_consent( 'statistics' );
			p = true === w.wp_has_consent( 'marketing' );
		} catch ( erro ) {
			return negado();
		}
		var decidido = -1 !== String( tipo ).indexOf( 'optout' );
		if ( ! decidido ) {
			var prefixo = w.consent_api && 'string' === typeof w.consent_api.cookie_prefix ? w.consent_api.cookie_prefix : '';
			decidido = prefixo ? ( '' !== lerCookie( prefixo + '_statistics' ) || '' !== lerCookie( prefixo + '_marketing' ) ) : ( a || p );
		}
		return { analise: a, publicidade: p, decidido: decidido };
	}

	/* Aviso próprio -------------------------------------------------------- */

	var aviso = null;
	var painel = null;
	var personalizar = null;
	var recusar = null;
	var caixas = [];
	var flutuante = null;
	var origem = null;
	var contagem = null;
	var contagemParada = false;

	function focar( el ) {
		if ( ! el || 'function' !== typeof el.focus ) {
			return;
		}
		try {
			el.focus( { preventScroll: true } );
		} catch ( erro ) {
			el.focus();
		}
	}

	function sincronizarCaixas() {
		for ( var i = 0; i < caixas.length; i++ ) {
			caixas[ i ].checked = estado.decidido && true === estado[ caixas[ i ].getAttribute( 'data-rastro-finalidade' ) ];
		}
	}

	function abrirPainel( aberto ) {
		if ( ! painel ) {
			return;
		}
		painel.hidden = ! aberto;
		if ( personalizar ) {
			personalizar.setAttribute( 'aria-expanded', aberto ? 'true' : 'false' );
		}
		if ( aberto ) {
			sincronizarCaixas();
		}
	}

	function atualizarFlutuante() {
		if ( flutuante ) {
			flutuante.hidden = !! ( aviso && ! aviso.hidden );
		}
	}

	function mostrar( comFoco, comPreferencias ) {
		if ( ! aviso ) {
			return;
		}
		aviso.hidden = false;
		abrirPainel( !! comPreferencias );
		atualizarFlutuante();
		if ( comFoco ) {
			focar( comPreferencias && caixas.length ? caixas[ 0 ] : recusar );
		}
	}

	function esconder() {
		if ( ! aviso ) {
			return;
		}
		var focoDentro = 'function' === typeof aviso.contains && aviso.contains( d.activeElement );
		aviso.hidden = true;
		abrirPainel( false );
		atualizarFlutuante();
		if ( focoDentro && origem ) {
			focar( origem );
		}
		origem = null;
	}

	function pararContagem() {
		contagemParada = true;
		if ( null !== contagem ) {
			w.clearTimeout( contagem );
			contagem = null;
		}
	}

	/** Escolha do visitante no aviso próprio (só finalidades com destino). */
	function escolher( e ) {
		var novo = {
			analise: tem( 'analise' ) && true === e.analise,
			publicidade: tem( 'publicidade' ) && true === e.publicidade,
			decidido: true
		};
		pararContagem();
		gravarCookie( 'a' + ( novo.analise ? 1 : 0 ) + 'p' + ( novo.publicidade ? 1 : 0 ) );
		aplicar( novo, true );
		esconder();
	}

	function iniciarContagem() {
		if ( contagemParada || null !== contagem || ! tem( 'analise' ) ) {
			return;
		}
		var eventos = [ 'focusin', 'pointerenter', 'touchstart' ];
		for ( var i = 0; i < eventos.length; i++ ) {
			aviso.addEventListener( eventos[ i ], pararContagem, { passive: true } );
		}
		contagem = w.setTimeout( function () {
			contagem = null;
			if ( contagemParada || estado.decidido ) {
				return;
			}
			// Publicidade nunca liga sozinha.
			escolher( { analise: true, publicidade: false } );
		}, segundos * 1000 );
	}

	function ligarAviso() {
		aviso = d.querySelector( '[data-rastro-aviso]' );
		if ( ! aviso ) {
			return;
		}
		painel = aviso.querySelector( '[data-rastro-aviso-preferencias]' );
		personalizar = aviso.querySelector( '[data-rastro-aviso-personalizar]' );
		recusar = aviso.querySelector( '[data-rastro-aviso-recusar]' );
		var aceitar = aviso.querySelector( '[data-rastro-aviso-aceitar]' );
		var salvar = aviso.querySelector( '[data-rastro-aviso-salvar]' );
		var lista = aviso.querySelectorAll( '[data-rastro-finalidade]' );
		for ( var i = 0; i < lista.length; i++ ) {
			if ( tem( lista[ i ].getAttribute( 'data-rastro-finalidade' ) ) ) {
				caixas.push( lista[ i ] );
			}
		}

		if ( aceitar ) {
			aceitar.addEventListener( 'click', function () {
				escolher( { analise: true, publicidade: true } );
			} );
		}
		if ( recusar ) {
			recusar.addEventListener( 'click', function () {
				escolher( { analise: false, publicidade: false } );
			} );
		}
		if ( personalizar ) {
			personalizar.addEventListener( 'click', function () {
				pararContagem();
				var abrir = ! painel || painel.hidden;
				abrirPainel( abrir );
				if ( abrir && caixas.length ) {
					focar( caixas[ 0 ] );
				}
			} );
		}
		if ( salvar ) {
			salvar.addEventListener( 'click', function () {
				var e = { analise: false, publicidade: false };
				for ( var c = 0; c < caixas.length; c++ ) {
					e[ caixas[ c ].getAttribute( 'data-rastro-finalidade' ) ] = true === caixas[ c ].checked;
				}
				escolher( e );
			} );
		}

		// Esc com o foco dentro: fecha; sem escolha, vale como recusa só nesta visita.
		d.addEventListener( 'keydown', function ( evento ) {
			if ( evento && 'Escape' === evento.key && ! aviso.hidden && aviso.contains( d.activeElement ) ) {
				pararContagem();
				if ( ! estado.decidido ) {
					marcarVisita();
				}
				esconder();
			}
		} );

		if ( ! estado.decidido && ! recusouNestaVisita() ) {
			// Aparecer sozinho não tira o foco de onde a pessoa está.
			mostrar( false, false );
			if ( 'aceite_automatico' === modo ) {
				iniciarContagem();
			}
		}
	}

	/* API ------------------------------------------------------------------ */

	function abrir() {
		if ( 'basico' === modo ) {
			return false;
		}
		if ( 'proprio' === provedor ) {
			if ( ! aviso ) {
				return false;
			}
			pararContagem();
			mostrar( true, estado.decidido );
			return true;
		}
		if ( externo && 'string' === typeof externo.abrir && externo.abrir ) {
			try {
				var botao = d.querySelector( externo.abrir );
				if ( botao && 'function' === typeof botao.click ) {
					botao.click();
					return true;
				}
			} catch ( erro ) {
				// Seletor inválido: nada abre.
			}
		}
		return false;
	}

	function set( e ) {
		e = e && 'object' === typeof e ? e : {};
		if ( 'basico' === modo ) {
			return copia( estado );
		}
		if ( 'proprio' === provedor ) {
			escolher( e );
		} else if ( 'wp_consent_api' === provedor && 'function' === typeof w.wp_set_consent ) {
			try {
				w.wp_set_consent( 'statistics', true === e.analise ? 'allow' : 'deny' );
				w.wp_set_consent( 'marketing', true === e.publicidade ? 'allow' : 'deny' );
			} catch ( erro ) {
				// A API decide; o estado é lido de novo abaixo.
			}
			aplicar( lerWpConsent(), true );
		}
		// Ponte: a escolha é do aviso do site.
		return copia( estado );
	}

	w.rastro.consent = {
		provedor: provedor,
		modo: modo,
		finalidades: finalidades.slice(),
		get: function () {
			return copia( estado );
		},
		set: set,
		abrir: abrir
	};

	/* Carga ---------------------------------------------------------------- */

	if ( 'basico' === modo ) {
		estado = { analise: true, publicidade: true, decidido: true };
	} else if ( 'proprio' === provedor ) {
		estado = lerProprio();
	} else if ( 'wp_consent_api' === provedor ) {
		estado = lerWpConsent();
		d.addEventListener( 'wp_listen_for_consent_change', function () {
			aplicar( lerWpConsent(), true );
		} );
		d.addEventListener( 'wp_consent_type_defined', function () {
			aplicar( lerWpConsent(), false );
		} );
	} else {
		estado = lerPonte( lerCookie( externo.cookie ) );
		if ( 'string' === typeof externo.evento && externo.evento ) {
			d.addEventListener( externo.evento, function ( evento ) {
				var valor = evento && evento.detail && externo.campo ? evento.detail[ externo.campo ] : undefined;
				if ( 'string' !== typeof valor ) {
					valor = lerCookie( externo.cookie );
				}
				aplicar( lerPonte( valor ), true );
			} );
		}
	}

	// Escolha já existente: o Consent Mode sai antes de qualquer tag (no
	// básico o padrão do <head> já é concedido).
	if ( 'basico' !== modo && ( estado.analise || estado.publicidade ) ) {
		consentMode( estado );
	}

	function iniciarDom() {
		if ( 'proprio' === provedor && 'basico' !== modo ) {
			ligarAviso();
		}
		if ( 'basico' !== modo ) {
			flutuante = d.querySelector( '[data-rastro-flutuante]' );
			var botoes = d.querySelectorAll( '[data-rastro-preferencias]' );
			for ( var b = 0; b < botoes.length; b++ ) {
				botoes[ b ].addEventListener( 'click', function ( evento ) {
					origem = evento && evento.currentTarget ? evento.currentTarget : null;
					abrir();
				} );
			}
			atualizarFlutuante();
		}
		// Uma vez na carga, quando já há escolha (e nada mudou antes).
		if ( estado.decidido && 0 === emitidos ) {
			emitir();
		}
	}

	// Com defer, o documento está "interactive" e o DOMContentLoaded ainda
	// vem; o load é a reserva para quando o script entra depois dele.
	var iniciado = false;
	function iniciarUmaVez() {
		if ( iniciado ) {
			return;
		}
		iniciado = true;
		iniciarDom();
	}
	if ( 'complete' === d.readyState ) {
		w.setTimeout( iniciarUmaVez, 0 );
	} else {
		d.addEventListener( 'DOMContentLoaded', iniciarUmaVez );
		w.addEventListener( 'load', iniciarUmaVez );
	}
}( window, document ) );
