jQuery(document).ready(function($) {

	$('[data-story]').on('click', 'div.snippet', function() {
		//set vars
		var div = $(this);
		var content = div.html().replace('&nbsp;', ' ');
		var attr = div.prop("attributes");
		var textarea = $("<textarea />");
		//copy attributes
		$.each(attr, function() {
			textarea.attr(this.name, this.value);
		});
		//set height
		textarea.height(div.height() + 30);
		//replace element
		textarea.val(content);
		div.replaceWith(textarea);
		//focus element
		textarea.focus();
		//setup blur event
		textarea.on('blur', function() {
			//set vars
			var textarea = $(this);
			var content = textarea.val();
			var attr = textarea.prop("attributes");
			var div = $("<div />");
			//copy attributes
			$.each(attr, function() {
				div.attr(this.name, this.value);
			});
			//remove height
			div.css('height', '');
			//replace element
			div.html(content);
			textarea.replaceWith(div);
			//ajax data
			var data = {
				id: div.closest('[data-story]').attr('data-story'),
				snippet: content,
				nonce: $('#_wpnonce').val()
			};
			//save update
			wp.ajax.post('roundup_save_story', data).done(function(response) {
				console.log(response);
			});
		});
	});

});