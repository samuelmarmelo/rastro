/**
 * Rastro: eventos da loja no navegador (contrato, seção 6; manual
 * docs/EVENTOS.md).
 *
 * Os dados vêm prontos do PHP em window.rastroConfig.eventos, sem dado
 * pessoal. Cada evento sai por window.rastro.event( nome, params, opcoes )
 * quando ela existe; antes disso, entra na fila
 * window.rastroFilaEventos (lista de [ nome, params, opcoes ]), que o
 * módulo de tags esvazia ao começar. Nenhum envio a terceiro sai daqui: o
 * consentimento e a entrega a cada destino são do módulo de tags.
 *
 * - Carga da página (rastroConfig.eventos.carga): view_item_list,
 *   view_item, search, view_cart, begin_checkout (uma vez por carrinho na
 *   sessão) e purchase (uma vez por pedido na sessão; o servidor já marca
 *   o pedido).
 * - add_to_cart: envio do formulário da página de produto (simples,
 *   variável, agrupado e composto, como um item só) e evento added_to_cart
 *   do WooCommerce para os botões das listas (jQuery só quando existe).
 * - remove_from_cart: clique em link de remover do carrinho ou do
 *   minicart (endereço com remove_item=).
 * - add_payment_info: primeira escolha da forma de pagamento (ou o envio
 *   do checkout com a forma já marcada), uma vez.
 *
 * SafeWidget: cada bloco isolado; erro aqui nunca afeta a loja. JS puro,
 * sem dependência; nada é escrito na página.
 */
( function ( w, d ) {
	'use strict';

	var cfg = w.rastroConfig && w.rastroConfig.eventos;
	if ( ! cfg || 'object' !== typeof cfg ) {
		return;
	}

	var MARCA = 'rastro:evento:';
	var memoria = {};
	var ligados = Array.isArray( cfg.ligados ) ? cfg.ligados : [];

	function seguro( nome, fn ) {
		try {
			return fn();
		} catch ( erro ) {
			if ( w.rastroConfig && w.rastroConfig.debug && w.console ) {
				w.console.warn( 'rastro eventos: ' + nome, erro );
			}
			return undefined;
		}
	}

	function ligado( nome ) {
		return -1 !== ligados.indexOf( nome );
	}

	/** Entrega ao módulo de tags, ou guarda na fila até ele começar. */
	function enviar( nome, params, opcoes ) {
		var args = [ nome, params || {}, opcoes || {} ];
		if ( w.rastro && 'function' === typeof w.rastro.event ) {
			w.rastro.event( args[ 0 ], args[ 1 ], args[ 2 ] );
			return;
		}
		w.rastroFilaEventos = w.rastroFilaEventos || [];
		w.rastroFilaEventos.push( args );
	}

	/* Marcas de uma vez por sessão (sessionStorage, com memória de reserva). */

	function armazem() {
		try {
			return w.sessionStorage || null;
		} catch ( erro ) {
			return null;
		}
	}

	function marcado( guarda ) {
		if ( memoria[ guarda ] ) {
			return true;
		}
		var s = armazem();
		try {
			return !! ( s && s.getItem( MARCA + guarda ) );
		} catch ( erro ) {
			return false;
		}
	}

	function marcar( guarda ) {
		memoria[ guarda ] = true;
		var s = armazem();
		try {
			if ( s ) {
				s.setItem( MARCA + guarda, '1' );
			}
		} catch ( erro ) {
			// Sem armazenamento: vale a memória desta página.
		}
	}

	function limparMarcas( prefixos ) {
		Object.keys( memoria ).forEach( function ( guarda ) {
			prefixos.forEach( function ( prefixo ) {
				if ( 0 === guarda.indexOf( prefixo ) ) {
					delete memoria[ guarda ];
				}
			} );
		} );
		var s = armazem();
		try {
			if ( ! s ) {
				return;
			}
			for ( var i = s.length - 1; i >= 0; i-- ) {
				var chave = s.key( i ) || '';
				for ( var j = 0; j < prefixos.length; j++ ) {
					if ( 0 === chave.indexOf( MARCA + prefixos[ j ] ) ) {
						s.removeItem( chave );
						break;
					}
				}
			}
		} catch ( erro ) {
			// Sem armazenamento: nada a limpar.
		}
	}

	/* Itens. */

	function dinheiro( valor ) {
		var n = Number( valor );
		return isFinite( n ) && n > 0 ? Math.round( n * 100 ) / 100 : 0;
	}

	function inteiro( valor ) {
		var n = parseInt( valor, 10 );
		return isNaN( n ) ? 0 : n;
	}

	function copiar( objeto ) {
		var copia = {};
		Object.keys( objeto || {} ).forEach( function ( chave ) {
			copia[ chave ] = objeto[ chave ];
		} );
		return copia;
	}

	function mapa( nome ) {
		var m = cfg[ nome ];
		return m && 'object' === typeof m ? m : {};
	}

	function doMapa( nome, chave ) {
		var m = mapa( nome );
		return Object.prototype.hasOwnProperty.call( m, String( chave ) ) ? copiar( m[ String( chave ) ] ) : null;
	}

	/** Item do catálogo impresso pelo PHP, ou o mínimo (só o item_id). */
	function itemPorId( id, sku ) {
		var item = id > 0 ? ( doMapa( 'produtos', id ) || doMapa( 'variacoes', id ) ) : null;
		if ( item ) {
			return item;
		}
		var itemId = 'sku' === cfg.id_produto && sku ? String( sku ) : ( id > 0 ? String( id ) : '' );
		return itemId ? { item_id: itemId, quantity: 1 } : null;
	}

	function parametros( itens ) {
		var centavos = 0;
		itens.forEach( function ( item ) {
			centavos += Math.round( dinheiro( item.price ) * 100 ) * Math.max( 1, inteiro( item.quantity ) );
		} );
		return { currency: cfg.moeda || '', value: centavos / 100, items: itens };
	}

	/* 1. Eventos da carga da página. */

	seguro( 'carga', function () {
		( Array.isArray( cfg.carga ) ? cfg.carga : [] ).forEach( function ( ev ) {
			seguro( 'carga-evento', function () {
				if ( ! ev || 'string' !== typeof ev.nome || ! ligado( ev.nome ) ) {
					return;
				}
				if ( ev.guarda ) {
					if ( marcado( ev.guarda ) ) {
						return;
					}
					marcar( ev.guarda );
				}
				if ( 'purchase' === ev.nome ) {
					// Compra feita: a próxima finalização é uma sessão nova.
					limparMarcas( [ 'begin_checkout:', 'add_payment_info:' ] );
				}
				enviar( ev.nome, ev.params, ev.opcoes );
			} );
		} );
	} );

	/* 2. add_to_cart pelo formulário da página de produto. */

	function campo( form, nome ) {
		var el = form.querySelector( '[name="' + nome + '"]' );
		return el ? String( el.value || '' ) : '';
	}

	function varianteDosCampos( form ) {
		var partes = [];
		Array.prototype.forEach.call( form.querySelectorAll( 'select[name^="attribute_"]' ), function ( el ) {
			if ( el.value ) {
				partes.push( String( el.value ) );
			}
		} );
		return partes.join( ', ' ).slice( 0, 100 );
	}

	function itensDoFormulario( form ) {
		var itens = [];

		// Produto agrupado: um campo quantity[ID] por filho.
		var grupo = form.querySelectorAll( 'input[name^="quantity["]' );
		if ( grupo.length ) {
			Array.prototype.forEach.call( grupo, function ( el ) {
				var m = /^quantity\[(\d+)\]$/.exec( el.getAttribute( 'name' ) || '' );
				var qtd = inteiro( el.value );
				var item = m && qtd > 0 ? itemPorId( inteiro( m[ 1 ] ), '' ) : null;
				if ( item ) {
					item.quantity = qtd;
					itens.push( item );
				}
			} );
			return itens;
		}

		var id = inteiro( campo( form, 'add-to-cart' ) ) || inteiro( campo( form, 'product_id' ) ) || inteiro( cfg.produto );
		var variacao = inteiro( campo( form, 'variation_id' ) );
		if ( form.classList && form.classList.contains( 'variations_form' ) && variacao <= 0 ) {
			// Escolha da variação incompleta: o WooCommerce não adiciona.
			return itens;
		}

		var item = null;
		if ( variacao > 0 ) {
			item = doMapa( 'variacoes', variacao );
			if ( ! item ) {
				item = itemPorId( id, '' );
				if ( item ) {
					if ( 'sku' !== cfg.id_produto ) {
						item.item_id = String( variacao );
					}
					var variante = varianteDosCampos( form );
					if ( variante ) {
						item.item_variant = variante;
					}
				}
			}
		} else {
			item = itemPorId( id, '' );
		}
		if ( item ) {
			item.quantity = Math.max( 1, inteiro( campo( form, 'quantity' ) ) );
			itens.push( item );
		}
		return itens;
	}

	var ultimoEnvio = { form: null, quando: 0 };

	seguro( 'adicionar-formulario', function () {
		// Captura: roda antes dos scripts que interrompem o envio (AJAX do tema).
		d.addEventListener( 'submit', function ( evento ) {
			seguro( 'adicionar-envio', function () {
				if ( ! ligado( 'add_to_cart' ) ) {
					return;
				}
				var alvo = evento.target;
				var form = alvo && alvo.closest ? alvo.closest( 'form.cart' ) : null;
				if ( ! form || ( 'function' === typeof form.checkValidity && ! form.checkValidity() ) ) {
					return;
				}
				var agora = Date.now();
				if ( ultimoEnvio.form === form && agora - ultimoEnvio.quando < 1500 ) {
					return;
				}
				var itens = itensDoFormulario( form );
				if ( ! itens.length ) {
					return;
				}
				ultimoEnvio = { form: form, quando: agora };
				enviar( 'add_to_cart', parametros( itens ) );
			} );
		}, true );
	} );

	/* 3. add_to_cart pelos botões das listas (added_to_cart do WooCommerce). */

	var ouvindoJquery = false;

	function ouvirJquery() {
		if ( ouvindoJquery || ! w.jQuery || ! d.body ) {
			return;
		}
		ouvindoJquery = true;
		w.jQuery( d.body ).on( 'added_to_cart', function ( evento, fragmentos, hash, botao ) {
			seguro( 'adicionar-ajax', function () {
				if ( ! ligado( 'add_to_cart' ) ) {
					return;
				}
				var el = botao && botao[ 0 ] ? botao[ 0 ] : ( botao && botao.getAttribute ? botao : null );
				// Sem botão não há produto; dentro do formulário já contou no envio.
				if ( ! el || ! el.getAttribute || ( el.closest && el.closest( 'form.cart' ) ) ) {
					return;
				}
				var item = itemPorId( inteiro( el.getAttribute( 'data-product_id' ) ), el.getAttribute( 'data-product_sku' ) || '' );
				if ( ! item ) {
					return;
				}
				item.quantity = Math.max( 1, inteiro( el.getAttribute( 'data-quantity' ) ) );
				enviar( 'add_to_cart', parametros( [ item ] ) );
			} );
		} );
	}

	seguro( 'adicionar-ajax-ouvir', function () {
		ouvirJquery();
		if ( ! ouvindoJquery ) {
			w.addEventListener( 'load', function () {
				seguro( 'adicionar-ajax-ouvir', ouvirJquery );
			} );
		}
	} );

	/* 4. remove_from_cart no carrinho e no minicart. */

	var removidos = {};

	function chaveDoLink( link ) {
		var chave = link.getAttribute( 'data-cart_item_key' ) || link.getAttribute( 'data-cart-key' ) || '';
		if ( ! chave ) {
			var m = /[?&]remove_item=([^&#]+)/.exec( link.getAttribute( 'href' ) || '' );
			chave = m ? decodeURIComponent( m[ 1 ] ) : '';
		}
		return chave;
	}

	seguro( 'remover', function () {
		d.addEventListener( 'click', function ( evento ) {
			seguro( 'remover-clique', function () {
				if ( ! ligado( 'remove_from_cart' ) ) {
					return;
				}
				var alvo = evento.target;
				var link = alvo && alvo.closest ? alvo.closest( 'a[href*="remove_item="]' ) : null;
				if ( ! link ) {
					return;
				}
				var chave = chaveDoLink( link );
				if ( ! chave || removidos[ chave ] ) {
					return;
				}
				var item = doMapa( 'carrinho', chave ) || itemPorId( inteiro( link.getAttribute( 'data-product_id' ) ), link.getAttribute( 'data-product_sku' ) || '' );
				if ( ! item ) {
					return;
				}
				removidos[ chave ] = true;
				enviar( 'remove_from_cart', parametros( [ item ] ) );
			} );
		}, true );
	} );

	/* 5. add_payment_info: primeira escolha da forma de pagamento. */

	var pagamentoEnviado = false;

	function pagamento( tipo ) {
		var checkout = cfg.checkout;
		if ( pagamentoEnviado || ! ligado( 'add_payment_info' ) || ! checkout || ! checkout.params ) {
			return;
		}
		pagamentoEnviado = true;
		var guarda = 'add_payment_info:' + String( checkout.assinatura || '' );
		if ( marcado( guarda ) ) {
			return;
		}
		marcar( guarda );
		var params = copiar( checkout.params );
		params.payment_type = String( tipo || '' ).replace( /[^A-Za-z0-9_-]/g, '' ).slice( 0, 40 );
		enviar( 'add_payment_info', params );
	}

	seguro( 'pagamento', function () {
		if ( ! cfg.checkout ) {
			return;
		}
		d.addEventListener( 'change', function ( evento ) {
			seguro( 'pagamento-escolha', function () {
				var el = evento.target;
				// Só a escolha de quem compra (o WooCommerce também marca sozinho).
				if ( false === evento.isTrusted || ! el || ! el.getAttribute || 'payment_method' !== el.getAttribute( 'name' ) ) {
					return;
				}
				pagamento( el.value );
			} );
		}, true );
		d.addEventListener( 'submit', function ( evento ) {
			seguro( 'pagamento-envio', function () {
				var alvo = evento.target;
				var form = alvo && alvo.closest ? alvo.closest( 'form.checkout' ) : null;
				if ( ! form ) {
					return;
				}
				var escolhido = '';
				Array.prototype.forEach.call( form.querySelectorAll( 'input[name="payment_method"]' ), function ( el ) {
					if ( el.checked && ! escolhido ) {
						escolhido = String( el.value || '' );
					}
				} );
				if ( escolhido ) {
					pagamento( escolhido );
				}
			} );
		}, true );
	} );
}( window, document ) );
