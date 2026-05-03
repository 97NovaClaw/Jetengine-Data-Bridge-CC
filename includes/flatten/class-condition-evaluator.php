<?php
/**
 * Condition Evaluator — parses + evaluates the v1 declarative DSL from
 * BUILD-PLAN §3.5.
 *
 * Grammar (v1):
 *   condition  := expr
 *   expr       := and_expr ( "OR" and_expr )*
 *   and_expr   := not_expr ( "AND" not_expr )*
 *   not_expr   := "NOT"? primary
 *   primary    := "(" expr ")" | comparison
 *   comparison := value op value
 *   op         := "==" | "!=" | ">" | "<" | ">=" | "<="
 *               | "contains" | "not_contains"
 *               | "starts_with" | "ends_with"
 *               | "in" | "not_in"
 *   value      := PATH | LITERAL
 *   PATH       := "{" SCOPE "." FIELD_NAME "}"
 *   SCOPE      := "source" | "target" | "cct" | "product" | "variation"
 *   LITERAL    := QUOTED_STRING | NUMBER | BOOLEAN | ARRAY_LITERAL
 *
 * No loops. No function calls. No side effects. Snippet-mode (Phase 5b)
 * is the escape hatch for anything more complex.
 *
 * Failure mode (per Q4 / D-2): on any parse / eval error, return false
 * AND log so the bridge is skipped rather than wrongly applied.
 *
 * @package JEDB
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class JEDB_Condition_Evaluator {

	const DSL_VERSION = 1;

	/** @var JEDB_Condition_Evaluator|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Evaluate a bridge-config condition against the given context.
	 *
	 * Empty / whitespace-only DSL → returns true (no condition = always apply).
	 *
	 * @param string $dsl_string  Condition expression.
	 * @param array  $context     BUILD-PLAN §4.9 $context shape.
	 * @return bool
	 */
	public function evaluate( $dsl_string, array $context = array() ) {

		$dsl_string = is_string( $dsl_string ) ? trim( $dsl_string ) : '';

		if ( '' === $dsl_string ) {
			return true;
		}

		try {
			$tokens = $this->tokenize( $dsl_string );
			$pos    = 0;
			$ast    = $this->parse_expr( $tokens, $pos );

			if ( $pos < count( $tokens ) ) {
				throw new \RuntimeException( 'unexpected trailing tokens at position ' . $pos );
			}

			return (bool) $this->eval_node( $ast, $context );

		} catch ( \Throwable $e ) {
			if ( function_exists( 'jedb_log' ) ) {
				jedb_log( '[Condition_Evaluator] DSL error — treating as false', 'warning', array(
					'dsl'   => $dsl_string,
					'error' => $e->getMessage(),
				) );
			}
			return false;
		}
	}

	/**
	 * Compile-only validation. Used by the admin UI's syntax checker so the
	 * editor learns about bad expressions before saving.
	 *
	 * @return array{ok:bool, error:string|null}
	 */
	public function validate( $dsl_string ) {
		try {
			$tokens = $this->tokenize( (string) $dsl_string );
			$pos    = 0;
			$this->parse_expr( $tokens, $pos );

			if ( $pos < count( $tokens ) ) {
				return array( 'ok' => false, 'error' => sprintf( 'Unexpected token "%s"', $tokens[ $pos ]['value'] ) );
			}

			return array( 'ok' => true, 'error' => null );

		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'error' => $e->getMessage() );
		}
	}

	/* -----------------------------------------------------------------------
	 * Lexer — produces a flat token stream
	 * -------------------------------------------------------------------- */

	private function tokenize( $input ) {

		$tokens = array();
		$len    = strlen( $input );
		$i      = 0;

		while ( $i < $len ) {

			$ch = $input[ $i ];

			if ( ctype_space( $ch ) ) {
				$i++;
				continue;
			}

			if ( '(' === $ch || ')' === $ch ) {
				$tokens[] = array( 'type' => $ch, 'value' => $ch );
				$i++;
				continue;
			}

			if ( '{' === $ch ) {
				$end = strpos( $input, '}', $i );
				if ( false === $end ) {
					throw new \RuntimeException( 'unterminated path: ' . substr( $input, $i ) );
				}
				$path = substr( $input, $i + 1, $end - $i - 1 );
				$tokens[] = array( 'type' => 'PATH', 'value' => trim( $path ) );
				$i = $end + 1;
				continue;
			}

			if ( '"' === $ch || "'" === $ch ) {
				$quote = $ch;
				$j     = $i + 1;
				$buf   = '';
				while ( $j < $len ) {
					if ( '\\' === $input[ $j ] && $j + 1 < $len ) {
						$buf .= $input[ $j + 1 ];
						$j   += 2;
						continue;
					}
					if ( $input[ $j ] === $quote ) {
						break;
					}
					$buf .= $input[ $j ];
					$j++;
				}
				if ( $j >= $len ) {
					throw new \RuntimeException( 'unterminated string literal' );
				}
				$tokens[] = array( 'type' => 'STRING', 'value' => $buf );
				$i = $j + 1;
				continue;
			}

			if ( '[' === $ch ) {
				$end = strpos( $input, ']', $i );
				if ( false === $end ) {
					throw new \RuntimeException( 'unterminated array literal' );
				}
				$inside = substr( $input, $i + 1, $end - $i - 1 );
				$tokens[] = array( 'type' => 'ARRAY', 'value' => $this->parse_array_literal( $inside ) );
				$i = $end + 1;
				continue;
			}

			if ( ctype_digit( $ch ) || ( '-' === $ch && $i + 1 < $len && ctype_digit( $input[ $i + 1 ] ) ) ) {
				$j = $i;
				if ( '-' === $ch ) {
					$j++;
				}
				while ( $j < $len && ( ctype_digit( $input[ $j ] ) || '.' === $input[ $j ] ) ) {
					$j++;
				}
				$num = substr( $input, $i, $j - $i );
				$tokens[] = array( 'type' => 'NUMBER', 'value' => 0 + $num );
				$i = $j;
				continue;
			}

			if ( '=' === $ch && $i + 1 < $len && '=' === $input[ $i + 1 ] ) {
				$tokens[] = array( 'type' => 'OP', 'value' => '==' );
				$i += 2;
				continue;
			}

			if ( '!' === $ch && $i + 1 < $len && '=' === $input[ $i + 1 ] ) {
				$tokens[] = array( 'type' => 'OP', 'value' => '!=' );
				$i += 2;
				continue;
			}

			if ( ( '>' === $ch || '<' === $ch ) && $i + 1 < $len && '=' === $input[ $i + 1 ] ) {
				$tokens[] = array( 'type' => 'OP', 'value' => $ch . '=' );
				$i += 2;
				continue;
			}

			if ( '>' === $ch || '<' === $ch ) {
				$tokens[] = array( 'type' => 'OP', 'value' => $ch );
				$i++;
				continue;
			}

			if ( ctype_alpha( $ch ) || '_' === $ch ) {
				$j = $i;
				while ( $j < $len && ( ctype_alnum( $input[ $j ] ) || '_' === $input[ $j ] ) ) {
					$j++;
				}
				$word = substr( $input, $i, $j - $i );
				$lc   = strtolower( $word );

				if ( in_array( $lc, array( 'and', 'or', 'not' ), true ) ) {
					$tokens[] = array( 'type' => strtoupper( $lc ), 'value' => strtoupper( $lc ) );
				} elseif ( in_array( $lc, array( 'true', 'false' ), true ) ) {
					$tokens[] = array( 'type' => 'BOOL', 'value' => 'true' === $lc );
				} elseif ( in_array( $lc, array( 'null' ), true ) ) {
					$tokens[] = array( 'type' => 'NULL', 'value' => null );
				} elseif ( in_array( $lc, array( 'contains', 'not_contains', 'starts_with', 'ends_with', 'in', 'not_in' ), true ) ) {
					$tokens[] = array( 'type' => 'OP', 'value' => $lc );
				} else {
					throw new \RuntimeException( 'unknown identifier: ' . $word );
				}
				$i = $j;
				continue;
			}

			throw new \RuntimeException( sprintf( 'unexpected character "%s" at position %d', $ch, $i ) );
		}

		return $tokens;
	}

	private function parse_array_literal( $inside ) {

		$out = array();

		if ( '' === trim( $inside ) ) {
			return $out;
		}

		$parts = array();
		$buf   = '';
		$len   = strlen( $inside );
		$in_q  = false;
		$qchar = '';

		for ( $i = 0; $i < $len; $i++ ) {
			$c = $inside[ $i ];
			if ( $in_q ) {
				$buf .= $c;
				if ( $c === $qchar && '\\' !== ( $i > 0 ? $inside[ $i - 1 ] : '' ) ) {
					$in_q  = false;
					$qchar = '';
				}
				continue;
			}
			if ( '"' === $c || "'" === $c ) {
				$in_q  = true;
				$qchar = $c;
				$buf  .= $c;
				continue;
			}
			if ( ',' === $c ) {
				$parts[] = trim( $buf );
				$buf     = '';
				continue;
			}
			$buf .= $c;
		}
		if ( '' !== trim( $buf ) ) {
			$parts[] = trim( $buf );
		}

		foreach ( $parts as $raw ) {
			$first = isset( $raw[0] ) ? $raw[0] : '';
			if ( '"' === $first || "'" === $first ) {
				$out[] = stripslashes( substr( $raw, 1, -1 ) );
			} elseif ( is_numeric( $raw ) ) {
				$out[] = 0 + $raw;
			} elseif ( 'true' === strtolower( $raw ) ) {
				$out[] = true;
			} elseif ( 'false' === strtolower( $raw ) ) {
				$out[] = false;
			} elseif ( 'null' === strtolower( $raw ) ) {
				$out[] = null;
			} else {
				$out[] = $raw;
			}
		}

		return $out;
	}

	/* -----------------------------------------------------------------------
	 * Parser — produces a tiny AST as nested arrays
	 * -------------------------------------------------------------------- */

	private function parse_expr( array &$tokens, &$pos ) {
		$left = $this->parse_and( $tokens, $pos );
		while ( $pos < count( $tokens ) && 'OR' === $tokens[ $pos ]['type'] ) {
			$pos++;
			$right = $this->parse_and( $tokens, $pos );
			$left  = array( 'kind' => 'or', 'l' => $left, 'r' => $right );
		}
		return $left;
	}

	private function parse_and( array &$tokens, &$pos ) {
		$left = $this->parse_not( $tokens, $pos );
		while ( $pos < count( $tokens ) && 'AND' === $tokens[ $pos ]['type'] ) {
			$pos++;
			$right = $this->parse_not( $tokens, $pos );
			$left  = array( 'kind' => 'and', 'l' => $left, 'r' => $right );
		}
		return $left;
	}

	private function parse_not( array &$tokens, &$pos ) {
		if ( $pos < count( $tokens ) && 'NOT' === $tokens[ $pos ]['type'] ) {
			$pos++;
			return array( 'kind' => 'not', 'expr' => $this->parse_primary( $tokens, $pos ) );
		}
		return $this->parse_primary( $tokens, $pos );
	}

	private function parse_primary( array &$tokens, &$pos ) {

		if ( $pos >= count( $tokens ) ) {
			throw new \RuntimeException( 'unexpected end of expression' );
		}

		if ( '(' === $tokens[ $pos ]['type'] ) {
			$pos++;
			$expr = $this->parse_expr( $tokens, $pos );
			if ( $pos >= count( $tokens ) || ')' !== $tokens[ $pos ]['type'] ) {
				throw new \RuntimeException( 'missing ")"' );
			}
			$pos++;
			return $expr;
		}

		return $this->parse_comparison( $tokens, $pos );
	}

	private function parse_comparison( array &$tokens, &$pos ) {

		$left = $this->parse_value( $tokens, $pos );

		if ( $pos >= count( $tokens ) || 'OP' !== $tokens[ $pos ]['type'] ) {
			throw new \RuntimeException( 'expected comparison operator after value' );
		}

		$op = $tokens[ $pos ]['value'];
		$pos++;

		$right = $this->parse_value( $tokens, $pos );

		return array( 'kind' => 'cmp', 'op' => $op, 'l' => $left, 'r' => $right );
	}

	private function parse_value( array &$tokens, &$pos ) {

		if ( $pos >= count( $tokens ) ) {
			throw new \RuntimeException( 'unexpected end of expression — expected value' );
		}

		$t = $tokens[ $pos ];
		$pos++;

		switch ( $t['type'] ) {
			case 'PATH':
				return array( 'kind' => 'path', 'value' => $t['value'] );
			case 'STRING':
				return array( 'kind' => 'literal', 'value' => $t['value'] );
			case 'NUMBER':
				return array( 'kind' => 'literal', 'value' => $t['value'] );
			case 'BOOL':
				return array( 'kind' => 'literal', 'value' => $t['value'] );
			case 'NULL':
				return array( 'kind' => 'literal', 'value' => null );
			case 'ARRAY':
				return array( 'kind' => 'literal', 'value' => $t['value'] );
		}

		throw new \RuntimeException( 'unexpected token type: ' . $t['type'] );
	}

	/* -----------------------------------------------------------------------
	 * Evaluator
	 * -------------------------------------------------------------------- */

	private function eval_node( $node, array $context ) {

		switch ( $node['kind'] ) {

			case 'or':
				return $this->eval_node( $node['l'], $context ) || $this->eval_node( $node['r'], $context );
			case 'and':
				return $this->eval_node( $node['l'], $context ) && $this->eval_node( $node['r'], $context );
			case 'not':
				return ! $this->eval_node( $node['expr'], $context );
			case 'cmp':
				$l = $this->resolve_value( $node['l'], $context );
				$r = $this->resolve_value( $node['r'], $context );
				return $this->compare( $l, $node['op'], $r );
		}

		throw new \RuntimeException( 'unknown node kind: ' . $node['kind'] );
	}

	private function resolve_value( $node, array $context ) {

		if ( 'literal' === $node['kind'] ) {
			return $node['value'];
		}

		if ( 'path' !== $node['kind'] ) {
			throw new \RuntimeException( 'unexpected node kind in resolve_value' );
		}

		$path = $node['value'];

		if ( false === strpos( $path, '.' ) ) {
			throw new \RuntimeException( 'malformed path "' . $path . '" — expected SCOPE.field' );
		}

		list( $scope, $field ) = explode( '.', $path, 2 );
		$scope = strtolower( trim( $scope ) );
		$field = trim( $field );

		switch ( $scope ) {
			case 'source':
			case 'cct':
				$bag = isset( $context['source_data'] ) && is_array( $context['source_data'] ) ? $context['source_data'] : array();
				break;
			case 'target':
			case 'product':
			case 'variation':
				$bag = isset( $context['target_data'] ) && is_array( $context['target_data'] ) ? $context['target_data'] : array();
				break;
			default:
				throw new \RuntimeException( 'unknown scope "' . $scope . '"' );
		}

		return array_key_exists( $field, $bag ) ? $bag[ $field ] : null;
	}

	private function compare( $l, $op, $r ) {

		switch ( $op ) {
			case '==':
				return $this->loose_equals( $l, $r );
			case '!=':
				return ! $this->loose_equals( $l, $r );
			case '>':
				return is_numeric( $l ) && is_numeric( $r ) && (float) $l > (float) $r;
			case '<':
				return is_numeric( $l ) && is_numeric( $r ) && (float) $l < (float) $r;
			case '>=':
				return is_numeric( $l ) && is_numeric( $r ) && (float) $l >= (float) $r;
			case '<=':
				return is_numeric( $l ) && is_numeric( $r ) && (float) $l <= (float) $r;
			case 'contains':
				return $this->contains( $l, $r );
			case 'not_contains':
				return ! $this->contains( $l, $r );
			case 'starts_with':
				return is_string( $l ) && is_string( $r ) && 0 === strpos( $l, $r );
			case 'ends_with':
				return is_string( $l ) && is_string( $r ) && substr( $l, -strlen( $r ) ) === $r;
			case 'in':
				return is_array( $r ) && $this->array_contains_loose( $r, $l );
			case 'not_in':
				return is_array( $r ) && ! $this->array_contains_loose( $r, $l );
		}

		return false;
	}

	private function loose_equals( $a, $b ) {

		if ( is_bool( $a ) || is_bool( $b ) ) {
			return (bool) $a === (bool) $b;
		}

		if ( is_numeric( $a ) && is_numeric( $b ) ) {
			return (float) $a === (float) $b;
		}

		return (string) $a === (string) $b;
	}

	private function contains( $haystack, $needle ) {

		if ( is_array( $haystack ) ) {
			return $this->array_contains_loose( $haystack, $needle );
		}

		if ( is_string( $haystack ) ) {
			$needle = is_scalar( $needle ) ? (string) $needle : '';
			if ( '' === $needle ) {
				return false;
			}
			return false !== strpos( $haystack, $needle );
		}

		return false;
	}

	private function array_contains_loose( array $haystack, $needle ) {
		foreach ( $haystack as $item ) {
			if ( $this->loose_equals( $item, $needle ) ) {
				return true;
			}
		}
		return false;
	}
}
