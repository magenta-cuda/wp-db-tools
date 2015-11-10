<?php
function ddt_wp_db_diff_get_next_mysql_token( $buffer, &$position ) {
    $length = strlen( $buffer );
    $first_start = NULL;
    $first_quote = NULL;
    while ( $position < $length && ctype_space( substr( $buffer, $position, 1 ) ) ) {
        ++$position;
    }
    if ( $position === $length ) {
        return FALSE;
    }
at_start_of_token:
    $char = substr( $buffer, $position, 1 );
    if ( ctype_digit( $char ) ) {
        $quote = '';
        $start = $position++;
        while ( $position < $length && ctype_digit( substr( $buffer, $position, 1 ) ) ) {
            ++$position;
        }
        $end = $position;
    } else if ( $char === '\'' || $char === '"' ) {
        $quote = $char;
        $start = ++$position;
        while ( TRUE ) {
            if ( ( $i = strpos( $buffer, $quote, $position ) ) === FALSE ) {
                $position = $length;
                return FALSE;
            }
            if ( $i + 1 < $length && substr_compare( $buffer, $quote, $i + 1, 1 ) === 0 ) {
                $position = $i + 2;
            } else if ( substr_compare( $buffer, '\\', $i - 1, 1 ) === 0 ) {
                $position = $i + 1;
            } else {
                $end = $i;
                $position = $end + 1;
                break;
            }
        }
    }
    while ( $position < $length && ctype_space( substr( $buffer, $position, 1 ) ) ) {
        ++$position;
    }
    if ( $position < $length && ( substr_compare( $buffer, '\'', $position, 1 ) === 0 || substr_compare( $buffer, '"', $position, 1 ) === 0 ) ) {
        # this is a concatenation
        if ( !$first_start ) {
            $first_start = $start;
            $first_quote = $quote;
        }
        goto at_start_of_token;   # the first goto I have used in 3 years of PHP programming
    }
    if ( $position < $length && substr_compare( $buffer, ',', $position, 1 ) === 0 ) {
        ++$position;
    }
    if ( !$first_start ) {
        $first_start = $start;
        $first_quote = $quote;
    }
    return $first_quote . substr( $buffer, $first_start, $end - $first_start ) . $quote;
}

$buffer = <<<EOD
'12345', "abcde", 12345, 'ABC\'DE', "abc\"de", 'AB''CDE', "a""bcde", 'AB' 'CDE', "ABC" "DE"   , 'A'  "BCDE", 8888
EOD;

$position = 0;
$value = FALSE;

echo '$position=' . $position . ',  $value=^' . $value . "$\n";
while ( $value = ddt_wp_db_diff_get_next_mysql_token( $buffer, $position ) ) {
    echo '$position=' . $position . ',  $value=^' . $value . "$\n";
}
echo '$position=' . $position . ',  $value=^' . $value . "$\n";
?>