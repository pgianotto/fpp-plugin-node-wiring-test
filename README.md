# fpp-plugin-node-wiring-test

An [FPP](https://github.com/FalconChristmas/fpp) plugin for verifying prop
wiring: pick a model, then step or auto-chase through its nodes one at a time
in real wiring order and compare what actually lights up against each node's
expected index/position/channel range.

## Why

Pixel props get miswired - a string plugged in backwards, two strings
crossed, a node count off by a few. The usual way to catch it is to run some
kind of chase and eyeball it against the layout. This plugin does exactly
that from the FPP web UI, using FPP's own built-in channel-test engine (no
new network protocol, no extra daemon, nothing installed) so you can:

- select any configured model from a dropdown,
- step through its nodes one at a time (or auto-play with an adjustable
  interval),
- see, for the node currently lit, its index, its expected x/y position in
  the model's buffer (for models FPP can resolve a 2D layout for), and the
  absolute channel range being driven,
- mark a node as mismatched with an optional note as you go, and export the
  resulting list as a CSV checklist for fixing wiring afterward.

It can also point a camera at the prop and find the wiring errors for you:
run an auto-scan and it flashes each node on/off, watches the camera for
where it lights up, and fits a position mapping from the whole scan at
once - no separate calibration step. Any node whose detected position
disagrees with the fit gets flagged and matched to whichever other node it's
actually sitting at, and you can download a corrected `.xmodel` (a standalone
xLights Custom Model) with every node placed at its real physical position,
so you can re-import it without re-wiring anything.

## How it works

The whole plugin is a single PHP page (`www/index.php`) with client-side
JavaScript. It never talks to anything off the FPP box:

- `GET /api/models?simple=true` to list configured models
- `GET /api/models/{model}` to fetch the selected model's definition
- `POST /api/fppd/testing` with `{"enabled":1,"mode":"RGBFill","channelSet":
  "<start>-<end>", ...}` to light exactly one node's channel range at a time
- `POST /api/fppd/testing` with `{"enabled":0}` to stop and clear the
  override (also sent via `navigator.sendBeacon` on page unload/hide, as a
  best-effort cleanup)

The node-to-position mapping is a JavaScript port of FPP's own
`PixelOverlayModel` node-ordering logic
(`src/overlays/PixelOverlayModel.cpp`), so "Node 7" here is the same node
FPP itself means by node 7 - including horizontal/vertical string
orientation, start corner, strands-per-string, and "Custom" models defined
with a dense or compressed node map. Models too large to lay out as a grid
(or without a resolvable layout) fall back to a plain wiring-order strip;
node index and channel range stay accurate either way, they're just not
drawn to scale.

## Installing

Install from FPP's Plugin Manager once listed, or manually:

```bash
cd /home/fpp/media/plugins
git clone https://github.com/pgianotto/fpp-plugin-node-wiring-test.git
```

Then restart `fppd` (or just the web UI) so the plugin is picked up, and look
for **Node Wiring Test** under the **Output** menu.

## Requirements

A reasonably current FPP build with the `/api/fppd/testing` channel-test
API and model-aware `channelSet` support (targeted at FPP 8.0+; if your
build predates this API, Status &rarr; Testing &rarr; Channel Output Testing
will also be missing/different, which is the tell).

## Limitations

- Geometric layout is only drawn for models FPP itself can resolve a 2D
  buffer for (horizontal/vertical string models, "Custom" dense/compressed
  maps, framebuffer-style models). Everything else shows wiring order only,
  and the camera auto-scan has no geometry to calibrate against or export in
  that case.
- Running a test here overrides live output on the model's channels while
  active, the same as FPP's own Channel Output Testing page - don't run it
  mid-show.
- This only drives FPP's existing test-mode output; it doesn't itself talk
  E1.31/sACN/DDP/Art-Net - FPP's configured outputs do that, same as any
  other output from this box.
- Camera auto-scan needs a flash-and-diff with decent contrast against
  ambient light, and assumes the camera/lighting stay put for the whole
  scan. A camera wired directly into the FPP Pi needs its own
  streaming/snapshot server already running (e.g. mjpg-streamer) - this
  plugin only consumes an existing stream URL, it doesn't install or
  configure that server (yet).
- The corrected `.xmodel` is always exported as a generic xLights Custom
  Model. If your original model is a built-in xLights prop type (Arches,
  Spinner, Tree, etc.), importing it will replace that prop's native
  appearance/behavior with a flat custom grid - it fixes the wiring, but you
  lose the original prop type's rendering. Preserving the original prop type
  (by patching just the node order into your existing `.xmodel`) is a
  planned follow-up, not yet implemented.

## License

MIT, see [LICENSE](LICENSE).
