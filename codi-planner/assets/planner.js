(function() {

	//Helper: animate
	$.fn.plannerAnimate = function(effect, opts = {}) {
		//set vars
		var isIn = /(^|\s|\-)in(\s|\-|$)/.test(effect);
		var isOut = /(^|\s|\-)out(\s|\-|$)/.test(effect);
		//onStart listener
		var onStart = function(e) {
			//onStart callback?
			opts.onStart && opts.onStart(e);
			//remove listener
			this.removeEventListener('transitionstart', onStart);
		};
		//onEnd listener
		var onEnd = function(e) {
			//hide element?
			isOut && this.classList.add('planner-hidden');
			//reset classes
			this.classList.remove('planner-animate');
			this.classList.remove.apply(this.classList, effect.split(/\s+/g));
			//onEnd callback?
			opts.onEnd && opts.onEnd(e);
			//remove listeners
			this.removeEventListener('transitionend', onEnd);
			this.removeEventListener('transitioncancel', onEnd);
		};
		//loop through elements
		for(var i=0; i < this.length; i++) {
			//use closure
			(function(el) {
				//is hidden?
				var isHidden = el.classList.contains('planner-hidden');
				//infer direction?
				if(!isIn && !isOut) {
					isOut = !isHidden;
				}
				//stop here?
				if((isOut && isHidden) || (isIn && !isHidden)) {
					return;
				}
				//register listeners
				el.addEventListener('transitionstart', onStart);
				el.addEventListener('transitionend', onEnd);
				el.addEventListener('transitioncancel', onEnd);
				//prep animation
				isOut && el.classList.add('planner-animate');
				!isOut && el.classList.add.apply(el.classList, effect.split(/\s+/g));
				//start animation
				requestAnimationFrame(function() {
					requestAnimationFrame(function() {
						//apply classes
						isOut && el.classList.add.apply(el.classList, effect.split(/\s+/g));
						isOut && el.classList.add('out');
						!isOut && el.classList.add('planner-animate');
						!isOut && el.classList.remove('planner-hidden');
						//manually fire listeners?
						if(window.getComputedStyle(el, null).getPropertyValue('transition') === 'all 0s ease 0s') {
							onStart.call(el);
							onEnd.call(el);
						}
					});
				});
			})(this[i]);
		}
		//chain it
		return this;
	};

	//Helper: overlay
	$.fn.plannerOverlay = function(text, opts = {}) {
		//set vars
		var html = '';
		var that = this;
		//overlay html
		html += '<div class="planner-overlay planner-hidden">';
		html += '<div class="inner">';
		html += '<div class="head">';
		html += '<div class="title">' + (opts.title || '') + '</div>';
		if(opts.close !== false) {
			html += '<div class="close" data-close="true">X</div>';
		}
		html += '</div>';
		html += '<div class="body">' + text + '</div>';
		html += '</div>';
		html += '</div>';
		//return html?
		if(opts.html) return html;
		//loop through nodes
		for(var i=0; i < this.length; i++) {
			//create overlay
			var overlay = $.parseHTML(html)[0];
			//append overlay
			this[i].appendChild(overlay);
		}
		//start animation
		that.find('.planner-overlay').plannerAnimate('fade in', {
			onEnd: function() {
				//add close listener
				that.find('.planner-overlay [data-close]').on('click', function(e) {
					//get overlay
					var o = $(this).closest('.planner-overlay');
					//animate and close
					o.plannerAnimate('fade out', {
						onEnd: function() {
							o.remove();
						}
					});
				});
			}
		});
		//chain it
		return this;
	};

	//Helper: display notice
	var displayNotice = function(type, msg, fade = 3000) {
		//generate html
		var html = '<div class="planner-notice ' + type + '">' + msg + '</div>';
		//append to body
		$(html).hide().appendTo("body").fadeIn(600);
		//delayed fade out
		$(".planner-notice").delay(fade).fadeOut(600, function() {
			$(this).remove();
		});
	};

	//Helper: save post
	var savePost = function(silent = false) {
		//wrap in promise
		return new Promise(function(resolve, reject) {
			//get form
			var form = $('form[name="planner-edit"]');
			//disable button
			var submit = form.find('[type="submit"]');
			submit.prop('disabled', true);
			//fields to check
			var fields = [ 'post_title', 'post_content', 'post_parent', 'planner_assigned', 'planner_status' ];
			//post data
			var data = {
				action: "planner_edit",
				ID: $('div.planner').attr('data-post'),
				nonce: $('div.planner').attr('data-nonce')
			};
			//add additional data
			for(var i=0; i < fields.length; i++) {
				data[fields[i]] = form[0].elements[fields[i]] ? form[0].elements[fields[i]].value : null;
			}
			//check tinymce?
			if(window.tinyMCE) {
				//get content editor
				var editor = tinyMCE.get('post_content');
				//set content?
				if(editor) {
					data['post_content'] = editor.getContent().trim();
				}
			}
			//make request
			$.ajax({
				type: "POST",
				dataType: "json",
				url: "/wp-admin/admin-ajax.php",
				data : data,
				success: function(response) {
					//enable button
					submit.prop('disabled', false);
					//success?
					if(response.success) {
						//resolve
						resolve();
						//success
						if(!silent) {
							displayNotice('success', 'Planner successfully updated.');
						}
					} else {
						//console log
						console.log('Planner edit', response);
						//reject
						reject();
						//display error
						displayNotice('error', 'And error occurred. Please try again.');
					}
				},
				error: function() {
					//enable button
					submit.prop('disabled', false);
					//reject
					reject();
					//display error
					displayNotice('error', 'And error occurred. Please try again.');
				}
			});
		});
	};

	//Event: create post
	$('div.planner .add a').on('click', function(e) {
		//prevent default
		e.preventDefault();
		//set vars
		var html = '';
		var nonce = $('div.planner').attr('data-nonce');
		var parentId = this.getAttribute('data-series-add') || 0;
		var title = parentId ? 'Suggest an article' : 'Suggest a series';
		var name = parentId ? 'Article' : 'Series';
		//create html
		html += '<form method="post" name="planner-create">';
		html += '<p><label for="planner_post_title">' + name + ' title</label><input type="text" name="post_title" id="planner_post_title"></p>';
		html += '<p><label for="planner_post_content">Describe the purpose of the ' + name.toLowerCase() + '</label><textarea name="post_content" id="planner_post_content"></textarea></p>';
		html += '<p><input type="submit" value="Add suggestion"></p>';
		html += '</form>';
		//open overlay
		$('body').plannerOverlay(html, {
			title: title
		});
		//listen for submit
		$('form[name="planner-create"]').on('submit', function(e) {
			//prevent default
			e.preventDefault();
			//disable button
			var submit = $(this).find('[type="submit"]');
			submit.prop('disabled', true);
			//get IDs
			var ids = [];
			var attr = parentId ? 'data-post' : 'data-series';
			$('div.planner [' + attr + ']').each(function() {
				ids.push(this.getAttribute(attr));
			});
			//get data
			var data = {
				action: "planner_create",
				post_title: this.elements['post_title'].value,
				post_content: this.elements['post_content'].value,
				post_parent: parentId,
				menu_order: ids.length + 1,
				nonce: nonce
			};
			//make request
			$.ajax({
				type: "POST",
				dataType: "json",
				url: "/wp-admin/admin-ajax.php",
				data : data,
				success: function(response) {
					//enable button
					submit.prop('disabled', false);
					//success?
					if(response.success) {
						//set hash?
						if(window.scrollY > 0) {
							location.hash = Math.round(window.scrollY);
						}
						//refresh page
						location.reload();
					} else {
						//console log
						console.log('Planner create', response);
						//get error message
						var msg = 'An error occurred. Please try again.';
						//is empty?
						if(response.data.error === 'empty') {
							msg = 'Please fill out all the fields.';
						}
						//is dupe?
						if(response.data.error === 'dupe') {
							msg = 'This ' + name + ' title already exists.';
						}
						//display error
						displayNotice('error', msg);
					}
				},
				error: function() {
					//enable button
					submit.prop('disabled', false);
					//display error
					displayNotice('error', 'An error occurred. Please try again.');
				}
			});
		});
	});

	//Event: edit post
	$('form[name="planner-edit"]').on('submit', function(e) {
		//prevent default
		e.preventDefault();
		//attempt save
		savePost();
	});

	//Event: submit post for review
	$('[name="review"]').on('click', function(e) {
		//prevent default
		e.preventDefault();
		//confirm request?
		if(!confirm('This will submit the post for editorial review and publishing. You\'ll still be able to edit it afterwards. Continue?')) {
			return;
		}
		//save post first
		savePost(true).then(function() {
			//disable button
			var submit = $(this);
			submit.prop('disabled', true);
			//post data
			var data = {
				action: "planner_submit",
				ID: $('div.planner').attr('data-post'),
				nonce: $('div.planner').attr('data-nonce')
			};
			//make request
			$.ajax({
				type: "POST",
				dataType: "json",
				url: "/wp-admin/admin-ajax.php",
				data : data,
				success: function(response) {
					//enable button
					submit.prop('disabled', false);
					//success?
					if(response.success) {
						//remove button
						$('[name="review"]').remove();
						//display success
						displayNotice('success', 'This post was successfully submitted. You can keep editing it here.');
					} else {
						//console log
						console.log('Planner submit', response);
						//get error message
						var msg = 'An error occurred. Please try again.';
						//is empty?
						if(response.data.error === 'empty') {
							msg = 'Please fill out all the fields.';
						}
						//is dupe?
						if(response.data.error === 'dupe') {
							msg = 'This ' + name + ' title already exists.';
						}
						//display error
						displayNotice('error', msg);
					}
				},
				error: function() {
					//enable button
					submit.prop('disabled', false);
					//display error
					displayNotice('error', 'An error occurred. Please try again.');
				}
			});
		});
	});

	//Event: sort posts
	$('div.planner .sortable').sortable({
		axis: "y",
		items: ".item",
		update: function(e) {
			//set vars
			var ids = [];
			var nonce = $('div.planner').attr('data-nonce');
			var isSeries = $(this).closest('.series').length > 0;
			var attr = isSeries ? 'data-series' : 'data-post';
			//get IDs
			$('div.planner [' + attr + ']').each(function() {
				ids.push(this.getAttribute(attr));
			});
			//valid IDs?
			if(!ids.length) {
				return console.error('Planner sort: IDs not found');
			}
			//make request
			$.ajax({
				type: "POST",
				dataType: "json",
				url: "/wp-admin/admin-ajax.php",
				data : {
					action: "planner_sort",
					ids: ids,
					nonce: nonce
				},
				success: function(response) {
					console.log('Planner sort', response);
				}
			});
		}
	});

	//Init: scroll to?
	if(location.hash && location.hash.match(/^#[0-9]+$/)) {
		var scrollDown = location.hash.replace('#', '');
		window.scrollTo({ top: scrollDown, behavior: 'auto' });
		history.replaceState(null, null, ' ');
	}

	//Init: list scroll
	var _status = false;
	$('.planner .scroller [data-status]').each(function() {
		//is published?
		if(!_status && this.getAttribute('data-status') !== 'published') {	
			//scroll to item
			document.querySelector('.planner .scroller').scrollTop = this.offsetTop;
			//stop here
			_status = true;
		}
	});

})();