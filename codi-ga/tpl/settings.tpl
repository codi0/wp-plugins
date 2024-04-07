<div class="wrap">
	<h2>Cookieless Google Analytics</h2>
	<p>Add Google Analytics to your website without using any cookies or client side storage. <a href="https://gist.github.com/codi0/f104742e70377b1a1c6f40e525a9586d" target="_blank">Learn more &raquo;</a></p>
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
				<td width="150">Skip capabilities<br>(E.g. manage_options)</td>
				<td><input type="text" name="codi_ga[skip_roles]" value="<?=$data['skip_roles']?>"></td>
			</tr>
		</table>
		<br><br>
		<input type="submit" class="button button-primary" value="Save Changes">
	</form>
</div>