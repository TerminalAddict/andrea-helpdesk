.DEFAULT_GOAL := help

# Frontend library versions — bump these and run `make fetch-assets` to upgrade
BOOTSTRAP_VERSION = 5.3.8
BOOTSTRAP_ICONS_VERSION = 1.13.1
JQUERY_VERSION          = 4.0.0
DOMPURIFY_VERSION       = 3.2.4
QUILL_VERSION           = 2.0.3
VENDOR_DIR              = public_html/assets/vendor

# Deployment configuration — copy Makefile.local.example to Makefile.local and set your values
LOCAL_HOST  ?= your-local-server
PROD_HOST   ?= your-prod-server
REMOTE_USER ?= deploy
REMOTE_PATH ?= /var/www/html/andrea-helpdesk
-include Makefile.local

RSYNC_OPTS  = -avz --delete
RSYNC_EXCLUDE = --exclude=/vendor --exclude=.env --exclude=storage --exclude=/cache --exclude=.git --exclude=/demo --exclude=*.swp

CRON_ENTRY  = * * * * * php $(REMOTE_PATH)/bin/cron.php >> $(REMOTE_PATH)/storage/logs/cron.log 2>&1

.PHONY: help install install-dev db-migrate db-seed reset-admin-password update fetch-assets \
        script \
        package \
        release \
        dev-release \
        deploy \
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
	mkdir -p $(VENDOR_DIR)/quill
	curl -sL "https://cdn.jsdelivr.net/npm/quill@$(QUILL_VERSION)/dist/quill.snow.css" \
	     -o $(VENDOR_DIR)/quill/quill.snow.css
	curl -sL "https://cdn.jsdelivr.net/npm/quill@$(QUILL_VERSION)/dist/quill.min.js" \
	     -o $(VENDOR_DIR)/quill/quill.min.js
	@echo "Assets ready — Bootstrap $(BOOTSTRAP_VERSION), Bootstrap Icons $(BOOTSTRAP_ICONS_VERSION), jQuery $(JQUERY_VERSION), DOMPurify $(DOMPURIFY_VERSION), Quill $(QUILL_VERSION)"

install: ## Install Composer dependencies (production)
	composer install --no-dev --optimize-autoloader

install-dev: ## Install Composer dependencies (development)
	composer install

db-migrate: ## Run database migrations
	php bin/migrate.php

db-seed: ## Seed initial admin agent (reads ADMIN_* from .env)
	php bin/seed.php

reset-admin-password: ## Interactively reset a password for an existing admin account
	php bin/reset-admin-password.php

script: ## Bump CLI installer patch version, commit, and push only bin/install-cli.sh if it changed
	@if git diff --quiet -- bin/install-cli.sh && git diff --cached --quiet -- bin/install-cli.sh; then \
		echo "No bin/install-cli.sh changes to release."; \
	else \
		NEW_VERSION=$$(php bin/script-release.php) && \
		git add bin/install-cli.sh && \
		git commit -m "Bump CLI installer version to $$NEW_VERSION" && \
		git push; \
	fi

package: ## Build a clean release zip under build/ for FTP/browser installs
	php bin/build-release-package.php

release: ## Create a stable release, commit, tag, and push current branch
	@BRANCH=$$(git rev-parse --abbrev-ref HEAD) && \
	if [ "$$BRANCH" != "main" ]; then echo "make release must be run from main (current: $$BRANCH)"; exit 1; fi && \
	NEW_VERSION=$$(php bin/release.php --channel=stable) && \
	git add -A && \
	git commit -m "Bump version to $$NEW_VERSION" && \
	git tag "v$$NEW_VERSION" && \
	git push origin HEAD && \
	git push origin "v$$NEW_VERSION"

dev-release: ## Create a development release, commit, tag, and push current branch
	@BRANCH=$$(git rev-parse --abbrev-ref HEAD) && \
	if [ "$$BRANCH" != "development" ]; then echo "make dev-release must be run from development (current: $$BRANCH)"; exit 1; fi && \
	NEW_VERSION=$$(php bin/release.php --channel=development) && \
	git add -A && \
	git commit -m "Bump version to $$NEW_VERSION" && \
	git tag "dev-v$$NEW_VERSION" && \
	git push origin HEAD && \
	git push origin "dev-v$$NEW_VERSION"

storage-setup: ## Create storage directory structure
	mkdir -p cache storage/attachments storage/logs storage/cache
	mkdir -p storage/runtime
	touch storage/logs/app.log storage/logs/imap.log storage/logs/cron.log storage/logs/chat-supervisor.log storage/logs/chat-websocket.log
	@echo "Storage directories created."

deploy: script ## Release installer changes first, then deploy to production server
	rsync $(RSYNC_OPTS) $(RSYNC_EXCLUDE) ./ $(REMOTE_USER)@$(PROD_HOST):$(REMOTE_PATH)/
	ssh $(REMOTE_USER)@$(PROD_HOST) "cd $(REMOTE_PATH) && composer install --no-dev --optimize-autoloader"
	ssh $(REMOTE_USER)@$(PROD_HOST) "cd $(REMOTE_PATH) && php bin/migrate.php"
	ssh $(REMOTE_USER)@$(PROD_HOST) "mkdir -p $(REMOTE_PATH)/storage/attachments $(REMOTE_PATH)/storage/logs $(REMOTE_PATH)/storage/runtime"
	@echo "Deployed to $(PROD_HOST)"

cron-install-local: ## Install Andrea background cron on local server
	@(crontab -l 2>/dev/null | grep -vE 'bin/(imap-poll|cron)\.php|chat-supervisor'; printf '%s\n' "* * * * * php $(REMOTE_PATH)/bin/cron.php >> $(REMOTE_PATH)/storage/logs/cron.log 2>&1") | crontab -
	@echo "Cron installed locally"

cron-install-production: ## Install Andrea background cron on production server
	ssh $(REMOTE_USER)@$(PROD_HOST) '(crontab -l 2>/dev/null | grep -vE '\''bin/(imap-poll|cron)\.php|chat-supervisor'\''; printf '\''%s\n'\'' '\''$(CRON_ENTRY)'\'') | crontab -'
	@echo "Cron installed on $(PROD_HOST)"

logs-local: ## Tail app log on local server
	ssh $(REMOTE_USER)@$(LOCAL_HOST) "tail -f $(REMOTE_PATH)/storage/logs/app.log"

logs-production: ## Tail app log on production server
	ssh $(REMOTE_USER)@$(PROD_HOST) "tail -f $(REMOTE_PATH)/storage/logs/app.log"

logs-imap-local: ## Tail IMAP poll log on local server
	ssh $(REMOTE_USER)@$(LOCAL_HOST) "tail -f $(REMOTE_PATH)/storage/logs/imap.log"

logs-imap-production: ## Tail IMAP poll log on production server
	ssh $(REMOTE_USER)@$(PROD_HOST) "tail -f $(REMOTE_PATH)/storage/logs/imap.log"
