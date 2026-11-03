<?php
/*
 * Plugin API endpoints for Node Wiring Test's optional Pi-side camera
 * stream: list candidate USB cameras, and start/stop the bundled
 * backend/camera_stream.py (an MJPEG-over-HTTP server for one V4L2 device),
 * so the "Stream/snapshot URL" source in www/index.php can point at a
 * camera physically attached to this FPP box instead of needing one
 * already running.
 *
 * All endpoints are under /api/plugin/fpp-plugin-node-wiring-test/...
 */

function getEndpointsfpppluginnodewiringtest() {
    $result = array();

    $result[] = array('method' => 'GET', 'endpoint' => 'camera/devices', 'callback' => 'nwtCameraDevices');
    $result[] = array('method' => 'GET', 'endpoint' => 'camera/status', 'callback' => 'nwtCameraStatus');
    $result[] = array('method' => 'POST', 'endpoint' => 'camera/start', 'callback' => 'nwtCameraStart');
    $result[] = array('method' => 'POST', 'endpoint' => 'camera/stop', 'callback' => 'nwtCameraStop');
    $result[] = array('method' => 'GET', 'endpoint' => 'camera/frame', 'callback' => 'nwtCameraFrame');

    return $result;
}

function nwtPluginDir() {
    return dirname(__FILE__);
}

function nwtDataDir() {
    // Outside the plugin directory on purpose (PLUGIN_GUIDELINES.md 2.12):
    // state here must survive a plugin Reinstall, which deletes and
    // re-clones the plugin directory itself.
    $dir = '/home/fpp/media/plugindata/fpp-plugin-node-wiring-test';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

function nwtPidFile() {
    return nwtDataDir() . '/camera_stream.pid';
}

function nwtRunningPid() {
    $pidFile = nwtPidFile();
    if (!file_exists($pidFile)) {
        return null;
    }
    $pid = (int) trim(file_get_contents($pidFile));
    if ($pid > 0 && file_exists("/proc/$pid")) {
        return $pid;
    }
    @unlink($pidFile);
    return null;
}

// GET /api/plugin/fpp-plugin-node-wiring-test/camera/devices
//
// Lists V4L2 capture devices whose v4l2-ctl --list-devices entry looks like
// an actual USB camera (card name grouped under a "usb-..." bus id), as
// opposed to the Pi's own platform codec/ISP video nodes, which share the
// /dev/videoN namespace but aren't cameras.
function nwtCameraDevices() {
    $out = array();
    exec('v4l2-ctl --list-devices 2>/dev/null', $lines, $rc);
    if ($rc !== 0 || empty($lines)) {
        return json(array('status' => 'OK', 'devices' => array()));
    }
    $currentName = null;
    $currentIsUsb = false;
    foreach ($lines as $line) {
        if ($line !== '' && $line[0] !== "\t" && $line[0] !== ' ') {
            $currentName = trim($line);
            $currentIsUsb = (strpos($currentName, '(usb-') !== false);
        } else if ($currentIsUsb) {
            $dev = trim($line);
            if (preg_match('/^\/dev\/video[0-9]+$/', $dev)) {
                $out[] = array('device' => $dev, 'name' => $currentName);
            }
        }
    }
    return json(array('status' => 'OK', 'devices' => $out));
}

// GET /api/plugin/fpp-plugin-node-wiring-test/camera/status
function nwtCameraStatus() {
    $pid = nwtRunningPid();
    $infoFile = nwtDataDir() . '/camera_stream.json';
    $info = array();
    if ($pid && file_exists($infoFile)) {
        $info = json_decode(file_get_contents($infoFile), true);
        if (!is_array($info)) { $info = array(); }
    }
    $result = array('status' => 'OK', 'running' => $pid !== null, 'pid' => $pid);
    return json(array_merge($result, $info));
}

// POST /api/plugin/fpp-plugin-node-wiring-test/camera/start
// Body: {"device": "/dev/video1", "port": 8091, "width": 1280, "height": 720, "fps": 15}
function nwtCameraStart() {
    if (nwtRunningPid() !== null) {
        return json(array('status' => 'ERROR', 'message' => 'Already running. Stop it first.'));
    }

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) { $body = array(); }

    $device = isset($body['device']) ? $body['device'] : '';
    $port = isset($body['port']) ? (int) $body['port'] : 8091;
    $width = isset($body['width']) ? (int) $body['width'] : 640;
    $height = isset($body['height']) ? (int) $body['height'] : 480;
    $fps = isset($body['fps']) ? (int) $body['fps'] : 8;

    if (!preg_match('/^\/dev\/video[0-9]+$/', $device) || !file_exists($device)) {
        return json(array('status' => 'ERROR', 'message' => 'Invalid or missing camera device.'));
    }
    if ($port < 1024 || $port > 65535) {
        return json(array('status' => 'ERROR', 'message' => 'Port must be between 1024 and 65535.'));
    }
    if ($width < 160 || $width > 3840 || $height < 120 || $height > 2160) {
        return json(array('status' => 'ERROR', 'message' => 'Width/height out of range.'));
    }
    if ($fps < 1 || $fps > 60) {
        return json(array('status' => 'ERROR', 'message' => 'FPS must be between 1 and 60.'));
    }

    $script = nwtPluginDir() . '/backend/camera_stream.py';
    $logFile = nwtDataDir() . '/camera_stream.log';

    // Argument list is fixed-position and every value is pre-validated above
    // (device against a strict path pattern, the rest as bounded integers),
    // but still shell-escaped since this becomes a real shell command line.
    // setsid makes the python process its own session/process-group leader
    // (pgid == its own pid), so camera/stop's `kill -TERM -$pid` (a negative
    // pid signals the whole group) also reaches the ffmpeg child it spawns -
    // without this, ffmpeg would be left holding the camera device open
    // after a stop.
    $cmd = sprintf(
        'setsid python3 %s --device %s --port %d --width %d --height %d --fps %d >> %s 2>&1 < /dev/null & echo $!',
        escapeshellarg($script),
        escapeshellarg($device),
        $port, $width, $height, $fps,
        escapeshellarg($logFile)
    );
    $pid = (int) trim(shell_exec($cmd));
    if ($pid <= 0) {
        return json(array('status' => 'ERROR', 'message' => 'Failed to start camera_stream.py.'));
    }
    file_put_contents(nwtPidFile(), (string) $pid);
    file_put_contents(nwtDataDir() . '/camera_stream.json', json_encode(array(
        'device' => $device, 'port' => $port, 'width' => $width, 'height' => $height, 'fps' => $fps
    )));

    // Give ffmpeg/the HTTP server a moment to come up before the caller
    // tries to load the stream URL.
    usleep(500000);

    return json(array('status' => 'OK', 'running' => true, 'pid' => $pid, 'port' => $port));
}

// POST /api/plugin/fpp-plugin-node-wiring-test/camera/stop
function nwtCameraStop() {
    $pid = nwtRunningPid();
    if ($pid === null) {
        return json(array('status' => 'OK', 'running' => false, 'message' => 'Not running.'));
    }
    // Kill the whole process group: camera_stream.py spawns ffmpeg as a
    // child, and killing just the parent pid would orphan it holding the
    // camera device open.
    shell_exec('kill -TERM -' . intval($pid) . ' 2>/dev/null');
    shell_exec('kill -TERM ' . intval($pid) . ' 2>/dev/null');
    usleep(300000);
    @unlink(nwtPidFile());
    return json(array('status' => 'OK', 'running' => false));
}

// GET /api/plugin/fpp-plugin-node-wiring-test/camera/frame
//
// Proxies one JPEG frame from the locally-running camera_stream.py. The
// page polls THIS same-origin endpoint rather than fetching
// http://<device>:<port>/snapshot.jpg directly, because FPP's own Apache
// Content-Security-Policy (img-src 'self') blocks an <img> from loading a
// different port on the same host - confirmed live (FPP-Mickey): the
// browser console reported exactly that CSP violation against the direct
// port-8091 URL, even though the endpoint itself served a valid image.
// Fetching it server-side here and re-serving it under the page's own
// origin sidesteps that without touching Apache's CSP config at all.
function nwtCameraFrame() {
    $infoFile = nwtDataDir() . '/camera_stream.json';
    $pid = nwtRunningPid();
    if ($pid === null || !file_exists($infoFile)) {
        http_response_code(503);
        return '';
    }
    $info = json_decode(file_get_contents($infoFile), true);
    $port = (is_array($info) && isset($info['port'])) ? (int) $info['port'] : 0;
    if ($port <= 0) {
        http_response_code(503);
        return '';
    }
    $ctx = stream_context_create(array('http' => array('timeout' => 2)));
    $data = @file_get_contents('http://127.0.0.1:' . $port . '/snapshot.jpg', false, $ctx);
    if ($data === false) {
        http_response_code(503);
        return '';
    }
    send_header('Content-Type: image/jpeg');
    send_header('Cache-Control: no-store');
    return $data;
}
?>
