// Package smaz implements the Smaz compression algorithm for small strings.
// Based on the original smaz library by antirez.
package smaz

// Codebook entries (same as in Python and PHP versions)
var codeStrings = []string{
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
	"e, ", " it", "whi", " ma", "ge", "x", "e c", "men", ".com",
}

// Special codes
const (
	verbatimByte  = 254 // Code for a single verbatim byte
	verbatimString = 255 // Code for a verbatim string followed by length
)

var (
	// encodeMap maps strings to their codes
	encodeMap map[string]byte
	
	// decodeMap maps codes to their strings
	decodeMap []string
	
	// trie for efficient prefix matching
	codeTrie trieNode
)

func init() {
	// Build encode and decode maps
	encodeMap = make(map[string]byte, len(codeStrings))
	decodeMap = make([]string, len(codeStrings))
	
	for i, s := range codeStrings {
		encodeMap[s] = byte(i)
		decodeMap[i] = s
		codeTrie.put([]byte(s), byte(i))
	}
}

// trieNode represents a node in the prefix trie
type trieNode struct {
	branches [256]*trieNode
	val      byte
	terminal bool
}

// put inserts a key-value pair into the trie
func (n *trieNode) put(key []byte, val byte) {
	for _, c := range key {
		if n.branches[c] == nil {
			n.branches[c] = &trieNode{}
		}
		n = n.branches[c]
	}
	n.val = val
	n.terminal = true
}

// findLongestPrefix finds the longest matching prefix in the trie
// Returns the length of the match and the code, or 0 if no match
func (n *trieNode) findLongestPrefix(input []byte) (int, byte) {
	lastMatch := 0
	var lastCode byte
	
	for i, c := range input {
		if n.branches[c] == nil {
			break
		}
		n = n.branches[c]
		if n.terminal {
			lastMatch = i + 1
			lastCode = n.val
		}
	}
	
	return lastMatch, lastCode
}

// SmazError represents an error during compression/decompression
type SmazError struct {
	message string
}

func (e *SmazError) Error() string {
	return "smaz: " + e.message
}

// Compress compresses a byte slice using the Smaz algorithm
func Compress(input []byte) ([]byte, error) {
	if input == nil {
		return nil, &SmazError{"input cannot be nil"}
	}
	
	output := make([]byte, 0, len(input)/2)
	verbatim := make([]byte, 0)
	
	i := 0
	inputLen := len(input)
	
	for i < inputLen {
		// Try to find the longest matching code (max 7 bytes)
		maxLen := 7
		if inputLen-i < maxLen {
			maxLen = inputLen - i
		}
		
		matchLen, code := codeTrie.findLongestPrefix(input[i:i+maxLen])
		
		if matchLen > 0 {
			// Found a match in the codebook
			if len(verbatim) > 0 {
				output = flushVerbatim(output, verbatim)
				verbatim = verbatim[:0]
			}
			output = append(output, code)
			i += matchLen
		} else {
			// No match, add to verbatim buffer
			verbatim = append(verbatim, input[i])
			i++
			
			// If verbatim buffer is full, flush it
			if len(verbatim) == 255 {
				output = flushVerbatim(output, verbatim)
				verbatim = verbatim[:0]
			}
		}
	}
	
	// Flush any remaining verbatim data
	if len(verbatim) > 0 {
		output = flushVerbatim(output, verbatim)
	}
	
	return output, nil
}

// MustCompress compresses data and panics on error
func MustCompress(input []byte) []byte {
	result, err := Compress(input)
	if err != nil {
		panic(err)
	}
	return result
}

// Decompress decompresses Smaz-compressed data
func Decompress(input []byte) ([]byte, error) {
	if input == nil {
		return nil, &SmazError{"input cannot be nil"}
	}
	
	output := make([]byte, 0, len(input))
	i := 0
	inputLen := len(input)
	
	for i < inputLen {
		code := input[i]
		
		switch code {
		case verbatimByte:
			// Single verbatim byte
			if i+1 >= inputLen {
				return nil, &SmazError{"incomplete verbatim byte sequence"}
			}
			output = append(output, input[i+1])
			i += 2
			
		case verbatimString:
			// Verbatim string with length
			if i+1 >= inputLen {
				return nil, &SmazError{"incomplete verbatim string length"}
			}
			length := int(input[i+1])
			if i+2+length > inputLen {
				return nil, &SmazError{"incomplete verbatim string data"}
			}
			output = append(output, input[i+2:i+2+length]...)
			i += 2 + length
			
		default:
			// Look up code in decode map
			if int(code) >= len(decodeMap) {
				return nil, &SmazError{"invalid code"}
			}
			output = append(output, decodeMap[code]...)
			i++
		}
	}
	
	return output, nil
}

// MustDecompress decompresses data and panics on error
func MustDecompress(input []byte) []byte {
	result, err := Decompress(input)
	if err != nil {
		panic(err)
	}
	return result
}

// CompressString compresses a string to bytes
func CompressString(s string) ([]byte, error) {
	return Compress([]byte(s))
}

// DecompressString decompresses bytes to a string
func DecompressString(data []byte) (string, error) {
	result, err := Decompress(data)
	if err != nil {
		return "", err
	}
	return string(result), nil
}

// flushVerbatim flushes the verbatim buffer to the output
func flushVerbatim(out, verbatim []byte) []byte {
	length := len(verbatim)
	pos := 0
	
	for pos < length {
		chunkSize := 255
		if length-pos < chunkSize {
			chunkSize = length - pos
		}
		
		if chunkSize == 1 {
			out = append(out, verbatimByte)
		} else {
			out = append(out, verbatimString, byte(chunkSize))
		}
		
		out = append(out, verbatim[pos:pos+chunkSize]...)
		pos += chunkSize
	}
	
	return out
}
