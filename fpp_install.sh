#!/bin/bash
# Install hook for the Node Wiring Test FPP plugin.
#
# The main plugin page needs nothing beyond FPP's own existing /api/models
# and /api/fppd/testing endpoints - that part works on every FPP platform
# (Raspberry Pi, BeagleBone Black/64, Armbian, Debian, Ubuntu, ...) with no
# extra packages at all.
#
# The optional Pi-side camera stream (backend/camera_stream.py, started/
# stopped from the page via api.php) additionally needs python3, ffmpeg and
# v4l-utils - all standard Linux/V4L2 tooling, nothing Raspberry-Pi-specific,
# so it works identically on a BeagleBone or any other apt-based FPP box with
# a USB camera attached. These are deliberately NOT declared in
# pluginInfo.json's dependencies: that block is apt-only, and a hard
# dependency there makes FPP refuse the WHOLE plugin install on any platform
# without apt (e.g. the "Fedora"/"MacOS" entries FPP's own platform schema
# allows) even though only this one optional feature needs them. Installed
# best-effort here instead, so the plugin - and its core step/scan features,
# which need none of this - stays installable everywhere; only the Pi-camera
# feature specifically is unavailable where these can't be installed.

PLUGINDIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
chmod +x "${PLUGINDIR}/backend/camera_stream.py"

if command -v apt-get >/dev/null 2>&1; then
    # Only ever installs what's missing - never apt-get update, never
    # re-installs/upgrades something already present (PLUGIN_GUIDELINES.md
    # §6.2: unconditional apt-get install on an already-installed base-system
    # package can trigger an unwanted boot-image rebuild on some platforms).
    command -v python3 >/dev/null 2>&1 || apt-get install -y python3
    command -v ffmpeg >/dev/null 2>&1 || apt-get install -y ffmpeg
    command -v v4l2-ctl >/dev/null 2>&1 || apt-get install -y v4l-utils
else
    echo "Node Wiring Test: no apt-get on this platform - skipping python3/ffmpeg/v4l-utils." >&2
fi

for bin in python3 ffmpeg v4l2-ctl; do
    if ! command -v "$bin" >/dev/null 2>&1; then
        echo "Node Wiring Test: WARNING - '$bin' not available; the optional Pi/USB camera stream feature won't work until it's installed. The rest of the plugin is unaffected." >&2
    fi
done

exit 0
