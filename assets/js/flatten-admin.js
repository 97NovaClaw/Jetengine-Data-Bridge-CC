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

	/* -----------------------------------------------------------------------
	 * Phase 3.6 / D-20-D-24: Taxonomy rules section.
	 *
	 * In-memory model:
	 *   - taxonomyCatalog: array fetched from the AJAX endpoint, shape per
	 *     class-tab-flatten.php::ajax_get_post_type_taxonomies.
	 *   - currentPostType: parsed from the target_target dropdown.
	 *   - The DOM rows are the source of truth for taxonomyRules between
	 *     renders (same pattern as mappings). syncJSON reads them.
	 * -------------------------------------------------------------------- */

	var $taxSection      = $( '#jedb_flatten_taxonomies_section' );
	var $taxTbody        = $( '#jedb_flatten_taxonomies tbody' );
	var $taxStatus       = $( '#jedb_flatten_taxonomies_status' );
	var $taxSummaryPill  = $taxSection.find( '.jedb-tax-summary-pill' );

	var taxonomyCatalog  = [];
	var currentPostType  = bootstrap.initial_post_type || '';
	var taxonomyRules    = ( bootstrap.initial_taxonomies || [] ).slice();
	var taxonomyDefault  = bootstrap.taxonomy_default_rule || {
		taxonomy: '', apply_terms: [], apply_terms_inverse: [],
		match_by: 'slug', merge_strategy: 'append',
		create_if_missing: false, snippet: null, enabled: true, note: ''
	};

	function findTaxonomyInCatalog( slug ) {
		for ( var i = 0; i < taxonomyCatalog.length; i++ ) {
			if ( taxonomyCatalog[ i ].slug === slug ) { return taxonomyCatalog[ i ]; }
		}
		return null;
	}

	function fetchTaxonomies( postType, done ) {
		if ( ! postType ) {
			taxonomyCatalog = [];
			if ( done ) { done(); }
			return;
		}
		$taxStatus.text( '… loading taxonomies for ' + postType );
		$.post( bootstrap.ajax_url, {
			action:    'jedb_flatten_get_post_type_taxonomies',
			nonce:     bootstrap.nonce,
			post_type: postType
		} ).done( function ( resp ) {
			if ( resp && resp.success && resp.data ) {
				taxonomyCatalog = resp.data.taxonomies || [];
				$taxStatus.text( taxonomyCatalog.length + ' taxonomies registered for ' + postType );
			} else {
				taxonomyCatalog = [];
				$taxStatus.text( 'Failed to load taxonomies' );
			}
			if ( done ) { done(); }
		} ).fail( function () {
			taxonomyCatalog = [];
			$taxStatus.text( 'AJAX error loading taxonomies' );
			if ( done ) { done(); }
		} );
	}

	function makeTermsSelect( taxonomySlug, selectedRefs, matchBy, name ) {

		var $sel = $( '<select multiple size="4" />' )
			.attr( 'data-tax-control', name )
			.css( { width: '100%', minHeight: '90px' } );

		var taxData  = findTaxonomyInCatalog( taxonomySlug );
		var selected = ( selectedRefs || [] ).map( String );

		if ( ! taxData || ! taxData.terms || ! taxData.terms.length ) {
			$sel.append( $( '<option/>' ).attr( 'disabled', true ).text( taxData ? '— no terms registered —' : '— select a taxonomy first —' ) );
			return $sel;
		}

		taxData.terms.forEach( function ( term ) {
			var optValue;
			if ( 'name' === matchBy ) {
				optValue = String( term.name );
			} else if ( 'id' === matchBy ) {
				optValue = String( term.id );
			} else {
				optValue = String( term.slug );
			}
			var $opt = $( '<option/>' ).val( optValue ).text( term.name + ' [' + term.slug + ']' );
			if ( selected.indexOf( optValue ) !== -1 ) {
				$opt.prop( 'selected', true );
			}
			$sel.append( $opt );
		} );

		if ( taxData.truncated ) {
			$sel.append( $( '<option/>' ).attr( 'disabled', true ).text( '… showing first 100 of ' + taxData.terms_count + ' — edit raw JSON for the rest' ) );
		}

		return $sel;
	}

	function readMultiSelectValues( $sel ) {
		var out = $sel.val();
		if ( ! out ) { return []; }
		if ( ! Array.isArray( out ) ) { out = [ out ]; }
		return out.filter( function ( v ) { return v !== null && v !== ''; } );
	}

	function makeTaxonomyRow( rule ) {

		rule = $.extend( true, {}, taxonomyDefault, rule || {} );

		var $tr = $( '<tr class="jedb-taxonomy-row"/>' );

		// Taxonomy slug select
		var $taxSel = $( '<select class="jedb-tax-slug"/>' );
		$taxSel.append( $( '<option/>' ).val( '' ).text( '— select —' ) );
		taxonomyCatalog.forEach( function ( tax ) {
			var label = tax.label + ' (' + tax.slug + ')';
			if ( tax.hierarchical ) { label += ' · hierarchical'; }
			label += ' · ' + tax.terms_count + ' terms';
			$taxSel.append( $( '<option/>' ).val( tax.slug ).text( label ).prop( 'selected', tax.slug === rule.taxonomy ) );
		} );
		if ( rule.taxonomy && ! findTaxonomyInCatalog( rule.taxonomy ) ) {
			// Saved taxonomy that's no longer registered — keep visible so editor sees it.
			$taxSel.append( $( '<option/>' ).val( rule.taxonomy ).text( rule.taxonomy + ' (NOT REGISTERED)' ).prop( 'selected', true ) );
		}

		var $applySel   = makeTermsSelect( rule.taxonomy, rule.apply_terms,         rule.match_by, 'apply' );
		var $inverseSel = makeTermsSelect( rule.taxonomy, rule.apply_terms_inverse, rule.match_by, 'inverse' );

		var $matchBy = $( '<select class="jedb-tax-match-by"/>' );
		[ 'slug', 'name', 'id' ].forEach( function ( v ) {
			$matchBy.append( $( '<option/>' ).val( v ).text( v ).prop( 'selected', v === rule.match_by ) );
		} );

		var $strategy = $( '<select class="jedb-tax-merge-strategy"/>' );
		[ 'append', 'replace' ].forEach( function ( v ) {
			$strategy.append( $( '<option/>' ).val( v ).text( v ).prop( 'selected', v === rule.merge_strategy ) );
		} );

		var $createCb = $( '<input type="checkbox" class="jedb-tax-create-if-missing" />' )
			.prop( 'checked', !! rule.create_if_missing );

		var $rm = $( '<button type="button" class="button button-small button-link-delete" title="Remove">×</button>' )
			.on( 'click', function () {
				$tr.remove();
				updateTaxonomySummary();
				syncJSON();
			} );

		$tr.append( $( '<td/>' ).append( $taxSel ) )
		   .append( $( '<td/>' ).append( $applySel ) )
		   .append( $( '<td/>' ).append( $inverseSel ) )
		   .append( $( '<td/>' ).append( $matchBy ) )
		   .append( $( '<td/>' ).append( $strategy ) )
		   .append( $( '<td/>' ).append( $createCb ) )
		   .append( $( '<td/>' ).append( $rm ) );

		// Re-render apply/inverse selects when taxonomy or match_by changes,
		// preserving as much of the current selection as possible.
		var rebuildTermSelects = function () {
			var newTax     = $taxSel.val();
			var newMatchBy = $matchBy.val();
			var keptApply  = readMultiSelectValues( $tr.find( 'select[data-tax-control="apply"]' ) );
			var keptInv    = readMultiSelectValues( $tr.find( 'select[data-tax-control="inverse"]' ) );

			$tr.find( 'select[data-tax-control="apply"]' ).replaceWith(
				makeTermsSelect( newTax, keptApply, newMatchBy, 'apply' )
			);
			$tr.find( 'select[data-tax-control="inverse"]' ).replaceWith(
				makeTermsSelect( newTax, keptInv, newMatchBy, 'inverse' )
			);
			syncJSON();
		};

		$taxSel.on( 'change',  rebuildTermSelects );
		$matchBy.on( 'change', rebuildTermSelects );
		$applySel.on( 'change', syncJSON );
		$inverseSel.on( 'change', syncJSON );
		$strategy.on( 'change', syncJSON );
		$createCb.on( 'change', syncJSON );

		return $tr;
	}

	function readTaxonomyRulesFromDom() {

		var out = [];

		$taxTbody.children( 'tr' ).each( function () {

			var $tr      = $( this );
			var taxonomy = $tr.find( 'select.jedb-tax-slug' ).val() || '';
			var matchBy  = $tr.find( 'select.jedb-tax-match-by' ).val() || 'slug';
			var strategy = $tr.find( 'select.jedb-tax-merge-strategy' ).val() || 'append';
			var apply    = readMultiSelectValues( $tr.find( 'select[data-tax-control="apply"]' ) );
			var inverse  = readMultiSelectValues( $tr.find( 'select[data-tax-control="inverse"]' ) );
			var create   = $tr.find( 'input.jedb-tax-create-if-missing' ).is( ':checked' );

			out.push( {
				taxonomy:            taxonomy,
				apply_terms:         apply,
				apply_terms_inverse: inverse,
				match_by:            matchBy,
				merge_strategy:      strategy,
				create_if_missing:   create,
				snippet:             null,
				enabled:             true,
				note:                ''
			} );
		} );

		return out;
	}

	function renderTaxonomyRules() {

		// Read current DOM state into in-memory array first so a re-render
		// (e.g. on target change) doesn't lose unsaved edits.
		if ( $taxTbody.children( 'tr' ).length ) {
			taxonomyRules = readTaxonomyRulesFromDom();
		}

		$taxTbody.empty();

		taxonomyRules.forEach( function ( rule ) {
			$taxTbody.append( makeTaxonomyRow( rule ) );
		} );

		updateTaxonomySummary();
		syncJSON();
	}

	function updateTaxonomySummary() {
		var n = $taxTbody.children( 'tr' ).length;
		$taxSummaryPill.removeClass( 'jedb-pill-ok jedb-pill-warn' );
		if ( n === 0 ) {
			$taxSummaryPill.addClass( 'jedb-pill-warn' ).text( 'no rules' ).show();
		} else {
			$taxSummaryPill.addClass( 'jedb-pill-ok' ).text( n + ' rule' + ( n === 1 ? '' : 's' ) ).show();
		}
	}

	function refreshTaxonomySectionVisibility() {

		var targetVal     = $targetSel.val() || '';
		var isPostsTarget = targetVal.indexOf( 'posts::' ) === 0;

		if ( ! isPostsTarget ) {
			$taxSection.hide().attr( 'data-visible', '0' );
			currentPostType = '';
			taxonomyCatalog = [];
			return;
		}

		$taxSection.show().attr( 'data-visible', '1' );

		var newPostType = targetVal.substring( 7 );
		if ( newPostType === currentPostType && taxonomyCatalog.length ) {
			return;
		}

		currentPostType = newPostType;
		fetchTaxonomies( currentPostType, renderTaxonomyRules );
	}

	$( '#jedb_flatten_add_taxonomy_rule' ).on( 'click', function () {
		// Push a fresh row both into in-memory state and DOM so the next
		// renderTaxonomyRules() call (which reads DOM first) doesn't drop it.
		taxonomyRules = readTaxonomyRulesFromDom();
		taxonomyRules.push( $.extend( true, {}, taxonomyDefault ) );
		renderTaxonomyRules();
	} );

	$( '#jedb_flatten_refresh_taxonomies' ).on( 'click', function () {
		fetchTaxonomies( currentPostType, renderTaxonomyRules );
	} );

	/* -----------------------------------------------------------------------
	 * buildConfig + syncJSON + initial render
	 * -------------------------------------------------------------------- */

	function buildConfig() {

		var fromRaw = null;
		try {
			fromRaw = JSON.parse( $rawJson.val() || '{}' );
		} catch ( e ) {
			fromRaw = {};
		}

		var cfg = $.extend( true, {}, fromRaw );

		cfg.mappings   = readMappingsFromDom();
		cfg.taxonomies = readTaxonomyRulesFromDom();

		cfg.condition = $condInput.val() || '';
		cfg.priority  = parseInt( $form.find( '#jedb_flatten_priority' ).val(), 10 );
		if ( isNaN( cfg.priority ) ) { cfg.priority = 100; }

		cfg.link_via = $.extend( {}, cfg.link_via || {}, {
			type:                    $linkRadios.filter( ':checked' ).val() || 'je_relation',
			relation_id:             $relationSel.val() || '',
			side:                    ( cfg.link_via && cfg.link_via.side ) || 'auto',
			fallback_to_single_page: $form.find( 'input[name="link_via_fallback_to_single_page"]' ).is( ':checked' ),
			auto_attach_relation:    $form.find( 'input[name="link_via_auto_attach_relation"]' ).is( ':checked' )
		} );

		cfg.auto_create_target_when_unlinked = $form.find( 'input[name="auto_create_target_when_unlinked"]' ).is( ':checked' );

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

		// Taxonomies — fetch the catalog if the form already has a posts target,
		// then render whatever rules came in via bootstrap.
		if ( currentPostType ) {
			$taxSection.show().attr( 'data-visible', '1' );
			fetchTaxonomies( currentPostType, renderTaxonomyRules );
		} else {
			$taxSection.hide().attr( 'data-visible', '0' );
			updateTaxonomySummary();
		}

		syncJSON();
	}

	$( '#jedb_flatten_add_mapping' ).on( 'click', function () {
		$tbody.append( makeMappingRow( { source_field: '', target_field: '', push_transform: [], pull_transform: [] } ) );
		syncJSON();
	} );

	$tbody.on( 'change', 'select, input, textarea', syncJSON );

	$form.on( 'change', 'input[name="link_via_type"], #jedb_flatten_relation_id, #jedb_flatten_priority, input[name="link_via_fallback_to_single_page"], input[name="link_via_auto_attach_relation"], input[name="auto_create_target_when_unlinked"], input[name="direction"]', syncJSON );
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

	// Phase 3.6: target-target change re-fetches the taxonomy catalog so the
	// dropdowns inside the Taxonomies section reflect the new post type.
	$targetSel.on( 'change', refreshTaxonomySectionVisibility );

	renderInitial();

} )( jQuery );
