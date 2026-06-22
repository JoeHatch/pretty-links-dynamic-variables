/**
 * Pretty Links DV — click-time capture.
 *
 * Reads page / list-position / operator / placement / context straight from the
 * DOM the visitor actually sees, and appends them as prefixed query params to the
 * Pretty Link href just before navigation. JS-primary by design: the listing is
 * reordered client-side by geo, so the position a visitor clicks from only exists
 * in the live DOM — server-rendered order would be wrong.
 *
 * The redirect handler reads these params, records them, and strips them from the
 * outbound URL so they never reach the affiliate network.
 *
 * No-JS clicks still record and still get the encrypted DV token injected
 * server-side; they simply lack the page/position dimensions.
 */
( function () {
	'use strict';

	var cfg = window.PLDV_CAPTURE || {};
	var prefix = cfg.prefix || 'pldv_';
	var match = cfg.match || '/go/';

	function pageId() {
		var p = ( window.location.pathname || '' ).replace( /^\/+|\/+$/g, '' );
		return p || 'home';
	}

	function isTracked( a ) {
		if ( ! a || ! a.getAttribute ) {
			return false;
		}
		var href = a.getAttribute( 'href' ) || '';
		return href.indexOf( match ) !== -1;
	}

	function rowOf( a ) {
		if ( ! a.closest ) {
			return null;
		}
		return a.closest(
			'[data-review-pagination-item],[data-original-order],.trusted-row,tr,li'
		);
	}

	// 1-based position of `row` among its visible same-tag siblings (the order the
	// visitor sees at click time, after any geo reordering).
	function livePosition( row ) {
		if ( ! row || ! row.parentNode ) {
			return null;
		}
		var siblings = row.parentNode.children;
		var n = 0;
		for ( var i = 0; i < siblings.length; i++ ) {
			var r = siblings[ i ];
			if ( r.nodeType !== 1 || r.tagName !== row.tagName ) {
				continue;
			}
			// Skip hidden rows (display:none / hidden geo-restricted rows).
			if ( r !== row && r.offsetParent === null ) {
				continue;
			}
			n++;
			if ( r === row ) {
				return n;
			}
		}
		return null;
	}

	function hasParam( url, key ) {
		var re = new RegExp(
			'[?&]' + key.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ) + '='
		);
		return re.test( url );
	}

	function setParam( url, key, val ) {
		if ( val === null || val === undefined || val === '' ) {
			return url;
		}
		if ( hasParam( url, key ) ) {
			return url;
		}
		var sep = url.indexOf( '?' ) === -1 ? '?' : '&';
		return url + sep + encodeURIComponent( key ) + '=' + encodeURIComponent( val );
	}

	function stamp( a ) {
		if ( ! isTracked( a ) || a.getAttribute( 'data-pldv-stamped' ) === '1' ) {
			return;
		}

		var row = rowOf( a );
		var ds = a.dataset || {};
		var pos = livePosition( row );
		var ord = null;
		if ( row && row.dataset ) {
			ord = row.dataset.originalOrder;
			if ( ord === undefined || ord === null || ord === '' ) {
				ord = row.dataset.reviewPaginationIndex;
			}
		}

		var url = a.getAttribute( 'href' );
		url = setParam( url, prefix + 'pg', pageId() );
		url = setParam( url, prefix + 'pos', pos );
		url = setParam( url, prefix + 'ord', ord );
		url = setParam( url, prefix + 'pl', ds.ctaPlacement );
		url = setParam( url, prefix + 'ctx', ds.ctaContext );
		url = setParam( url, prefix + 'op', ds.ctaTarget );

		a.setAttribute( 'href', url );
		a.setAttribute( 'data-pldv-stamped', '1' );
	}

	function onInteraction( e ) {
		var t = e.target;
		var a = t && t.closest ? t.closest( 'a' ) : null;
		if ( a ) {
			stamp( a );
		}
	}

	// Capture phase, delegated on document → covers dynamically-added links and
	// fires before navigation (incl. new-tab / middle-click / keyboard).
	[ 'mousedown', 'click', 'auxclick', 'keydown' ].forEach( function ( ev ) {
		document.addEventListener( ev, onInteraction, true );
	} );
}() );
