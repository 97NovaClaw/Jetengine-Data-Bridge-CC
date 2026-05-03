<?php
/**
 * Transformer Registry — holds every available JEDB_Transformer instance.
 *
 * Built-ins are registered automatically the first time someone asks for
 * the registry. Snippet-backed transformers (Phase 5b) register
 * themselves via the `jedb/transformer/register` action that fires at the
 * end of bootstrap_defaults().
 *
 * Third-party code can register additional transformers:
 *
 *   add_action( 'jedb/transformer/register', function ( $registry ) {
 *       $registry->register( new My_Custom_Transformer() );
 *   } );
 *
 * @package JEDB
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class JEDB_Transformer_Registry {

	/** @var JEDB_Transformer_Registry|null */
	private static $instance = null;

	/** @var array<string,JEDB_Transformer> */
	private $transformers = array();

	/** @var bool */
	private $bootstrapped = false;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function register( JEDB_Transformer $t ) {
		$this->transformers[ $t->get_name() ] = $t;
		return $this;
	}

	/**
	 * @return JEDB_Transformer|null
	 */
	public function get( $name ) {
		$this->bootstrap_defaults();
		return isset( $this->transformers[ $name ] ) ? $this->transformers[ $name ] : null;
	}

	public function has( $name ) {
		$this->bootstrap_defaults();
		return isset( $this->transformers[ $name ] );
	}

	/**
	 * @return array<string,JEDB_Transformer>
	 */
	public function all() {
		$this->bootstrap_defaults();
		return $this->transformers;
	}

	/**
	 * Apply a list of transformer steps to a value.
	 *
	 * @param mixed  $value      Initial value.
	 * @param array  $chain      Each element: ['name' => string, 'args' => array]
	 * @param string $direction  'push' | 'pull'
	 * @param array  $context    Snipped through to each transformer.
	 * @return mixed             Final value after the chain runs.
	 */
	public function run_chain( $value, array $chain, $direction, array $context = array() ) {

		$this->bootstrap_defaults();

		foreach ( $chain as $step ) {

			if ( ! is_array( $step ) ) {
				continue;
			}

			$name = isset( $step['name'] ) ? (string) $step['name'] : '';
			if ( '' === $name || ! isset( $this->transformers[ $name ] ) ) {
				if ( function_exists( 'jedb_log' ) ) {
					jedb_log( '[Transformer_Registry] unknown transformer in chain — skipping', 'warning', array(
						'name'      => $name,
						'direction' => $direction,
					) );
				}
				continue;
			}

			$args = isset( $step['args'] ) && is_array( $step['args'] ) ? $step['args'] : array();
			$t    = $this->transformers[ $name ];

			try {
				if ( 'pull' === $direction ) {
					$value = $t->apply_pull( $value, $args, $context );
				} else {
					$value = $t->apply_push( $value, $args, $context );
				}
			} catch ( \Throwable $e ) {
				if ( function_exists( 'jedb_log' ) ) {
					jedb_log( '[Transformer_Registry] transformer threw — passing value through unchanged', 'error', array(
						'name'      => $name,
						'direction' => $direction,
						'error'     => $e->getMessage(),
					) );
				}
			}
		}

		return $value;
	}

	private function bootstrap_defaults() {

		if ( $this->bootstrapped ) {
			return;
		}

		$this->bootstrapped = true;

		$dir = JEDB_PLUGIN_DIR . 'includes/flatten/transformers/';

		require_once $dir . 'class-transformer-passthrough.php';
		require_once $dir . 'class-transformer-yes-no-bool.php';
		require_once $dir . 'class-transformer-regex-replace.php';
		require_once $dir . 'class-transformer-format-number.php';
		require_once $dir . 'class-transformer-lookup-table.php';
		require_once $dir . 'class-transformer-name-builder.php';
		require_once $dir . 'class-transformer-truncate-words.php';
		require_once $dir . 'class-transformer-strip-html.php';
		require_once $dir . 'class-transformer-year-expander.php';

		$this->register( new JEDB_Transformer_Passthrough() );
		$this->register( new JEDB_Transformer_Yes_No_Bool() );
		$this->register( new JEDB_Transformer_Regex_Replace() );
		$this->register( new JEDB_Transformer_Format_Number() );
		$this->register( new JEDB_Transformer_Lookup_Table() );
		$this->register( new JEDB_Transformer_Name_Builder() );
		$this->register( new JEDB_Transformer_Truncate_Words() );
		$this->register( new JEDB_Transformer_Strip_HTML() );
		$this->register( new JEDB_Transformer_Year_Expander() );

		do_action( 'jedb/transformer/register', $this );
	}
}
