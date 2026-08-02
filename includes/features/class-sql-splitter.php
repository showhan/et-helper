<?php
defined( 'ABSPATH' ) || exit;

/**
 * Feature: SQL Splitter
 * Splits a multi-statement .sql dump into individual statements, respecting
 * quoted strings, backtick identifiers, and comments — so semicolons that
 * appear inside data (or comments) don't cause a bad split.
 */
class ETH_SQL_Splitter {

    /** @return string[] Individual, trimmed, non-empty SQL statements. */
    public static function split( string $sql ): array {
        $statements = [];
        $buffer     = '';
        $len        = strlen( $sql );
        $quote      = null; // ', ", or `
        $i          = 0;

        while ( $i < $len ) {
            $ch  = $sql[$i];
            $two = $ch . ( $i + 1 < $len ? $sql[$i + 1] : '' );

            if ( $quote !== null ) {
                $buffer .= $ch;
                if ( $ch === '\\' && $quote !== '`' ) {
                    // Escaped char inside a string — consume it verbatim.
                    if ( $i + 1 < $len ) { $buffer .= $sql[$i + 1]; $i += 2; continue; }
                } elseif ( $ch === $quote ) {
                    $quote = null;
                }
                $i++;
                continue;
            }

            // Line comment: -- or #
            if ( $two === '--' || $ch === '#' ) {
                while ( $i < $len && $sql[$i] !== "\n" ) $i++;
                continue;
            }
            // Block comment: /* ... */
            if ( $two === '/*' ) {
                $end = strpos( $sql, '*/', $i + 2 );
                $i   = ( $end === false ) ? $len : $end + 2;
                continue;
            }
            // Enter quoted string / identifier
            if ( $ch === "'" || $ch === '"' || $ch === '`' ) {
                $quote   = $ch;
                $buffer .= $ch;
                $i++;
                continue;
            }
            // Statement terminator
            if ( $ch === ';' ) {
                $trimmed = trim( $buffer );
                if ( $trimmed !== '' ) $statements[] = $trimmed;
                $buffer = '';
                $i++;
                continue;
            }

            $buffer .= $ch;
            $i++;
        }

        $trimmed = trim( $buffer );
        if ( $trimmed !== '' ) $statements[] = $trimmed;

        return $statements;
    }
}
