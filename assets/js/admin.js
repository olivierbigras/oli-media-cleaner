/**
 * Oli Media Cleaner 2.0: screen script (plain JavaScript, no build step).
 * Depends on wp-i18n and wp-a11y. Loaded on the plugin screen only.
 */
( function () {
	'use strict';

	var cfg = window.olimcData || {};
	var __ = wp.i18n.__;
	var _n = wp.i18n._n;
	var sprintf = wp.i18n.sprintf;
	var speak = wp.a11y.speak;
	var FLASH = 'olimcFlash';
	var NUM = null;
	try {
		NUM = new Intl.NumberFormat( cfg.locale || undefined );
	} catch ( e ) {
		NUM = null;
	}

	function fmt( n ) {
		return NUM ? NUM.format( n ) : String( n );
	}
	function pct( n ) {
		/* translators: %s: a number; the percent sign is part of the string, e.g. 42% */
		return sprintf( __( '%s%%', 'oli-media-cleaner' ), fmt( n ) );
	}
	function $( sel, root ) {
		return ( root || document ).querySelector( sel );
	}
	function $$( sel, root ) {
		return Array.prototype.slice.call( ( root || document ).querySelectorAll( sel ) );
	}
	function el( tag, cls, text ) {
		var node = document.createElement( tag );
		if ( cls ) {
			node.className = cls;
		}
		if ( text !== undefined ) {
			node.textContent = text;
		}
		return node;
	}
	function sleep( ms ) {
		return new Promise( function ( resolve ) {
			setTimeout( resolve, ms );
		} );
	}
	function tabUrl( tab, extra ) {
		var u = new URL( window.location.href );
		u.search = '';
		u.searchParams.set( 'page', 'oli-media-cleaner' );
		u.searchParams.set( 'tab', tab );
		if ( extra ) {
			Object.keys( extra ).forEach( function ( k ) {
				u.searchParams.set( k, extra[ k ] );
			} );
		}
		return u.toString();
	}

	// ─── Server calls ────────────────────────────────────────────────

	function post( action, data ) {
		var body = new URLSearchParams();
		body.append( 'action', 'olimc_' + action );
		body.append( 'nonce', cfg.nonce );
		Object.keys( data || {} ).forEach( function ( k ) {
			var v = data[ k ];
			if ( Array.isArray( v ) ) {
				v.forEach( function ( x ) {
					body.append( k + '[]', x );
				} );
			} else if ( v !== undefined && v !== null ) {
				body.append( k, v );
			}
		} );
		return fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) {
				return r.json().then( function ( j ) {
					return { ok: !! j.success, status: r.status, data: j.data, network: false };
				} );
			} )
			.catch( function () {
				return { ok: false, status: 0, data: null, network: true };
			} );
	}

	// ─── Notices ─────────────────────────────────────────────────────

	var notices = $( '#olimc-notices' );

	function notice( type, text, opts ) {
		opts = opts || {};
		var box = el( 'div', 'notice notice-' + type + ' inline is-dismissible olimc-result' );
		var p = el( 'p', '', text );
		if ( opts.undo && opts.undo.length ) {
			p.appendChild( document.createTextNode( ' ' ) );
			var undo = el( 'button', 'button-link olimc-undo', __( 'Undo', 'oli-media-cleaner' ) );
			undo.type = 'button';
			undo.addEventListener( 'click', function () {
				undoTrash( opts.undo );
			} );
			p.appendChild( undo );
		}
		box.appendChild( p );
		if ( opts.details && opts.details.length ) {
			var det = el( 'details' );
			det.appendChild( el( 'summary', '', __( 'Technical details', 'oli-media-cleaner' ) ) );
			det.appendChild( el( 'pre', 'olimc-mono', opts.details.join( '\n' ) ) );
			box.appendChild( det );
		}
		var x = el( 'button', 'notice-dismiss' );
		x.type = 'button';
		x.appendChild( el( 'span', 'screen-reader-text', __( 'Dismiss this notice.', 'oli-media-cleaner' ) ) );
		x.addEventListener( 'click', function () {
			box.remove();
		} );
		box.appendChild( x );
		if ( notices ) {
			if ( ! opts.keep ) {
				$$( '.olimc-result', notices ).forEach( function ( n ) {
					n.remove();
				} );
			}
			notices.insertBefore( box, notices.firstChild );
		}
		if ( ! opts.silent ) {
			speak( text, type === 'error' ? 'assertive' : 'polite' );
		}
		return box;
	}

	/** Keep a notice across a page reload, then show it and move focus to the list title. */
	function flashAndReload( list ) {
		try {
			window.sessionStorage.setItem( FLASH, JSON.stringify( list ) );
		} catch ( e ) {
			// Storage can be blocked: the page still reloads.
		}
		window.location.reload();
	}
	function showFlash() {
		var raw = null;
		try {
			raw = window.sessionStorage.getItem( FLASH );
			window.sessionStorage.removeItem( FLASH );
		} catch ( e ) {
			raw = null;
		}
		if ( ! raw ) {
			return;
		}
		var list = [];
		try {
			list = JSON.parse( raw );
		} catch ( e ) {
			list = [];
		}
		list.forEach( function ( n, i ) {
			notice( n.type, n.text, { undo: n.undo, details: n.details, keep: i > 0 } );
		} );
		var title = $( '.olimc-list-title' );
		if ( title ) {
			title.focus();
		}
	}

	// ─── Messages shared by the actions ──────────────────────────────

	function nameOf( o ) {
		return o && o.name ? o.name : '';
	}
	function msgTrashed( n ) {
		return sprintf( /* translators: %s: number of files */ _n( '%s file moved to the trash.', '%s files moved to the trash.', n, 'oli-media-cleaner' ), fmt( n ) );
	}
	function msgRestored( n ) {
		return sprintf( /* translators: %s: number of files */ _n( '%s file restored to the media library.', '%s files restored to the media library.', n, 'oli-media-cleaner' ), fmt( n ) );
	}
	function msgDeleted( n ) {
		return sprintf( /* translators: %s: number of files */ _n( '%s file permanently deleted.', '%s files permanently deleted.', n, 'oli-media-cleaner' ), fmt( n ) );
	}
	function msgKept( n ) {
		return sprintf( /* translators: %s: number of files */ _n( '%s file kept.', '%s files kept.', n, 'oli-media-cleaner' ), fmt( n ) );
	}
	function msgSkippedKept( n ) {
		return sprintf( /* translators: %s: number of files */ _n( '%s kept file was skipped.', '%s kept files were skipped.', n, 'oli-media-cleaner' ), fmt( n ) );
	}
	function msgFail( n, reason ) {
		return sprintf( /* translators: %1$s: number of files, %2$s: reason */ _n( '%1$s file could not be moved. %2$s', '%1$s files could not be moved. %2$s', n, 'oli-media-cleaner' ), fmt( n ), reason || '' ).trim();
	}
	function msgError( data, fallback ) {
		return data && data.message ? data.message : fallback;
	}
	function genericError() {
		return __( 'The request did not complete. Nothing was changed. Reload the page and try again.', 'oli-media-cleaner' );
	}

	// ─── Dialog ──────────────────────────────────────────────────────

	var dialog = $( '#olimc-dialog' );
	var canDialog = !! ( dialog && typeof dialog.showModal === 'function' );

	/**
	 * Confirmation dialog. Resolves true or false. Focus starts on Cancel and
	 * returns to the trigger. Without <dialog> it falls back to the browser confirmation (window.confirm).
	 */
	function confirmDialog( o ) {
		var lines = [ o.title, o.body ].join( '\n\n' );
		if ( ! canDialog ) {
			var yes = window.confirm( lines );
			if ( yes && o.check ) {
				yes = window.confirm( o.check );
			}
			return Promise.resolve( yes );
		}
		$( '#olimc-dlg-title' ).textContent = o.title;
		$( '#olimc-dlg-body' ).textContent = o.body;
		var sizeLine = $( '#olimc-dlg-size' );
		sizeLine.textContent = o.size || '';
		sizeLine.hidden = ! o.size;
		dialog.setAttribute( 'aria-describedby', o.size ? 'olimc-dlg-body olimc-dlg-size' : 'olimc-dlg-body' );
		var list = $( '#olimc-dlg-list' );
		list.textContent = '';
		( o.sample || [] ).forEach( function ( s ) {
			var li = el( 'li' );
			if ( s.thumb ) {
				var img = el( 'img' );
				img.src = s.thumb;
				img.alt = '';
				img.width = 32;
				img.height = 32;
				li.appendChild( img );
			}
			li.appendChild( el( 'span', 'olimc-mono', s.name ) );
			list.appendChild( li );
		} );
		var more = $( '#olimc-dlg-more' );
		more.textContent = o.more || '';
		more.hidden = ! o.more;
		var okBox = $( '#olimc-dlg-ok' );
		var chk = $( '#olimc-dlg-check' );
		chk.checked = false;
		okBox.hidden = ! o.check;
		$( '#olimc-dlg-check-label' ).textContent = o.check || '';
		var confirmBtn = $( '#olimc-dlg-confirm' );
		confirmBtn.textContent = o.ok;
		confirmBtn.className = 'button button-primary button-large' + ( o.danger ? ' olimc-danger' : '' );
		confirmBtn.disabled = !! o.check;
		dialog.setAttribute( 'role', 'alertdialog' );
		var cancelBtn = $( '#olimc-dlg-cancel' );

		return new Promise( function ( resolve ) {
			var result = false;
			function onCheck() {
				confirmBtn.disabled = ! chk.checked;
			}
			function onConfirm() {
				result = true;
				dialog.close();
			}
			function onCancel() {
				result = false;
				dialog.close();
			}
			function onClose() {
				chk.removeEventListener( 'change', onCheck );
				confirmBtn.removeEventListener( 'click', onConfirm );
				cancelBtn.removeEventListener( 'click', onCancel );
				dialog.removeEventListener( 'close', onClose );
				if ( o.trigger && o.trigger.focus ) {
					o.trigger.focus();
				}
				resolve( result );
			}
			chk.addEventListener( 'change', onCheck );
			confirmBtn.addEventListener( 'click', onConfirm );
			cancelBtn.addEventListener( 'click', onCancel );
			dialog.addEventListener( 'close', onClose );
			dialog.showModal();
			cancelBtn.focus();
		} );
	}

	// ─── Scan ────────────────────────────────────────────────────────

	var panel = $( '#olimc-panel' );
	var lastSpeak = 0;
	var lastStep = 0;
	var lastBucket = -1;
	var pendingSpeech = null;
	var scanView = null;

	function paintBar( p ) {
		var bar = $( '#olimc-bar' );
		if ( bar ) {
			bar.firstElementChild.style.width = p + '%';
		}
	}
	function stepLabels() {
		return [
			__( 'Preparing', 'oli-media-cleaner' ),
			__( 'Custom fields and ACF', 'oli-media-cleaner' ),
			__( 'Other metadata', 'oli-media-cleaner' ),
			__( 'Content, theme and widgets', 'oli-media-cleaner' ),
			__( 'Sorting results', 'oli-media-cleaner' ),
		];
	}
	function clock( seconds ) {
		var m = Math.floor( seconds / 60 );
		var s = seconds % 60;
		return ( m < 10 ? '0' : '' ) + m + ':' + ( s < 10 ? '0' : '' ) + s;
	}
	function announce( text ) {
		var now = Date.now();
		var live = $( '#olimc-live' );
		if ( ! live ) {
			return;
		}
		if ( now - lastSpeak >= 2000 ) {
			live.textContent = text;
			lastSpeak = now;
			return;
		}
		pendingSpeech = text;
		setTimeout( function () {
			if ( pendingSpeech && Date.now() - lastSpeak >= 2000 ) {
				live.textContent = pendingSpeech;
				lastSpeak = Date.now();
				pendingSpeech = null;
			}
		}, 2000 - ( now - lastSpeak ) + 20 );
	}

	function paintScan( v ) {
		scanView = v;
		var labels = stepLabels();
		var p = Math.min( 99, v.percent );
		paintBar( p );
		var bar = $( '#olimc-bar' );
		var stepText = sprintf( __( 'Step %1$d of %2$d: %3$s', 'oli-media-cleaner' ), v.step, v.steps, labels[ v.step - 1 ] );
		var valueText = sprintf( __( '%1$s, step %2$d of %3$d', 'oli-media-cleaner' ), pct( p ), v.step, v.steps );
		if ( bar ) {
			bar.setAttribute( 'aria-valuenow', String( p ) );
			bar.setAttribute( 'aria-valuetext', valueText );
		}
		$( '#olimc-step' ).textContent = stepText;
		$( '#olimc-count' ).textContent = sprintf(
			/* translators: %1$s: number of items done, %2$s: total number of items */ _n( '%1$s of %2$s file checked', '%1$s of %2$s files checked', v.total, 'oli-media-cleaner' ),
			fmt( v.checked ),
			fmt( v.total )
		);
		$( '#olimc-elapsed' ).textContent = sprintf( /* translators: %s: elapsed time, for example 00:42 */ __( 'Elapsed: %s', 'oli-media-cleaner' ), clock( v.elapsed ) );
		$$( '.olimc-steps li' ).forEach( function ( li, i ) {
			var n = i + 1;
			li.className = n === v.step ? 'is-now' : '';
			li.textContent = ( n < v.step ? '✓' : ( n === v.step ? '●' : '○' ) ) + ' ' + labels[ i ];
		} );
		var bucket = Math.floor( p / 10 );
		if ( v.step !== lastStep || bucket > lastBucket ) {
			announce( stepText + ' — ' + pct( p ) );
			lastStep = v.step;
			lastBucket = Math.max( lastBucket, bucket );
		}
	}

	function failedPanel( v, detail ) {
		var labels = stepLabels();
		var p = v ? v.percent : 0;
		var step = v ? v.step : 1;
		panel.setAttribute( 'data-mode', 'failed' );
		panel.textContent = '';
		var h = el( 'h2', 'screen-reader-text', __( 'Scan stopped', 'oli-media-cleaner' ) );
		h.id = 'olimc-panel-title';
		h.tabIndex = -1;
		panel.appendChild( h );
		var msg = sprintf( __( 'The scan stopped at %1$s (step %2$d of %3$d). No file was marked as unused.', 'oli-media-cleaner' ), pct( p ), step, 5 );
		var n = el( 'div', 'notice notice-error inline' );
		n.appendChild( el( 'p', '', msg ) );
		panel.appendChild( n );
		var row = el( 'p' );
		var resume = el( 'button', 'button button-primary button-large', __( 'Resume scan', 'oli-media-cleaner' ) );
		resume.type = 'button';
		resume.id = 'olimc-scan-resume';
		var restart = el( 'button', 'button button-large', __( 'Start over', 'oli-media-cleaner' ) );
		restart.type = 'button';
		restart.id = 'olimc-scan-restart';
		row.appendChild( resume );
		row.appendChild( document.createTextNode( ' ' ) );
		row.appendChild( restart );
		panel.appendChild( row );
		var d = el( 'details' );
		d.appendChild( el( 'summary', '', __( 'Technical details', 'oli-media-cleaner' ) ) );
		d.appendChild( el( 'pre', 'olimc-mono', ( labels[ step - 1 ] || '' ) + ( detail ? ' — ' + detail : '' ) ) );
		panel.appendChild( d );
		speak( msg, 'assertive' );
		h.focus();
	}

	async function scanLoop() {
		var failures = 0;
		for ( ;; ) {
			var res = await post( 'scan_step' );
			if ( ! res.ok ) {
				if ( res.data && res.data.code === 'lock' ) {
					panel.remove();
					notice( 'error', res.data.message );
					return;
				}
				failures += 1;
				if ( res.network && failures < 2 ) {
					await sleep( 1500 );
					continue;
				}
				failedPanel( scanView, res.data && res.data.message ? res.data.message : '' );
				return;
			}
			failures = 0;
			var v = res.data;
			if ( v.failed ) {
				failedPanel( v, v.detail );
				return;
			}
			if ( v.done ) {
				var done = sprintf(
					/* translators: %1$s: number of unused files, %2$s: their total size */ _n(
						'Scan complete: %1$s unused file (%2$s). Nothing was changed.',
						'Scan complete: %1$s unused files (%2$s). Nothing was changed.',
						v.count,
						'oli-media-cleaner'
					),
					fmt( v.count ),
					v.bytes_label
				);
				try {
					window.sessionStorage.setItem( FLASH, JSON.stringify( [ { type: 'success', text: done } ] ) );
				} catch ( e ) {
					// Storage can be blocked.
				}
				window.location.href = tabUrl( 'review' );
				return;
			}
			paintScan( v );
			if ( cfg.pause ) {
				await sleep( cfg.pause );
			}
		}
	}

	function bindScan() {
		var start = $( '#olimc-scan-start' );
		if ( start ) {
			start.addEventListener( 'click', async function () {
				start.setAttribute( 'aria-disabled', 'true' );
				var res = await post( 'scan_start' );
				if ( res.ok ) {
					window.location.href = tabUrl( 'review', { olimc_run: 1 } );
					return;
				}
				start.removeAttribute( 'aria-disabled' );
				notice( 'error', msgError( res.data, genericError() ) );
			} );
		}
		document.addEventListener( 'click', async function ( e ) {
			var t = e.target.closest( 'button' );
			if ( ! t || ! t.id ) {
				return;
			}
			if ( t.id === 'olimc-scan-resume' ) {
				window.location.href = tabUrl( 'review', { olimc_run: 1 } );
			} else if ( t.id === 'olimc-scan-restart' ) {
				var res = await post( 'scan_start' );
				if ( res.ok ) {
					window.location.href = tabUrl( 'review', { olimc_run: 1 } );
				} else {
					notice( 'error', msgError( res.data, genericError() ) );
				}
			} else if ( t.id === 'olimc-scan-cancel' ) {
				await post( 'scan_cancel' );
				window.location.href = tabUrl( 'review' );
			}
		} );
		if ( panel && panel.getAttribute( 'data-mode' ) === 'running' ) {
			var seed = {
				percent: parseInt( panel.getAttribute( 'data-percent' ), 10 ) || 2,
				step: parseInt( panel.getAttribute( 'data-step' ), 10 ) || 1,
				steps: 5,
				total: parseInt( panel.getAttribute( 'data-total' ), 10 ) || 0,
				checked: 0,
				elapsed: 0,
			};
			paintScan( seed );
			var title = $( '#olimc-panel-title' );
			if ( title ) {
				title.focus();
			}
			scanLoop();
		} else if ( panel ) {
			paintBar( parseInt( panel.getAttribute( 'data-percent' ), 10 ) || 0 );
		}
	}

	// ─── List actions ────────────────────────────────────────────────

	var form = $( '#olimc-bulk' );
	var tab = cfg.tab;
	var stale = !! ( form && form.getAttribute( 'data-stale' ) === '1' );
	var selectAll = false;

	function rowFor( id ) {
		return form ? form.querySelector( 'tr[data-id="' + id + '"]' ) : null;
	}
	function pageIds() {
		return $$( 'tbody th.check-column input[type=checkbox], tbody td.check-column input[type=checkbox]', form ).map( function ( c ) {
			return parseInt( c.value, 10 );
		} );
	}
	function checkedIds() {
		return $$( 'tbody .check-column input[type=checkbox]:checked', form ).map( function ( c ) {
			return parseInt( c.value, 10 );
		} );
	}
	function filterParams() {
		var f = cfg.filters || {};
		return { type: f.type || '', month: f.month || '', size: f.size || '', s: f.search || '' };
	}
	function sumLabel( data ) {
		return data.size_label;
	}

	function progressUi( total, verbFn ) {
		var box = el( 'section', 'olimc-panel olimc-progress' );
		var line = el( 'p', '', '' );
		var bar = el( 'div', 'olimc-bar' );
		bar.setAttribute( 'role', 'progressbar' );
		bar.setAttribute( 'aria-valuemin', '0' );
		bar.setAttribute( 'aria-valuemax', '100' );
		bar.setAttribute( 'aria-valuenow', '0' );
		bar.setAttribute( 'aria-label', __( 'Scan progress', 'oli-media-cleaner' ) );
		bar.appendChild( el( 'span' ) );
		var cancel = el( 'button', 'button button-large', __( 'Cancel', 'oli-media-cleaner' ) );
		cancel.type = 'button';
		var state = { cancelled: false };
		cancel.addEventListener( 'click', function () {
			state.cancelled = true;
			cancel.setAttribute( 'aria-disabled', 'true' );
		} );
		var live = el( 'div', 'screen-reader-text' );
		live.setAttribute( 'role', 'status' );
		live.setAttribute( 'aria-live', 'polite' );
		box.appendChild( line );
		box.appendChild( bar );
		box.appendChild( live );
		var p = el( 'p' );
		p.appendChild( cancel );
		box.appendChild( p );
		var anchor = $( '.olimc-toolbar' ) || $( '#olimc-main' );
		anchor.parentNode.insertBefore( box, anchor.nextSibling );
		var last = 0;
		return {
			state: state,
			update: function ( done ) {
				var text = verbFn( done, total );
				line.textContent = text;
				var pc = total ? Math.round( ( done / total ) * 100 ) : 100;
				bar.firstElementChild.style.width = pc + '%';
				bar.setAttribute( 'aria-valuenow', String( pc ) );
				if ( Date.now() - last > 2000 ) {
					live.textContent = text;
					last = Date.now();
				}
			},
			close: function () {
				box.remove();
			},
		};
	}

	function verbTrash( done, total ) {
		return sprintf( /* translators: %1$s: number of items done, %2$s: total number of items */ __( '%1$s of %2$s files moved', 'oli-media-cleaner' ), fmt( done ), fmt( total ) );
	}
	function verbDelete( done, total ) {
		return sprintf( /* translators: %1$s: number of items done, %2$s: total number of items */ __( '%1$s of %2$s files deleted', 'oli-media-cleaner' ), fmt( done ), fmt( total ) );
	}
	function verbRestore( done, total ) {
		return sprintf( /* translators: %1$s: number of items done, %2$s: total number of items */ __( '%1$s of %2$s files restored', 'oli-media-cleaner' ), fmt( done ), fmt( total ) );
	}

	/** Send IDs in batches. Returns the merged server answers. */
	async function runBatches( route, ids, verbFn ) {
		var maxSize = cfg.batch || 50;
		var size = maxSize;
		// Aim for well under 10 s per request, and under half of max_execution_time when it is low.
		var limit = cfg.maxExec > 0 ? Math.min( 10, cfg.maxExec * 0.5 ) : 10;
		var ui = ids.length > size ? progressUi( ids.length, verbFn ) : null;
		var out = { ok: true, status: 200, parts: [], error: null, cancelled: false, processed: 0 };
		var i = 0;
		while ( i < ids.length ) {
			if ( ui && ui.state.cancelled ) {
				out.cancelled = true;
				break;
			}
			var chunk = ids.slice( i, i + size );
			var started = Date.now();
			var res = await post( route, { ids: chunk } );
			var seconds = ( Date.now() - started ) / 1000;
			if ( ! res.ok ) {
				out.ok = false;
				out.status = res.status;
				out.error = res.data;
				break;
			}
			out.parts.push( res.data );
			i += chunk.length;
			out.processed = Math.min( ids.length, i );
			if ( ui ) {
				ui.update( out.processed );
			}
			if ( chunk.length > 0 && seconds > 0 ) {
				size = Math.max( 5, Math.min( maxSize, Math.floor( ( limit * 0.8 ) / ( seconds / chunk.length ) ) ) );
			}
			if ( cfg.pause ) {
				await sleep( cfg.pause );
			}
		}
		if ( ui ) {
			ui.close();
		}
		return out;
	}

	function merge( parts, key ) {
		var all = [];
		parts.forEach( function ( p ) {
			all = all.concat( p[ key ] || [] );
		} );
		return all;
	}
	function sum( parts, key ) {
		return parts.reduce( function ( a, p ) {
			return a + ( parseInt( p[ key ], 10 ) || 0 );
		}, 0 );
	}
	function reasonOf( parts ) {
		for ( var i = 0; i < parts.length; i++ ) {
			if ( parts[ i ].reason ) {
				return parts[ i ].reason;
			}
		}
		return '';
	}

	/** Build the notices for a finished action. */
	function resultNotices( act, run ) {
		var list = [];
		if ( ! run.ok ) {
			list.push( { type: 'error', text: msgError( run.error, genericError() ) } );
			return list;
		}
		var parts = run.parts;
		if ( act === 'trash' ) {
			var trashed = merge( parts, 'trashed' );
			var used = merge( parts, 'used' );
			var kept = sum( parts, 'kept' );
			var capN = sum( parts, 'cap' );
			var failed = merge( parts, 'failed' );
			if ( trashed.length ) {
				list.push( { type: 'success', text: msgTrashed( trashed.length ), undo: trashed } );
			}
			if ( used.length ) {
				list.push( {
					type: 'error',
					text: used
						.slice( 0, 3 )
						.map( function ( u ) {
							return sprintf( /* translators: %s: file name */ __( '“%s” is now in use and was not moved. Run a new scan.', 'oli-media-cleaner' ), nameOf( u ) );
						} )
						.join( ' ' ),
				} );
			}
			if ( kept ) {
				list.push( { type: 'info', text: msgSkippedKept( kept ) } );
			}
			if ( capN ) {
				list.push( { type: 'error', text: __( 'You do not have permission to clean the media library.', 'oli-media-cleaner' ) } );
			}
			if ( failed.length ) {
				list.push( { type: 'warning', text: msgFail( failed.length, reasonOf( parts ) ), details: failed.map( nameOf ) } );
			}
		} else if ( act === 'restore' ) {
			var restored = merge( parts, 'restored' );
			var rfail = merge( parts, 'failed' );
			var exists = sum( parts, 'exists' );
			if ( restored.length ) {
				list.push( { type: 'success', text: msgRestored( restored.length ) } );
			}
			if ( exists ) {
				list.push( {
					type: 'warning',
					text: sprintf(
						/* translators: %s: number of files */ _n(
							'%s file could not be restored. A file with the same name already exists.',
							'%s files could not be restored. Files with the same name already exist.',
							exists,
							'oli-media-cleaner'
						),
						fmt( exists )
					),
				} );
			}
			if ( rfail.length ) {
				list.push( {
					type: 'warning',
					text: sprintf(
						/* translators: %s: number of files */ _n( '%s file could not be restored.', '%s files could not be restored.', rfail.length, 'oli-media-cleaner' ),
						fmt( rfail.length )
					),
				} );
			}
		} else if ( act === 'delete' || act === 'empty' ) {
			var deleted = merge( parts, 'deleted' );
			var keptRestored = sum( parts, 'restored_kept' );
			var dfail = merge( parts, 'failed' );
			if ( deleted.length ) {
				list.push( { type: 'success', text: msgDeleted( deleted.length ) } );
			}
			if ( keptRestored ) {
				list.push( {
					type: 'info',
					text: sprintf(
						/* translators: %s: number of files */ _n(
							'%s kept file was restored instead of deleted.',
							'%s kept files were restored instead of deleted.',
							keptRestored,
							'oli-media-cleaner'
						),
						fmt( keptRestored )
					),
				} );
			}
			if ( sum( parts, 'kept' ) ) {
				list.push( { type: 'info', text: msgSkippedKept( sum( parts, 'kept' ) ) } );
			}
			if ( dfail.length ) {
				list.push( {
					type: 'warning',
					text: sprintf(
						/* translators: %s: number of files */ _n(
							'%s file could not be deleted because your site still uses it.',
							'%s files could not be deleted because your site still uses them.',
							dfail.length,
							'oli-media-cleaner'
						),
						fmt( dfail.length )
					),
					details: dfail.map( nameOf ),
				} );
			}
			if ( sum( parts, 'cap' ) ) {
				list.push( { type: 'error', text: __( 'You do not have permission to clean the media library.', 'oli-media-cleaner' ) } );
			}
		} else if ( act === 'keep' ) {
			var k = merge( parts, 'kept' );
			list.push( { type: 'success', text: msgKept( k.length ) } );
		} else if ( act === 'unkeep' ) {
			var u = merge( parts, 'unkept' );
			list.push( {
				type: 'success',
				text: sprintf( /* translators: %s: number of files */ _n( '%s file is no longer kept.', '%s files are no longer kept.', u.length, 'oli-media-cleaner' ), fmt( u.length ) ),
			} );
		}
		if ( run.cancelled ) {
			list.push( {
				type: 'info',
				text: sprintf( __( '%1$s of %2$s files moved', 'oli-media-cleaner' ), fmt( run.processed ), fmt( run.processed ) ),
			} );
		}
		return list;
	}

	var VERBS = { trash: verbTrash, delete: verbDelete, empty: verbDelete, restore: verbRestore };
	var ROUTES = { trash: 'trash', keep: 'keep', unkeep: 'unkeep', restore: 'restore', delete: 'delete', empty: 'empty', restore_all: 'restore' };

	/** Run an action on IDs, show the outcome, then update the page. */
	async function perform( act, ids, ctx ) {
		ctx = ctx || {};
		var run = await runBatches( ROUTES[ act ], ids, VERBS[ act ] || verbTrash );
		var list = resultNotices( act === 'restore_all' ? 'restore' : act, run );
		var firstRow = ctx.row;
		var nextFocus = null;
		if ( firstRow && ids.length === 1 && run.ok ) {
			var nextRow = firstRow.nextElementSibling;
			if ( nextRow ) {
				nextFocus = nextRow.querySelector( '.olimc-act' );
			}
			firstRow.remove();
			var remaining = $$( 'tbody tr[data-id]', form ).length;
			if ( remaining === 0 ) {
				flashAndReload( list );
				return;
			}
			list.forEach( function ( n, i ) {
				notice( n.type, n.text, { undo: n.undo, details: n.details, keep: i > 0 } );
			} );
			refreshChrome();
			( nextFocus || $( '.olimc-list-title' ) ).focus();
			return;
		}
		flashAndReload( list );
	}

	/** Replace the tiles and the tab counters with fresh ones from the server. */
	function refreshChrome() {
		fetch( window.location.href, { credentials: 'same-origin' } )
			.then( function ( r ) {
				return r.text();
			} )
			.then( function ( html ) {
				var doc = new DOMParser().parseFromString( html, 'text/html' );
				[ '.olimc-stats', 'nav.olimc-tabs' ].forEach( function ( sel ) {
					var fresh = $( sel, doc );
					var cur = $( sel );
					if ( fresh && cur ) {
						var nodes = Array.prototype.map.call( fresh.childNodes, function ( node ) {
							return document.importNode( node, true );
						} );
						if ( cur.replaceChildren ) {
							cur.replaceChildren.apply( cur, nodes );
						} else {
							cur.textContent = '';
							nodes.forEach( function ( node ) {
								cur.appendChild( node );
							} );
						}
					}
				} );
			} )
			.catch( function () {} );
	}

	async function undoTrash( ids ) {
		var items = ids.map( function ( x ) {
			return typeof x === 'object' ? x.id : x;
		} );
		var run = await runBatches( 'restore', items, verbRestore );
		flashAndReload( resultNotices( 'restore', run ) );
	}

	function dialogText( act, n ) {
		if ( act === 'trash' ) {
			return {
				title: sprintf( /* translators: %s: number of files */ _n( 'Move %s file to the trash?', 'Move %s files to the trash?', n, 'oli-media-cleaner' ), fmt( n ) ),
				ok: __( 'Move to trash', 'oli-media-cleaner' ),
			};
		}
		if ( act === 'delete' ) {
			return {
				title: sprintf( /* translators: %s: number of files */ _n( 'Permanently delete %s file?', 'Permanently delete %s files?', n, 'oli-media-cleaner' ), fmt( n ) ),
				ok: __( 'Delete permanently', 'oli-media-cleaner' ),
			};
		}
		return { title: __( 'Empty the trash?', 'oli-media-cleaner' ), ok: __( 'Empty the trash', 'oli-media-cleaner' ) };
	}

	/** Group action: summary from the server, dialog, then run in batches. */
	async function bulk( act, scopeAll, ids, trigger ) {
		var req = { tab: tab, scope: scopeAll ? 'all' : 'ids', ids: ids };
		if ( scopeAll ) {
			Object.assign( req, filterParams() );
		}
		var prep = await post( 'bulk_prepare', req );
		if ( ! prep.ok ) {
			notice( 'error', msgError( prep.data, genericError() ) );
			return;
		}
		var d = prep.data;
		if ( scopeAll ) {
			ids = d.ids;
		}
		if ( ! ids.length ) {
			return;
		}
		var needsDialog = act === 'trash' || act === 'delete' || act === 'empty';
		if ( needsDialog ) {
			var t = dialogText( act, d.count );
			var body =
				act === 'trash'
					? sprintf(
						/* translators: %s: number of files */
						_n(
							'This file goes offline and is removed from the Media Library. If your site still uses it, it will appear as missing: restore it from the trash at any time. Emptying the trash deletes it for good.',
							'These %s files go offline and are removed from the Media Library. If your site still uses them, they will appear as missing: restore them from the trash at any time. Emptying the trash deletes them for good.',
							d.count,
							'oli-media-cleaner'
						),
						fmt( d.count )
					)
					: __( 'The files and their thumbnails will be erased from the server. This cannot be undone. A recent backup is recommended.', 'oli-media-cleaner' );
			var check = null;
			if ( act === 'trash' && d.big ) {
				check = __( 'I checked some examples in the list and I have a recent backup.', 'oli-media-cleaner' );
			} else if ( act === 'empty' ) {
				check = __( 'I understand that this is permanent.', 'oli-media-cleaner' );
			}
			var ok = await confirmDialog( {
				title: t.title,
				body: body,
				size: act === 'trash' && d.size_label ? sprintf( /* translators: %s: total size of the selected files, already formatted (e.g. 21.6 KB) */ __( 'Total size: %s', 'oli-media-cleaner' ), d.size_label ) : '',
				sample: d.sample,
				more: d.more > 0 ? sprintf( /* translators: %s: number of other files */ _n( 'and %s more', 'and %s more', d.more, 'oli-media-cleaner' ), d.more_label ) : '',
				check: check,
				ok: t.ok,
				danger: act !== 'trash',
				trigger: trigger,
			} );
			if ( ! ok ) {
				return;
			}
		}
		await perform( act, ids, {} );
	}

	function applyDisabled( btn ) {
		return btn && btn.getAttribute( 'aria-disabled' ) === 'true';
	}

	var applyWasDisabled = false;

	function updateStaleGuards() {
		var nowDisabled = false;
		$$( '#olimc-bulk .bulkactions' ).forEach( function ( box ) {
			var sel = $( 'select', box );
			var btn = $( 'input[type=submit], button[type=submit]', box );
			if ( ! sel || ! btn ) {
				return;
			}
			if ( stale && sel.value === 'trash' ) {
				nowDisabled = true;
				btn.setAttribute( 'aria-disabled', 'true' );
				btn.setAttribute( 'aria-describedby', 'olimc-stale' );
			} else {
				btn.removeAttribute( 'aria-disabled' );
				btn.removeAttribute( 'aria-describedby' );
			}
		} );
		if ( nowDisabled !== applyWasDisabled ) {
			applyWasDisabled = nowDisabled;
			if ( nowDisabled ) {
				speak( __( 'This list predates your latest changes (plugin, theme or a scan older than 14 days). Run a new scan before moving files to the trash.', 'oli-media-cleaner' ), 'polite' );
			}
		}
		var allBtn = $( '#olimc-selall' );
		if ( allBtn ) {
			if ( stale ) {
				allBtn.setAttribute( 'aria-disabled', 'true' );
				allBtn.setAttribute( 'aria-describedby', 'olimc-stale' );
			} else {
				allBtn.removeAttribute( 'aria-disabled' );
				allBtn.removeAttribute( 'aria-describedby' );
			}
		}
	}

	function updateSelectionBar() {
		var bar = $( '#olimc-selbar' );
		if ( ! bar || ! form ) {
			return;
		}
		var total = parseInt( form.getAttribute( 'data-total' ), 10 ) || 0;
		var ids = pageIds();
		var checked = checkedIds();
		bar.textContent = '';
		if ( selectAll ) {
			bar.hidden = false;
			bar.appendChild( document.createTextNode( sprintf( /* translators: %s: number of results */ __( 'All %s results are selected.', 'oli-media-cleaner' ), fmt( total ) ) + ' ' ) );
			var clear = el( 'button', 'button-link', __( 'Clear selection', 'oli-media-cleaner' ) );
			clear.type = 'button';
			clear.addEventListener( 'click', function () {
				selectAll = false;
				$$( 'input[type=checkbox]', form ).forEach( function ( c ) {
					c.checked = false;
				} );
				updateSelectionBar();
			} );
			bar.appendChild( clear );
			return;
		}
		if ( ids.length && checked.length === ids.length && total > ids.length ) {
			bar.hidden = false;
			var msg = sprintf( /* translators: %s: number of files */ _n( '%s file is selected on this page.', '%s files are selected on this page.', checked.length, 'oli-media-cleaner' ), fmt( checked.length ) );
			bar.appendChild( document.createTextNode( msg + ' ' ) );
			var all = el( 'button', 'button-link', sprintf( /* translators: %s: number of results */ __( 'Select all %s results', 'oli-media-cleaner' ), fmt( total ) ) );
			all.type = 'button';
			all.id = 'olimc-selall';
			all.addEventListener( 'click', function () {
				if ( applyDisabled( all ) ) {
					return;
				}
				selectAll = true;
				updateSelectionBar();
			} );
			bar.appendChild( all );
			updateStaleGuards();
		} else {
			bar.hidden = true;
		}
	}

	function bindList() {
		if ( ! form ) {
			return;
		}
		form.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '.olimc-act' );
			if ( ! btn ) {
				return;
			}
			var act = btn.getAttribute( 'data-act' );
			var id = parseInt( btn.getAttribute( 'data-id' ), 10 );
			var row = btn.closest( 'tr' );
			if ( act === 'delete' ) {
				bulk( 'delete', false, [ id ], btn );
				return;
			}
			perform( act, [ id ], { row: row } );
		} );
		form.addEventListener( 'change', function ( e ) {
			if ( e.target.matches( 'input[type=checkbox]' ) ) {
				selectAll = false;
				updateSelectionBar();
			}
			if ( e.target.matches( '.bulkactions select' ) ) {
				updateStaleGuards();
			}
		} );
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var submitter = e.submitter || null;
			if ( applyDisabled( submitter ) ) {
				return;
			}
			var act = '';
			$$( '.bulkactions select', form ).forEach( function ( s ) {
				if ( s.value && s.value !== '-1' ) {
					act = s.value;
				}
			} );
			var ids = checkedIds();
			if ( ! act || ( ! ids.length && ! selectAll ) ) {
				return;
			}
			if ( stale && act === 'trash' ) {
				return;
			}
			if ( act === 'keep' || act === 'unkeep' || act === 'restore' ) {
				if ( selectAll ) {
					bulk( act, true, [], submitter );
				} else {
					perform( act, ids, {} );
				}
				return;
			}
			bulk( act, selectAll, ids, submitter );
		} );
		$$( '.olimc-btn[data-act]' ).forEach( function ( b ) {
			b.addEventListener( 'click', function () {
				var act = b.getAttribute( 'data-act' );
				if ( act === 'empty' ) {
					bulk( 'empty', true, [], b );
				} else {
					bulkAllRestore( b );
				}
			} );
		} );
		updateStaleGuards();
	}

	async function bulkAllRestore() {
		var prep = await post( 'bulk_prepare', { tab: 'trash', scope: 'all' } );
		if ( prep.ok && prep.data.ids.length ) {
			await perform( 'restore', prep.data.ids, {} );
		}
	}

	// ─── Settings ────────────────────────────────────────────────────

	function bindSettings() {
		var testBtn = $( '#olimc-patterns-test' );
		if ( testBtn ) {
			testBtn.addEventListener( 'click', async function () {
				var status = $( '#olimc-pattern-status' );
				var lines = $( '#olimc-pattern-lines' );
				var res = await post( 'test_patterns', { patterns: $( '#olimc-patterns' ).value } );
				lines.textContent = '';
				if ( ! res.ok ) {
					status.textContent = msgError( res.data, genericError() );
					return;
				}
				var d = res.data;
				status.textContent = sprintf(
					/* translators: %s: number of files that match the patterns */
					_n( '%s file matches.', '%s files match.', d.total, 'oli-media-cleaner' ),
					d.total_label
				);
				d.none.forEach( function ( line ) {
					var li = el( 'li' );
					li.appendChild( el( 'code', '', line ) );
					li.appendChild( document.createTextNode( ' ' + __( 'No match. Check the spelling.', 'oli-media-cleaner' ) ) );
					lines.appendChild( li );
				} );
				d.foreign.forEach( function ( line ) {
					lines.appendChild( el( 'li', '', sprintf( __( 'The address %s is not on this site and was not saved.', 'oli-media-cleaner' ), line ) ) );
				} );
			} );
		}
		var copy = $( '#olimc-diag-copy' );
		if ( copy ) {
			copy.addEventListener( 'click', function () {
				var area = $( '#olimc-diag' );
				area.select();
				var done = function () {
					speak( __( 'Report copied.', 'oli-media-cleaner' ), 'polite' );
				};
				if ( navigator.clipboard && navigator.clipboard.writeText ) {
					navigator.clipboard.writeText( area.value ).then( done ).catch( function () {
						document.execCommand( 'copy' );
						done();
					} );
				} else {
					document.execCommand( 'copy' );
					done();
				}
			} );
		}
	}

	// ─── Notices of the page, details link ───────────────────────────

	function bindChrome() {
		document.addEventListener( 'click', function ( e ) {
			var x = e.target.closest( '.olimc-dismissible .notice-dismiss' );
			if ( x ) {
				var box = x.closest( '.olimc-dismissible' );
				post( 'dismiss', { which: box.getAttribute( 'data-olimc-dismiss' ) } );
			}
			var link = e.target.closest( '.olimc-sources-link' );
			if ( link ) {
				e.preventDefault();
				var d = $( '#olimc-sources-detail' );
				d.open = ! d.open;
			}
		} );
		document.addEventListener( 'keydown', function ( e ) {
			var d = $( '#olimc-sources-detail' );
			if ( e.key === 'Escape' && d && d.open && ! document.querySelector( 'dialog[open]' ) && d.parentNode.contains( document.activeElement ) ) {
				d.open = false;
				var l = $( '.olimc-sources-link' );
				if ( l ) {
					l.focus();
				}
			}
		} );
		var det = $( '#olimc-sources-detail' );
		if ( det ) {
			det.addEventListener( 'toggle', function () {
				var l = $( '.olimc-sources-link' );
				if ( l ) {
					l.setAttribute( 'aria-expanded', det.open ? 'true' : 'false' );
				}
			} );
		}
		var notes = $$( '.notice:not(.olimc-result)', notices );
		notes.forEach( function ( n ) {
			var t = n.textContent.trim();
			if ( t ) {
				speak( t, 'polite' );
			}
		} );
	}

	function init() {
		$$( '[data-w]' ).forEach( function ( n ) {
			n.style.width = n.getAttribute( 'data-w' ) + '%';
		} );
		bindChrome();
		bindScan();
		bindList();
		bindSettings();
		showFlash();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
