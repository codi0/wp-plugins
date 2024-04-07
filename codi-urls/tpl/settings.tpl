<div class="wrap">
	<?php
	if(isset($_POST[$page])) {
		if($_POST['action'] === 'redirect') {
			if($data['res']) {
				echo '<p class="notice notice-success">.htaccess file updated</p>';
			} else {
				echo '<p class="notice notice-error">Unable to write to .htaccess file</p>';
			}
		} else {
			if($data['old'] && $data['new']) {
				echo '<p class="notice notice-success">' . $data['res'] . ' posts updated</p>';
			} else {
				echo '<p class="notice notice-error">Please add both an old and new link</p>';
			}
		}
	}
	?>
	<h2>Bulk update</h2>
	<p>Update out of date URLs in all posts or pages at once.</p>
	<form method="post">
		<input type="hidden" name="action" value="replace">
		<?php wp_nonce_field($page); ?>
		<table class="form-table">
			<tr>
				<td>Old URL</td>
				<td><input type="text" name="<?=$page?>[old]" value="<?=$data['old']?>" size="50"></td>
			</tr>
			<tr>
				<td>New URL</td>
				<td><input type="text" name="<?=$page?>[new]" value="<?=$data['new']?>" size="50"></td>
			</tr>
		</table>
		<br>
		<input type="submit" class="button button-primary" value="Save Changes">
	</form>
	<br><br>
	<h2>.htaccess redirects</h2>
	<p>Setup redirects for old URLs. Changes may require a system restart to take effect, depending on your web server.</p>
	<form method="post">
		<input type="hidden" name="action" value="redirect">
		<?php wp_nonce_field($page); ?>
		<table class="form-table">
			<tr>
				<td>Code</td>
				<td>Old URL</td>
				<td>New URL</td>
			</tr>
			<?php $key = -1; foreach($data['redirects'] as $key => $redirect) { ?>
			<tr>
				<td>
					<select name="<?=$page?>[redirects][<?=$key?>][code]">
						<option value="301"<?=($redirect['code'] == 301 ? ' selected' : '')?>>301</option>
						<option value="302"<?=($redirect['code'] == 302 ? ' selected' : '')?>>302</option>
					</select>
				</td>
				<td>
					<input type="text" name="<?=$page?>[redirects][<?=$key?>][old]" value="<?=$redirect['old']?>" size="50">
				</td>
				<td>
					<input type="text" name="<?=$page?>[redirects][<?=$key?>][new]" value="<?=$redirect['new']?>" size="50">
				</td>
			</tr>
			<?php } ?>
			<tr>
				<td>
					<select name="<?=$page?>[redirects][<?=++$key?>][code]">
						<option value="301">301</option>
						<option value="302">302</option>
					</select>
				</td>
				<td>
					<input type="text" name="<?=$page?>[redirects][<?=$key?>][old]" value="" size="50">
				</td>
				<td>
					<input type="text" name="<?=$page?>[redirects][<?=$key?>][new]" value="" size="50">
				</td>
			</tr>
		</table>
		<br>
		<input type="submit" class="button button-primary" value="Save Changes">
	</form>
</div>