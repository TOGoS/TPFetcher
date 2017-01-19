default: run-unit-tests

.PHONY: default run-unit-tests

vendor: composer.lock
	composer install
	touch "$@"

composer.lock: | composer.json
	composer update
	touch "$@"

run-unit-tests: vendor
	vendor/bin/phpunit --bootstrap vendor/autoload.php test/
