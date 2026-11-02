#!/bin/bash
# Install hook for the Node Wiring Test FPP plugin.
#
# The main plugin page needs nothing beyond FPP's own existing /api/models
# and /api/fppd/testing endpoints. The optional Pi-side camera stream
# (backend/camera_stream.py, started/stopped from the page via api.php) uses
# python3, ffmpeg, and v4l-utils - all declared in pluginInfo.json's
# dependencies so FPP installs them, but check anyway rather than fail
# silently on a system where they're somehow still missing.

PLUGINDIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
chmod +x "${PLUGINDIR}/backend/camera_stream.py"

for bin in python3 ffmpeg v4l2-ctl; do
    if ! command -v "$bin" >/dev/null 2>&1; then
        echo "Node Wiring Test: WARNING - '$bin' not found; the optional Pi camera stream feature won't work until it's installed." >&2
    fi
done

exit 0
