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
