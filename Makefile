.PHONY: phar check-exts profile test preflight token-budget

# Build PHAR via humbug/box — requires humbug/box installed separately
# Install: composer require --dev humbug/box  OR  curl -LS https://github.com/humbug/box/releases/latest/download/box.phar -o /tmp/box && chmod +x /tmp/box
BOX ?= vendor/bin/box
ifeq (,$(wildcard $(BOX)))
BOX = /tmp/box
endif

phar:
	@mkdir -p build
	@if [ ! -x "$(BOX)" ]; then \
		echo "box not found at $(BOX)"; \
		echo "Install with: composer require --dev humbug/box  (or fetch box.phar to /tmp/box)"; \
		exit 1; \
	fi
	$(BOX) compile
	@echo "built build/paider.phar"
	@ls -lh build/paider.phar
	@php build/paider.phar --version

check-exts:
	php bin/check-exts.php

profile:
	php bin/profile-startup.php

test:
	vendor/bin/pest

preflight:
	bash m1/preflight.sh

token-budget:
	php bin/token-budget.php
