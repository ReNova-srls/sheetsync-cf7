/**
 * SheetSync for CF7 – Dashboard tab (vanilla JS, no dependencies).
 *
 * - Stacked daily column chart (SVG) with hover/focus tooltip.
 * - Entry inspector (<dialog>) with "send again" and "delete".
 *
 * Every server value is inserted with textContent (never innerHTML).
 *
 * @package SheetSync_CF7
 */
( function () {
	'use strict';

	const cfg = window.ssCf7Dash;
	if ( ! cfg ) {
		return;
	}

	const i18n = cfg.i18n || {};
	const SVG_NS = 'http://www.w3.org/2000/svg';
	const STATUSES = [ 'sent', 'failed', 'pending' ]; // Stack order, bottom → top.

	const $ = ( selector, root = document ) => root.querySelector( selector );
	const $$ = ( selector, root = document ) => Array.from( root.querySelectorAll( selector ) );

	const el = ( tag, attrs = {}, text = null ) => {
		const node = document.createElement( tag );
		Object.keys( attrs ).forEach( ( key ) => node.setAttribute( key, attrs[ key ] ) );
		if ( null !== text ) {
			node.textContent = text;
		}
		return node;
	};

	const svg = ( tag, attrs = {} ) => {
		const node = document.createElementNS( SVG_NS, tag );
		Object.keys( attrs ).forEach( ( key ) => node.setAttribute( key, attrs[ key ] ) );
		return node;
	};

	const format = ( text, ...args ) => {
		let i = 0;
		return String( text || '' ).replace( /%[sd]/g, () => ( i < args.length ? String( args[ i++ ] ) : '' ) );
	};

	const number = ( value ) => Number( value || 0 ).toLocaleString( document.documentElement.lang || undefined );

	const ajax = async ( action, fields = {} ) => {
		const body = new FormData();
		body.append( 'action', action );
		body.append( 'nonce', cfg.nonce );
		Object.keys( fields ).forEach( ( key ) => body.append( key, fields[ key ] ) );

		try {
			const response = await fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body } );
			const json = await response.json();
			if ( json && typeof json === 'object' && 'success' in json ) {
				json.data = json.data || {};
				return json;
			}
		} catch ( e ) {
			// Handled below.
		}
		return { success: false, data: { message: i18n.requestFailed } };
	};

	/* ---------------------------------------------------------------------
	 * Chart
	 * ------------------------------------------------------------------ */

	const chartBox = $( '#ss-cf7-chart' );

	/**
	 * Clean axis maximum and step (1, 2, 5 × 10^n).
	 */
	const niceScale = ( max, ticks = 4 ) => {
		if ( max <= 0 ) {
			return { max: 4, step: 1 };
		}
		const raw = max / ticks;
		const power = Math.pow( 10, Math.floor( Math.log10( raw ) ) );
		const step = [ 1, 2, 5, 10 ].map( ( m ) => m * power ).find( ( s ) => s >= raw ) || raw;
		const niceStep = Math.max( 1, Math.ceil( step ) );
		return { max: Math.ceil( max / niceStep ) * niceStep, step: niceStep };
	};

	/**
	 * Column segment path: 4px rounded top on the topmost segment only, square at the baseline.
	 */
	const segmentPath = ( x, y, w, h, roundTop ) => {
		const r = roundTop ? Math.min( 4, w / 2, h ) : 0;
		return [
			'M', x, y + h,
			'V', y + r,
			r ? `Q ${ x } ${ y } ${ x + r } ${ y }` : '',
			'H', x + w - r,
			r ? `Q ${ x + w } ${ y } ${ x + w } ${ y + r }` : '',
			'V', y + h,
			'Z',
		].join( ' ' );
	};

	const renderChart = () => {
		if ( ! chartBox ) {
			return;
		}

		let series = [];
		try {
			series = JSON.parse( chartBox.getAttribute( 'data-series' ) || '[]' );
		} catch ( e ) {
			series = [];
		}

		chartBox.textContent = '';

		const total = series.reduce( ( sum, d ) => sum + d.sent + d.failed + d.pending, 0 );
		if ( ! series.length || ! total ) {
			chartBox.appendChild( el( 'p', { class: 'ss-cf7-chart-empty' }, i18n.noData ) );
			return;
		}

		const width = Math.max( 280, chartBox.clientWidth );
		const height = 220;
		const margin = { top: 8, right: 8, bottom: 26, left: 36 };
		const plotW = width - margin.left - margin.right;
		const plotH = height - margin.top - margin.bottom;

		const maxValue = Math.max( ...series.map( ( d ) => d.sent + d.failed + d.pending ) );
		const scale = niceScale( maxValue );
		const y = ( value ) => margin.top + plotH - ( value / scale.max ) * plotH;

		const slot = plotW / series.length;
		const barW = Math.max( 2, Math.min( 24, slot * 0.6 ) );

		const root = svg( 'svg', { width, height, viewBox: `0 0 ${ width } ${ height }`, class: 'ss-cf7-chart-svg', 'aria-hidden': 'true' } );

		// Gridlines + y ticks (hairline, recessive).
		for ( let v = 0; v <= scale.max; v += scale.step ) {
			const gy = Math.round( y( v ) ) + 0.5;
			root.appendChild( svg( 'line', { x1: margin.left, x2: width - margin.right, y1: gy, y2: gy, class: v === 0 ? 'ss-cf7-axis' : 'ss-cf7-grid' } ) );
			const tick = svg( 'text', { x: margin.left - 6, y: gy + 4, class: 'ss-cf7-tick', 'text-anchor': 'end' } );
			tick.textContent = number( v );
			root.appendChild( tick );
		}

		// X labels: only as many as fit (~44px each).
		const every = Math.max( 1, Math.ceil( 44 / slot ) );

		series.forEach( ( d, i ) => {
			const cx = margin.left + slot * i + slot / 2;
			const x = cx - barW / 2;
			const group = svg( 'g', { class: 'ss-cf7-col' } );

			// Stacked segments with a 2px surface gap between them.
			const visible = STATUSES.filter( ( s ) => d[ s ] > 0 );
			let base = 0;
			visible.forEach( ( status, index ) => {
				const top = base + d[ status ];
				const y0 = y( base );
				const y1 = y( top );
				const gap = index > 0 ? 2 : 0;
				const h = Math.max( 0, y0 - y1 - gap );
				if ( h > 0 ) {
					group.appendChild( svg( 'path', { d: segmentPath( x, y1, barW, h, index === visible.length - 1 ), class: 'ss-cf7-seg is-' + status } ) );
				}
				base = top;
			} );

			// Hit target: the whole column slot, taller than the mark.
			group.appendChild( svg( 'rect', { x: margin.left + slot * i, y: margin.top, width: slot, height: plotH, class: 'ss-cf7-hit' } ) );
			group.dataset.index = String( i );
			root.appendChild( group );

			if ( i % every === 0 ) {
				const label = svg( 'text', { x: cx, y: height - 8, class: 'ss-cf7-tick', 'text-anchor': 'middle' } );
				label.textContent = d.label;
				root.appendChild( label );
			}
		} );

		chartBox.appendChild( root );

		// Tooltip (also reachable with the keyboard through the focusable overlay buttons).
		const tooltip = el( 'div', { class: 'ss-cf7-tooltip', role: 'status' } );
		tooltip.hidden = true;
		chartBox.appendChild( tooltip );

		const showTooltip = ( index, anchorX ) => {
			const d = series[ index ];
			tooltip.textContent = '';
			tooltip.appendChild( el( 'strong', {}, d.full ) );
			STATUSES.forEach( ( status ) => {
				const row = el( 'div', { class: 'ss-cf7-tooltip-row' } );
				row.appendChild( el( 'span', { class: 'ss-cf7-swatch is-' + status, 'aria-hidden': 'true' } ) );
				row.appendChild( el( 'span', {}, i18n[ status ] ) );
				row.appendChild( el( 'span', { class: 'ss-cf7-tooltip-value' }, number( d[ status ] ) ) );
				tooltip.appendChild( row );
			} );
			tooltip.hidden = false;

			const left = Math.min( Math.max( 0, anchorX - tooltip.offsetWidth / 2 ), width - tooltip.offsetWidth );
			tooltip.style.left = left + 'px';
			tooltip.style.top = margin.top + 'px';

			$$( '.ss-cf7-col', root ).forEach( ( g ) => g.classList.toggle( 'is-active', g.dataset.index === String( index ) ) );
		};

		const hideTooltip = () => {
			tooltip.hidden = true;
			$$( '.ss-cf7-col', root ).forEach( ( g ) => g.classList.remove( 'is-active' ) );
		};

		root.addEventListener( 'mousemove', ( event ) => {
			const group = event.target.closest( '.ss-cf7-col' );
			if ( group ) {
				const i = Number( group.dataset.index );
				showTooltip( i, margin.left + slot * i + slot / 2 );
			}
		} );
		root.addEventListener( 'mouseleave', hideTooltip );

		// Keyboard: one focusable button per day with a full text alternative.
		const keys = el( 'div', { class: 'ss-cf7-chart-keys' } );
		series.forEach( ( d, i ) => {
			const button = el( 'button', {
				type: 'button',
				class: 'ss-cf7-chart-key',
				'aria-label': `${ d.full }: ${ i18n.sent } ${ d.sent }, ${ i18n.failed } ${ d.failed }, ${ i18n.pending } ${ d.pending }`,
			} );
			button.style.left = ( margin.left + slot * i ) + 'px';
			button.style.width = slot + 'px';
			button.addEventListener( 'focus', () => showTooltip( i, margin.left + slot * i + slot / 2 ) );
			button.addEventListener( 'blur', hideTooltip );
			keys.appendChild( button );
		} );
		chartBox.appendChild( keys );
	};

	if ( chartBox ) {
		renderChart();
		let resizeTimer;
		window.addEventListener( 'resize', () => {
			window.clearTimeout( resizeTimer );
			resizeTimer = window.setTimeout( renderChart, 150 );
		} );
	}

	/* ---------------------------------------------------------------------
	 * Entry inspector
	 * ------------------------------------------------------------------ */

	const dialog = $( '#ss-cf7-entry-dialog' );
	if ( ! dialog || typeof dialog.showModal !== 'function' ) {
		return;
	}

	const title = $( '#ss-cf7-dialog-title', dialog );
	const body = $( '.ss-cf7-dialog-body', dialog );
	const foot = $( '.ss-cf7-dialog-foot', dialog );
	const result = $( '#ss-cf7-dialog-result', dialog );
	let changed = false;

	const showResult = ( type, message ) => {
		result.className = 'ss-cf7-result is-' + type;
		result.textContent = message;
		result.hidden = false;
	};

	const pairsTable = ( caption, headLeft, pairs ) => {
		const section = el( 'section', { class: 'ss-cf7-dialog-section' } );
		section.appendChild( el( 'h3', {}, caption ) );
		const table = el( 'table', { class: 'widefat striped' } );
		const thead = el( 'thead' );
		const headRow = el( 'tr' );
		headRow.appendChild( el( 'th', { scope: 'col' }, headLeft ) );
		headRow.appendChild( el( 'th', { scope: 'col' }, i18n.value ) );
		thead.appendChild( headRow );
		table.appendChild( thead );
		const tbody = el( 'tbody' );
		pairs.forEach( ( [ key, value ] ) => {
			const tr = el( 'tr' );
			tr.appendChild( el( 'th', { scope: 'row' }, key || '—' ) );
			const td = el( 'td', {}, '' !== String( value ) ? String( value ) : i18n.empty );
			if ( '' === String( value ) ) {
				td.className = 'ss-cf7-muted';
			}
			tr.appendChild( td );
			tbody.appendChild( tr );
		} );
		table.appendChild( tbody );
		section.appendChild( table );
		return section;
	};

	const renderEntry = ( entry ) => {
		title.textContent = format( i18n.entryTitle, entry.id );
		body.textContent = '';
		foot.textContent = '';

		const meta = el( 'dl', { class: 'ss-cf7-entry-meta' } );
		const addMeta = ( label, value, node = null ) => {
			meta.appendChild( el( 'dt', {}, label ) );
			const dd = el( 'dd', {}, node ? null : String( value ) );
			if ( node ) {
				dd.appendChild( node );
			}
			meta.appendChild( dd );
		};

		const pill = el( 'span', { class: 'ss-cf7-status-pill is-' + entry.status }, entry.statusLabel );
		addMeta( i18n.date, entry.date );
		addMeta( i18n.form, entry.form );
		addMeta( i18n.status, '', pill );
		addMeta( i18n.attempts, entry.attempts );
		if ( entry.retryInfo ) {
			addMeta( i18n.autoRetry, entry.retryInfo );
		}
		if ( entry.sent_at ) {
			addMeta( i18n.sentAt, entry.sent_at );
		}
		if ( entry.sheetUrl ) {
			const link = el( 'a', { href: entry.sheetUrl, target: '_blank', rel: 'noopener noreferrer' }, entry.sheet + ' ↗' );
			addMeta( i18n.sheet, '', link );
		}
		body.appendChild( meta );

		if ( entry.error && 'sent' !== entry.status ) {
			const error = el( 'div', { class: 'ss-cf7-result is-error' } );
			error.appendChild( el( 'p', {}, i18n.error + ': ' + entry.error ) );
			body.appendChild( error );
		}

		body.appendChild( pairsTable( i18n.formData, i18n.field, entry.fields || [] ) );

		if ( entry.row && entry.row.length ) {
			body.appendChild( pairsTable( i18n.sheetRow, i18n.column, entry.row ) );
		}

		// Actions.
		if ( 'sent' !== entry.status ) {
			const retry = el( 'button', { type: 'button', class: 'button button-primary' }, i18n.retry );
			retry.addEventListener( 'click', async () => {
				retry.disabled = true;
				showResult( 'info', i18n.retrying );
				const response = await ajax( 'ss_cf7_retry_entry', { entry_id: entry.id } );
				retry.disabled = false;
				if ( response.success ) {
					changed = true;
					if ( response.data.entry ) {
						renderEntry( response.data.entry );
					}
					showResult( 'success', response.data.message );
				} else {
					changed = true; // Attempts/error changed.
					showResult( 'error', response.data.message || i18n.requestFailed );
				}
			} );
			foot.appendChild( retry );
		}

		const remove = el( 'button', { type: 'button', class: 'button ss-cf7-button-danger' }, i18n.delete );
		remove.addEventListener( 'click', async () => {
			if ( ! window.confirm( i18n.confirmDelete ) ) { // eslint-disable-line no-alert
				return;
			}
			remove.disabled = true;
			const response = await ajax( 'ss_cf7_delete_entry', { entry_id: entry.id } );
			if ( response.success ) {
				changed = true;
				dialog.close();
			} else {
				remove.disabled = false;
				showResult( 'error', response.data.message || i18n.requestFailed );
			}
		} );
		foot.appendChild( remove );

		const close = el( 'button', { type: 'button', class: 'button' }, i18n.close );
		close.addEventListener( 'click', () => dialog.close() );
		foot.appendChild( close );
	};

	const openEntry = async ( id ) => {
		changed = false;
		result.hidden = true;
		title.textContent = format( i18n.entryTitle, id );
		body.textContent = '';
		body.appendChild( el( 'p', {}, i18n.loading ) );
		foot.textContent = '';
		dialog.showModal();

		const response = await ajax( 'ss_cf7_get_entry', { entry_id: id } );
		if ( response.success ) {
			renderEntry( response.data );
		} else {
			body.textContent = '';
			showResult( 'error', response.data.message || i18n.requestFailed );
		}
	};

	document.addEventListener( 'click', ( event ) => {
		const button = event.target.closest( '.ss-cf7-entry-view' );
		if ( button ) {
			openEntry( Number( button.getAttribute( 'data-entry-id' ) ) );
		}
	} );

	$( '.ss-cf7-dialog-close', dialog ).addEventListener( 'click', () => dialog.close() );

	// Click on the backdrop closes the dialog.
	dialog.addEventListener( 'click', ( event ) => {
		if ( event.target === dialog ) {
			dialog.close();
		}
	} );

	// Refresh list, KPIs and chart after a resend/delete.
	dialog.addEventListener( 'close', () => {
		if ( changed ) {
			window.location.reload();
		}
	} );
}() );
