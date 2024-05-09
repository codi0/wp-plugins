<div class="wrap">
	<h2>Google Analytics Privacy</h2>
	<p>Add Google Analytics to your website that respsects 'Do Not Track' and 'Global Privacy Control' standards.</p>
	<form method="post">
		<?php wp_nonce_field($page); ?>
		<table class="form-table">
			<tr>
				<td width="150">Measurement ID</td>
				<td><input type="text" name="codi_ga[id]" value="<?=$data['id']?>"></td>
			</tr>
			<tr>
				<td width="150">Track admin area?</td>
				<td><input type="checkbox" name="codi_ga[admin]" value="1"<?=($data['admin'] == 1 ? ' checked' : '')?>></td>
			</tr>
			<tr>
				<td width="150">Skip capabilities</td>
				<td>
					<input type="text" name="codi_ga[skip_roles]" value="<?=$data['skip_roles']?>" size="50">
					<br>
					<small>Use a comma separate list, such as: <b>manage_options, edit_others_posts</b></small>
				</td>
			</tr>
		</table>
		<br><br>
		<input type="submit" class="button button-primary" value="Save Changes">
	</form>
</div>