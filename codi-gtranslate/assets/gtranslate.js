document.addEventListener('DOMContentLoaded', function() {

	var defLang = 'en';
	var currentLang = '';
	var id = 'gTranslator';
	var isInitialized = false;
	var hasNativeTranslator = !!window.Translator;

	var pageLang = (function() {
		var res = (document.documentElement.lang || defLang).split('-')[0];
		res = (res === 'zh') ? 'zh-CN' : res;
		return res;
	})();

	var browserLangs = (function() {
		var res = [];
		var arr = (navigator.languages || []).slice();
		arr.push(navigator.language || navigator.userLanguage || navigator.browserLanguage || navigator.systemLanguage || defLang);
		for(var i=0; i < arr.length; i++) {
			var tmp = arr[i].split('-')[0];
			tmp = (tmp === 'zh') ? 'zh-CN' : tmp;
			if(!res.includes(tmp)) {
				res.push(tmp);
			}
		}
		return res;
	})();

	var languages = {
		'': 'Change language',
		'sq': 'Albanian (Shqip)',
		'am': 'Amharic (አማርኛ)',
		'ar': 'Arabic (العربية)',
		'bn': 'Bengali (বাংলা)',
		'bg': 'Bulgarian (Български)',
		'zh-CN': 'Chinese (简体中文)',
		'hr': 'Croatian (Hrvatski)',
		'cs': 'Czech (Čeština)',
		'da': 'Danish (Dansk)',
		'nl': 'Dutch (Nederlands)',
		'en': 'English',
		'et': 'Estonian (Eesti)',
		'tl': 'Filipino/Tagalog',
		'fi': 'Finnish (Suomi)',
		'fr': 'French (Français)',
		'de': 'German (Deutsch)',
		'el': 'Greek (Ελληνικά)',
		'gu': 'Gujarati (ગુજરાતી)',
		'he': 'Hebrew (עברית)',
		'hi': 'Hindi (हिन्दी)',
		'hu': 'Hungarian (Magyar)',
		'it': 'Italian (Italiano)',
		'ja': 'Japanese (日本語)',
		'ko': 'Korean (한국어)',
		'ku': 'Kurdish (کوردی)',
		'lv': 'Latvian (Latviešu)',
		'lt': 'Lithuanian (Lietuvių)',
		'ml': 'Malayalam (മലയാളം)',
		'mt': 'Maltese (Malti)',
		'no': 'Norwegian (Norsk)',
		'pa': 'Panjabi (ਪੰਜਾਬੀ)',
		'fa': 'Persian/Farsi (فارسی)',
		'pl': 'Polish (Polski)',
		'pt': 'Portuguese (Português)',
		'ro': 'Romanian (Română)',
		'ru': 'Russian (Русский)',
		'sr': 'Serbian (Српски)',
		'si': 'Sinhala (සිංහල)',
		'sk': 'Slovak (Slovenčina)',
		'sl': 'Slovenian (Slovenščina)',
		'so': 'Somali (Soomaali)',
		'es': 'Spanish (Español)',
		'sw': 'Swahili (Kiswahili)',
		'sv': 'Swedish (Svenska)',
		'ta': 'Tamil (தமிழ்)',
		'te': 'Telugu (తెలుగు)',
		'th': 'Thai (ไทย)',
		'ti': 'Tigrinya (ትግርኛ)',
		'tr': 'Turkish (Türkçe)',
		'uk': 'Ukrainian (Українська)',
		'ur': 'Urdu (اردو)',
		'vi': 'Vietnamese (Tiếng Việt)',
		'cy': 'Welsh (Cymraeg)'
	};

	var getCookie = function(name) {
		var nameEQ = name + "=";
		var ca = document.cookie.split(';');
		for (var i = 0; i < ca.length; i++) {
			var c = ca[i];
			while (c.charAt(0) === ' ') c = c.substring(1, c.length);
			if (c.indexOf(nameEQ) === 0) return c.substring(nameEQ.length, c.length);
		}
		return '';
	};

	var setCookie = function(name, value, days) {
		days = days || (value ? 1 : 0);
		var date = new Date();
		date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
		var expires = days > 0 ? 'expires=' + date.toUTCString() + ';' : 'expires=Thu, 01 Jan 1970 00:00:00 UTC;';
		document.cookie = name + '=' + value + ';' + expires + 'path=/';
	};

	var translateTo = function(langCode) {
		var oldCookie = getCookie('googtrans');
		var newCookie = '/' + pageLang + '/' + langCode;
		if (!langCode || pageLang === langCode && browserLangs.includes(pageLang)) {
			newCookie = '';
		}
		if(langCode === currentLang || oldCookie === newCookie || !languages[langCode]) {
			return;
		}
		currentLang = langCode;
		setCookie('googtrans', newCookie);
		if (isInitialized) {
			location.reload();
		} else {
			initializeGoogleTranslate();
		}
	};

	var initializeGoogleTranslate = function() {
		if(isInitialized || pageLang === currentLang) {
			return;
		}
		isInitialized = true;
		window.gTranslateInit = function() {
			var googleEl = document.createElement('div');
			googleEl.id = 'google_translate_element';
			googleEl.style.display = 'none';
			document.body.appendChild(googleEl);
			new google.translate.TranslateElement({
				pageLanguage: pageLang,
				includedLanguages: Object.keys(languages).filter(function(k) { return k; }).join(','),
				layout: google.translate.TranslateElement.InlineLayout.SIMPLE,
				autoDisplay: false
			}, 'google_translate_element');
		};
		if(!document.getElementById('customTranslateCSS')) {
			var style = document.createElement('style');
			document.head.appendChild(style);
			style.id = 'customTranslateCSS';
			style.textContent = `
			body { top: 0 !important; }
			.goog-te-gadget { display: none !important; }
			.goog-te-gadget-simple { display: none !important; }
			.goog-te-combo { display: none !important; }
			.goog-te-banner-frame { display: none !important; }
			.goog-te-menu-frame { display: none !important; }
			.skiptranslate { display: none !important; }
			.goog-te-ftab { display: none !important; }
			iframe.goog-te-menu-frame { display: none !important; }
		`;
		}
		var scripts = document.getElementsByTagName('script');
		var s = document.createElement('script'); s.async = true;
		s.src = 'https://translate.google.com/translate_a/element.js?cb=gTranslateInit';
		scripts[scripts.length - 1].parentNode.appendChild(s);
	};

	var el = document.getElementById(id);
	var existingCookie = getCookie('googtrans');
	var select = document.createElement('select');

	if (!el) {
		el = document.createElement('div');
		el.id = id;
		var parent = document.querySelector('footer') || document.querySelector('body');
		if (parent) {
			while (parent.children.length === 1) {
				parent = parent.children[0];
			}
			parent.appendChild(el);
		}
	}

	el.innerHTML = '';
	el.appendChild(select);
	select.classList.add('notranslate');

	select.addEventListener('change', function(e) {
		translateTo(e.target.value);
	});

	for (var code in languages) {
		var option = document.createElement('option');
		option.value = code;
		option.textContent = languages[code];
		select.appendChild(option);
	}

	if (existingCookie) {
		var langCode = existingCookie.split('/')[2];
		if (langCode && languages[langCode]) {
			select.value = currentLang = langCode;
			initializeGoogleTranslate();
		}
	}

	if (!currentLang && !hasNativeTranslator && !browserLangs.includes(pageLang) && languages[browserLangs[0]]) {
		select.value = browserLangs[0];
		translateTo(browserLangs[0]);
	}

});