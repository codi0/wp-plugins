<div class="wrap">
	<h2><?= $log ?></h2>
	<p><a href="<?=remove_query_arg('log')?>">&laquo; Back to settings</a></p>
	<?php
	foreach($logFiles as $file) {
		//skip file?
		if(basename($file) !== $log) {
			return;
		}
		//display log data
		echo str_replace("\n", "<br>", htmlentities(file_get_contents($file), ENT_QUOTES, 'UTF-8'));
	}
	?>
</div>