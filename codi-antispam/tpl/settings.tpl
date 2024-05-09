<div class="wrap">
	<h2>Cloudflare Turnstile</h2>
	<p>Automatically protects all WordPress front-end forms using Turnstile challenges.</p>
	<form method="post">
		<?php wp_nonce_field($page); ?>
		<table class="form-table">
			<tr>
				<td width="100">Site Key</td>
				<td><input type="text" name="<?=$page?>[site_key]" value="<?=$data['site_key']?>" size="50"></td>
			</tr>
			<tr>
				<td>Secret Key</td>
				<td><input type="text" name="<?=$page?>[secret_key]" value="<?=$data['secret_key']?>" size="50"></td>
			</tr>
			<tr>
				<td>Logging</td>
				<td>
					<table>
						<tr>
							<td>Interactive challenges?</td>
							<td><input type="checkbox" name="<?=$page?>[log_interactive]" value="1"<?=($data['log_interactive'] ? ' checked' : '')?>></td>
						</tr>
						<tr>
							<td>Challenge failures?</td>
							<td><input type="checkbox" name="<?=$page?>[log_failures]" value="1"<?=($data['log_failures'] ? ' checked' : '')?>></td>
						</tr>
						<tr>
							<td>Cloudflare timeouts?</td>
							<td><input type="checkbox" name="<?=$page?>[log_timeouts]" value="1"<?=($data['log_timeouts'] ? ' checked' : '')?>></td>
						</tr>
					</table>
				</td>
			</tr>
			<tr>
				<td></td>
				<td><input type="submit" class="button button-primary" value="Save Changes"></td>
			</tr>
		</table>
	</form>
	<br><br>
	<h2>Logs</h2>
	<?php
	if($logFiles) {
		echo '<table class="wp-list-table widefat striped fixed">' . "\n";
		echo '<tr>' . "\n";
		echo '<th>Name</th>' . "\n";
		echo '<th>Records Count</th>' . "\n";
		echo '<th>Actions</th>' . "\n";
		echo '</tr>' . "\n";
		foreach($logFiles as $file) {
			echo '<tr>' . "\n";
			echo '<td>' . basename($file) . '</td>' . "\n";
			echo '<td>' . count(file($file)) . '</td>' . "\n";
			echo '<td><a href="' . add_query_arg('log', basename($file)) . '">View log</a></td>' . "\n";
			echo '</tr>' . "\n";
		}
		echo '</table>' . "\n";
	} else {
		echo '<p>No log records found</p>' . "\n";
	}
	?>
</div>