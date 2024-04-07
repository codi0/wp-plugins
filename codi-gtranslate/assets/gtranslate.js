window.addEventListener('load', function() {

	//set vars
	var id = 'gTranslator';
	var cb = 'gTranslateInit';
	var el = document.getElementById(id);

	//callback function
	window.gTranslateInit = function() {
		new google.translate.TranslateElement({ pageLanguage: 'en' }, id);
	}
	
	//create el?
	if(!el) {
		el = document.createElement('div'); el.id = id;
		(document.querySelector('footer') || document.querySelector('body')).appendChild(el);
	}

	//load script
	var scripts = document.getElementsByTagName('script');
	var s = document.createElement('script'); s.async = true;
	s.src = 'https://translate.google.com/translate_a/element.js?cb=' + cb;
	scripts[scripts.length-1].parentNode.appendChild(s);

});