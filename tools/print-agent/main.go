// Command print-agent hands ESC/POS bytes from the workshop application to a
// thermal receipt printer, so a receipt prints — and is cut — without the
// browser's print dialog.
//
// It is deliberately dumb. The application lays the receipt out and sends the
// finished bytes; this program checks who is asking, forwards the bytes to one
// configured printer and reports whether that worked. Nothing about invoices,
// tax or layout lives here, so a change to how a receipt looks never needs a
// new agent on the counter PC.
//
// It listens on the loopback address only and answers only the origins named
// in its config file: a page on any other site, or another machine on the
// network, cannot make it print. See README.md.
package main

import (
	"encoding/base64"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"io"
	"log"
	"net"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"time"
)

const version = "1.0.0"

// The largest job accepted. A long GST invoice is a few kilobytes; anything
// near this is not a receipt.
const maxBody = 1 << 20

// Config is config.json, beside the executable.
type Config struct {
	// Where to listen. Must be a loopback address.
	Listen string `json:"listen"`
	// The application's own origins — exactly as the browser sends them, e.g.
	// "https://accounts.example.com". Nothing else may print.
	Origins []string `json:"origins"`
	Printer PrinterConfig `json:"printer"`
}

// PrinterConfig names the one printer this agent drives.
type PrinterConfig struct {
	// "network": a printer on the LAN, raw on port 9100.
	// "windows": a printer installed in Windows, by its name, sent RAW.
	Type string `json:"type"`
	// For "network": "192.168.1.50:9100". The port defaults to 9100.
	Address string `json:"address"`
	// For "windows": the name shown in Settings > Printers & scanners.
	Name string `json:"name"`
}

func (p PrinterConfig) describe() string {
	if p.Type == "windows" {
		return p.Name
	}
	return p.Address
}

func loadConfig(path string) (Config, error) {
	var cfg Config

	raw, err := os.ReadFile(path)
	if err != nil {
		return cfg, fmt.Errorf("reading %s: %w", path, err)
	}
	if err := json.Unmarshal(raw, &cfg); err != nil {
		return cfg, fmt.Errorf("%s is not valid JSON: %w", path, err)
	}

	if cfg.Listen == "" {
		cfg.Listen = "127.0.0.1:9123"
	}

	host, _, err := net.SplitHostPort(cfg.Listen)
	if err != nil {
		return cfg, fmt.Errorf("listen %q: %w", cfg.Listen, err)
	}
	// Refused rather than warned about: on any other address every machine on
	// the network could send this printer whatever it liked.
	if ip := net.ParseIP(host); host != "localhost" && (ip == nil || !ip.IsLoopback()) {
		return cfg, fmt.Errorf("listen must be a loopback address such as 127.0.0.1, not %q", host)
	}

	if len(cfg.Origins) == 0 {
		return cfg, errors.New(`"origins" must name the application's address, e.g. "https://accounts.example.com"`)
	}
	for i, origin := range cfg.Origins {
		cfg.Origins[i] = strings.TrimRight(strings.TrimSpace(origin), "/")
	}

	switch cfg.Printer.Type {
	case "network":
		if cfg.Printer.Address == "" {
			return cfg, errors.New(`printer "address" is required for a network printer`)
		}
		if _, _, err := net.SplitHostPort(cfg.Printer.Address); err != nil {
			cfg.Printer.Address = net.JoinHostPort(cfg.Printer.Address, "9100")
		}
	case "windows":
		if cfg.Printer.Name == "" {
			return cfg, errors.New(`printer "name" is required for a Windows printer`)
		}
	default:
		return cfg, fmt.Errorf(`printer "type" must be "network" or "windows", not %q`, cfg.Printer.Type)
	}

	return cfg, nil
}

// send delivers one job to the configured printer.
func send(p PrinterConfig, data []byte) error {
	if p.Type == "windows" {
		return sendToWindowsPrinter(p.Name, data)
	}
	return sendToNetworkPrinter(p.Address, data)
}

func sendToNetworkPrinter(address string, data []byte) error {
	conn, err := net.DialTimeout("tcp", address, 5*time.Second)
	if err != nil {
		return fmt.Errorf("cannot reach the printer at %s — is it on and connected? (%v)", address, err)
	}
	defer conn.Close()

	_ = conn.SetWriteDeadline(time.Now().Add(15 * time.Second))
	if _, err := conn.Write(data); err != nil {
		return fmt.Errorf("the printer at %s stopped accepting data: %v", address, err)
	}
	return nil
}

type agent struct {
	cfg Config
	// One job at a time. Two receipts interleaved on one roll are two
	// unreadable receipts.
	mu sync.Mutex
}

func (a *agent) allowed(origin string) bool {
	for _, o := range a.cfg.Origins {
		if o == origin {
			return true
		}
	}
	return false
}

func reply(w http.ResponseWriter, status int, body map[string]any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(body)
}

func (a *agent) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	origin := r.Header.Get("Origin")

	// No origin, or the wrong one, gets nothing: not a page on another site,
	// and not a request typed into a terminal by somebody hoping to print.
	if !a.allowed(origin) {
		reply(w, http.StatusForbidden, map[string]any{"ok": false, "error": "This origin may not use the print agent."})
		return
	}

	h := w.Header()
	h.Set("Access-Control-Allow-Origin", origin)
	h.Set("Vary", "Origin")
	h.Set("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
	h.Set("Access-Control-Allow-Headers", "Content-Type")
	// Chrome asks before a public page may reach a loopback address.
	h.Set("Access-Control-Allow-Private-Network", "true")

	switch {
	case r.Method == http.MethodOptions:
		w.WriteHeader(http.StatusNoContent)

	case r.Method == http.MethodGet && r.URL.Path == "/status":
		reply(w, http.StatusOK, map[string]any{
			"ok":      true,
			"version": version,
			"printer": a.cfg.Printer.describe(),
			"type":    a.cfg.Printer.Type,
		})

	case r.Method == http.MethodPost && r.URL.Path == "/print":
		a.print(w, r)

	default:
		reply(w, http.StatusNotFound, map[string]any{"ok": false, "error": "Not found."})
	}
}

func (a *agent) print(w http.ResponseWriter, r *http.Request) {
	var job struct {
		Data string `json:"data"`
	}

	body, err := io.ReadAll(io.LimitReader(r.Body, maxBody+1))
	if err != nil || len(body) > maxBody {
		reply(w, http.StatusRequestEntityTooLarge, map[string]any{"ok": false, "error": "The print job is too large."})
		return
	}
	if err := json.Unmarshal(body, &job); err != nil {
		reply(w, http.StatusBadRequest, map[string]any{"ok": false, "error": "The print job is not valid JSON."})
		return
	}

	data, err := base64.StdEncoding.DecodeString(job.Data)
	if err != nil || len(data) == 0 {
		reply(w, http.StatusBadRequest, map[string]any{"ok": false, "error": "The print job carried no data."})
		return
	}

	a.mu.Lock()
	err = send(a.cfg.Printer, data)
	a.mu.Unlock()

	if err != nil {
		log.Printf("print failed: %v", err)
		reply(w, http.StatusBadGateway, map[string]any{"ok": false, "error": err.Error()})
		return
	}

	log.Printf("printed %d bytes to %s", len(data), a.cfg.Printer.describe())
	reply(w, http.StatusOK, map[string]any{"ok": true})
}

func main() {
	exe, _ := os.Executable()
	dir := filepath.Dir(exe)

	configPath := flag.String("config", filepath.Join(dir, "config.json"), "path to config.json")
	logPath := flag.String("log", filepath.Join(dir, "print-agent.log"), "log file; empty logs to the console")
	flag.Parse()

	if *logPath != "" {
		if f, err := os.OpenFile(*logPath, os.O_CREATE|os.O_APPEND|os.O_WRONLY, 0o644); err == nil {
			log.SetOutput(f)
		}
	}

	cfg, err := loadConfig(*configPath)
	if err != nil {
		log.Fatalf("config: %v", err)
	}

	server := &http.Server{
		Addr:              cfg.Listen,
		Handler:           &agent{cfg: cfg},
		ReadHeaderTimeout: 5 * time.Second,
		ReadTimeout:       15 * time.Second,
		WriteTimeout:      30 * time.Second,
	}

	log.Printf("print-agent %s listening on %s, printer %s (%s), origins %v",
		version, cfg.Listen, cfg.Printer.describe(), cfg.Printer.Type, cfg.Origins)

	log.Fatal(server.ListenAndServe())
}
