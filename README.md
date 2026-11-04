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
  interval), jump straight to any node by typing its number, or click a node
  directly in the preview to light it,
- see, for the node currently lit, its index, its expected x/y position in
  the model's buffer (for models FPP can resolve a 2D layout for), and the
  absolute channel range being driven,
- mark a node as mismatched with an optional note as you go, and export the
  resulting list as a CSV checklist for fixing wiring afterward,
- or, if you give the actual node number that lit up instead when marking a
  mismatch, download a corrected `.xmodel` built straight from that log - no
  camera needed at all,
- including bulk-correcting a whole stretch of nodes at once (the signature
  of a miswired connector or a spliced-in prop: a run of nodes all shifted by
  the same constant amount) by giving the range and the offset, instead of
  marking each one in it individually.

The plain layout preview has its own toolbar: a Front/Back toggle mirrors the
display left/right for working from behind the prop (display only, never
changes wiring order or channels sent), a "Show wiring path" checkbox draws a
line through the nodes in real wiring order, a "Show node numbers" checkbox
labels every node at once (once zoomed in enough to read them), zoom buttons
(or Ctrl/Cmd+scroll over the preview) zoom in on dense models, and hovering
any node shows its number in a tooltip. Node 1 (the start of the wire) is
always green and the last node (its end) is always red, so either end is
identifiable at a glance.

Point a camera at the prop (this device's own, or a USB camera physically
attached to the FPP box) and the live video gets a live overlay of the
model's shape right on top of it - drag to position it, a slider to size it, and the node
currently being stepped/played highlights on the overlay in its expected
position, so you can watch the real prop and the overlay side by side and
see mismatches with your own eyes, no scan required.

It can also find the wiring errors for you automatically: run an auto-scan
and it flashes each node on/off, watches the camera for where it lights up,
and fits a position mapping from the whole scan at once - no separate
calibration step (this is also what the live overlay switches to once a scan
completes, instead of the plain manually-positioned reference grid). Any
node whose detected position disagrees with the fit gets flagged and matched
to whichever other node it's actually sitting at, and you can download a
corrected `.xmodel` (a standalone xLights Custom Model) with every node
placed at its real physical position, so you can re-import it without
re-wiring anything.

## How it works

The main plugin page (`www/index.php`) is PHP plus client-side JavaScript,
and the step/chase/mismatch-log feature never talks to anything off the FPP
box:

- `GET /api/models?simple=true` to list configured models
- `GET /api/models/{model}` to fetch the selected model's definition
- `POST /api/fppd/testing` with `{"enabled":1,"mode":"RGBFill","channelSet":
  "<start>-<end>", ...}` to light exactly one node's channel range at a time
- `POST /api/fppd/testing` with `{"enabled":0}` to stop and clear the
  override (also sent via `navigator.sendBeacon` on page unload/hide, as a
  best-effort cleanup)

The optional camera auto-scan can also drive a USB camera physically
attached to the FPP box: `api.php` exposes
`/api/plugin/fpp-plugin-node-wiring-test/camera/{devices,start,stop,status}`,
which detect V4L2 USB cameras (`v4l2-ctl --list-devices`) and start/stop
`backend/camera_stream.py` - a small MJPEG-over-HTTP server that pipes the
camera's own hardware MJPEG output through `ffmpeg -c:v copy` (no
re-encode) and serves both a continuous `multipart/x-mixed-replace` stream
(`/stream.mjpg`) and a plain single-JPEG endpoint (`/snapshot.jpg`). The
page itself polls `/snapshot.jpg` on an interval rather than embedding
`/stream.mjpg` directly - Chrome (and others, at various points) doesn't
reliably render a continuous MJPEG stream in an `<img>`, the same
long-standing issue mjpg-streamer users hit, confirmed here against this
plugin's own stream before switching to polling. It's started on request
from the page, not installed as a system service, and does not start on
boot.

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

FPP 10.0 or later. This plugin was developed and verified against FPP
10.x-master; the `/api/fppd/testing` channel-test API and model-aware
`channelSet` support it relies on weren't confirmed present any earlier, so
`pluginInfo.json` doesn't claim compatibility below FPP 10. If your build
predates this API, Status &rarr; Testing &rarr; Channel Output Testing will
also be missing/different, which is the tell.

No `platforms` restriction is declared, so it's offered on every FPP
platform (Raspberry Pi, BeagleBone Black/64, Armbian, Debian, Ubuntu, ...) -
nothing in the code is Pi-specific. The core step/auto-scan features need
nothing beyond FPP's own API; the optional USB-camera-on-this-device feature
additionally needs `ffmpeg`/`python3`/`v4l-utils`, which `fpp_install.sh`
installs best-effort wherever `apt` is available (see Limitations).

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
  scan.
- The bundled Pi/USB camera stream (`backend/camera_stream.py`) needs
  `ffmpeg`, `python3` and `v4l-utils` on the FPP box and a USB camera FPP can
  see as `/dev/videoN`. These are standard Linux/V4L2 tooling, nothing
  Raspberry-Pi-specific, so this works the same on a BeagleBone or any other
  apt-based platform with a camera attached - `fpp_install.sh` installs
  whichever of them are missing, best-effort, on any platform with `apt`.
  (They're deliberately *not* a hard `dependencies` entry in
  `pluginInfo.json`, since that would make FPP refuse the install of the
  *entire* plugin - including the core step/scan features, which need none
  of this - on a platform without `apt` at all.) It opens a port on your
  local network (default 8091) for as long as you leave it running - stop it
  from the page when you're done, since nothing currently times it out
  automatically.
- The corrected `.xmodel` is always exported as a generic xLights Custom
  Model. If your original model is a built-in xLights prop type (Arches,
  Spinner, Tree, etc.), importing it will replace that prop's native
  appearance/behavior with a flat custom grid - it fixes the wiring, but you
  lose the original prop type's rendering. Preserving the original prop type
  (by patching just the node order into your existing `.xmodel`) is a
  planned follow-up, not yet implemented.

## License

MIT, see [LICENSE](LICENSE).
