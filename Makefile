dev:
	@echo "Starting PHP development server..."
	php8.4 -S localhost:8888 -t ./public/ 2>&1 | grep -E -v "\[200\]|Accepted|Closing"

sync:
	@echo "Syncing with remote server..."
	@rsync -av --exclude-from='.rsyncignore' ./ iheb@iheb.tn:/var/www/pmp.ecosm.tn/ --rsync-path="sudo rsync" --chown=www-data:www-data 

.PHONY: dev sync
