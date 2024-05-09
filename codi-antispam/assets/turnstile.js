(function(siteKey, fieldName, ajaxUrl, isDebug) {

	window.tsInit = function() {

		var formEl;
		var widgetId;
		
		var debug = function() {
			var args = Array.prototype.slice.call(arguments);
			isDebug && console.log.apply(console, args);
		}

		var updateToken = function(token) {
			var forms = document.querySelectorAll('form');
			for(var i in forms) {
				if(!forms[i].hasOwnProperty(i)) {
					continue;
				}
				var input = forms[i].querySelector('input[name="' + fieldName + '"]');
				if(!input) {
					input = document.createElement('input');
					input.setAttribute('type', 'hidden');
					input.setAttribute('name', fieldName);
					forms[i].appendChild(input);
				}
				input.setAttribute('value', token);
			}
		};

		var setupWidget = function() {
			//stop here?
			if(formEl || widgetId === true) {
				return;
			}
			//reset widget?
			if(widgetId && typeof widgetId === 'string') {
				requestAnimationFrame(function() {
					debug('ts-reset', widgetId);
					turnstile.reset(widgetId);
				});
				return;
			}
			//get overlay
			var o = document.querySelector('#ts-overlay');
			//create?
			if(!o) {
				var o = document.createElement('div');
				o.innerHTML = '<div id="ts-overlay" style="position:fixed; top:0; bottom:0; left:0; right:0; background:rgba(0, 0, 0, 0.6); border:1px solid grey; z-index:99999; display:none;">' +
									'<div style="display:flex; flex-direction:column; align-items:center; justify-content:center; width:100%; height:100%;">' +
										'<p style="color:white; text-align:center;">A quick anti-spam check...</p>' +
										'<div id="ast-widget"></div>' + 
									'</div>' + 
							  '</div>';
				o = o.firstChild;
				document.body.appendChild(o);
			}
			//processing...
			widgetId = true;
			//allow submit to run if needed
			requestAnimationFrame(function() {
				//create widget
				widgetId = turnstile.render('#ast-widget', {
					'sitekey': siteKey,
					'response-field': false,
					'callback': function(token) {
						debug('ts-token', widgetId);
						updateToken(token);
						setTimeout(function() {
							o.style.display = 'none';
							if(formEl) {
								formEl.submit();
								formEl = null;
							}
						}, o.style.display === 'none' ? 0 : 1500);
					},
					'before-interactive-callback': function() {
						debug('ts-display', widgetId);
						o.style.display = 'block';
						fetch(ajaxUrl, {
							method: 'POST',
							headers: {
								"Content-Type": "application/x-www-form-urlencoded",
							},
							body: (new URLSearchParams({ action: 'aspam_log' })).toString()
						});
					},
					'error-callback': function(errNum) {
						debug('ts-error', widgetId, errNum);
					},
					'expired-callback': function() {
						debug('ts-expired', widgetId);
						turnstile.reset(widgetId);
					}
				});
				debug('ts-create', widgetId);
			});
		};

		document.body.addEventListener('click', function(e) {
			//is handled?
			if(widgetId) {
				return;
			}
			//set vars
			var form = null;
			var parent = e.target;
			//loop through parents
			while(parent) {
				if(parent.tagName === 'FORM') {
					form = parent;
					break;
				}
				if(parent === document) {
					return;
				}
				parent = parent.parentNode;
			}
			//setup widget
			setupWidget();
		});

		document.body.addEventListener('submit', function(e) {
			//set vars
			var res = true;
			//has widget?
			if(typeof widgetId !== 'string') {
				res = false;
				formEl = e.target;
				e.preventDefault();
			}
			//run setup
			debug('ts-submit', res);
			setupWidget();
			//return
			return res;
		});
	
	};

})('TS_SITE_KEY', 'TS_FIELD', 'TS_AJAX', TS_DEBUG);