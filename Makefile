.DEFAULT_GOAL := help

# Frontend library versions — bump these and run `make fetch-assets` to upgrade
BOOTSTRAP_VERSION = 5.3.8
BOOTSTRAP_ICONS_VERSION = 1.13.1
JQUERY_VERSION          = 4.0.0
DOMPURIFY_VERSION       = 3.2.4
VENDOR_DIR              = public_html/assets/vendor

LOCAL_HOST  = your-local-server
PROD_HOST   = your-prod-server
REMOTE_USER = deploy
REMOTE_PATH = \/var\/www\/html\/andrea-helpdesk
RSYNC_OPTS  = -avz --delete
RSYNC_EXCLUDE = --exclude=/vendor --exclude=.env --exclude=storage --exclude=.git --exclude=*.swp

CRON_ENTRY  = "* * * * * php $(REMOTE_PATH)/bin/imap-poll.php >> $(REMOTE_PATH)/storage/logs/imap.log 2>&1"

.PHONY: help install install-dev db-migrate db-seed update fetch-assets \
        deploy-local deploy-production \
        cron-install-local cron-install-production \
        logs-local logs-production storage-setup

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-28s\033[0m %s\n", $$1, $$2}'

update: ## Check npm for latest Bootstrap, Bootstrap Icons, and jQuery — download and update Makefile if newer
	@bash bin/update-assets.sh

fetch-assets: ## Download Bootstrap, Bootstrap Icons, and jQuery locally (bump versions above to upgrade)
	mkdir -p $(VENDOR_DIR)/bootstrap $(VENDOR_DIR)/bootstrap-icons/fonts $(VENDOR_DIR)/jquery
	curl -sL "https://cdn.jsdelivr.net/npm/bootstrap@$(BOOTSTRAP_VERSION)/dist/css/bootstrap.min.css" \
	     -o $(VENDOR_DIR)/bootstrap/bootstrap.min.css
	curl -sL "https://cdn.jsdelivr.net/npm/bootstrap@$(BOOTSTRAP_VERSION)/dist/js/bootstrap.bundle.min.js" \
	     -o $(VENDOR_DIR)/bootstrap/bootstrap.bundle.min.js
	curl -sL "https://cdn.jsdelivr.net/npm/bootstrap-icons@$(BOOTSTRAP_ICONS_VERSION)/font/bootstrap-icons.min.css" \
	     -o $(VENDOR_DIR)/bootstrap-icons/bootstrap-icons.min.css
	curl -sL "https://cdn.jsdelivr.net/npm/bootstrap-icons@$(BOOTSTRAP_ICONS_VERSION)/font/fonts/bootstrap-icons.woff2" \
	     -o $(VENDOR_DIR)/bootstrap-icons/fonts/bootstrap-icons.woff2
	curl -sL "https://cdn.jsdelivr.net/npm/bootstrap-icons@$(BOOTSTRAP_ICONS_VERSION)/font/fonts/bootstrap-icons.woff" \
	     -o $(VENDOR_DIR)/bootstrap-icons/fonts/bootstrap-icons.woff
	curl -sL "https://code.jquery.com/jquery-$(JQUERY_VERSION).min.js" \
	     -o $(VENDOR_DIR)/jquery/jquery.min.js
	mkdir -p $(VENDOR_DIR)/dompurify
	curl -sL "https://cdn.jsdelivr.net/npm/dompurify@$(DOMPURIFY_VERSION)/dist/purify.min.js" \
	     -o $(VENDOR_DIR)/dompurify/purify.min.js
	@echo "Assets ready — Bootstrap $(BOOTSTRAP_VERSION), Bootstrap Icons $(BOOTSTRAP_ICONS_VERSION), jQuery $(JQUERY_VERSION), DOMPurify $(DOMPURIFY_VERSION)"

install: ## Install Composer dependencies (production)
	composer install --no-dev --optimize-autoloader

install-dev: ## Install Composer dependencies (development)
	composer install

db-migrate: ## Run database migrations
	php bin/migrate.php

db-seed: ## Seed initial admin agent (reads ADMIN_* from .env)
	php bin/seed.php

storage-setup: ## Create storage directory structure
	mkdir -p storage/attachments storage/logs
	touch storage/logs/app.log storage/logs/imap.log
	@echo "Storage directories created."

deploy-local: ## Deploy to local dev server (your-local-server)
	rsync $(RSYNC_OPTS) $(RSYNC_EXCLUDE) ./ $(REMOTE_USER)@$(LOCAL_HOST):$(REMOTE_PATH)/
	ssh $(REMOTE_USER)@$(LOCAL_HOST) "cd $(REMOTE_PATH) && composer install --no-dev --optimize-autoloader"
	ssh $(REMOTE_USER)@$(LOCAL_HOST) "mkdir -p $(REMOTE_PATH)/storage/attachments $(REMOTE_PATH)/storage/logs"
	@echo "Deployed to $(LOCAL_HOST)"

deploy-production: ## Deploy to production server (your-prod-server)
	rsync $(RSYNC_OPTS) $(RSYNC_EXCLUDE) ./ $(REMOTE_USER)@$(PROD_HOST):$(REMOTE_PATH)/
	ssh $(REMOTE_USER)@$(PROD_HOST) "cd $(REMOTE_PATH) && composer install --no-dev --optimize-autoloader"
	ssh $(REMOTE_USER)@$(PROD_HOST) "mkdir -p $(REMOTE_PATH)/storage/attachments $(REMOTE_PATH)/storage/logs"
	@echo "Deployed to $(PROD_HOST)"

cron-install-local: ## Install IMAP poll crontab on local server
	ssh $(REMOTE_USER)@$(LOCAL_HOST) "(crontab -l 2>/dev/null | grep -v imap-poll; echo $(CRON_ENTRY)) | crontab -"
	@echo "Cron installed on $(LOCAL_HOST)"

cron-install-production: ## Install IMAP poll crontab on production server
	ssh $(REMOTE_USER)@$(PROD_HOST) "(crontab -l 2>/dev/null | grep -v imap-poll; echo $(CRON_ENTRY)) | crontab -"
	@echo "Cron installed on $(PROD_HOST)"

logs-local: ## Tail app log on local server
	ssh $(REMOTE_USER)@$(LOCAL_HOST) "tail -f $(REMOTE_PATH)/storage/logs/app.log"

logs-production: ## Tail app log on production server
	ssh $(REMOTE_USER)@$(PROD_HOST) "tail -f $(REMOTE_PATH)/storage/logs/app.log"

logs-imap-local: ## Tail IMAP poll log on local server
	ssh $(REMOTE_USER)@$(LOCAL_HOST) "tail -f $(REMOTE_PATH)/storage/logs/imap.log"

logs-imap-production: ## Tail IMAP poll log on production server
	ssh $(REMOTE_USER)@$(PROD_HOST) "tail -f $(REMOTE_PATH)/storage/logs/imap.log"
