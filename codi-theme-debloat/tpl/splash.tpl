	<style>
	#splash {
		display: flex;
		align-items: center;
		justify-content: center;
		position: fixed;
		top: 0; left: 0;
		width: 100%;
		height: 100%;
		background: #fff;
		z-index: 999;
	}
	#splash .spinner {
		position: fixed;
		top: 0;
		bottom: 0;
		left: 0;
		right: 0;
		width: 80px;
		height: 80px;
		margin: auto;
		border: 12px solid #333;
		border-top: 12px solid #ccc;
		border-radius: 50%;
		animation: spin 500ms linear infinite;
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
			document.querySelector('#splash .spinner').style.animationDelay = spinner ? '0ms' : '750ms';
			document.querySelector('#splash').style.display = show ? "flex" : "none";
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
		setTimeout(function() {
      splash.loader(false);
		}, 1000);
	});
	</script>