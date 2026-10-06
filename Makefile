.PHONY: up down logs reset shell refresh lint ps

## Start FreshRSS in the background (first run installs it automatically)
up:
	docker compose up -d
	@echo "FreshRSS: http://localhost:8080  (admin / admin123)"

## Stop containers (data is kept)
down:
	docker compose down

## Tail FreshRSS + Apache logs
logs:
	docker compose logs -f freshrss

## Stop and DELETE the data volume -> fresh install on next `make up`
reset:
	docker compose down -v

## Shell inside the container (FreshRSS lives in /var/www/FreshRSS)
shell:
	docker compose exec freshrss sh

## Refresh all feeds for the admin user right now
refresh:
	docker compose exec freshrss php cli/actualize-user.php --user admin

## PHP syntax-check every extension using the container's PHP
lint:
	docker compose exec freshrss sh -c 'find extensions -name "*.php" -o -name "*.phtml" | xargs -n1 php -l'

ps:
	docker compose ps
