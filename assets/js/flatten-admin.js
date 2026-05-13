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

	// alpha.12 (Phase 4 Day 4): live mutable state for required_overrides
	// + matching field presets. The Apply preset handler writes to
	// `liveRequiredOverrides`; buildConfig reads from it instead of
	// from the original bootstrap so changes flow into the submitted
	// config_json on save. `matchingPresets` is read-only — refreshes
	// require a page reload (would need a new server-side load to
	// recompute target-matching presets, since target_target changes
	// also force a reload via the existing source/target dropdowns).
	var liveRequiredOverrides = bootstrap.required_overrides
		? { add: ( bootstrap.required_overrides.add || [] ).slice(), remove: ( bootstrap.required_overrides.remove || [] ).slice() }
		: { add: [], remove: [] };
	var matchingPresets = bootstrap.matching_presets || [];

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

		// Phase 4 alpha.3 (D-26 / D-27): per-mapping meta box surfacing
		// flags + freeform group label. The Day 2 Bridge meta box reads
		// these to decide which fields to render on the Woo product edit
		// screen.
		var surfaceTarget = !!mapping.surface_on_target;
		var surfaceSource = !!mapping.surface_on_source;
		var groupVal      = String( mapping.group || '' );

		var $surfaceTd = $( '<td class="jedb-mapping-meta-cell"/>' );
		var $tgtChk    = $( '<input type="checkbox" class="jedb-surface-target" />' ).prop( 'checked', surfaceTarget );
		var $srcChk    = $( '<input type="checkbox" class="jedb-surface-source" />' ).prop( 'checked', surfaceSource );
		var $groupIn   = $( '<input type="text" class="jedb-mapping-group" placeholder="Group label" />' ).val( groupVal );

		$surfaceTd
			.append( $( '<label/>' ).append( $tgtChk ).append( ' Target' ) )
			.append( $( '<label/>' ).append( $srcChk ).append( ' Source' ) )
			.append( $groupIn );

		var $rm = $( '<button type="button" class="button button-small button-link-delete">Remove</button>' )
			.on( 'click', function () {
				$tr.remove();
				syncJSON();
			} );

		$tr.append( $sourceTd ).append( $targetTd ).append( $pushTd ).append( $pullTd ).append( $surfaceTd ).append( $( '<td/>' ).append( $rm ) );

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

			// Phase 4 alpha.3 — meta cell at column 5.
			var $metaTd       = $tr.find( 'td:nth-child(5)' );
			var surfaceTarget = $metaTd.find( 'input.jedb-surface-target' ).is( ':checked' );
			var surfaceSource = $metaTd.find( 'input.jedb-surface-source' ).is( ':checked' );
			var groupVal      = $metaTd.find( 'input.jedb-mapping-group' ).val() || '';

			out.push( {
				source_field:      src,
				target_field:      tgt,
				push_transform:    pushChain,
				pull_transform:    pullChain,
				enabled:           true,
				surface_on_source: surfaceSource,
				surface_on_target: surfaceTarget,
				group:             String( groupVal )
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

	// Phase 4b alpha.14: WC variations panel section visibility hook
	// (Woo-product-target-only per D6). Toggled by refreshVariationsSectionVisibility().
	var $wcVariationsSection = $( '#jedb_flatten_wc_variations_section' );

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
		} else {
			$taxSection.show().attr( 'data-visible', '1' );

			var newPostType = targetVal.substring( 7 );
			if ( newPostType !== currentPostType || ! taxonomyCatalog.length ) {
				currentPostType = newPostType;
				fetchTaxonomies( currentPostType, renderTaxonomyRules );
			}
		}

		// Phase 4b alpha.14: WC Variations panel section is Woo-specific
		// (D6). Hidden when target isn't `posts::product`.
		if ( $wcVariationsSection && $wcVariationsSection.length ) {
			if ( 'posts::product' === targetVal ) {
				$wcVariationsSection.show().attr( 'data-visible', '1' );
			} else {
				$wcVariationsSection.hide().attr( 'data-visible', '0' );
			}
		}
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

		// Phase 4b alpha.14: cct_screen.wc_variations panel config.
		// Read straight from the form fields each sync.
		cfg.cct_screen = $.extend( true, {}, cfg.cct_screen || {}, {
			wc_variations: {
				enabled:                  $form.find( 'input[name="cct_screen_wc_variations_enabled"]' ).is( ':checked' ),
				title:                    String( $form.find( 'input[name="cct_screen_wc_variations_title"]' ).val() || '' ),
				auto_force_variable_type: $form.find( 'input[name="cct_screen_wc_variations_auto_force_variable_type"]' ).is( ':checked' ),
				show_full_page:           $form.find( 'input[name="cct_screen_wc_variations_show_full_page"]' ).is( ':checked' )
			}
		} );

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

		// Phase 4 alpha.3 (D-27 / §4.6): top-level redirect shim opt-in.
		cfg.cct_single_redirect = $form.find( 'input[name="cct_single_redirect"]' ).is( ':checked' );

		// Phase 4 alpha.3 (D-27 / §4.5): Meta box settings block. Form
		// fields override anything that may have been pasted into the
		// raw JSON for these specific keys.
		var groupsRaw = String( $form.find( 'input[name="meta_box_groups"]' ).val() || '' );
		var groupsArr = groupsRaw.split( ',' )
			.map( function ( s ) { return $.trim( s ); } )
			.filter( function ( s ) { return s.length > 0; } );

		cfg.meta_box = $.extend( {}, cfg.meta_box || {}, {
			enabled:       $form.find( 'input[name="meta_box_enabled"]' ).is( ':checked' ),
			title:         String( $form.find( 'input[name="meta_box_title"]' ).val() || '' ),
			position:      String( $form.find( 'input[name="meta_box_position"]:checked' ).val() || 'normal' ),
			groups:        groupsArr,
			show_advanced: $form.find( 'input[name="meta_box_show_advanced"]' ).is( ':checked' )
		} );

		if ( ! cfg.trigger ) {
			cfg.trigger = { type: 'cct_save', args: {} };
		}

		// alpha.12: required_overrides is mutated by the Apply preset
		// handler. We mirror that mutation into the config we're about
		// to ship by reading from `liveRequiredOverrides` (kept in sync
		// by the handler below) rather than relying on whatever was in
		// the initial config_json. Falls back to bootstrap's initial.
		cfg.required_overrides = $.extend( true, {}, liveRequiredOverrides );

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

	$form.on( 'change', 'input[name="link_via_type"], #jedb_flatten_relation_id, #jedb_flatten_priority, input[name="link_via_fallback_to_single_page"], input[name="link_via_auto_attach_relation"], input[name="auto_create_target_when_unlinked"], input[name="cct_single_redirect"], input[name="meta_box_enabled"], input[name="meta_box_position"], input[name="meta_box_show_advanced"], input[name="direction"], input[name="cct_screen_wc_variations_enabled"], input[name="cct_screen_wc_variations_auto_force_variable_type"], input[name="cct_screen_wc_variations_show_full_page"]', syncJSON );
	$form.on( 'input',  '#jedb_flatten_condition, #jedb_flatten_meta_box_title, #jedb_flatten_meta_box_groups, #jedb_flatten_wc_variations_title', syncJSON );
	$form.on( 'input',  '#jedb_flatten_config_raw', function () { $hiddenJson.val( $rawJson.val() ); } );

	// alpha.10: hide the "Reverse-direction options" row whenever
	// direction = push, since the auto-create flag is only meaningful
	// for pull / bidirectional bridges. Persisted value still saves
	// correctly via the hidden config_json on submit; we just don't
	// show the control when it would be a no-op.
	function toggleReverseRow() {
		var dir = $form.find( 'input[name="direction"]:checked' ).val() || 'push';
		var $row = $form.find( 'tr.jedb-reverse-direction-row' );
		if ( dir === 'push' ) {
			$row.hide();
		} else {
			$row.show();
		}
	}
	$form.on( 'change', 'input[name="direction"]', toggleReverseRow );
	toggleReverseRow();

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

	/* =================================================================
	 * alpha.12 (Phase 4 Day 4) — Mandatory coverage actions
	 *
	 * Apply preset: snapshots the selected preset's mandatory field
	 *   names into liveRequiredOverrides.add. The next form save
	 *   persists the change via buildConfig() → config_json.
	 *
	 * Scaffold missing mappings: for every effective required field
	 *   not yet present in the mappings table, appends a passthrough
	 *   mapping row (target_field set, source_field empty, push/pull
	 *   chain = passthrough). Editor fills in source side.
	 *
	 * Both are pure client-side mutations — no AJAX. The visible
	 *   coverage badges are updated in-place after each action so the
	 *   editor sees immediate feedback without a page reload.
	 * ============================================================== */

	var $coveragePanel = $( '#jedb_flatten_required_panel' );
	var $coverageStatus = $( '#jedb_flatten_coverage_status' );
	var $presetSelect = $( '#jedb_flatten_preset_select' );

	function presetBySlug( slug ) {
		for ( var i = 0; i < matchingPresets.length; i++ ) {
			if ( matchingPresets[ i ].slug === slug ) {
				return matchingPresets[ i ];
			}
		}
		return null;
	}

	function effectiveRequiredFields() {
		// Mirrors JEDB_Field_Presets_Manager::compute_effective_required_fields
		// on the JS side: adapter required ∪ overrides.add ∖ overrides.remove.
		// Returns [ {name, origin} ].
		var seen = {};
		var out  = [];
		( targetRequired || [] ).forEach( function ( n ) {
			n = String( n );
			if ( ! n || seen[ n ] ) { return; }
			seen[ n ] = true;
			out.push( { name: n, origin: 'adapter' } );
		} );
		( liveRequiredOverrides.add || [] ).forEach( function ( n ) {
			n = String( n );
			if ( ! n || seen[ n ] ) { return; }
			seen[ n ] = true;
			out.push( { name: n, origin: 'override' } );
		} );
		var removeLookup = {};
		( liveRequiredOverrides.remove || [] ).forEach( function ( n ) {
			removeLookup[ String( n ) ] = true;
		} );
		return out.filter( function ( row ) {
			return ! removeLookup[ row.name ];
		} );
	}

	function mappedTargetFields() {
		var seen = {};
		readMappingsFromDom().forEach( function ( m ) {
			if ( m.target_field ) {
				seen[ String( m.target_field ) ] = true;
			}
		} );
		return seen;
	}

	function renderCoverage() {
		if ( ! $coveragePanel.length ) { return; }

		var required = effectiveRequiredFields();
		var mapped   = mappedTargetFields();

		var covered = 0;
		var missing = 0;
		required.forEach( function ( row ) {
			if ( mapped[ row.name ] ) { covered++; } else { missing++; }
		} );

		// Remove the "no required fields" placeholder if present —
		// we're about to render a real list.
		$coveragePanel.find( '.jedb-coverage-empty-placeholder' ).remove();

		var $list = $coveragePanel.find( '.jedb-coverage-list' );
		if ( ! $list.length ) {
			// Coverage list didn't exist on initial render (no required
			// fields at all). Insert a list above the actions block.
			$list = $( '<ul class="jedb-required-list jedb-coverage-list"/>' );
			$coveragePanel.find( '.jedb-coverage-actions' ).before( $list );
		}
		$list.empty();
		required.forEach( function ( row ) {
			var isCovered = !! mapped[ row.name ];
			var glyph     = isCovered ? '\u2713' : '\u26a0';
			var originLbl = row.origin === 'adapter' ? 'required by adapter' : 'required by override / preset';
			$list.append(
				$( '<li class="jedb-coverage-row"/>' )
					.addClass( isCovered ? 'jedb-coverage-ok' : 'jedb-coverage-missing' )
					.append( $( '<span class="jedb-coverage-badge" aria-hidden="true"/>' ).text( glyph ) )
					.append( $( '<code/>' ).text( row.name ) )
					.append( $( '<small class="jedb-coverage-origin"/>' ).text( originLbl ) )
			);
		} );

		var $summary = $coveragePanel.find( '.jedb-coverage-summary' );
		if ( ! $summary.length ) {
			$summary = $( '<p class="jedb-coverage-summary"/>' );
			$list.before( $summary );
		}
		$summary.empty();
		if ( required.length > 0 ) {
			$summary.append( $( '<strong/>' ).text( 'Coverage: ' + covered + ' of ' + required.length + ' required fields mapped.' ) );
			if ( missing > 0 ) {
				$summary.append( ' ' ).append( $( '<span class="jedb-coverage-missing-pill"/>' ).text( missing + ' missing' ) );
			}
		}
	}

	$presetSelect.on( 'change', function () {
		// Lightweight UX cue — disable the Apply button when nothing's
		// selected. Doesn't gate the click handler.
		var $applyBtn = $( '#jedb_flatten_apply_preset' );
		$applyBtn.prop( 'disabled', ! $presetSelect.val() );
	} ).trigger( 'change' );

	$( document ).on( 'click', '#jedb_flatten_apply_preset', function ( e ) {
		e.preventDefault();
		var slug = $presetSelect.val();
		if ( ! slug ) {
			$coverageStatus.text( 'Pick a preset first.' );
			return;
		}
		var preset = presetBySlug( slug );
		if ( ! preset ) {
			$coverageStatus.text( 'Selected preset not found in bootstrap.' );
			return;
		}

		var added = 0;
		( preset.fields || [] ).forEach( function ( f ) {
			if ( ! f || ! f.name || ! f.mandatory ) { return; }
			if ( liveRequiredOverrides.add.indexOf( f.name ) === -1 ) {
				liveRequiredOverrides.add.push( f.name );
				added++;
			}
		} );

		syncJSON();
		renderCoverage();

		$coverageStatus.text(
			added > 0
				? 'Applied preset "' + preset.label + '" — ' + added + ' field(s) added to required_overrides. Save the bridge to persist.'
				: 'Preset "' + preset.label + '" applied — no new fields added (all already in required_overrides).'
		);
	} );

	$( document ).on( 'click', '#jedb_flatten_scaffold_missing', function ( e ) {
		e.preventDefault();

		var required = effectiveRequiredFields();
		var mapped   = mappedTargetFields();
		var missing  = required.filter( function ( row ) { return ! mapped[ row.name ]; } );

		if ( missing.length === 0 ) {
			$coverageStatus.text( 'Nothing to scaffold — all required fields already have mappings.' );
			return;
		}

		// Append one passthrough mapping per missing field.
		missing.forEach( function ( row ) {
			$tbody.append( makeMappingRow( {
				source_field:   '',
				target_field:   row.name,
				push_transform: [ { name: 'passthrough', args: { comment: '' } } ],
				pull_transform: [ { name: 'passthrough', args: { comment: '' } } ],
				enabled:        true
			} ) );
		} );

		syncJSON();
		renderCoverage();

		$coverageStatus.text(
			'Scaffolded ' + missing.length + ' passthrough mapping(s). Fill in the source side of each and save the bridge.'
		);
	} );

	renderInitial();

} )( jQuery );
