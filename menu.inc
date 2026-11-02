<?php

# $menuEntries is an array of menu entries for this plugin - one entry per
# menu type (status/content/output/help) at most. This plugin drives output
# test-mode on a model, so it lives under 'output'; a short help page gets
# its own 'help' entry.

$menuEntries = Array(
	Array(
		'text' => 'Node Wiring Test',
		'type' => 'output',
		'page' => 'www/index.php',
		'wrap' => 1
	),
	Array(
		'text' => 'Node Wiring Test - Help',
		'type' => 'help',
		'page' => 'www/about.php',
		'wrap' => 1
	)
);

foreach ($menuEntries as $entry)
{
	if ($entry['type'] != $menu)
		continue;

	if (preg_match('/^http.?:\/\//', $entry['page']))
	{
		printf("<li><a href='%s' target='_blank'>%s</a></li>\n",
			$entry['page'], $entry['text']);
	}
	else
	{
		$nopage = '';
		if (isset($entry['wrap']) && ($entry['wrap'] == 0))
			$nopage = '&nopage=1';

		printf("<li><a href='plugin.php?plugin=%s&page=%s%s'>%s</a></li>\n",
			$plugin, $entry['page'], $nopage, $entry['text']);
	}
}
?>
