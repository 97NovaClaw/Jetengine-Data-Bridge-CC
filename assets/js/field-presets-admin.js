/**
 * Field Presets admin tab — Phase 4 Day 4 frontend behavior.
 *
 * Dynamic add/remove of field rows in the editor table. The form itself
 * is a regular POST → admin-post.php → JEDB_Tab_Field_Presets::handle_save();
 * this script only manages the per-field row DOM.
 *
 * @package JEDB
 */

( function ( $ ) {
	'use strict';

	$( function () {

		var $tbody = $( '#jedb_field_presets_fields tbody' );
		if ( ! $tbody.length ) {
			return;
		}

		var $addBtn = $( '#jedb_field_presets_add_field' );
		var counter = $tbody.children( 'tr' ).length;

		function makeRow() {
			counter++;
			var idx = 'new_' + counter;
			var $tr = $( '<tr class="jedb-preset-field-row"/>' );
			$tr.append( '<td><input type="text" name="field_name[]" class="regular-text" placeholder="e.g. regular_price" /></td>' );
			$tr.append( '<td><input type="text" name="field_label[]" class="regular-text" /></td>' );
			$tr.append( '<td><input type="checkbox" name="field_mandatory[' + idx + ']" value="1" /></td>' );
			$tr.append( '<td><input type="text" name="field_group[]" class="regular-text" /></td>' );
			$tr.append( '<td><input type="text" name="field_hint[]" class="regular-text" /></td>' );
			$tr.append( '<td><button type="button" class="button button-small button-link-delete jedb-preset-field-remove">Remove</button></td>' );
			return $tr;
		}

		$addBtn.on( 'click', function () {
			$tbody.append( makeRow() );
		} );

		$tbody.on( 'click', '.jedb-preset-field-remove', function () {
			$( this ).closest( 'tr' ).remove();
		} );

		// If we're editing an existing preset with zero fields, seed one
		// empty row so the editor has somewhere to type immediately.
		if ( $tbody.children( 'tr' ).length === 0 ) {
			$tbody.append( makeRow() );
		}
	} );

} )( jQuery );
