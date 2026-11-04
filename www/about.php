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
                <b>Preview toolbar:</b> above the plain layout preview,
                <b>Front</b>/<b>Back</b> mirrors the layout left/right for
                working from behind the prop (display only - it never changes
                wiring order or channels); <b>Show wiring path</b> draws a
                line connecting nodes in real wiring order (0, 1, 2, ...) so
                you can see the physical wire run, not just where each node
                sits; and the zoom controls (or Ctrl/Cmd+scroll over the
                preview) zoom in on dense models - very large models are
                capped in how far they can zoom to avoid an oversized canvas.
                Hover any node in the preview to see its number in a tooltip,
                handy for filling in "Node that actually lit" above without
                losing count.
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
            <p>
                <b>Fixing wiring by eye, without a camera:</b> if you'd rather
                just watch the prop yourself while stepping through it, fill
                in <b>Node that actually lit</b> with the real node number
                before clicking <b>Mark this node as mismatched</b> - e.g. if
                you command node 7 and physically see node 12 light up
                instead, step 7 says "actually lit: 12". Once you've gone
                through the whole prop this way, <b>Download corrected
                .xmodel (from this log)</b> (under the Mismatch log) builds
                the same kind of corrected <code>.xmodel</code> the camera
                auto-scan would, placing every corrected node at its real
                physical position - no camera involved at all. Entries left
                with just a note and no number aren't placement data, so they
                don't affect the export; every node you didn't correct keeps
                its originally-wired position.
            </p>
            <h4>Live camera overlay</h4>
            <p>
                Whenever a camera and a model are both active, the camera view
                shows a live overlay of the model's shape right on top of the
                video - a blue reference grid by default. Drag on the camera
                image to position it over the real prop, and use the
                <b>Size</b> slider to scale it to match; <b>Reset position</b>
                starts over. As you step or play through nodes, the node
                currently commanded shows up highlighted in yellow on the
                overlay, in its expected position - so you can watch the
                physical prop and the overlay side by side and see at a
                glance whether they agree, without running a full scan.
                Once an auto-scan has completed, the overlay switches to the
                scan's own (usually more accurate) calibration automatically,
                with detected nodes shown in green (matches) or red
                (mismatches) instead of the plain reference grid.
            </p>
            <h4>Camera auto-scan</h4>
            <p>
                <b>The camera needs to actually see the physical prop while
                it's being flashed</b> - pointing it at yourself, your desk,
                or anything else that isn't the prop gives the scan nothing
                real to detect. It works by diffing the camera image before
                and after each node flashes, so with no real light change in
                frame it just picks up noise (room lighting, auto-exposure,
                movement) and produces a scrambled, meaningless result -
                every node showing as a mismatch matched to some unrelated
                node is the signature of this, not a bug to chase.
            </p>
            <p>
                Point a camera at the prop - either this device's own camera,
                or a USB camera physically attached to the FPP box itself
                (under "A USB camera on this FPP device", <b>Detect</b> finds
                it and <b>Start USB Camera</b> starts streaming it right from
                this page) - and click <b>Run Auto Scan</b>. It blanks the model,
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
                <b>USB camera on this device:</b> Start USB Camera runs a
                small bundled script (ffmpeg + a Python MJPEG server)
                against the USB camera you picked - standard Linux/V4L2
                tooling, so this works the same on a Raspberry Pi, a
                BeagleBone, or any other apt-based FPP platform - reachable
                at <code>http://&lt;this device&gt;:8091/snapshot.jpg</code>
                (and as a continuous stream at <code>/stream.mjpg</code>, for
                tools that read it directly) on your local network for as
                long as it's running. The page itself polls
                <code>/snapshot.jpg</code> every ~400ms rather than embedding
                the stream directly - Chrome (and others, at various points)
                doesn't reliably render a continuous MJPEG stream in an
                &lt;img&gt; tag, a known issue for mjpg-streamer users too; a
                plain single-image endpoint, polled, works everywhere. It is
                not a system service and does not start on boot - stop it
                with <b>Stop Camera</b> when you're done, or it'll keep
                running (holding the camera device open) until fppd
                restarts.
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
