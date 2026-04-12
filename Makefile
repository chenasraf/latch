COMPOSER := $(shell command -v composer 2>/dev/null || echo php composer.phar)

.PHONY: install install-hooks test analyze fix

composer.phar:
	php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
	php composer-setup.php
	php -r "unlink('composer-setup.php');"

install:
	$(COMPOSER) install

install-hooks:
	lefthook install

test:
	vendor/bin/pest

analyze:
	vendor/bin/phpstan analyse

fix:
	vendor/bin/pint
