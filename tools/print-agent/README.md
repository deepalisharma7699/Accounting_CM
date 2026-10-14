# Print agent — thermal receipts without the print dialog

A small Windows program for the counter PC. The workshop application sends it
a finished receipt (ESC/POS bytes); it passes them to the thermal printer, which
prints and **cuts the paper**. There is no print dialog.

It is only needed for direct thermal printing. Without it, every document still
prints through Chrome's normal print dialog, on A4 or on the thermal roll.

## What it is, and what it is not

* One `print-agent.exe` plus a `config.json` beside it. Nothing to install
  besides those.
* It listens on `127.0.0.1` only, so no other computer on the network can reach
  it, and it answers only the application address named in `config.json`. A
  page on any other website cannot make it print.
* It knows nothing about invoices. The application lays the receipt out; the
  agent forwards bytes. A change to how a receipt looks never needs a new agent.

## 1. Connect the printer

Recommended: an **80 mm** ESC/POS printer with an **auto-cutter** and an
**Ethernet (LAN)** port — for example an Epson TM-T82X or TM-T20III (Ethernet
variant) or a TVS RP 3230. Check with the seller that the exact model has
Ethernet, an auto-cutter and ESC/POS.

**Network (recommended).** Plug it into the router. Print its self-test page
(usually: hold the FEED button while switching it on) and note the **IP
address**. Ask whoever runs the router to reserve that address for the printer,
so it does not change. Also install the printer's Windows driver, so the print
dialog keeps working as a fallback and for A4-style prints to the roll.

**USB.** Install the printer's Windows driver from the manufacturer, then note
the printer's **exact name** in *Settings → Bluetooth & devices → Printers &
scanners* (Windows 10: *Settings → Devices → Printers & scanners*).

## 2. Put the agent on the counter PC

1. Make a folder, for example `C:\PrintAgent`.
2. Copy `print-agent.exe`, `config.example.json`, `install.cmd` and
   `uninstall.cmd` into it.
3. Copy `config.example.json` to `config.json` and edit it in Notepad:

   **Network printer**

   ```json
   {
       "listen": "127.0.0.1:9123",
       "origins": ["https://your-application-address"],
       "printer": { "type": "network", "address": "192.168.1.50:9100" }
   }
   ```

   **USB printer**

   ```json
   {
       "listen": "127.0.0.1:9123",
       "origins": ["https://your-application-address"],
       "printer": { "type": "windows", "name": "EPSON TM-T82X Receipt" }
   }
   ```

   * `origins` is the address you open the application at, exactly as it
     appears in Chrome's address bar up to the first `/` after the domain — for
     example `https://accounts.example.com`. No trailing slash, no path. Several
     can be listed.
   * `address` is the printer's IP address; `:9100` is the standard raw printing
     port and can be left off.
   * `name` must match Windows' printer name exactly.
4. Double-click `install.cmd`. It starts the agent now and every time this
   Windows user signs in. Nothing is shown on screen while it runs.

To stop it and remove it from start-up, run `uninstall.cmd`.

## 3. Tell the application to use it

On the counter PC, in Chrome, open the application → **Settings** → *Printing
on this device*:

* **Estimate paper** and **GST invoice paper** — choose *Thermal — 80 mm* (or
  58 mm) for whichever should come out on the roll. Either may stay on A4.
* **Thermal printing** — *Direct, through the print agent*.
* **Print agent address** — leave as `http://127.0.0.1:9123` unless `listen`
  was changed.
* Press **Test the printer**. A short slip should print and be cut.
* Press **Save for this device**.

These settings belong to this browser on this PC only. The office PC keeps its
own, and can stay on A4.

The first time, Chrome may ask whether the site may **access other apps and
services on this device** (or devices on your local network). Choose **Allow** —
that is the application reaching the agent.

## If something goes wrong

The agent writes `print-agent.log` in its folder. Each print and each failure is
one line.

| What you see | What to check |
|---|---|
| "The print agent is not answering on this PC" | The agent is not running: run `install.cmd` again. Or the address in Settings does not match `listen`. |
| "This origin may not use the print agent" | `origins` in `config.json` does not match the address in Chrome's address bar. Fix it, then run `uninstall.cmd` and `install.cmd`. |
| "cannot reach the printer at …" | The printer is off, unplugged, or its IP address changed. Print its self-test page and compare. |
| "Windows has no printer named …" | The `name` in `config.json` differs from the name in Printers & scanners. |
| The agent does not start at all | `config.json` has a mistake; the reason is the last line of `print-agent.log`. |

Whenever the agent cannot print, the application opens the normal print dialog
instead, so a receipt can always be printed.

## Building it

`dist/print-agent.exe` is built from this folder with Go 1.22 or later:

```sh
./build.sh
```

The `.exe` is not committed; build it, or take the copy already in `dist/`.
