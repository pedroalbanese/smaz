<?php

/**
 * Smaz compression library for compressing small strings.
 * Based on the original smaz library by antirez.
 */

class Smaz
{
    // Special codes
    const VERBATIM_BYTE = 254;
    const VERBATIM_STRING = 255;
    
    /** @var array|null Encode map (string -> code) */
    private static $encodeMap = null;
    
    /** @var array Decode map (code -> string) */
    private static $decodeMap = [
        " ", "the", "e", "t", "a", "of", "o", "and", "i", "n", "s", "e ", "r", " th",
        " t", "in", "he", "th", "h", "he ", "to", "\r\n", "l", "s ", "d", " a", "an",
        "er", "c", " o", "d ", "on", " of", "re", "of ", "t ", ", ", "is", "u", "at",
        "   ", "n ", "or", "which", "f", "m", "as", "it", "that", "\n", "was", "en",
        "  ", " w", "es", " an", " i", "\r", "f ", "g", "p", "nd", " s", "nd ", "ed ",
        "w", "ed", "http://", "for", "te", "ing", "y ", "The", " c", "ti", "r ", "his",
        "st", " in", "ar", "nt", ",", " to", "y", "ng", " h", "with", "le", "al", "to ",
        "b", "ou", "be", "were", " b", "se", "o ", "ent", "ha", "ng ", "their", "\"",
        "hi", "from", " f", "in ", "de", "ion", "me", "v", ".", "ve", "all", "re ",
        "ri", "ro", "is ", "co", "f t", "are", "ea", ". ", "her", " m", "er ", " p",
        "es ", "by", "they", "di", "ra", "ic", "not", "s, ", "d t", "at ", "ce", "la",
        "h ", "ne", "as ", "tio", "on ", "n t", "io", "we", " a ", "om", ", a", "s o",
        "ur", "li", "ll", "ch", "had", "this", "e t", "g ", "e\r\n", " wh", "ere",
        " co", "e o", "a ", "us", " d", "ss", "\n\r\n", "\r\n\r", "=\"", " be", " e",
        "s a", "ma", "one", "t t", "or ", "but", "el", "so", "l ", "e s", "s,", "no",
        "ter", " wa", "iv", "ho", "e a", " r", "hat", "s t", "ns", "ch ", "wh", "tr",
        "ut", "/", "have", "ly ", "ta", " ha", " on", "tha", "-", " l", "ati", "en ",
        "pe", " re", "there", "ass", "si", " fo", "wa", "ec", "our", "who", "its", "z",
        "fo", "rs", ">", "ot", "un", "<", "im", "th ", "nc", "ate", "><", "ver", "ad",
        " we", "ly", "ee", " n", "id", " cl", "ac", "il", "</", "rt", " wi", "div",
        "e, ", " it", "whi", " ma", "ge", "x", "e c", "men", ".com"
    ];
    
    /**
     * Get the encode map (string -> code)
     *
     * @return array
     */
    private static function getEncodeMap(): array
    {
        if (self::$encodeMap === null) {
            self::$encodeMap = array_flip(self::$decodeMap);
        }
        return self::$encodeMap;
    }
    
    /**
     * Compress a string using the Smaz algorithm
     *
     * @param string $input Input string to compress
     * @return string Compressed binary data
     * @throws InvalidArgumentException If input is not a string
     */
    public static function compress(string $input): string
    {
        $inputLen = strlen($input);
        $output = '';
        $verbatim = '';
        $i = 0;
        $encodeMap = self::getEncodeMap();
        
        while ($i < $inputLen) {
            // Try to find the longest matching code (max length 7)
            $matched = false;
            $maxLen = min(7, $inputLen - $i);
            
            for ($j = $maxLen; $j > 0; $j--) {
                $chunk = substr($input, $i, $j);
                if (isset($encodeMap[$chunk])) {
                    // Found a match in the codebook
                    if (strlen($verbatim) > 0) {
                        $output .= self::flushVerbatim($verbatim);
                        $verbatim = '';
                    }
                    
                    $output .= chr($encodeMap[$chunk]);
                    $i += $j;
                    $matched = true;
                    break;
                }
            }
            
            if (!$matched) {
                // No match, add to verbatim buffer
                $verbatim .= $input[$i];
                $i++;
                
                // If verbatim buffer is full, flush it
                if (strlen($verbatim) == 255) {
                    $output .= self::flushVerbatim($verbatim);
                    $verbatim = '';
                }
            }
        }
        
        // Flush any remaining verbatim data
        if (strlen($verbatim) > 0) {
            $output .= self::flushVerbatim($verbatim);
        }
        
        return $output;
    }
    
    /**
     * Decompress Smaz-compressed data
     *
     * @param string $input Compressed binary data
     * @return string Decompressed string
     * @throws RuntimeException If the compressed data is invalid
     */
    public static function decompress(string $input): string
    {
        $output = '';
        $i = 0;
        $inputLen = strlen($input);
        
        while ($i < $inputLen) {
            $code = ord($input[$i]);
            
            if ($code === self::VERBATIM_BYTE) {
                // Single verbatim byte
                if ($i + 1 >= $inputLen) {
                    throw new RuntimeException('Incomplete verbatim byte sequence');
                }
                $output .= $input[$i + 1];
                $i += 2;
                
            } elseif ($code === self::VERBATIM_STRING) {
                // Verbatim string with length
                if ($i + 1 >= $inputLen) {
                    throw new RuntimeException('Incomplete verbatim string length');
                }
                
                $length = ord($input[$i + 1]);
                if ($i + 2 + $length > $inputLen) {
                    throw new RuntimeException('Incomplete verbatim string data');
                }
                
                $output .= substr($input, $i + 2, $length);
                $i += 2 + $length;
                
            } else {
                // Look up code in decode map
                if ($code >= count(self::$decodeMap)) {
                    throw new RuntimeException("Invalid code: {$code}");
                }
                
                $output .= self::$decodeMap[$code];
                $i++;
            }
        }
        
        return $output;
    }
    
    /**
     * Flush verbatim buffer to output format
     *
     * @param string $verbatim Verbatim string to flush
     * @return string Formatted verbatim data
     */
    private static function flushVerbatim(string $verbatim): string
    {
        $output = '';
        $len = strlen($verbatim);
        $pos = 0;
        
        while ($pos < $len) {
            $chunkSize = min(255, $len - $pos);
            $chunk = substr($verbatim, $pos, $chunkSize);
            
            if ($chunkSize === 1) {
                $output .= chr(self::VERBATIM_BYTE);
            } else {
                $output .= chr(self::VERBATIM_STRING) . chr($chunkSize);
            }
            
            $output .= $chunk;
            $pos += $chunkSize;
        }
        
        return $output;
    }
}

// Function aliases
if (!function_exists('smaz_compress')) {
    function smaz_compress(string $input): string
    {
        return Smaz::compress($input);
    }
}

if (!function_exists('smaz_decompress')) {
    function smaz_decompress(string $input): string
    {
        return Smaz::decompress($input);
    }
}

// CLI handling
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    function showHelp() {
        echo "Smaz compression tool\n";
        echo "Usage:\n";
        echo "  php smaz.php compress <text>\n";
        echo "  php smaz.php decompress <text>\n";
        echo "  php smaz.php compress -f <file> [-o <output>]\n";
        echo "  php smaz.php decompress -f <file> [-o <output>]\n";
        echo "  cat <file> | php smaz.php compress\n";
        echo "  cat <file.smaz> | php smaz.php decompress\n";
        echo "\nOptions:\n";
        echo "  -f, --file         Read input from file\n";
        echo "  -o, --output       Output file\n";
        echo "  -h, --help         Show this help\n";
    }
    
    // Parse arguments
    $shortopts = "f:o:h";
    $longopts = ["file:", "output:", "help"];
    $options = getopt($shortopts, $longopts);
    
    if (isset($options['h']) || isset($options['help'])) {
        showHelp();
        exit(0);
    }
    
    // Get action from first argument
    global $argc, $argv;
    $action = null;
    $input = null;
    
    if ($argc > 1) {
        if ($argv[1] === 'compress' || $argv[1] === 'c') {
            $action = 'compress';
            if ($argc > 2 && $argv[2][0] !== '-') {
                $input = $argv[2];
            }
        } elseif ($argv[1] === 'decompress' || $argv[1] === 'd') {
            $action = 'decompress';
            if ($argc > 2 && $argv[2][0] !== '-') {
                $input = $argv[2];
            }
        }
    }
    
    if (!$action) {
        echo "Error: No action specified (use compress or decompress)\n";
        showHelp();
        exit(1);
    }
    
    // Get input
    $inputData = null;
    
    if (isset($options['f']) || isset($options['file'])) {
        $filename = $options['f'] ?? $options['file'];
        if (!file_exists($filename)) {
            echo "Error: File not found: $filename\n";
            exit(1);
        }
        
        if ($action === 'compress') {
            $inputData = file_get_contents($filename);
        } else {
            $inputData = file_get_contents($filename);
        }
    } elseif ($input) {
        $inputData = $input;
    } else {
        // Read from stdin
        $inputData = stream_get_contents(STDIN);
    }
    
    if ($inputData === null || $inputData === '') {
        echo "Error: No input provided\n";
        exit(1);
    }
    
    try {
        // Process
        if ($action === 'compress') {
            $result = Smaz::compress($inputData);
        } else {
            $result = Smaz::decompress($inputData);
        }
        
        // Output
        if (isset($options['o']) || isset($options['output'])) {
            $outputFile = $options['o'] ?? $options['output'];
            if ($action === 'compress') {
                file_put_contents($outputFile, $result);
            } else {
                file_put_contents($outputFile, $result);
            }
        } else {
            if ($action === 'compress') {
                // Binary output to stdout
                echo $result;
            } else {
                echo $result;
            }
        }
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
        exit(1);
    }
}
