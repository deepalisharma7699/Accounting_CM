//go:build !windows

package main

import "errors"

// Only a Windows build can print to an installed Windows printer. Other builds
// exist to test the agent and still drive a network printer.
func sendToWindowsPrinter(string, []byte) error {
	return errors.New(`a "windows" printer can only be used by the Windows build of the agent`)
}
