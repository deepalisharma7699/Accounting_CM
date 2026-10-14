//go:build windows

package main

import (
	"fmt"
	"syscall"
	"unsafe"
)

// Raw printing through the Windows spooler: the bytes reach the printer
// untouched (datatype RAW), so the ESC/POS commands — including the cut — are
// carried out by the printer rather than interpreted by a driver.

var (
	winspool             = syscall.NewLazyDLL("winspool.drv")
	procOpenPrinter      = winspool.NewProc("OpenPrinterW")
	procClosePrinter     = winspool.NewProc("ClosePrinter")
	procStartDocPrinter  = winspool.NewProc("StartDocPrinterW")
	procEndDocPrinter    = winspool.NewProc("EndDocPrinter")
	procStartPagePrinter = winspool.NewProc("StartPagePrinter")
	procEndPagePrinter   = winspool.NewProc("EndPagePrinter")
	procWritePrinter     = winspool.NewProc("WritePrinter")
)

type docInfo1 struct {
	docName    *uint16
	outputFile *uint16
	datatype   *uint16
}

func sendToWindowsPrinter(name string, data []byte) error {
	printerName, err := syscall.UTF16PtrFromString(name)
	if err != nil {
		return err
	}

	var handle syscall.Handle
	if r, _, e := procOpenPrinter.Call(uintptr(unsafe.Pointer(printerName)), uintptr(unsafe.Pointer(&handle)), 0); r == 0 {
		return fmt.Errorf("Windows has no printer named %q — check the name in Printers & scanners (%v)", name, e)
	}
	defer procClosePrinter.Call(uintptr(handle))

	docName, _ := syscall.UTF16PtrFromString("Receipt")
	datatype, _ := syscall.UTF16PtrFromString("RAW")
	doc := docInfo1{docName: docName, datatype: datatype}

	if r, _, e := procStartDocPrinter.Call(uintptr(handle), 1, uintptr(unsafe.Pointer(&doc))); r == 0 {
		return fmt.Errorf("the printer refused the job: %v", e)
	}
	defer procEndDocPrinter.Call(uintptr(handle))

	if r, _, e := procStartPagePrinter.Call(uintptr(handle)); r == 0 {
		return fmt.Errorf("the printer refused the page: %v", e)
	}
	defer procEndPagePrinter.Call(uintptr(handle))

	var written uint32
	r, _, e := procWritePrinter.Call(uintptr(handle), uintptr(unsafe.Pointer(&data[0])), uintptr(len(data)), uintptr(unsafe.Pointer(&written)))
	if r == 0 || int(written) != len(data) {
		return fmt.Errorf("the printer stopped accepting data after %d of %d bytes: %v", written, len(data), e)
	}

	return nil
}
