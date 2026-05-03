<?php
/**
 * Transformer interface — every value transformer (built-in or
 * snippet-backed) implements this contract.
 *
 * Each transformer defines TWO directions explicitly per D-11 / L-010:
 *   - apply_push() runs in the source → target direction.
 *   - apply_pull() runs in the target → source direction.
 *
 * For transformers that have no meaningful pull (e.g. "strip HTML to plain"
 * cannot recover the original HTML), apply_pull() should return the value
 * unchanged. That is by design: the bridge config is responsible for
 * picking the right transformer per direction; the transformer itself
 * never silently drops data.
 *
 * Snippet-backed transformers (Phase 5b) are direction-agnostic — the
 * bridge config picks which snippet runs in which chain; the same
 * snippet appears in both pickers.
 *
 * @package JEDB
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

interface JEDB_Transformer {

	/**
	 * Stable identifier — referenced from flatten config rows.
	 */
	public function get_name();

	/**
	 * Human-readable label for the admin UI dropdown.
	 */
	public function get_label();

	/**
	 * One-line description for the admin UI tooltip.
	 */
	public function get_description();

	/**
	 * Schema describing what arguments this transformer accepts.
	 *
	 * @return array<int,array{name:string,label:string,type:string,default:mixed,help:string}>
	 */
	public function get_args_schema();

	/**
	 * Source → target.
	 *
	 * @param mixed $value
	 * @param array $args     Args from the bridge config row.
	 * @param array $context  Same shape as condition $context (BUILD-PLAN §4.9).
	 * @return mixed
	 */
	public function apply_push( $value, array $args = array(), array $context = array() );

	/**
	 * Target → source.
	 *
	 * @param mixed $value
	 * @param array $args
	 * @param array $context
	 * @return mixed
	 */
	public function apply_pull( $value, array $args = array(), array $context = array() );
}
