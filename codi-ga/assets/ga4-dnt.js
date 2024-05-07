(function(propertyIds) {

	//set globals
	window.dataLayer = window.dataLayer || [];
	window.gtag = window.gtag || function() { dataLayer.push(arguments) }

	//property IDs to array?
	if(typeof propertyIds == 'string') {
		propertyIds = propertyIds ? propertyIds.split(',') : [];
	}

	//do not track?
	if(window.navigator && (navigator.doNotTrack || navigator.globalPrivacyControl)) {
		return;
	}

	//set date
	gtag('js', new Date());

	//loop through property IDs
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