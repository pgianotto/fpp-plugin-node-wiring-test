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

    $result[] = array('method' => 'GET', 'endpoint' => 'vendor-models', 'callback' => 'nwtVendorModels');
    $result[] = array('method' => 'GET', 'endpoint' => 'vendor-xmodel', 'callback' => 'nwtVendorXModel');

    return $result;
}

function nwtPluginDir() {
    return dirname(__FILE__);
}

function nwtDataDir() {
    // Outside the plugin directory on purpose (PLUGIN_GUIDELINES.md 2.12):
    // state here must survive a plugin Reinstall, which deletes and
    // re-clones the plugin directory itself. Resolved via FPP's own
    // mediaDirectory setting, not hard-coded, so a relocated media
    // directory still works (PLUGIN_GUIDELINES.md 1.1 makes the same point
    // about logDirectory).
    global $settings;
    $media = (isset($settings['mediaDirectory']) && $settings['mediaDirectory'] !== '')
        ? $settings['mediaDirectory'] : '/home/fpp/media';
    $dir = $media . '/plugindata/fpp-plugin-node-wiring-test';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

function nwtPidFile() {
    return nwtDataDir() . '/camera_stream.pid';
}

// PLUGIN_GUIDELINES.md 1.1: exactly one runtime log file, named
// plugin-<repoName>.log, in FPP's own logs directory (resolved via FPP, not
// hard-coded) - not a file of our own under plugindata/.
function nwtLogFile() {
    global $settings;
    $dir = (isset($settings['logDirectory']) && $settings['logDirectory'] !== '')
        ? $settings['logDirectory'] : '/home/fpp/media/logs';
    return $dir . '/plugin-fpp-plugin-node-wiring-test.log';
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
    $logFile = nwtLogFile();

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

// ---- Vendor model catalog -------------------------------------------
//
// Lets the plugin offer "download a corrected .xmodel that still has this
// vendor model's faces/states/submodels" by sourcing the real vendor file
// instead of generating a blank one. The catalog itself is xLights' own:
// a central index (xlights_vendors.xml) listing participating vendors,
// each with their own inventory file listing models and, per model, one or
// more <wiring> options with a real downloadable .xmodel/.zip link - the
// exact mechanism xLights' own "Download Models" dialog uses (confirmed
// against xLights' actual source, src-core/import_export/VendorCatalog.cpp,
// and against the real public files it fetches). Nothing here is hardcoded
// from that source beyond the index URL itself - staying in sync with
// xLights' own list (new vendors, new models) is just this cache expiring
// and being refetched, not a plugin update.
//
// Fetched and cached here (not by the browser) for two reasons: FPP's own
// Content-Security-Policy would block a direct browser fetch to most of
// these vendor domains (same reasoning as camera/frame above), and vendor
// servers don't send CORS headers for an arbitrary cross-origin browser
// fetch anyway.

define('NWT_VENDOR_INDEX_URL', 'https://raw.githubusercontent.com/xLightsSequencer/xLights/master/download/xlights_vendors.xml');
define('NWT_VENDOR_CACHE_TTL', 24 * 60 * 60);       // re-check the catalog daily, like xLights itself
define('NWT_VENDOR_MODEL_CACHE_TTL', 30 * 24 * 60 * 60); // individual .xmodel files change rarely

function nwtVendorCacheFile() {
    return nwtDataDir() . '/vendor-catalog.json';
}

function nwtVendorModelCacheDir() {
    $dir = nwtDataDir() . '/vendor-models';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    return $dir;
}

// Plain file_get_contents+stream_context, matching camera/frame's existing
// style in this file rather than adding a curl dependency this plugin
// doesn't otherwise need. PHP's https:// wrapper verifies certs by default.
function nwtHttpGet($url, $timeoutSeconds) {
    $ctx = stream_context_create(array('http' => array(
        'timeout' => $timeoutSeconds,
        'header' => "User-Agent: fpp-plugin-node-wiring-test (+https://github.com/pgianotto/fpp-plugin-node-wiring-test)\r\n",
        'follow_location' => 1,
        'max_redirects' => 3,
    )));
    return @file_get_contents($url, false, $ctx);
}

// Builds the flattened {vendor, modelName, wiringName, xmodelLink, ...} list
// from the live index + every vendor's inventory, skipping (not aborting
// on) any single vendor whose file is unreachable or unparsable - one dead
// vendor site shouldn't take the rest of the catalog down with it, same as
// xLights' own loader.
function nwtBuildVendorCatalog() {
    set_time_limit(180); // several sequential vendor-site fetches

    $indexXml = nwtHttpGet(NWT_VENDOR_INDEX_URL, 10);
    if ($indexXml === false) { return null; }
    $index = @simplexml_load_string($indexXml);
    if ($index === false) { return null; }

    $entries = array();
    foreach ($index->children() as $v) {
        if ($v->getName() !== 'vendor') { continue; } // skip <musicvendor> etc.
        $vendorName = trim((string) $v->name);
        $link = trim((string) $v->link);
        $maxModels = isset($v->maxmodels) ? (int) $v->maxmodels : -1;
        if ($vendorName === '' || $link === '') { continue; }

        $invXml = nwtHttpGet($link, 8);
        if ($invXml === false) { continue; }
        $inv = @simplexml_load_string($invXml);
        if ($inv === false || $inv->getName() !== 'modelinventory') { continue; }

        $count = 0;
        foreach ($inv->models->model as $model) {
            if ($maxModels >= 1 && $count >= $maxModels) { break; }
            $count++;
            $modelName = trim((string) $model->name);
            foreach ($model->wiring as $wiring) {
                $xmodelLink = trim((string) $wiring->xmodellink);
                if ($xmodelLink === '') { continue; }
                $entries[] = array(
                    'vendor' => $vendorName,
                    'modelName' => $modelName,
                    'modelType' => trim((string) $model->type),
                    'wiringName' => trim((string) $wiring->name),
                    'xmodelLink' => $xmodelLink,
                );
            }
        }
    }
    return $entries;
}

// GET /api/plugin/fpp-plugin-node-wiring-test/vendor-models?refresh=1
function nwtVendorModels() {
    $cacheFile = nwtVendorCacheFile();
    $forceRefresh = isset($_GET['refresh']) && $_GET['refresh'] == '1';

    if (!$forceRefresh && file_exists($cacheFile) && (time() - filemtime($cacheFile)) < NWT_VENDOR_CACHE_TTL) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if (is_array($cached)) {
            return json(array('status' => 'OK', 'cached' => true, 'models' => $cached));
        }
    }

    $entries = nwtBuildVendorCatalog();
    if ($entries === null) {
        // Network/parse failure - fall back to a stale cache rather than an
        // empty result, if one exists.
        if (file_exists($cacheFile)) {
            $cached = json_decode(file_get_contents($cacheFile), true);
            if (is_array($cached)) {
                return json(array('status' => 'OK', 'cached' => true, 'stale' => true, 'models' => $cached));
            }
        }
        return json(array('status' => 'ERROR', 'message' => 'Could not reach the xLights vendor catalog.'));
    }

    file_put_contents($cacheFile, json_encode($entries));
    return json(array('status' => 'OK', 'cached' => false, 'models' => $entries));
}

// GET /api/plugin/fpp-plugin-node-wiring-test/vendor-xmodel?url=<xmodelLink>
//
// Only ever fetches a URL that's actually present in the cached catalog
// (built entirely from xLights' own vendor index above) - never an
// arbitrary caller-supplied URL - so this can't be turned into an open
// fetch proxy for anywhere else on the internet.
function nwtVendorXModel() {
    $url = isset($_GET['url']) ? trim($_GET['url']) : '';
    if ($url === '') {
        http_response_code(400);
        return 'Missing url.';
    }

    $cacheFile = nwtVendorCacheFile();
    if (!file_exists($cacheFile)) {
        http_response_code(409);
        return 'Vendor catalog not loaded yet - fetch vendor-models first.';
    }
    $catalog = json_decode(file_get_contents($cacheFile), true);
    $known = false;
    if (is_array($catalog)) {
        foreach ($catalog as $entry) {
            if (isset($entry['xmodelLink']) && $entry['xmodelLink'] === $url) { $known = true; break; }
        }
    }
    if (!$known) {
        http_response_code(403);
        return 'That URL is not in the cached vendor catalog.';
    }

    $isZip = (strtolower(substr($url, -4)) === '.zip');
    $cachePath = nwtVendorModelCacheDir() . '/' . sha1($url) . ($isZip ? '.zip' : '.xmodel');

    if (!file_exists($cachePath) || (time() - filemtime($cachePath)) >= NWT_VENDOR_MODEL_CACHE_TTL) {
        $data = nwtHttpGet($url, 15);
        if ($data === false) {
            http_response_code(502);
            return 'Could not download that model from the vendor.';
        }
        file_put_contents($cachePath, $data);
    }

    if (!$isZip) {
        send_header('Content-Type: application/xml');
        send_header('Cache-Control: no-store');
        return file_get_contents($cachePath);
    }

    if (!class_exists('ZipArchive')) {
        http_response_code(501);
        return "This vendor packages the model as a .zip, and PHP's zip extension isn't available on this FPP box.";
    }
    $zip = new ZipArchive();
    if ($zip->open($cachePath) !== true) {
        http_response_code(502);
        return 'Could not open the downloaded .zip file.';
    }
    $xml = null;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (preg_match('/\.xmodel$/i', $name)) {
            $xml = $zip->getFromIndex($i);
            break;
        }
    }
    $zip->close();
    if ($xml === null) {
        http_response_code(502);
        return 'No .xmodel file found inside the vendor .zip.';
    }
    send_header('Content-Type: application/xml');
    send_header('Cache-Control: no-store');
    return $xml;
}
?>
