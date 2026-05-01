<?php
/**
 * Relation Transaction Processor — handles the trojan-horse POST data on
 * CCT save and writes JE relation rows.
 *
 * Hooks both `created-item/{slug}` and `updated-item/{slug}` per L-014
 * verified signatures (the two hooks have DIFFERENT argument shapes).
 * Reads `$_POST['jedb_relations']` (JSON), validates the nonce, and
 * delegates to JEDB_Relation_Attacher for the actual writes.
 *
 * The processor enforces cardinality semantics for 1:1 / 1:M relations
 * by clearing the appropriate side before insert; M:M relations append
 * (per L-014).
 *
 * @package JEDB
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class JEDB_Relation_Transaction_Processor {

	/** @var JEDB_Relation_Transaction_Processor|null */
	private static $instance = null;

	/** @var JEDB_Relation_Attacher */
	private $attacher;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->hooks();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->attacher = new JEDB_Relation_Attacher();
	}

	private function hooks() {
		add_action( 'init', array( $this, 'register_cct_save_hooks' ), 30 );
	}

	/**
	 * Register the JE CCT save hooks for every CCT that has an ENABLED
	 * relation config. We use closures with `use ($cct_slug)` so each hook
	 * carries its own CCT context (RI's exact pattern; see L-014 update
	 * line 40-66 of class-transaction-processor.php in RI).
	 */
	public function register_cct_save_hooks() {

		$enabled = JEDB_Relation_Config_Manager::instance()->get_enabled();
		if ( empty( $enabled ) ) {
			return;
		}

		foreach ( $enabled as $config ) {

			$source_target = isset( $config['source_target'] ) ? (string) $config['source_target'] : '';
			if ( '' === $source_target || 0 !== strpos( $source_target, 'cct::' ) ) {
				continue;
			}

			$cct_slug = substr( $source_target, 5 );
			if ( '' === $cct_slug ) {
				continue;
			}

			add_action(
				'jet-engine/custom-content-types/created-item/' . $cct_slug,
				function ( $item, $item_id, $handler ) use ( $cct_slug ) {
					$this->process_save( $cct_slug, $item, absint( $item_id ), 'created' );
				},
				10,
				3
			);

			add_action(
				'jet-engine/custom-content-types/updated-item/' . $cct_slug,
				function ( $item, $prev_item, $handler ) use ( $cct_slug ) {
					$item_id = is_array( $item ) && isset( $item['_ID'] ) ? absint( $item['_ID'] ) : 0;
					$this->process_save( $cct_slug, $item, $item_id, 'updated' );
				},
				10,
				3
			);

			if ( function_exists( 'jedb_log' ) ) {
				jedb_log( 'Transaction processor: registered hooks for CCT', 'debug', array( 'cct_slug' => $cct_slug ) );
			}
		}
	}

	/**
	 * Common save handler called by both created-item and updated-item.
	 * Wrapped in try/catch so a fatal in our code can never interrupt the
	 * CCT save itself.
	 */
	private function process_save( $cct_slug, $item, $item_id, $context ) {

		try {

			if ( ! $item_id ) {
				$this->log( 'process_save: missing item_id', 'warning', array( 'cct_slug' => $cct_slug, 'context' => $context ) );
				return;
			}

			if ( empty( $_POST['jedb_relations'] ) ) {
				return;
			}

			if ( empty( $_POST['jedb_relations_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['jedb_relations_nonce'] ) ), 'jedb_relations' ) ) {
				$this->log( 'process_save: nonce verification failed', 'error', array( 'cct_slug' => $cct_slug ) );
				return;
			}

			$payload = json_decode( stripslashes( (string) $_POST['jedb_relations'] ), true );
			if ( ! is_array( $payload ) ) {
				$this->log( 'process_save: payload is not a JSON object', 'warning', array( 'cct_slug' => $cct_slug ) );
				return;
			}

			$this->log( 'process_save: parsed payload', 'debug', array(
				'cct_slug'    => $cct_slug,
				'item_id'     => $item_id,
				'context'     => $context,
				'payload'     => $payload,
			) );

			foreach ( $payload as $relation_id => $related_ids ) {
				$this->save_relation_set( $cct_slug, $item_id, (string) $relation_id, (array) $related_ids );
			}

		} catch ( \Throwable $t ) {
			$this->log( 'process_save: top-level exception', 'error', array(
				'cct_slug' => $cct_slug,
				'item_id'  => $item_id,
				'error'    => $t->getMessage(),
				'file'     => $t->getFile(),
				'line'     => $t->getLine(),
			) );
		}
	}

	/**
	 * Apply one relation's selected related items to the just-saved CCT row.
	 * Clears the appropriate side first for 1:1 / 1:M; appends for M:M.
	 */
	private function save_relation_set( $cct_slug, $item_id, $relation_id, array $related_ids ) {

		$relation = $this->attacher->get_relation_object( $relation_id );
		if ( ! $relation ) {
			$this->log( 'save_relation_set: relation not found in JE', 'error', array( 'relation_id' => $relation_id ) );
			return;
		}

		$args = method_exists( $relation, 'get_args' ) ? $relation->get_args() : array();
		$type = isset( $args['type'] ) ? (string) $args['type'] : 'one_to_many';
		$side = $this->attacher->determine_side( $relation_id, $cct_slug );

		if ( 'none' === $side ) {
			$this->log( 'save_relation_set: CCT not on either side of relation', 'warning', array(
				'cct_slug'    => $cct_slug,
				'relation_id' => $relation_id,
			) );
			return;
		}

		if ( 'one_to_one' === $type || 'one_to_many' === $type ) {
			$this->attacher->clear_existing_for_side( $relation_id, $item_id, $side );
		}

		$related_ids = array_values( array_filter( array_map( 'absint', $related_ids ) ) );

		foreach ( $related_ids as $related_id ) {

			$parent_id = ( 'parent' === $side ) ? $item_id    : $related_id;
			$child_id  = ( 'parent' === $side ) ? $related_id : $item_id;

			$result = $this->attacher->attach( $relation_id, $parent_id, $child_id );

			if ( false === $result ) {
				$this->log( 'save_relation_set: attach failed', 'error', array(
					'relation_id' => $relation_id,
					'parent_id'   => $parent_id,
					'child_id'    => $child_id,
				) );
			}
		}
	}

	private function log( $message, $level = 'info', array $context = array() ) {
		if ( function_exists( 'jedb_log' ) ) {
			jedb_log( '[TxProcessor] ' . $message, $level, $context );
		}
	}
}
