package main

import (
	"flag"
	"fmt"
	"io/ioutil"
	"log"
	"os"

	"github.com/pedroalbanese/smaz"
)

var dec = flag.Bool("d", false, "Decompress instead of Compress")

func main() {
	flag.Parse()

	var err error

	if *dec {
		err = decompressInput()
	} else {
		err = compressInput()
	}

	if err != nil {
		log.Fatal(err)
	}
}

func compressInput() error {
	data, err := ioutil.ReadAll(os.Stdin)
	if err != nil {
		return fmt.Errorf("failed to read from stdin: %w", err)
	}

	compressed := smaz.Compress(data)
	_, err = os.Stdout.Write(compressed)
	if err != nil {
		return fmt.Errorf("failed to write to stdout: %w", err)
	}

	return nil
}

func decompressInput() error {
	data, err := ioutil.ReadAll(os.Stdin)
	if err != nil {
		return fmt.Errorf("failed to read from stdin: %w", err)
	}

	decompressed, err := smaz.Decompress(data)
	if err != nil {
		return fmt.Errorf("failed to decompress data: %w", err)
	}

	_, err = os.Stdout.Write(decompressed)
	if err != nil {
		return fmt.Errorf("failed to write to stdout: %w", err)
	}

	return nil
}
