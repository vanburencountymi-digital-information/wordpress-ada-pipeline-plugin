.PHONY: build install test lint lint-fix shell

# Matches whoever runs `make` (dev or CI) so writes into the bind-mounted /app
# (vendor/, .phpunit.result.cache) land back owned by that user, not root or a
# uid baked into the image — see Dockerfile for why a fixed uid broke on CI.
RUN = docker compose run --rm --user "$$(id -u):$$(id -g)" app

build:
	docker compose build

# Run once after cloning, and again after adding/bumping a dependency in composer.json.
install:
	$(RUN) composer install

test:
	$(RUN) composer test

# WordPress Coding Standards 
lint:
	$(RUN) composer lint

lint-fix:
	$(RUN) composer lint:fix

shell:
	$(RUN) sh
