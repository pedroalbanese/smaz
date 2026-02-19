"""
Smaz compression library for compressing small strings.
Based on the original smaz library by antirez.
"""

from typing import Dict, List, Optional, Union
import sys
import argparse
import os

# Compression codebook (same as in Go and PHP versions)
CODES = [
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
]

# Build encode and decode lookup tables
ENCODE_MAP: Dict[str, int] = {s: i for i, s in enumerate(CODES)}
DECODE_MAP: List[str] = CODES

# Special codes
VERBATIM_BYTE = 254  # Code for a single verbatim byte
VERBATIM_STRING = 255  # Code for a verbatim string followed by length


class SmazError(Exception):
    """Exception raised for Smaz compression/decompression errors."""
    pass


def compress(data: Union[str, bytes]) -> bytes:
    """
    Compress a string or bytes using the Smaz algorithm.
    
    Args:
        data: Input string or bytes to compress
        
    Returns:
        Compressed bytes
        
    Raises:
        TypeError: If input is not str or bytes
    """
    if isinstance(data, str):
        input_bytes = data.encode('utf-8')
    elif isinstance(data, bytes):
        input_bytes = data
    else:
        raise TypeError("Input must be str or bytes")
    
    output = bytearray()
    verbatim = bytearray()
    i = 0
    input_len = len(input_bytes)
    
    while i < input_len:
        # Try to find the longest matching code (max length 7)
        matched = False
        max_len = min(7, input_len - i)
        
        for j in range(max_len, 0, -1):
            chunk = input_bytes[i:i+j].decode('latin-1')
            if chunk in ENCODE_MAP:
                # Flush any pending verbatim data
                if verbatim:
                    output.extend(_flush_verbatim(verbatim))
                    verbatim.clear()
                
                # Write the code
                output.append(ENCODE_MAP[chunk])
                i += j
                matched = True
                break
        
        if not matched:
            # No match found, add to verbatim buffer
            verbatim.append(input_bytes[i])
            i += 1
            
            # If verbatim buffer is full, flush it
            if len(verbatim) == 255:
                output.extend(_flush_verbatim(verbatim))
                verbatim.clear()
    
    # Flush any remaining verbatim data
    if verbatim:
        output.extend(_flush_verbatim(verbatim))
    
    return bytes(output)


def decompress(data: bytes) -> bytes:
    """
    Decompress Smaz-compressed data.
    
    Args:
        data: Compressed bytes
        
    Returns:
        Decompressed bytes
        
    Raises:
        SmazError: If the compressed data is invalid or corrupted
    """
    output = bytearray()
    i = 0
    data_len = len(data)
    
    while i < data_len:
        code = data[i]
        
        if code == VERBATIM_BYTE:
            # Single verbatim byte
            if i + 1 >= data_len:
                raise SmazError("Incomplete verbatim byte sequence")
            output.append(data[i + 1])
            i += 2
            
        elif code == VERBATIM_STRING:
            # Verbatim string with length
            if i + 1 >= data_len:
                raise SmazError("Incomplete verbatim string length")
            
            length = data[i + 1]
            if i + 2 + length > data_len:
                raise SmazError("Incomplete verbatim string data")
            
            output.extend(data[i+2:i+2+length])
            i += 2 + length
            
        else:
            # Look up code in decode map
            if code >= len(DECODE_MAP):
                raise SmazError(f"Invalid code: {code}")
            
            output.extend(DECODE_MAP[code].encode('latin-1'))
            i += 1
    
    return bytes(output)


def _flush_verbatim(verbatim: bytearray) -> bytearray:
    """
    Flush verbatim buffer to output format.
    
    Args:
        verbatim: Verbatim bytes to flush
        
    Returns:
        Formatted verbatim bytes with appropriate headers
    """
    output = bytearray()
    length = len(verbatim)
    
    if length == 0:
        return output
    
    # Process in chunks of up to 255 bytes
    pos = 0
    while pos < length:
        chunk_size = min(255, length - pos)
        chunk = verbatim[pos:pos+chunk_size]
        
        if chunk_size == 1:
            output.append(VERBATIM_BYTE)
        else:
            output.append(VERBATIM_STRING)
            output.append(chunk_size)
        
        output.extend(chunk)
        pos += chunk_size
    
    return output


# Convenience functions
def compress_str(s: str) -> bytes:
    """Compress a string to bytes."""
    return compress(s)


def decompress_str(data: bytes) -> str:
    """Decompress bytes to a string (assumes UTF-8 encoding)."""
    return decompress(data).decode('utf-8')


def main():
    """CLI entry point."""
    parser = argparse.ArgumentParser(
        description="Smaz compression tool - compress or decompress text"
    )
    
    parser.add_argument(
        'action',
        choices=['c', 'compress', 'd', 'decompress'],
        help='Action to perform (c/compress or d/decompress)'
    )
    
    parser.add_argument(
        'input',
        nargs='?',
        help='Input string (if not provided, reads from stdin)'
    )
    
    parser.add_argument(
        '-o', '--output',
        help='Output file (if not provided, prints to stdout)'
    )
    
    parser.add_argument(
        '-f', '--file',
        help='Read input from file'
    )
    
    args = parser.parse_args()
    
    # Normalize action
    action = 'compress' if args.action in ['c', 'compress'] else 'decompress'
    
    # Read input
    input_data = None
    
    if args.file:
        if action == 'compress':
            # For compression, read as text
            with open(args.file, 'r', encoding='utf-8') as f:
                input_data = f.read()
        else:
            # For decompression, read as binary
            with open(args.file, 'rb') as f:
                input_data = f.read()
    elif args.input:
        if action == 'compress':
            input_data = args.input
        else:
            # For decompression from command line, assume hex or base64?
            # Simpler: just treat as string and let decompress handle
            input_data = args.input.encode('latin-1')
    else:
        # Read from stdin
        if action == 'compress':
            input_data = sys.stdin.read()
        else:
            input_data = sys.stdin.buffer.read()
    
    if input_data is None:
        parser.error("No input provided")
    
    try:
        # Process
        if action == 'compress':
            result = compress(input_data)
        else:  # decompress
            result = decompress(input_data)
        
        # Output
        if args.output:
            if action == 'compress':
                # Write compressed data as binary
                with open(args.output, 'wb') as f:
                    f.write(result)
            else:
                # Write decompressed data as text
                with open(args.output, 'w', encoding='utf-8') as f:
                    f.write(result.decode('utf-8'))
        else:
            if action == 'compress':
                # For compressed output to stdout, use binary
                sys.stdout.buffer.write(result)
            else:
                # For decompressed output, print as text
                print(result.decode('utf-8'))
                
    except Exception as e:
        print(f"Error: {e}", file=sys.stderr)
        sys.exit(1)


if __name__ == '__main__':
    main()
