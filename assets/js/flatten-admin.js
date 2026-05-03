/**
 * Flatten admin tab — client behavior.
 *
 * Renders the field-mapping table from the bootstrap JSON in the page,
 * lets the editor add/remove/reorder rows, ensures the hidden
 * config_json input mirrors the live state on every change, and wires
 * the "Validate condition" + transformer-args toggles.
 *
 * @package JEDB
 */
( function ( $ ) {
	'use strict';

	var bootstrap = null;
	try {
		bootstrap = JSON.parse(
			document.getElementById( 'jedb-flatten-bootstrap' ).textContent
		);
	} catch ( e ) {
		console.error( '[JEDB] flatten bootstrap JSON parse failed', e );
		return;
	}

	var $form        = $( '#jedb-flatten-form' );
	if ( ! $form.length ) { return; }

	var $tbody       = $( '#jedb_flatten_mappings tbody' );
	var $hiddenJson  = $( '#jedb_flatten_config_json' );
	var $rawJson     = $( '#jedb_flatten_config_raw' );
	var $sourceSel   = $( '#jedb_flatten_source' );
	var $targetSel   = $( '#jedb_flatten_target' );
	var $relationSel = $( '#jedb_flatten_relation_id' );
	var $linkRadios  = $( 'input[name="link_via_type"]' );
	var $required    = $( '#jedb_flatten_required_panel' );
	var $condInput   = $( '#jedb_flatten_condition' );
	var $condStatus  = $( '#jedb_flatten_condition_status' );
	var $condBtn     = $( '#jedb_flatten_validate_condition' );

	var transformers   = bootstrap.transformers || [];
	var sourceSchema   = bootstrap.source_schema || [];
	var targetSchema   = bootstrap.target_schema || [];
	var targetRequired = bootstrap.target_required || [];
	var mappings       = bootstrap.initial_mappings || [];

	function transformerByName( name ) {
		for ( var i = 0; i < transformers.length; i++ ) {
			if ( transformers[ i ].name === name ) { return transformers[ i ]; }
		}
		return null;
	}

	function renderFieldOptions( schema, selected, includeReadonly ) {

		var $sel = $( '<select class="jedb-field-select" />' );
		$sel.append( $( '<option/>' ).val( '' ).text( '— select —' ) );

		var byGroup = {};
		schema.forEach( function ( f ) {
			var g = f.group || 'fields';
			if ( ! byGroup[ g ] ) { byGroup[ g ] = []; }
			byGroup[ g ].push( f );
		} );

		Object.keys( byGroup ).forEach( function ( g ) {
			var $og = $( '<optgroup/>' ).attr( 'label', g );
			byGroup[ g ].forEach( function ( f ) {
				if ( f.readonly && ! includeReadonly ) {
					return;
				}
				var label = f.label || f.name;
				if ( f.name && f.name !== label ) { label += ' (' + f.name + ')'; }
				if ( f.required ) { label = '★ ' + label; }
				if ( f.natively_rendered ) { label += ' · native'; }
				$og.append( $( '<option/>' ).val( f.name ).text( label ).prop( 'selected', f.name === selected ) );
			} );
			$sel.append( $og );
		} );

		return $sel;
	}

	function renderTransformerSelect( chain, direction ) {

		var $cell = $( '<div class="jedb-chain"/>' );
		chain = ( chain && chain.length ) ? chain : [ { name: 'passthrough', args: {} } ];

		chain.forEach( function ( step, idx ) {
			$cell.append( renderChainStep( step, direction, idx ) );
		} );

		var $add = $( '<button type="button" class="button button-small jedb-chain-add"/>' )
			.text( '+ step' )
			.on( 'click', function () {
				$cell.find( '.jedb-chain-add' ).before(
					renderChainStep( { name: 'passthrough', args: {} }, direction, $cell.find( '.jedb-chain-step' ).length )
				);
				syncJSON();
			} );
		$cell.append( $add );

		return $cell;
	}

	function renderChainStep( step, direction, idx ) {

		var $step = $( '<div class="jedb-chain-step"/>' )
			.attr( 'data-direction', direction )
			.attr( 'data-idx', idx );

		var $sel = $( '<select class="jedb-chain-name"/>' );
		transformers.forEach( function ( t ) {
			$sel.append( $( '<option/>' ).val( t.name ).text( t.label ).prop( 'selected', t.name === step.name ) );
		} );

		var $remove = $( '<button type="button" class="button button-small jedb-chain-remove" title="Remove step">×</button>' );

		var $args = $( '<div class="jedb-chain-args"/>' );

		function refreshArgs() {
			$args.empty();
			var t = transformerByName( $sel.val() );
			if ( ! t || ! t.args || ! t.args.length ) { return; }
			t.args.forEach( function ( argSpec ) {
				var $row = $( '<label class="jedb-chain-arg"/>' );
				$row.append( $( '<span/>' ).text( argSpec.label || argSpec.name ).attr( 'title', argSpec.help || '' ) );
				var current = ( step.args && step.args.hasOwnProperty( argSpec.name ) ) ? step.args[ argSpec.name ] : argSpec['default'];

				var $input;
				if ( argSpec.type === 'textarea' ) {
					$input = $( '<textarea rows="2"/>' ).val( typeof current === 'string' ? current : JSON.stringify( current || {} ) );
				} else if ( argSpec.type === 'checkbox' ) {
					$input = $( '<input type="checkbox"/>' ).prop( 'checked', !! current );
				} else if ( argSpec.type === 'number' ) {
					$input = $( '<input type="number"/>' ).val( current === undefined ? '' : current );
				} else if ( argSpec.type === 'select' && argSpec.options ) {
					$input = $( '<select/>' );
					argSpec.options.forEach( function ( opt ) {
						$input.append( $( '<option/>' ).val( opt ).text( opt ).prop( 'selected', opt === current ) );
					} );
				} else {
					$input = $( '<input type="text"/>' ).val( current === undefined ? '' : current );
				}

				$input.attr( 'data-arg-name', argSpec.name ).addClass( 'jedb-chain-arg-input' );
				$row.append( $input );
				$args.append( $row );
			} );
		}

		$sel.on( 'change', function () {
			step.args = {};
			refreshArgs();
			syncJSON();
		} );

		$remove.on( 'click', function () {
			$step.remove();
			syncJSON();
		} );

		$step.append( $sel ).append( $remove ).append( $args );
		refreshArgs();

		return $step;
	}

	function makeMappingRow( mapping ) {

		var $tr = $( '<tr class="jedb-mapping-row"/>' );

		var $sourceTd = $( '<td/>' ).append( renderFieldOptions( sourceSchema, mapping.source_field || '', false ) );
		var $targetTd = $( '<td/>' ).append( renderFieldOptions( targetSchema, mapping.target_field || '', false ) );

		var $pushTd = $( '<td/>' ).append( renderTransformerSelect( mapping.push_transform || [], 'push' ) );
		var $pullTd = $( '<td/>' ).append( renderTransformerSelect( mapping.pull_transform || [], 'pull' ) );

		var $rm = $( '<button type="button" class="button button-small button-link-delete">Remove</button>' )
			.on( 'click', function () {
				$tr.remove();
				syncJSON();
			} );

		$tr.append( $sourceTd ).append( $targetTd ).append( $pushTd ).append( $pullTd ).append( $( '<td/>' ).append( $rm ) );

		return $tr;
	}

	function readMappingsFromDom() {

		var out = [];
		$tbody.children( 'tr' ).each( function () {

			var $tr = $( this );
			var src = $tr.find( 'td:nth-child(1) select.jedb-field-select' ).val() || '';
			var tgt = $tr.find( 'td:nth-child(2) select.jedb-field-select' ).val() || '';

			var pushChain = readChain( $tr.find( 'td:nth-child(3)' ) );
			var pullChain = readChain( $tr.find( 'td:nth-child(4)' ) );

			out.push( {
				source_field:   src,
				target_field:   tgt,
				push_transform: pushChain,
				pull_transform: pullChain,
				enabled:        true
			} );
		} );
		return out;
	}

	function readChain( $td ) {
		var out = [];
		$td.find( '.jedb-chain-step' ).each( function () {
			var $step = $( this );
			var name  = $step.find( 'select.jedb-chain-name' ).val();
			var args  = {};
			$step.find( '.jedb-chain-arg-input' ).each( function () {
				var $i = $( this );
				var k  = $i.attr( 'data-arg-name' );
				if ( ! k ) { return; }
				if ( $i.is( ':checkbox' ) ) {
					args[ k ] = $i.is( ':checked' );
				} else if ( $i.is( '[type="number"]' ) ) {
					args[ k ] = $i.val() === '' ? null : Number( $i.val() );
				} else {
					args[ k ] = $i.val();
				}
			} );
			out.push( { name: name, args: args } );
		} );
		return out;
	}

	function buildConfig() {

		var fromRaw = null;
		try {
			fromRaw = JSON.parse( $rawJson.val() || '{}' );
		} catch ( e ) {
			fromRaw = {};
		}

		var cfg = $.extend( true, {}, fromRaw );

		cfg.mappings = readMappingsFromDom();

		cfg.condition = $condInput.val() || '';
		cfg.priority  = parseInt( $form.find( '#jedb_flatten_priority' ).val(), 10 );
		if ( isNaN( cfg.priority ) ) { cfg.priority = 100; }

		cfg.link_via = $.extend( {}, cfg.link_via || {}, {
			type:        $linkRadios.filter( ':checked' ).val() || 'je_relation',
			relation_id: $relationSel.val() || '',
			side:        ( cfg.link_via && cfg.link_via.side ) || 'auto'
		} );

		if ( ! cfg.trigger ) {
			cfg.trigger = { type: 'cct_save', args: {} };
		}
		if ( ! cfg.required_overrides ) {
			cfg.required_overrides = { add: [], remove: [] };
		}

		return cfg;
	}

	function syncJSON() {
		var cfg = buildConfig();
		$hiddenJson.val( JSON.stringify( cfg ) );
		$rawJson.val( JSON.stringify( cfg, null, 2 ) );
	}

	function renderInitial() {
		$tbody.empty();
		( mappings.length ? mappings : [ { source_field: '', target_field: '', push_transform: [], pull_transform: [] } ] )
			.forEach( function ( m ) {
				$tbody.append( makeMappingRow( m ) );
			} );
		syncJSON();
	}

	$( '#jedb_flatten_add_mapping' ).on( 'click', function () {
		$tbody.append( makeMappingRow( { source_field: '', target_field: '', push_transform: [], pull_transform: [] } ) );
		syncJSON();
	} );

	$tbody.on( 'change', 'select, input, textarea', syncJSON );

	$form.on( 'change', 'input[name="link_via_type"], #jedb_flatten_relation_id, #jedb_flatten_priority', syncJSON );
	$form.on( 'input',  '#jedb_flatten_condition', syncJSON );
	$form.on( 'input',  '#jedb_flatten_config_raw', function () { $hiddenJson.val( $rawJson.val() ); } );

	$form.on( 'submit', function () {
		syncJSON();
	} );

	$condBtn.on( 'click', function ( e ) {
		e.preventDefault();
		$.post( bootstrap.ajax_url, {
			action: 'jedb_flatten_validate_condition',
			nonce:  bootstrap.nonce,
			dsl:    $condInput.val() || ''
		} ).done( function ( resp ) {
			if ( ! resp || ! resp.success ) {
				$condStatus.removeClass( 'jedb-pill-ok jedb-pill-warn' ).addClass( 'jedb-pill-bad' ).text( 'AJAX error' ).show();
				return;
			}
			if ( resp.data && resp.data.ok ) {
				$condStatus.removeClass( 'jedb-pill-bad jedb-pill-warn' ).addClass( 'jedb-pill-ok' ).text( 'OK' ).show();
			} else {
				$condStatus.removeClass( 'jedb-pill-ok jedb-pill-warn' ).addClass( 'jedb-pill-bad' ).text( resp.data && resp.data.error ? resp.data.error : 'Invalid' ).show();
			}
		} );
	} );

	$( '#jedb_flatten_source, #jedb_flatten_target' ).on( 'change', function () {
		// On source/target change we re-save the form once so the relations
		// list and field schemas are re-rendered server-side. Simpler than
		// re-fetching everything via AJAX for v1.
		// (The user clicks "Save" — keep it explicit for now.)
	} );

	renderInitial();

} )( jQuery );
