window.addEventListener('load', function() {

	var id = 'gTranslator';
	var el = document.getElementById(id);

	var fn = function(e) {
		if(window.gTranslateInit) return;
		window.gTranslateInit = function() { new google.translate.TranslateElement({ pageLanguage: 'en' }, id) }
		var scripts = document.getElementsByTagName('script');
		var s = document.createElement('script'); s.async = true;
		s.src = 'https://translate.google.com/translate_a/element.js?cb=gTranslateInit';
		s.onload = function() { e && setTimeout(function() { el.scrollIntoView(true) }, 100) }
		scripts[scripts.length-1].parentNode.appendChild(s);
		el.innerHTML = '';
	};
	
	if(!el) {
		el = document.createElement('div'); el.id = id;
		(document.querySelector('footer') || document.querySelector('body')).appendChild(el);
	}
	
	el.innerHTML = '<select style="font-size:0.85em;"><option>Select language</option></select>';
	el.addEventListener('click', fn);
	document.cookie.indexOf('googtrans=') >=0 && fn();

});