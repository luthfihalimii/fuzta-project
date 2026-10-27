# Receipt printing — operations checklist

> **Status: hardware check incomplete.** The branch printer model and connection method are not confirmed. Do not mark receipt printing as complete until a test print has been performed at each branch with the actual printer.

## What the software produces

The receipt page (`/orders/{order}/receipt`) renders an 80mm paper layout from order snapshots and offers two transport actions:

| Action | Transport | Use when |
|---|---|---|
| Cetak browser | Browser print dialog + OS printer driver | A printer is installed on the device or shared on the local network with a working driver. This is the fallback and works on desktop, tablet, and phone. |
| Cetak RawBT | Android `rawbt:` intent containing ESC/POS text | An Android device has the RawBT app and a paired Bluetooth/USB/network printer. |

The browser path uses the system print dialog. The RawBT path opens the RawBT app with the receipt bytes; it does not require a driver. The transport is isolated in `resources/js/lib/receipt.ts` so it can be replaced once the printer model is known.

## Printer model and connection options

Record the confirmed values here before the first branch rollout:

- Printer model: **unknown**
- Paper width: **unknown** (58mm or 80mm)
- Connection: **unknown** — choose one:
  1. USB or LAN printer with an OS driver (browser print).
  2. Bluetooth or USB printer through RawBT on Android.
  3. Network printer exposed as raw TCP port 9100 (not implemented; would need a small local bridge or server-side transport).

Do not assume a model, paper width, or connection. The receipt page defaults to 80mm and the ESC/POS builder defaults to 32 columns (58mm). For 80mm ESC/POS, use the 42-column option when a transport supports it.

## RawBT setup

1. Install RawBT on the Android phone or tablet that will print.
2. Pair the printer in Android Bluetooth settings or connect it by USB, then add it in RawBT.
3. Set the paper width in RawBT to match the printer (58mm or 80mm).
4. Enable ESC/POS mode. Disable any "text only" or driver-specific mode that rewrites commands.
5. Open the receipt page in Chrome on the same device.
6. Tap **Cetak RawBT**. Android opens RawBT with the receipt.
7. Confirm the preview in RawBT, then print.

If RawBT is not installed, the intent does nothing. Use **Cetak browser** instead.

## Test print checklist

Print one receipt at each branch and confirm:

- [ ] Branch name, address, and phone are correct.
- [ ] Order number, date, and time use `Asia/Jakarta` and 24-hour time.
- [ ] Channel (Dine-in/Takeaway), table number, and cashier are correct.
- [ ] Item quantities, names, variants, modifiers, and notes are present.
- [ ] Money uses `Rp 85.000` formatting and the total matches the POS/order page.
- [ ] Payment status is correct; unpaid/voided receipts are clearly marked as not proof of payment.
- [ ] Cash received and change appear for cash payments; QRIS shows no cash fields.
- [ ] The receipt fits the paper width with no clipped characters or broken lines.
- [ ] Indonesian text prints correctly (no missing glyphs).
- [ ] Paper feeds and cuts correctly (if the printer has a cutter).
- [ ] A second copy can be printed without changing the order.

## Recording the hardware check

Add a row after testing each branch:

| Date | Branch | Printer model | Connection | Paper | Tester | Result |
|---|---|---|---|---|---|---|
| — | — | — | — | — | — | Not tested |

## Fallback and limitations

- Browser print depends on the device's print setup and driver; test it on the actual cashier device.
- RawBT is Android-only. iPhone/iPad must use browser print or a supported driver.
- The app does not control the cash drawer, print server, or printer cutter directly.
- Receipts are rendered from order snapshots, so later catalog changes do not alter past receipts.
- Do not print card credentials. The system stores only payment method and references, never card data.
