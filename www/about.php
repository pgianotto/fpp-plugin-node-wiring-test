<div class="mt-2">
    <fieldset class="border rounded p-2">
        <legend>Node Wiring Test - Help</legend>
        <div class="p-2">
            <p>
                Pick a model and this page steps (or auto-chases) through its
                nodes one at a time, in the model's real wiring order, by
                calling FPP's own channel-test API
                (<code>POST /api/fppd/testing</code>, the same engine behind
                Status/Testing &rarr; Channel Output Testing). Each step lights
                only that node's channels and shows:
            </p>
            <ul>
                <li>the node's index (e.g. "Node 7 of 150")</li>
                <li>its expected position in the model's buffer (x/y), when the
                    model's layout can be worked out geometrically</li>
                <li>the absolute channel range being driven</li>
            </ul>
            <p>
                Watch the physical prop as you step or play through it. If the
                node that lights up physically doesn't match what's shown here
                (wrong position, wrong order, a string that's reversed or
                crossed with another), click <b>Mark this node as mismatched</b>
                to log it, optionally with a note, then export the log as a CSV
                checklist once you're done.
            </p>
            <p>
                <b>Geometry support:</b> models using a simple horizontal or
                vertical string layout, and "Custom" models with a dense or
                compressed node map, are drawn to their real x/y layout. Very
                large models, or ones FPP can't resolve a grid for, fall back
                to a plain left-to-right strip - node order and channel ranges
                are still exactly what will be commanded, they're just not
                drawn to scale.
            </p>
            <p>
                <b>Stopping:</b> clicking <b>Stop</b>, switching models, or
                leaving the page sends <code>{"enabled": 0}</code> to clear the
                test override. Nothing here is saved to FPP's configuration -
                it's the same kind of transient override as the built-in
                Channel Output Test page.
            </p>
            <h4>Camera auto-scan</h4>
            <p>
                Point a camera at the prop - either this device's own camera,
                or a USB camera physically attached to the FPP box itself
                (under "Stream/snapshot URL", <b>Detect</b> finds it and
                <b>Start Pi Camera</b> starts streaming it right from this
                page) - and click <b>Run Auto Scan</b>. It blanks the model,
                then flashes each node white
                one at a time, diffing the camera frame before/after each
                flash to find where it lit up. No separate calibration step
                is needed: the whole scan's detections are fit to the
                model's expected layout at once (a position mapping that's
                robust to a handful of actually-miswired nodes dragging it
                off), which both aligns the overlay and flags any node whose
                detected position disagrees with the fit.
            </p>
            <p>
                Each flagged node is matched to whichever other node's
                expected position its camera point is actually closest to -
                shown as "physically here instead" in the results table.
                <b>Download corrected .xmodel</b> builds a standalone xLights
                Custom Model with every node placed at its real detected
                position, so you can re-import it in place of the original
                without re-wiring anything.
            </p>
            <p>
                <b>Limitations:</b> this only works for models with real 2D
                geometry (not the plain wiring-order fallback strip), needs
                decent, fairly even lighting/contrast between "off" and "on"
                so the diff is clean, and assumes the camera and ambient
                light don't move/change mid-scan.
            </p>
            <p>
                <b>Pi USB camera:</b> Start Pi Camera runs a small bundled
                script (ffmpeg + a Python MJPEG server) against the USB
                camera you picked, reachable at
                <code>http://&lt;this device&gt;:8091/stream.mjpg</code> on
                your local network for as long as it's running. It is not a
                system service and does not start on boot - stop it with
                <b>Stop Pi Camera</b> when you're done, or it'll keep running
                (holding the camera device open) until fppd restarts.
            </p>
        </div>
    </fieldset>

    <fieldset class="border rounded p-2 mt-2">
        <legend>Node Wiring Test Info</legend>
        <div class="p-2">
            <div id='credits'>
                <a href='https://github.com/pgianotto/fpp-plugin-node-wiring-test'>Git Repository</a><br>
                <a href='https://github.com/pgianotto/fpp-plugin-node-wiring-test/issues'>Bug Reporter</a><br>
            </div>
        </div>
    </fieldset>
</div>
