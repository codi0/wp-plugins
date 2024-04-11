(function(propertyIds) {

	//set vars
	var cookies = {};
	var opd = Object.getOwnPropertyDescriptor;
	var dc = opd ? (opd(Document.prototype, 'cookie') || opd(HTMLDocument.prototype, 'cookie')) : null;
	
	//cookieless tracking possible?
	if(!dc || !dc.configurable || !Object.defineProperty) {
		return;
	}

	//property IDs to array?
	if(typeof propertyIds == 'string') {
		propertyIds = propertyIds ? propertyIds.split(',') : [];
	}

	//overload document.cookie
	Object.defineProperty(document, 'cookie', {
		get: function() {
			var stored = dc.get.call(document);
			var arr = stored ? stored.split('; ') : [];
			var local = Object.values(cookies);
			for(var i=0; i < local.length; i++) {
				arr.push(local[i]);
			}
			return arr.join('; ');
		},
		set: function(value) {
			if(value.indexOf('_ga') == 0) {
				value = value.split(';')[0];
				cookies[value.split('=')[0]] = value;
			} else {
				dc.set.call(document, value);
			}
			return value;
		}
	});

	//hash function
	var cyrb53 = function(str, seed=0) {
		var h1 = 0xdeadbeef ^ seed, h2 = 0x41c6ce57 ^ seed;
		for(var i = 0, ch; i < str.length; i++) {
			ch = str.charCodeAt(i);
			h1 = Math.imul(h1 ^ ch, 2654435761);
			h2 = Math.imul(h2 ^ ch, 1597334677);
		}
		h1  = Math.imul(h1 ^ (h1 >>> 16), 2246822507);
		h1 ^= Math.imul(h2 ^ (h2 >>> 13), 3266489909);
		h2  = Math.imul(h2 ^ (h2 >>> 16), 2246822507);
		h2 ^= Math.imul(h1 ^ (h1 >>> 13), 3266489909);
		return 4294967296 * (2097151 & h2) + (h1 >>> 0);
	}

	//generaate canvas URL
	var canvasUrl = function() {
		var res = '';
		var canvas = document.createElement('canvas');
		var ctx = (canvas && canvas.getContext) ? canvas.getContext('2d') : null;
		if(ctx) {
			ctx.textBaseline = "top";
			ctx.font = "14px 'Arial'";
			ctx.textBaseline = "alphabetic";
			ctx.fillStyle = "#f60";
			ctx.fillRect(125, 1, 62, 20);
			ctx.fillStyle = "#069";
			ctx.fillText('cd', 2, 15);
			ctx.fillStyle = "rgba(102, 204, 0, 0.7)";
			ctx.fillText('cd', 4, 17);
			res = canvas.toDataURL();
		}
		return res;
	}

	//generate client ID
	var clientId = (function() {
		var parts = [];
		parts.push((navigator.userAgent || '').toLowerCase().replace(/[^a-z]/g, ''));
		parts.push((navigator.language || '').toLowerCase());
		parts.push(screen.colorDepth || 0);
		parts.push((screen.height > screen.width) ? screen.height+'x'+screen.width : screen.width+'x'+screen.height);
		parts.push(new Date().getTimezoneOffset() || 0);
		parts.push(canvasUrl());
		return 'ID.' + cyrb53(parts.join(','));
	})();

	//set globals
	window.dataLayer = window.dataLayer || [];
	window.gtag = window.gtag || function() { dataLayer.push(arguments) }
	
	//set config
	gtag('js', new Date());
	gtag('set', 'client_id', clientId);
	for(var i=0; i < propertyIds.length; i++) {
		gtag('config', propertyIds[0]);
	}

	//load script
	setTimeout(function() {
		var scripts = document.getElementsByTagName('script');
		var s = document.createElement('script'); s.async = true;
		s.src = 'https://www.googletagmanager.com/gtag/js?id=' + propertyIds[0];
		scripts[scripts.length-1].parentNode.appendChild(s);
	}, 0);

})('G-XXXXXXXXXX');