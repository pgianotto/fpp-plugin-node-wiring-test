#!/usr/bin/env python3
"""MJPEG-over-HTTP server for a local V4L2 camera (e.g. a USB webcam on the
FPP Pi), used by the Node Wiring Test plugin's camera auto-scan as a
"Stream/snapshot URL" source it can already consume.

No mjpg-streamer dependency: the camera's own hardware MJPEG output is piped
straight through by ffmpeg (-c:v copy, no re-encode) and re-served here as a
standard multipart/x-mixed-replace stream any browser <img> tag can display.

Usage: camera_stream.py --device /dev/video1 --port 8091 [--width 1280]
       [--height 720] [--fps 15]

Started/stopped by ../api.php (FPP plugin API), not run directly by FPP.
"""
import argparse
import http.server
import re
import socketserver
import subprocess
import sys
import threading
import time

FRAME_START = b"\xff\xd8"
FRAME_END = b"\xff\xd9"

latest_frame = None
frame_lock = threading.Lock()
frame_event = threading.Event()
ffmpeg_proc = None


def capture_loop(device, width, height, fps):
    global latest_frame, ffmpeg_proc
    cmd = [
        "ffmpeg", "-loglevel", "error", "-nostdin",
        "-f", "v4l2", "-input_format", "mjpeg",
        "-video_size", "%dx%d" % (width, height), "-framerate", str(fps),
        "-i", device,
        "-c:v", "copy", "-f", "mjpeg", "pipe:1",
    ]
    while True:
        ffmpeg_proc = subprocess.Popen(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        # A bytearray + .extend() is amortized O(1) per append; a bytes
        # object's "+=" copies the WHOLE buffer on every append, which at a
        # ~150-300KB JPEG frame read in 4KB chunks meant tens of MB of
        # memcopy per frame - measured at 60-70% of a core on a Pi 4 for a
        # single stream before this fix, for what should be near-zero-cost
        # passthrough since ffmpeg already does no re-encoding (-c:v copy).
        buf = bytearray()
        try:
            while True:
                chunk = ffmpeg_proc.stdout.read(65536)
                if not chunk:
                    break
                buf.extend(chunk)
                # Keep only from the last frame start onward so a slow
                # consumer never grows this buffer unbounded.
                start = buf.rfind(FRAME_START)
                end = buf.rfind(FRAME_END)
                if start != -1 and end != -1 and end > start:
                    with frame_lock:
                        latest_frame = bytes(buf[start:end + len(FRAME_END)])
                    frame_event.set()
                    frame_event.clear()
                    del buf[:end + len(FRAME_END)]
                elif len(buf) > 2_000_000:
                    del buf[:-200_000]  # runaway garbage, never a real frame this big
        except Exception:
            pass
        finally:
            try:
                ffmpeg_proc.terminate()
            except Exception:
                pass
        # ffmpeg died (camera unplugged, device busy, etc.) - retry, slowly.
        time.sleep(2)


class Handler(http.server.BaseHTTPRequestHandler):
    def log_message(self, fmt, *args):
        pass  # keep the plugin log (stderr redirect) quiet on normal requests

    def do_GET(self):
        if self.path.startswith("/snapshot.jpg"):
            with frame_lock:
                frame = latest_frame
            if frame is None:
                self.send_response(503)
                self.end_headers()
                return
            self.send_response(200)
            self.send_header("Content-Type", "image/jpeg")
            self.send_header("Content-Length", str(len(frame)))
            self.send_header("Cache-Control", "no-store")
            self.end_headers()
            self.wfile.write(frame)
            return

        if self.path.startswith("/stream.mjpg") or self.path == "/":
            self.send_response(200)
            self.send_header("Age", "0")
            self.send_header("Cache-Control", "no-store, private")
            self.send_header("Content-Type", "multipart/x-mixed-replace; boundary=frame")
            self.end_headers()
            try:
                while True:
                    frame_event.wait(timeout=2)
                    with frame_lock:
                        frame = latest_frame
                    if frame is None:
                        continue
                    self.wfile.write(b"--frame\r\n")
                    self.wfile.write(b"Content-Type: image/jpeg\r\n")
                    self.wfile.write(("Content-Length: %d\r\n\r\n" % len(frame)).encode())
                    self.wfile.write(frame)
                    self.wfile.write(b"\r\n")
            except (BrokenPipeError, ConnectionResetError):
                pass
            return

        self.send_response(404)
        self.end_headers()


class ThreadingHTTPServer(socketserver.ThreadingMixIn, http.server.HTTPServer):
    daemon_threads = True
    allow_reuse_address = True


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--device", required=True)
    ap.add_argument("--port", type=int, required=True)
    # Defaults kept low on purpose: this is for spotting which node lit up,
    # not a security-cam feed, and lower resolution/fps directly cuts the
    # per-frame Python-level byte shuffling cost on the Pi's CPU.
    ap.add_argument("--width", type=int, default=640)
    ap.add_argument("--height", type=int, default=480)
    ap.add_argument("--fps", type=int, default=8)
    args = ap.parse_args()

    if not re.match(r"^/dev/video[0-9]+$", args.device):
        print("Refusing non-v4l2-looking device path: %s" % args.device, file=sys.stderr)
        sys.exit(1)

    t = threading.Thread(target=capture_loop, args=(args.device, args.width, args.height, args.fps), daemon=True)
    t.start()

    server = ThreadingHTTPServer(("0.0.0.0", args.port), Handler)
    print("Serving %s as MJPEG on port %d (/stream.mjpg, /snapshot.jpg)" % (args.device, args.port))
    try:
        server.serve_forever()
    finally:
        if ffmpeg_proc:
            try:
                ffmpeg_proc.terminate()
            except Exception:
                pass


if __name__ == "__main__":
    main()
