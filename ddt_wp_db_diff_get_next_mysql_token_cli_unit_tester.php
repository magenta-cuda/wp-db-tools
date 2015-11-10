<?php
function ddt_wp_db_diff_get_next_mysql_token( $buffer, &$position ) {
    $length = strlen( $buffer );
    while ( $position < $length && ctype_space( substr( $buffer, $position, 1 ) ) ) {
        ++$position;
    }
    if ( $position === $length ) {
        return FALSE;
    }
    $char = substr( $buffer, $position, 1 );
    if ( ctype_digit( $char ) ) {
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
    if ( $position < $length && substr_compare( $buffer, ',', $position, 1 ) === 0 ) {
        ++$position;
    }
    return substr( $buffer, $start, $end - $start );
}

$buffer = <<<EOD
'12345', "abcde", 12345, 'ABC\'DE', "abc\"de", 'AB''CDE', "a""bcde"
EOD;

$position = 0;
$value = FALSE;

echo '$position=' . $position . ',  $value=' . $value . "\n";
while ( $value = ddt_wp_db_diff_get_next_mysql_token( $buffer, $position ) ) {
    echo '$position=' . $position . ',  $value=' . $value . "\n";
}
echo '$position=' . $position . ',  $value=' . $value . "\n";
?>