/**
 * SheetSync for CF7 – admin scripts (vanilla JS, no dependencies).
 *
 * - Settings page: JSON file reader + client-side pre-validation, copy email,
 *   "Test connection" (AJAX), confirmation before removing credentials.
 * - CF7 editor panel: Spreadsheet URL → ID extraction, "Sync headers" (AJAX).
 *
 * Every server message is inserted with textContent (never innerHTML).
 *
 * @package SheetSync_CF7
 */
( function () {
	'use strict';

	const cfg = window.ssCf7;
	if ( ! cfg ) {
		return;
	}

	const i18n = cfg.i18n || {};
	const REQUIRED_FIELDS = [ 'client_email', 'private_key', 'token_uri' ];
	const MAX_JSON_BYTES = 20480;
	const SHEET_URL_RE = /\/spreadsheets\/d\/([a-zA-Z0-9_-]+)/;

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	const $ = ( selector, root = document ) => root.querySelector( selector );
	const $$ = ( selector, root = document ) => Array.from( root.querySelectorAll( selector ) );

	/**
	 * Replaces %s placeholders in order.
	 */
	const format = ( text, ...args ) => {
		let i = 0;
		return String( text || '' ).replace( /%s/g, () => ( i < args.length ? String( args[ i++ ] ) : '' ) );
	};

	/**
	 * Shows a message box. `lists` = [ { title, items } ].
	 */
	const showResult = ( box, type, message, lists = [] ) => {
		if ( ! box ) {
			return;
		}

		box.className = 'ss-cf7-result is-' + type;
		box.textContent = '';

		const p = document.createElement( 'p' );
		p.textContent = message;
		box.appendChild( p );

		lists.forEach( ( list ) => {
			if ( ! list.items || ! list.items.length ) {
				return;
			}
			const title = document.createElement( 'p' );
			title.textContent = list.title;
			const ul = document.createElement( 'ul' );
			list.items.forEach( ( item ) => {
				const li = document.createElement( 'li' );
				const code = document.createElement( 'code' );
				code.textContent = item;
				li.appendChild( code );
				ul.appendChild( li );
			} );
			box.appendChild( title );
			box.appendChild( ul );
		} );

		box.hidden = false;
	};

	const hideResult = ( box ) => {
		if ( box ) {
			box.hidden = true;
			box.textContent = '';
		}
	};

	const setBusy = ( button, spinner, busy ) => {
		if ( button ) {
			button.disabled = busy;
			button.setAttribute( 'aria-busy', busy ? 'true' : 'false' );
		}
		if ( spinner ) {
			spinner.classList.toggle( 'is-active', busy );
		}
	};

	/**
	 * POSTs to admin-ajax.php. Always resolves to { success, data: { message } }.
	 */
	const ajax = async ( action, fields = {} ) => {
		const body = new FormData();
		body.append( 'action', action );
		body.append( 'nonce', cfg.nonce );

		Object.keys( fields ).forEach( ( key ) => {
			const value = fields[ key ];
			if ( Array.isArray( value ) ) {
				value.forEach( ( item ) => body.append( key + '[]', item ) );
			} else {
				body.append( key, value );
			}
		} );

		try {
			const response = await fetch( cfg.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body,
			} );
			const json = await response.json();
			if ( json && typeof json === 'object' && 'success' in json ) {
				json.data = json.data || {};
				return json;
			}
		} catch ( e ) {
			// Network error or non-JSON response (e.g. "0" / "-1"): handled below.
		}

		return { success: false, data: { message: i18n.requestFailed } };
	};

	/**
	 * Copies text, with a fallback for non-HTTPS admin areas.
	 */
	const copyText = async ( text ) => {
		if ( navigator.clipboard && window.isSecureContext ) {
			try {
				await navigator.clipboard.writeText( text );
				return true;
			} catch ( e ) {
				// Fall through to the legacy method.
			}
		}

		const temp = document.createElement( 'textarea' );
		temp.value = text;
		temp.setAttribute( 'readonly', '' );
		temp.style.position = 'fixed';
		temp.style.top = '-1000px';
		document.body.appendChild( temp );
		temp.select();

		let ok = false;
		try {
			ok = document.execCommand( 'copy' );
		} catch ( e ) {
			ok = false;
		}
		document.body.removeChild( temp );
		return ok;
	};

	/**
	 * Client-side pre-validation (the server validates again, including the key).
	 * Returns { error } or { data }.
	 */
	const validateJson = ( text ) => {
		const raw = String( text || '' ).trim();
		if ( ! raw ) {
			return { error: i18n.emptyJson };
		}

		let data;
		try {
			data = JSON.parse( raw );
		} catch ( e ) {
			return { error: i18n.invalidJson };
		}

		if ( ! data || typeof data !== 'object' || Array.isArray( data ) ) {
			return { error: i18n.invalidJson };
		}

		if ( data.type && data.type !== 'service_account' ) {
			return { error: i18n.notServiceAcc };
		}

		const missing = REQUIRED_FIELDS.filter( ( field ) => typeof data[ field ] !== 'string' || ! data[ field ].trim() );
		if ( missing.length ) {
			return { error: format( i18n.missingFields, missing.join( ', ' ) ) };
		}

		return { data };
	};

	/* ---------------------------------------------------------------------
	 * Copy email buttons (settings page + CF7 panel)
	 * ------------------------------------------------------------------ */

	document.addEventListener( 'click', async ( event ) => {
		const button = event.target.closest( '[data-ss-cf7-copy]' );
		if ( ! button ) {
			return;
		}

		event.preventDefault();

		const label = $( '.ss-cf7-copy-label', button ) || button;
		const ok = await copyText( button.getAttribute( 'data-ss-cf7-copy' ) || '' );

		if ( ok ) {
			label.textContent = i18n.copied;
			button.classList.add( 'is-copied' );
			window.setTimeout( () => {
				label.textContent = i18n.copy;
				button.classList.remove( 'is-copied' );
			}, 2000 );
		} else {
			window.alert( i18n.copyFailed ); // eslint-disable-line no-alert
		}
	} );

	// Select the whole email on focus (easy manual copy).
	const emailInput = $( '#ss-cf7-client-email' );
	if ( emailInput ) {
		emailInput.addEventListener( 'focus', () => emailInput.select() );
	}

	/* ---------------------------------------------------------------------
	 * Settings page: credentials form
	 * ------------------------------------------------------------------ */

	const credentialsForm = $( '#ss-cf7-credentials-form' );

	if ( credentialsForm ) {
		const fileInput = $( '#ss-cf7-json-file', credentialsForm );
		const textarea = $( '#ss-cf7-json', credentialsForm );
		const feedback = $( '#ss-cf7-json-feedback', credentialsForm );

		if ( fileInput && textarea ) {
			fileInput.addEventListener( 'change', () => {
				const file = fileInput.files && fileInput.files[ 0 ];
				if ( ! file ) {
					return;
				}

				if ( file.size > MAX_JSON_BYTES ) {
					fileInput.value = '';
					showResult( feedback, 'error', i18n.fileTooLarge );
					return;
				}

				const reader = new FileReader();

				reader.onload = () => {
					textarea.value = String( reader.result || '' );
					const check = validateJson( textarea.value );
					if ( check.error ) {
						showResult( feedback, 'error', check.error );
					} else {
						showResult( feedback, 'success', format( i18n.fileLoaded, check.data.client_email ) );
					}
				};

				reader.onerror = () => showResult( feedback, 'error', i18n.fileReadError );

				reader.readAsText( file );
			} );

			textarea.addEventListener( 'input', () => hideResult( feedback ) );
		}

		credentialsForm.addEventListener( 'submit', ( event ) => {
			const check = validateJson( textarea ? textarea.value : '' );
			if ( check.error ) {
				event.preventDefault();
				showResult( feedback, 'error', check.error );
				if ( textarea ) {
					textarea.focus();
				}
			}
		} );
	}

	/* ---------------------------------------------------------------------
	 * Settings page: confirmation before removing credentials
	 * ------------------------------------------------------------------ */

	$$( 'form[data-ss-cf7-confirm]' ).forEach( ( form ) => {
		form.addEventListener( 'submit', ( event ) => {
			if ( ! window.confirm( form.getAttribute( 'data-ss-cf7-confirm' ) ) ) { // eslint-disable-line no-alert
				event.preventDefault();
			}
		} );
	} );

	/* ---------------------------------------------------------------------
	 * Settings page: test connection
	 * ------------------------------------------------------------------ */

	const testButton = $( '#ss-cf7-test-connection' );

	if ( testButton ) {
		const spinner = $( '#ss-cf7-test-spinner' );
		const result = $( '#ss-cf7-test-result' );

		testButton.addEventListener( 'click', async () => {
			setBusy( testButton, spinner, true );
			showResult( result, 'info', i18n.testing );

			const response = await ajax( 'ss_cf7_test_connection' );

			setBusy( testButton, spinner, false );
			showResult( result, response.success ? 'success' : 'error', response.data.message || i18n.requestFailed );
		} );
	}

	/* ---------------------------------------------------------------------
	 * CF7 editor panel
	 * ------------------------------------------------------------------ */

	const panel = $( '.ss-cf7-panel' );

	if ( panel ) {
		const idInput = $( '#ss-cf7-spreadsheet-id', panel );
		const sheetInput = $( '#ss-cf7-sheet-name', panel );
		const syncButton = $( '#ss-cf7-sync-headers', panel );
		const syncSpinner = $( '#ss-cf7-sync-spinner', panel );
		const syncResult = $( '#ss-cf7-sync-result', panel );
		const mappingBox = $( '#ss-cf7-mapping', panel );
		const loadButton = $( '#ss-cf7-load-headers', panel );
		const loadSpinner = $( '#ss-cf7-load-spinner', panel );
		const mappingResult = $( '#ss-cf7-mapping-result', panel );

		const normalize = ( text ) => String( text || '' ).trim().toLowerCase().replace( /\s+/g, ' ' );

		const parseData = ( el, attr ) => {
			try {
				const value = JSON.parse( el.getAttribute( attr ) || '[]' );
				return Array.isArray( value ) ? value : [];
			} catch ( e ) {
				return [];
			}
		};

		/* ---- Column mapping table ------------------------------------- */

		const sources = mappingBox ? parseData( mappingBox, 'data-sources' ) : [];
		const mappingTable = mappingBox ? $( '.ss-cf7-mapping-table', mappingBox ) : null;
		const mappingBody = mappingTable ? $( 'tbody', mappingTable ) : null;

		const option = ( value, label, selected ) => {
			const opt = document.createElement( 'option' );
			opt.value = value;
			opt.textContent = label;
			opt.selected = selected;
			return opt;
		};

		const buildSelect = ( row, index ) => {
			const select = document.createElement( 'select' );
			select.className = 'ss-cf7-rule';
			select.name = 'ss_cf7[mapping][' + index + '][rule]';
			select.setAttribute( 'aria-label', row.header );

			const rule = row.rule || 'auto';
			select.appendChild( option( 'auto', row.auto ? format( i18n.autoMatch, row.auto ) : i18n.autoNone, 'auto' === rule ) );
			select.appendChild( option( 'empty', i18n.ruleEmpty, 'empty' === rule ) );
			select.appendChild( option( 'text', i18n.ruleText, 'text' === rule ) );

			[ [ 'fields', i18n.groupFields ], [ 'meta', i18n.groupMeta ], [ 'tracking', i18n.groupTracking ] ].forEach( ( [ group, label ] ) => {
				const items = sources.filter( ( source ) => source.group === group );
				if ( ! items.length ) {
					return;
				}
				const optgroup = document.createElement( 'optgroup' );
				optgroup.label = label;
				items.forEach( ( source ) => optgroup.appendChild( option( source.value, source.label, source.value === rule ) ) );
				select.appendChild( optgroup );
			} );

			// A mapped field that no longer exists in the form: keep it visible instead of losing it.
			if ( rule.startsWith( 'field:' ) && ! sources.some( ( source ) => source.value === rule ) ) {
				select.appendChild( option( rule, rule.slice( 6 ) + ' (?)', true ) );
			}

			return select;
		};

		const renderRows = ( rows ) => {
			if ( ! mappingBody ) {
				return;
			}

			mappingBody.textContent = '';

			rows.forEach( ( row, index ) => {
				const tr = document.createElement( 'tr' );
				tr.dataset.header = row.header;
				tr.dataset.auto = row.auto || '';

				const headerCell = document.createElement( 'td' );
				const strong = document.createElement( 'strong' );
				strong.textContent = row.header;
				const hidden = document.createElement( 'input' );
				hidden.type = 'hidden';
				hidden.name = 'ss_cf7[mapping][' + index + '][header]';
				hidden.value = row.header;
				headerCell.appendChild( strong );
				headerCell.appendChild( hidden );

				const ruleCell = document.createElement( 'td' );
				const select = buildSelect( row, index );
				const text = document.createElement( 'input' );
				text.type = 'text';
				text.className = 'regular-text ss-cf7-rule-text';
				text.name = 'ss_cf7[mapping][' + index + '][text]';
				text.value = row.text || '';
				text.placeholder = i18n.textPlaceholder;
				text.setAttribute( 'aria-label', row.header + ' – ' + i18n.ruleText );
				text.hidden = 'text' !== select.value;

				select.addEventListener( 'change', () => {
					text.hidden = 'text' !== select.value;
					if ( ! text.hidden ) {
						text.focus();
					}
				} );

				ruleCell.appendChild( select );
				ruleCell.appendChild( text );
				tr.appendChild( headerCell );
				tr.appendChild( ruleCell );
				mappingBody.appendChild( tr );
			} );

			mappingTable.hidden = ! rows.length;
		};

		const readRows = () => ( mappingBody ? $$( 'tr', mappingBody ) : [] ).map( ( tr ) => ( {
			header: tr.dataset.header,
			auto: tr.dataset.auto,
			rule: $( '.ss-cf7-rule', tr ).value,
			text: $( '.ss-cf7-rule-text', tr ).value,
		} ) );

		// New header list from the sheet: keep the rules already chosen (matched by header name).
		const mergeHeaders = ( headers ) => {
			const current = {};
			readRows().forEach( ( row ) => {
				current[ normalize( row.header ) ] = row;
			} );

			renderRows( headers.map( ( item ) => {
				const previous = current[ normalize( item.header ) ];
				return {
					header: item.header,
					auto: item.auto || '',
					rule: previous ? previous.rule : 'auto',
					text: previous ? previous.text : '',
				};
			} ) );
		};

		if ( mappingBox ) {
			// Tells PHP that the mapping fields come from this script (see save_form_settings()).
			const ready = document.createElement( 'input' );
			ready.type = 'hidden';
			ready.name = 'ss_cf7[mapping_ready]';
			ready.value = '1';
			mappingBox.appendChild( ready );

			renderRows( parseData( mappingBox, 'data-rows' ) );
		}

		/* ---- Panel values --------------------------------------------- */

		const currentValues = () => ( {
			spreadsheet_id: idInput ? idInput.value.trim() : '',
			sheet_name: sheetInput ? sheetInput.value.trim() : '',
			meta: $$( '.ss-cf7-meta', panel ).filter( ( box ) => box.checked ).map( ( box ) => box.value ),
			exclude: $$( '.ss-cf7-exclude', panel ).filter( ( box ) => box.checked ).map( ( box ) => box.value ),
			mapping: JSON.stringify( readRows().map( ( row ) => ( { header: row.header, rule: row.rule, text: row.text } ) ) ),
		} );

		const initialValues = JSON.stringify( currentValues() );

		const requestFields = () => Object.assign( { form_id: panel.getAttribute( 'data-form-id' ) || '0' }, currentValues() );

		const withUnsavedWarning = ( message ) => ( JSON.stringify( currentValues() ) !== initialValues ? message + ' ' + i18n.unsavedWarning : message );

		// Pasting the full spreadsheet URL keeps only the ID.
		if ( idInput ) {
			const extractId = () => {
				const match = idInput.value.match( SHEET_URL_RE );
				if ( match ) {
					idInput.value = match[ 1 ];
				}
			};
			idInput.addEventListener( 'change', extractId );
			idInput.addEventListener( 'paste', () => window.setTimeout( extractId, 0 ) );
		}

		const ensureId = ( resultBox ) => {
			if ( idInput && idInput.value.trim() ) {
				return true;
			}
			showResult( resultBox, 'error', i18n.missingId );
			if ( idInput ) {
				idInput.focus();
			}
			return false;
		};

		/* ---- Sync headers --------------------------------------------- */

		if ( syncButton ) {
			syncButton.addEventListener( 'click', async () => {
				if ( ! ensureId( syncResult ) ) {
					return;
				}

				setBusy( syncButton, syncSpinner, true );
				showResult( syncResult, 'info', i18n.syncing );

				const response = await ajax( 'ss_cf7_sync_headers', requestFields() );

				setBusy( syncButton, syncSpinner, false );

				if ( ! response.success ) {
					showResult( syncResult, 'error', response.data.message || i18n.requestFailed );
					return;
				}

				if ( Array.isArray( response.data.headers ) ) {
					mergeHeaders( response.data.headers );
				}

				const orphans = response.data.orphans || [];
				showResult( syncResult, orphans.length ? 'warning' : 'success', withUnsavedWarning( response.data.message ), [
					{ title: i18n.addedColumns, items: response.data.added || [] },
					{ title: i18n.orphanColumns, items: orphans },
				] );
			} );
		}

		/* ---- Load columns for the mapping ----------------------------- */

		if ( loadButton ) {
			loadButton.addEventListener( 'click', async () => {
				if ( ! ensureId( mappingResult ) ) {
					return;
				}

				setBusy( loadButton, loadSpinner, true );
				showResult( mappingResult, 'info', i18n.loading );

				const response = await ajax( 'ss_cf7_load_headers', requestFields() );

				setBusy( loadButton, loadSpinner, false );

				if ( ! response.success ) {
					showResult( mappingResult, 'error', response.data.message || i18n.requestFailed );
					return;
				}

				mergeHeaders( response.data.headers || [] );
				showResult( mappingResult, 'success', withUnsavedWarning( response.data.message ) );
			} );
		}
	}
}() );

