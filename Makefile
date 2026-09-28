.PHONY: build install test shell

build:
	docker compose build

# Run once after cloning, and again after adding/bumping a dependency in composer.json.
install:
	docker compose run --rm app composer install

test:
	docker compose run --rm app composer test

shell:
	docker compose run --rm app sh
