#!/bin/sh
# Builds dist/print-agent.exe for Windows 10/11 (64-bit). Needs Go 1.22+.
# -H=windowsgui keeps a console window from opening on the counter PC.
set -e
cd "$(dirname "$0")"
GOOS=windows GOARCH=amd64 CGO_ENABLED=0 go build -trimpath -ldflags "-s -w -H=windowsgui" -o dist/print-agent.exe .
echo "built dist/print-agent.exe"
