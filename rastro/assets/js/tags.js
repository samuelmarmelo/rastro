/**
 * Rastro: tags e desempenho (contrato, seção 5; manual docs/TAGS.md).
 *
 * Espera o consentimento (evento `rastro:consent` no document, ou
 * window.rastro.consent.get() na carga) e carrega cada destino aceito uma
 * vez, fora do caminho crítico, conforme `carregamento`:
 * - ocioso: depois do `load`, em requestIdleCallback (limite de 2 s), ou na
 *   primeira interação, o que vier antes;
 * - interacao: primeira interação (pointerdown, keydown, scroll,
 *   touchstart), com limite de 8 s;
 * - imediato: na hora do aceite.
 *
 * window.rastro.event( nome, params, opcoes ): evento canônico no formato do
 * GA4, guardado numa fila na memória da página e entregue a cada destino
 * quando ele carrega. Sem aceite, a fila é descartada.
 *
 * Trechos de carga oficiais (Google, Meta, TikTok, OpenAI, PostHog)
 * adaptados: a fila de cada serviço é a do trecho oficial e a inserção do
 * <script> passa por um ajudante único (uma vez por chave). Cada destino roda
 * num try/catch: um carregador com erro não derruba os outros.
 *
 * JS puro, sem biblioteca e sem etapa de build.
 */
( function ( w, d ) {
	'use strict';

	w.rastro = w.rastro || {};
	if ( w.rastro.tags && w.rastro.tags.iniciado ) {
		return;
	}

	// Só propriedades próprias (nome de evento "constructor" não acha nada).
	function de( mapa, chave ) {
		return 'string' === typeof chave && Object.prototype.hasOwnProperty.call( mapa, chave ) ? mapa[ chave ] : undefined;
	}

	var cfg = w.rastroConfig && 'object' === typeof w.rastroConfig ? w.rastroConfig : {};
	var tagsCfg = cfg.tags && 'object' === typeof cfg.tags ? cfg.tags : {};
	var destinosCfg = tagsCfg.destinos && 'object' === typeof tagsCfg.destinos ? tagsCfg.destinos : {};
	var pagina = cfg.pagina && 'object' === typeof cfg.pagina ? cfg.pagina : {};
	var modoCarga = de( { ocioso: 1, interacao: 1, imediato: 1 }, cfg.carregamento ) ? cfg.carregamento : 'ocioso';

	var ORDEM = [ 'gtm', 'ga4', 'ads', 'meta', 'tiktok', 'openai', 'posthog' ];
	var FINALIDADES = { analise: 1, publicidade: 1 };
	// Mesmas regras do esquema da base (nucleo/config.php), conferidas de novo aqui.
	var IDS = {
		gtm: /^GTM-[A-Z0-9]{4,12}$/,
		ga4: /^G-[A-Z0-9]{6,14}$/,
		ads: /^AW-[0-9]{6,14}$/,
		meta: /^[0-9]{8,20}$/,
		tiktok: /^[A-Z0-9]{15,30}$/,
		openai: /^[A-Za-z0-9_-]{10,64}$/,
		posthog: /^phc_[A-Za-z0-9]{20,80}$/
	};
	var ROTULO = /^[A-Za-z0-9_-]{6,40}$/;
	var POSTHOG_HOST = /^https:\/\/(us|eu)\.i\.posthog\.com$/;
	var NOME_EVENTO = /^[a-z][a-z0-9_]{0,39}$/;
	var HOST_GOOGLE = 'https://www.googletagmanager.com';
	var HOSTS = {
		gtm: HOST_GOOGLE,
		ga4: HOST_GOOGLE,
		ads: HOST_GOOGLE,
		meta: 'https://connect.facebook.net',
		tiktok: 'https://analytics.tiktok.com',
		openai: 'https://bzrcdn.openai.com'
	};
	var INTERACOES = [ 'pointerdown', 'keydown', 'scroll', 'touchstart' ];
	var LIMITE_OCIOSO = 2000;
	var LIMITE_INTERACAO = 8000;
	var FILA_MAXIMA = 100;
	// Eventos com objeto ecommerce no dataLayer (formato do GA4).
	var ECOMMERCE = { view_item_list: 1, view_item: 1, select_item: 1, add_to_cart: 1, remove_from_cart: 1, view_cart: 1, begin_checkout: 1, add_shipping_info: 1, add_payment_info: 1, purchase: 1, refund: 1 };
	// Mapa da seção 6 do contrato.
	var PARA_ADS = { view_item_list: 1, view_item: 1, add_to_cart: 1, begin_checkout: 1, purchase: 1, search: 1 };
	var PARA_META = { view_item: 'ViewContent', add_to_cart: 'AddToCart', begin_checkout: 'InitiateCheckout', add_payment_info: 'AddPaymentInfo', purchase: 'Purchase', search: 'Search' };
	var PARA_TIKTOK = { view_item: 'ViewContent', add_to_cart: 'AddToCart', begin_checkout: 'InitiateCheckout', add_payment_info: 'AddPaymentInfo', purchase: 'Purchase', search: 'Search' };

	var estado = { analise: false, publicidade: false, decidido: false };
	var recebeuConsentimento = false;
	var destinos = {};
	var carregados = {};
	var tentados = {};
	var scripts = {};
	var conectados = {};
	var fila = [];
	var parado = false;
	var portaAberta = false;
	var agendado = false;
	var desfazer = [];
	var gtagIniciado = false;

	/* Configuração ------------------------------------------------------------ */

	// Só destinos conhecidos, com ID válido e finalidades da lista fechada.
	ORDEM.forEach( function ( chave ) {
		var c = destinosCfg[ chave ];
		if ( ! c || 'object' !== typeof c || 'string' !== typeof c.id || ! IDS[ chave ].test( c.id ) ) {
			return;
		}
		var com = [];
		( Array.isArray( c.carrega_com ) ? c.carrega_com : [] ).forEach( function ( f ) {
			if ( de( FINALIDADES, f ) && com.indexOf( f ) < 0 ) {
				com.push( f );
			}
		} );
		if ( ! com.length ) {
			return;
		}
		if ( 'posthog' === chave && ( ! c.config || 'object' !== typeof c.config || ! POSTHOG_HOST.test( String( c.config.api_host ) ) ) ) {
			return;
		}
		destinos[ chave ] = { carrega_com: com, c: c };
	} );

	function permitido( chave ) {
		var dest = destinos[ chave ];
		if ( ! dest ) {
			return false;
		}
		for ( var i = 0; i < dest.carrega_com.length; i++ ) {
			if ( true === estado[ dest.carrega_com[ i ] ] ) {
				return true;
			}
		}
		return false;
	}

	/* DOM --------------------------------------------------------------------- */

	function ondeInserir() {
		return d.head || d.documentElement || null;
	}

	// preconnect do host só no momento da carga.
	function preconectar( host, anonimo ) {
		if ( ! host || conectados[ host ] ) {
			return;
		}
		conectados[ host ] = true;
		try {
			var l = d.createElement( 'link' );
			l.rel = 'preconnect';
			l.href = host;
			if ( anonimo ) {
				l.crossOrigin = 'anonymous';
			}
			var alvo = ondeInserir();
			if ( alvo ) {
				alvo.appendChild( l );
			}
		} catch ( e ) {}
	}

	// <script async> uma vez por chave (como nos trechos oficiais: antes do primeiro script).
	function inserirScript( chave, src ) {
		if ( scripts[ chave ] ) {
			return;
		}
		scripts[ chave ] = true;
		var s = d.createElement( 'script' );
		s.async = true;
		s.src = src;
		s.setAttribute( 'data-rastro-tag', chave );
		var primeiro = d.getElementsByTagName( 'script' )[ 0 ];
		if ( primeiro && primeiro.parentNode ) {
			primeiro.parentNode.insertBefore( s, primeiro );
		} else {
			ondeInserir().appendChild( s );
		}
	}

	function disparar( nome, detalhe ) {
		try {
			var ev;
			if ( 'function' === typeof w.CustomEvent ) {
				ev = new w.CustomEvent( nome, { detail: detalhe } );
			} else {
				ev = d.createEvent( 'CustomEvent' );
				ev.initCustomEvent( nome, false, false, detalhe );
			}
			d.dispatchEvent( ev );
		} catch ( e ) {}
	}

	/* Carregadores ------------------------------------------------------------ */

	function filaGtag() {
		w.dataLayer = w.dataLayer || [];
		if ( 'function' !== typeof w.gtag ) {
			// O gtag.js só lê o objeto arguments empurrado no dataLayer.
			w.gtag = function () {
				w.dataLayer.push( arguments );
			};
		}
		return w.gtag;
	}

	// Endereço saneado do servidor, só em página privada e com protocolo.
	function urlPrivada() {
		return true === pagina.privada && 'string' === typeof pagina.url && /^https?:\/\//i.test( pagina.url ) ? pagina.url : '';
	}

	// Um só gtag/js para GA4 e Ads (o primeiro ID que carregar dá o endereço).
	// Em página privada, antes de qualquer config: endereço saneado e sem
	// referrer para todos os IDs (gtag('set', campo, valor), forma documentada).
	function baseGtag( id ) {
		var gtag = filaGtag();
		if ( ! gtagIniciado ) {
			gtagIniciado = true;
			gtag( 'js', new Date() );
			if ( urlPrivada() ) {
				gtag( 'set', 'page_location', urlPrivada() );
				gtag( 'set', 'page_referrer', '' );
			}
		}
		preconectar( HOST_GOOGLE );
		inserirScript( 'gtag', HOST_GOOGLE + '/gtag/js?id=' + encodeURIComponent( id ) );
		return gtag;
	}

	var CARREGAR = {
		// Trecho oficial do contêiner (gtm.start e gtm.js).
		gtm: function ( c ) {
			w.dataLayer = w.dataLayer || [];
			w.dataLayer.push( { 'gtm.start': new Date().getTime(), event: 'gtm.js' } );
			preconectar( HOSTS.gtm );
			inserirScript( 'gtm', HOST_GOOGLE + '/gtm.js?id=' + encodeURIComponent( c.id ) );
		},
		ga4: function ( c ) {
			var opcoes = { send_page_view: false !== c.send_page_view };
			if ( 'string' === typeof c.page_location && /^https?:\/\//i.test( c.page_location ) ) {
				opcoes.page_location = c.page_location;
			}
			baseGtag( c.id )( 'config', c.id, opcoes );
		},
		ads: function ( c ) {
			baseGtag( c.id )( 'config', c.id );
		},
		// Trecho oficial do Meta Pixel (fila fbq).
		meta: function ( c ) {
			if ( ! w.fbq ) {
				var n = w.fbq = function () {
					if ( n.callMethod ) {
						n.callMethod.apply( n, arguments );
					} else {
						n.queue.push( arguments );
					}
				};
				if ( ! w._fbq ) {
					w._fbq = n;
				}
				n.push = n;
				n.loaded = true;
				n.version = '2.0';
				n.queue = [];
				preconectar( HOSTS.meta );
				inserirScript( 'meta', HOSTS.meta + '/en_US/fbevents.js' );
			}
			// Sem configuração automática (cliques e metadados da página), antes do init.
			w.fbq( 'set', 'autoConfig', false, c.id );
			w.fbq( 'init', c.id );
			w.fbq( 'track', 'PageView' );
		},
		// Trecho oficial do TikTok Pixel (fila ttq).
		tiktok: function ( c ) {
			w.TiktokAnalyticsObject = 'ttq';
			var ttq = w.ttq = w.ttq || [];
			ttq.methods = [ 'page', 'track', 'identify', 'instances', 'debug', 'on', 'off', 'once', 'ready', 'alias', 'group', 'enableCookie', 'disableCookie', 'holdConsent', 'revokeConsent', 'grantConsent' ];
			ttq.setAndDefer = function ( t, e ) {
				t[ e ] = function () {
					t.push( [ e ].concat( Array.prototype.slice.call( arguments, 0 ) ) );
				};
			};
			for ( var i = 0; i < ttq.methods.length; i++ ) {
				ttq.setAndDefer( ttq, ttq.methods[ i ] );
			}
			ttq.instance = function ( t ) {
				for ( var e = ttq._i[ t ] || [], n = 0; n < ttq.methods.length; n++ ) {
					ttq.setAndDefer( e, ttq.methods[ n ] );
				}
				return e;
			};
			ttq.load = function ( e, n ) {
				var r = HOSTS.tiktok + '/i18n/pixel/events.js';
				ttq._i = ttq._i || {};
				ttq._i[ e ] = [];
				ttq._i[ e ]._u = r;
				ttq._t = ttq._t || {};
				ttq._t[ e ] = +new Date();
				ttq._o = ttq._o || {};
				ttq._o[ e ] = n || {};
				preconectar( HOSTS.tiktok );
				inserirScript( 'tiktok', r + '?sdkid=' + encodeURIComponent( e ) + '&lib=ttq' );
			};
			ttq.load( c.id );
			ttq.page();
		},
		// Trecho oficial da OpenAI (fila oaiq).
		openai: function ( c ) {
			if ( ! w.oaiq ) {
				var q = function () {
					q.q.push( arguments );
				};
				q.q = [];
				w.oaiq = q;
				preconectar( HOSTS.openai );
				inserirScript( 'openai', HOSTS.openai + '/sdk/oaiq.min.js' );
			}
			w.oaiq( 'init', { pixelId: c.id, debug: true === c.debug } );
			w.oaiq( 'measure', 'page_viewed', { type: 'contents' } );
		},
		// Trecho oficial do PostHog (insere o array.js a partir do api_host).
		posthog: function ( c ) {
			var opcoes = copiar( c.config ) || {};
			preconectar( opcoes.api_host.replace( '.i.posthog.com', '-assets.i.posthog.com' ), true );
			scripts.posthog = true;
			/* eslint-disable */
			!function(t,e){var o,n,p,r;e.__SV||(window.posthog && window.posthog.__loaded)||(window.posthog=e,e._i=[],e.init=function(i,s,a){function g(t,e){var o=e.split(".");2==o.length&&(t=t[o[0]],e=o[1]),t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}}p||((p=t.createElement("script")).type="text/javascript",p.crossOrigin="anonymous",p.async=!0,p.src=s.api_host.replace(".i.posthog.com","-assets.i.posthog.com")+"/static/array.js",p.onerror=function(){p=null},(r=t.getElementsByTagName("script")[0]).parentNode.insertBefore(p,r));var u=e;for(void 0!==a?u=e[a]=[]:a="posthog",u.people=u.people||[],Object.defineProperty(u,"toString",{configurable:!0,enumerable:!0,writable:!0,value:function(t){var e="posthog";return"posthog"!==a&&(e+="."+a),t||(e+=" (stub)"),e}}),Object.defineProperty(u.people,"toString",{configurable:!0,enumerable:!0,writable:!0,value:function(){return u.toString(1)+".people (stub)"}}),o="mh bh wh xh init Xh ed Gh Jh od ac Yh ad Uh fd pd gd md yd bd capture getExtension nd mr Zh Kh calculateEventProperties wd register register_once register_for_session unregister unregister_for_session Cd Vh Sd getFeatureFlag getFeatureFlagPayload getFeatureFlagResult getAllFeatureFlags isFeatureEnabled reloadFeatureFlags updateFlags updateEarlyAccessFeatureEnrollment getEarlyAccessFeatures on onFeatureFlags onSurveysLoaded onSessionId getSurveys getActiveMatchingSurveys onActiveMatchingSurveysChanged renderSurvey displaySurvey cancelPendingSurvey canRenderSurvey canRenderSurveyAsync Td identify setPersonProperties unsetPersonProperties group resetGroups setPersonPropertiesForFlags resetPersonPropertiesForFlags setGroupPropertiesForFlags resetGroupPropertiesForFlags reset Id shutdown setIdentity clearIdentity get_distinct_id getGroups get_session_id get_session_replay_url alias set_config startSessionRecording stopSessionRecording sessionRecordingStarted captureException addExceptionStep captureLog startExceptionAutocapture stopExceptionAutocapture loadToolbar get_property getSessionProperty xd hd createPersonProfile setInternalOrTestUser $d Ih Ch opt_in_capturing opt_out_capturing Th has_opted_in_capturing has_opted_out_capturing get_explicit_consent_status is_capturing clear_opt_in_out_capturing ld debug lc Fs getPageViewId captureTraceFeedback captureTraceMetric qh".split(" "),n=0;n<o.length;n++)g(u,o[n]);e._i.push([i,s,a])},e.__SV=1)}(document,window.posthog||[]);
			/* eslint-enable */
			opcoes.before_send = limparEventoPosthog;
			opcoes.session_recording = opcoes.session_recording || {};
			opcoes.session_recording.maskCapturedNetworkRequestFn = function ( r ) {
				if ( r && r.name ) {
					r.name = semConsulta( r.name );
				}
				return r;
			};
			w.posthog.init( c.id, opcoes );
		}
	};

	/* Endereços no PostHog ------------------------------------------------------ */

	function semConsulta( v ) {
		return 'string' === typeof v ? v.replace( /[?#].*$/, '' ) : v;
	}

	function caminhoDe( url ) {
		var m = /^https?:\/\/[^/]+(\/[^?#]*)?/i.exec( String( url ) );
		return m ? ( m[ 1 ] || '/' ) : '/';
	}

	// Endereços sem consulta nem âncora; em página privada, o endereço saneado do servidor.
	function limparEventoPosthog( ev ) {
		try {
			if ( ! ev || 'object' !== typeof ev ) {
				return ev;
			}
			var privada = true === pagina.privada && 'string' === typeof pagina.url;
			var limpar = function ( o ) {
				if ( ! o || 'object' !== typeof o ) {
					return;
				}
				for ( var k in o ) {
					if ( ! Object.prototype.hasOwnProperty.call( o, k ) || 'string' !== typeof o[ k ] ) {
						continue;
					}
					if ( /^(https?:)?\/\//i.test( o[ k ] ) || /(url|referrer|referer|href|pathname)$/i.test( k ) ) {
						o[ k ] = semConsulta( o[ k ] );
					}
				}
				if ( privada ) {
					if ( 'string' === typeof o.$current_url ) {
						o.$current_url = pagina.url;
					}
					if ( 'string' === typeof o.$pathname ) {
						o.$pathname = caminhoDe( pagina.url );
					}
				}
			};
			var p = ev.properties;
			limpar( p );
			limpar( ev.$set );
			limpar( ev.$set_once );
			if ( p && 'object' === typeof p ) {
				limpar( p.$set );
				limpar( p.$set_once );
				if ( 'string' === typeof p.$elements_chain ) {
					p.$elements_chain = p.$elements_chain.replace( /(attr__href=")([^"?#]*)[?#][^"]*"/g, '$1$2"' );
				}
			}
		} catch ( e ) {}
		return ev;
	}

	/* Eventos ------------------------------------------------------------------- */

	function copiar( v ) {
		if ( ! v || 'object' !== typeof v ) {
			return null;
		}
		try {
			return JSON.parse( JSON.stringify( v ) );
		} catch ( e ) {
			return null;
		}
	}

	function itensDe( p ) {
		return Array.isArray( p.items ) ? p.items.filter( function ( i ) {
			return i && 'object' === typeof i;
		} ) : [];
	}

	function numero( v ) {
		var n = 'string' === typeof v ? parseFloat( v ) : v;
		return 'number' === typeof n && isFinite( n ) ? n : undefined;
	}

	function quantidade( i ) {
		var q = numero( i.quantity );
		return undefined === q ? 1 : q;
	}

	function valorMoeda( p, saida ) {
		var valor = numero( p.value );
		if ( undefined !== valor ) {
			saida.value = valor;
		}
		if ( 'string' === typeof p.currency && /^[A-Z]{3}$/.test( p.currency ) ) {
			saida.currency = p.currency;
		}
		return saida;
	}

	function totalItens( itens ) {
		var total = 0;
		itens.forEach( function ( i ) {
			total += quantidade( i );
		} );
		return total;
	}

	// ID de deduplicação: o de opcoes, ou na compra rastro-<número do pedido>.
	function idDoEvento( ev ) {
		if ( ev.opcoes && 'string' === typeof ev.opcoes.event_id && /^[A-Za-z0-9_.:-]{1,80}$/.test( ev.opcoes.event_id ) ) {
			return ev.opcoes.event_id;
		}
		if ( 'purchase' === ev.nome && ( 'string' === typeof ev.params.transaction_id || 'number' === typeof ev.params.transaction_id ) && /^[A-Za-z0-9_-]{1,40}$/.test( String( ev.params.transaction_id ) ) ) {
			return 'rastro-' + String( ev.params.transaction_id );
		}
		return '';
	}

	function dadosMeta( ev ) {
		var p = ev.params;
		var itens = itensDe( p );
		var dados = valorMoeda( p, {} );
		if ( itens.length ) {
			dados.content_type = 'product';
			dados.content_ids = itens.map( function ( i ) {
				return String( i.item_id );
			} );
			dados.contents = itens.map( function ( i ) {
				return { id: String( i.item_id ), quantity: quantidade( i ) };
			} );
		}
		if ( 'begin_checkout' === ev.nome ) {
			dados.num_items = totalItens( itens );
		}
		if ( 'search' === ev.nome && 'string' === typeof p.search_term ) {
			dados.search_string = p.search_term;
		}
		return dados;
	}

	function dadosTiktok( ev ) {
		var p = ev.params;
		var itens = itensDe( p );
		var dados = valorMoeda( p, {} );
		if ( itens.length ) {
			dados.content_type = 'product';
			dados.contents = itens.map( function ( i ) {
				var item = { content_id: String( i.item_id ), content_type: 'product', quantity: quantidade( i ) };
				if ( 'string' === typeof i.item_name ) {
					item.content_name = i.item_name;
				}
				if ( undefined !== numero( i.price ) ) {
					item.price = numero( i.price );
				}
				return item;
			} );
		}
		if ( 'search' === ev.nome && 'string' === typeof p.search_term ) {
			dados.search_string = p.search_term;
		}
		return dados;
	}

	var ENTREGAR = {
		gtm: function ( ev ) {
			w.dataLayer = w.dataLayer || [];
			if ( de( ECOMMERCE, ev.nome ) ) {
				// Limpa o objeto ecommerce anterior antes do novo.
				w.dataLayer.push( { ecommerce: null } );
				w.dataLayer.push( { event: ev.nome, ecommerce: copiar( ev.params ) || {} } );
				return;
			}
			var dados = copiar( ev.params ) || {};
			dados.event = ev.nome;
			w.dataLayer.push( dados );
		},
		ga4: function ( ev, c ) {
			var dados = copiar( ev.params ) || {};
			dados.send_to = c.id;
			filaGtag()( 'event', ev.nome, dados );
		},
		ads: function ( ev, c ) {
			if ( ! de( PARA_ADS, ev.nome ) ) {
				return;
			}
			var gtag = filaGtag();
			var dados = copiar( ev.params ) || {};
			dados.send_to = c.id;
			gtag( 'event', ev.nome, dados );
			if ( 'purchase' === ev.nome && 'string' === typeof c.rotulo_compra && ROTULO.test( c.rotulo_compra ) ) {
				var conversao = valorMoeda( ev.params, { send_to: c.id + '/' + c.rotulo_compra } );
				if ( undefined !== ev.params.transaction_id ) {
					conversao.transaction_id = String( ev.params.transaction_id );
				}
				gtag( 'event', 'conversion', conversao );
			}
		},
		meta: function ( ev ) {
			var nome = de( PARA_META, ev.nome );
			if ( ! nome || 'function' !== typeof w.fbq ) {
				return;
			}
			var id = idDoEvento( ev );
			if ( id ) {
				w.fbq( 'track', nome, dadosMeta( ev ), { eventID: id } );
			} else {
				w.fbq( 'track', nome, dadosMeta( ev ) );
			}
		},
		tiktok: function ( ev ) {
			var nome = de( PARA_TIKTOK, ev.nome );
			if ( ! nome || ! w.ttq || 'function' !== typeof w.ttq.track ) {
				return;
			}
			var id = idDoEvento( ev );
			if ( id ) {
				w.ttq.track( nome, dadosTiktok( ev ), { event_id: id } );
			} else {
				w.ttq.track( nome, dadosTiktok( ev ) );
			}
		},
		// Sem eventos da loja na OpenAI por enquanto (só page_viewed; docs/TAGS.md).
		openai: function () {},
		// Mesmo nome canônico; só value, currency e quantidade_itens.
		posthog: function ( ev ) {
			if ( ! w.posthog || 'function' !== typeof w.posthog.capture ) {
				return;
			}
			var dados = valorMoeda( ev.params, {} );
			var itens = itensDe( ev.params );
			if ( itens.length ) {
				dados.quantidade_itens = totalItens( itens );
			}
			w.posthog.capture( ev.nome, dados );
		}
	};

	function entregar( chave, ev ) {
		if ( ev.entregue[ chave ] ) {
			return;
		}
		ev.entregue[ chave ] = true;
		try {
			ENTREGAR[ chave ]( ev, destinos[ chave ].c );
		} catch ( e ) {}
	}

	function entregarFila( chave ) {
		for ( var i = 0; i < fila.length; i++ ) {
			entregar( chave, fila[ i ] );
		}
	}

	function semAceite() {
		return estado.decidido && ! estado.analise && ! estado.publicidade;
	}

	function evento( nome, params, opcoes ) {
		if ( parado || 'string' !== typeof nome || ! NOME_EVENTO.test( nome ) ) {
			return false;
		}
		var ev = {
			nome: nome,
			params: copiar( params ) || {},
			opcoes: copiar( opcoes ) || {},
			entregue: {}
		};
		// Aviso local na página (nada sai daqui); quem ouvir confere o consentimento.
		disparar( 'rastro:event', { nome: ev.nome, params: copiar( ev.params ) || {}, opcoes: copiar( ev.opcoes ) || {} } );
		if ( semAceite() ) {
			return false;
		}
		fila.push( ev );
		if ( fila.length > FILA_MAXIMA ) {
			fila.shift();
		}
		ORDEM.forEach( function ( chave ) {
			if ( carregados[ chave ] ) {
				entregar( chave, ev );
			}
		} );
		return true;
	}

	/* Carga --------------------------------------------------------------------- */

	// Destinos que leem o endereço da barra sozinhos (sem page_location saneado).
	var LEEM_ENDERECO = { gtm: 1, meta: 1, tiktok: 1, openai: 1 };
	var enderecoTrocado = false;

	// Pedido recebido: tira a chave e o número do pedido da barra de endereço
	// (history.replaceState com o caminho saneado do servidor) logo antes de
	// carregar um destino que lê o endereço sozinho. Efeito colateral: recarregar
	// a página depois mostra o pedido recebido do WooCommerce sem a chave.
	function trocarEndereco() {
		if ( enderecoTrocado || 'pedido_recebido' !== pagina.tipo || 'string' !== typeof pagina.url ) {
			return;
		}
		enderecoTrocado = true;
		try {
			var loc = w.location;
			var hist = w.history;
			if ( ! loc || ! hist || 'function' !== typeof hist.replaceState ) {
				return;
			}
			var limpo = caminhoDe( pagina.url );
			if ( String( loc.pathname || '' ) + String( loc.search || '' ) !== limpo ) {
				hist.replaceState( null, '', limpo );
			}
		} catch ( e ) {}
	}

	function carregarPermitidos() {
		if ( parado ) {
			return;
		}
		ORDEM.forEach( function ( chave ) {
			if ( tentados[ chave ] || ! permitido( chave ) ) {
				return;
			}
			tentados[ chave ] = true;
			if ( de( LEEM_ENDERECO, chave ) ) {
				trocarEndereco();
			}
			try {
				CARREGAR[ chave ]( destinos[ chave ].c );
				carregados[ chave ] = true;
			} catch ( e ) {
				return;
			}
			entregarFila( chave );
		} );
	}

	function limparAgenda() {
		desfazer.forEach( function ( fn ) {
			try {
				fn();
			} catch ( e ) {}
		} );
		desfazer = [];
	}

	function abrirPorta() {
		if ( portaAberta || parado ) {
			return;
		}
		portaAberta = true;
		limparAgenda();
		carregarPermitidos();
	}

	function aoInteragir() {
		abrirPorta();
	}

	function ouvirInteracao() {
		var opcoes = { passive: true, capture: true };
		INTERACOES.forEach( function ( tipo ) {
			w.addEventListener( tipo, aoInteragir, opcoes );
			desfazer.push( function () {
				w.removeEventListener( tipo, aoInteragir, opcoes );
			} );
		} );
	}

	function depoisDaCarga( fn ) {
		if ( 'complete' === d.readyState ) {
			fn();
			return;
		}
		var aoCarregar = function () {
			w.removeEventListener( 'load', aoCarregar );
			fn();
		};
		w.addEventListener( 'load', aoCarregar );
		desfazer.push( function () {
			w.removeEventListener( 'load', aoCarregar );
		} );
	}

	function quandoOcioso( fn ) {
		if ( 'function' === typeof w.requestIdleCallback ) {
			var pedido = w.requestIdleCallback( fn, { timeout: LIMITE_OCIOSO } );
			desfazer.push( function () {
				if ( 'function' === typeof w.cancelIdleCallback ) {
					w.cancelIdleCallback( pedido );
				}
			} );
			return;
		}
		var t = w.setTimeout( fn, 1 );
		desfazer.push( function () {
			w.clearTimeout( t );
		} );
	}

	function agendar() {
		if ( portaAberta ) {
			carregarPermitidos();
			return;
		}
		if ( agendado ) {
			return;
		}
		agendado = true;
		if ( 'imediato' === modoCarga ) {
			abrirPorta();
			return;
		}
		ouvirInteracao();
		if ( 'interacao' === modoCarga ) {
			var t = w.setTimeout( abrirPorta, LIMITE_INTERACAO );
			desfazer.push( function () {
				w.clearTimeout( t );
			} );
			return;
		}
		depoisDaCarga( function () {
			quandoOcioso( abrirPorta );
		} );
	}

	/* Consentimento --------------------------------------------------------------- */

	function parar() {
		if ( parado ) {
			return;
		}
		parado = true;
		fila = [];
		limparAgenda();
		try {
			if ( carregados.posthog && w.posthog && 'function' === typeof w.posthog.opt_out_capturing ) {
				w.posthog.opt_out_capturing();
			}
		} catch ( e ) {}
		try {
			if ( carregados.openai && 'function' === typeof w.oaiq ) {
				w.oaiq( 'consent', false );
			}
		} catch ( e ) {}
		// Meta: pausa o envio do Pixel até a recarga (API de consentimento oficial).
		try {
			if ( carregados.meta && 'function' === typeof w.fbq ) {
				w.fbq( 'consent', 'revoke' );
			}
		} catch ( e ) {}
	}

	// Algum destino carregou nesta página? (mesmo depois de parar(): a revogação
	// usa isto para saber se precisa recarregar)
	function algumCarregado() {
		for ( var i = 0; i < ORDEM.length; i++ ) {
			if ( carregados[ ORDEM[ i ] ] ) {
				return true;
			}
		}
		return false;
	}

	function aplicar( novo ) {
		if ( parado || ! novo || 'object' !== typeof novo ) {
			return;
		}
		estado = {
			analise: true === novo.analise,
			publicidade: true === novo.publicidade,
			decidido: true === novo.decidido
		};
		// Revogação: um destino carregado perdeu o aceite.
		for ( var i = 0; i < ORDEM.length; i++ ) {
			if ( carregados[ ORDEM[ i ] ] && ! permitido( ORDEM[ i ] ) ) {
				parar();
				return;
			}
		}
		if ( ! estado.analise && ! estado.publicidade ) {
			if ( estado.decidido ) {
				fila = [];
			}
			return;
		}
		agendar();
	}

	w.rastro.event = evento;

	// Eventos chamados antes deste arquivo (eventos.js da loja usa a fila
	// window.rastroFilaEventos: [ nome, params, opcoes ]), na ordem, e a fila
	// fica vazia. Daqui em diante, push na fila também vira evento.
	( function () {
		var antiga = w.rastroFilaEventos;
		var pendentes = Object.prototype.toString.call( antiga ) === '[object Array]' ? antiga.slice() : [];
		var entregar = function ( item ) {
			if ( item && 'string' === typeof item[ 0 ] ) {
				try {
					evento( item[ 0 ], item[ 1 ] || {}, item[ 2 ] || {} );
				} catch ( e ) {
					// Um evento com erro não impede os outros.
				}
			}
		};
		var nova = [];
		nova.push = function () {
			for ( var a = 0; a < arguments.length; a++ ) {
				entregar( arguments[ a ] );
			}
			return 0;
		};
		w.rastroFilaEventos = nova;
		for ( var p = 0; p < pendentes.length; p++ ) {
			entregar( pendentes[ p ] );
		}
	}() );

	w.rastro.tags = {
		iniciado: true,
		carregado: function ( destino ) {
			if ( parado ) {
				return false;
			}
			if ( undefined === destino ) {
				return algumCarregado();
			}
			return true === carregados[ destino ];
		},
		algumCarregado: algumCarregado,
		parar: parar
	};

	d.addEventListener( 'rastro:consent', function ( e ) {
		recebeuConsentimento = true;
		aplicar( e && e.detail );
	} );

	// Escolha já feita antes deste arquivo rodar.
	if ( ! recebeuConsentimento ) {
		try {
			if ( w.rastro.consent && 'function' === typeof w.rastro.consent.get ) {
				var atual = w.rastro.consent.get();
				if ( atual && 'object' === typeof atual && ( atual.analise || atual.publicidade || atual.decidido ) ) {
					aplicar( atual );
				}
			}
		} catch ( e ) {}
	}
	// Modo básico (seção 2.1): sem aviso, tudo concedido desde a carga.
	if ( ! recebeuConsentimento && ! estado.analise && ! estado.publicidade && cfg.consent && 'basico' === cfg.consent.modo ) {
		aplicar( { analise: true, publicidade: true, decidido: true } );
	}
}( window, document ) );
