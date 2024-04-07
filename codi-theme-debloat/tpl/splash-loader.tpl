	<style>
	body {
		visibility: hidden;
	}
	#splash-loader {
		position: fixed;
		top: 0;
		bottom: 0;
		left: 0;
		right: 0;
		margin: auto;
		border: 12px solid #333;
		border-top: 12px solid #ccc;
		border-radius: 50%;
		width: 70px;
		height: 70px;
		animation: spin 500ms linear infinite;
		visibility: visible;
	}
	@keyframes spin {
		100% {
			transform: rotate(360deg);
		}
	}
	</style>
	<script>
	var splash = {
		start: 0,
		timer: function(min) {
			var ms = performance.now() - splash.start;
			return ms > min ? 0 : min - ms;
		},
		loader: function(show, spinner=true) {
			splash.start = performance.now();
			document.querySelector('#splash-loader').style.animationDelay = spinner ? '0ms' : '750ms';
			document.querySelector('#splash-loader').style.display = show ? "block" : "none";
			document.querySelector('body').style.visibility = show ? "hidden" : "visible";
		}
	};
	splash.start = performance.now();
	document.addEventListener('DOMContentLoaded', function(e) {
		setTimeout(function() {
			splash.loader(false);
		}, splash.timer(750));
	});
	window.addEventListener('pageshow', function(e) {
		if(e.persisted) {
			splash.loader(true);
			setTimeout(function() {
				splash.loader(false);
			}, splash.timer(500));
		}
	});
	window.addEventListener('beforeunload', function(e) {
		splash.loader(true, false);
	});
	</script>