#!/bin/bash
# Uninstall hook for the Node Wiring Test FPP plugin.

PIDFILE="/home/fpp/media/plugindata/fpp-plugin-node-wiring-test/camera_stream.pid"
if [ -f "$PIDFILE" ]; then
    PID=$(cat "$PIDFILE" 2>/dev/null)
    if [ -n "$PID" ] && [ -d "/proc/$PID" ]; then
        kill -TERM "-$PID" 2>/dev/null
        kill -TERM "$PID" 2>/dev/null
    fi
    rm -f "$PIDFILE"
fi

# From FPP 10.4, Reinstall (and Reinstall All) runs this with
# FPP_PLUGIN_REINSTALL=1 before installing again; keep the camera stream's
# own log/pid directory across a reinstall, only drop it on a real removal.
if [ "${FPP_PLUGIN_REINSTALL:-0}" != "1" ]; then
    rm -rf "/home/fpp/media/plugindata/fpp-plugin-node-wiring-test"
fi

exit 0
