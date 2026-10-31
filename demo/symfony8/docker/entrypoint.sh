#!/bin/sh
set -e

# FRANKENPHP_MODE: classic | worker (REQ-DEMO-010). Default: worker.
# Set via .env / Compose only — not baked into the image ENV.
MODE="${FRANKENPHP_MODE:-worker}"
case "$MODE" in
	classic)
		if [ -f /app/docker/frankenphp/Caddyfile.dev ]; then
			cp /app/docker/frankenphp/Caddyfile.dev /etc/frankenphp/Caddyfile
		elif [ -f /etc/frankenphp/Caddyfile.dev ]; then
			cp /etc/frankenphp/Caddyfile.dev /etc/frankenphp/Caddyfile
		fi
		;;
	worker)
		if [ -f /app/docker/frankenphp/Caddyfile ]; then
			cp /app/docker/frankenphp/Caddyfile /etc/frankenphp/Caddyfile
		fi
		# else keep image default Caddyfile (worker enabled)
		;;
	*)
		echo "Unknown FRANKENPHP_MODE=$MODE (expected classic|worker)" >&2
		exit 1
		;;
esac
echo "FrankenPHP mode: $MODE"

mkdir -p /app/var/cache /app/var/log
chmod -R 777 /app/var
if [ ! -f /app/vendor/autoload_runtime.php ]; then composer install --no-interaction; fi
exec frankenphp run --config /etc/frankenphp/Caddyfile --adapter caddyfile
