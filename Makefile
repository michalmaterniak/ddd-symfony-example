compose_v2_not_supported = $(shell command docker compose 2> /dev/null)
ifeq (,$(compose_v2_not_supported))
  DOCKER_COMPOSE_COMMAND = docker-compose
else
  DOCKER_COMPOSE_COMMAND = docker compose
endif
up:
	$(DOCKER_COMPOSE_COMMAND) up -d --build
run:
	$(DOCKER_COMPOSE_COMMAND) up -d --build
down:
	$(DOCKER_COMPOSE_COMMAND) down --remove-orphans
sh:
	$(DOCKER_COMPOSE_COMMAND) exec php bash
cs:
	$(DOCKER_COMPOSE_COMMAND) exec php vendor/bin/php-cs-fixer fix --dry-run --diff
cs-fix:
	$(DOCKER_COMPOSE_COMMAND) exec php vendor/bin/php-cs-fixer fix
phpcs:
	$(DOCKER_COMPOSE_COMMAND) exec php vendor/bin/phpcs
phpstan:
	$(DOCKER_COMPOSE_COMMAND) exec php vendor/bin/phpstan analyse --memory-limit=1G

WORKERS_COMPOSE = $(DOCKER_COMPOSE_COMMAND) -f docker-compose.yml -f docker-compose.workers.yml
up-workers:
	$(WORKERS_COMPOSE) up -d --build worker-rabbit worker-doctrine
down-workers:
	$(WORKERS_COMPOSE) stop worker-rabbit worker-doctrine
	$(WORKERS_COMPOSE) rm -f worker-rabbit worker-doctrine
restart-workers:
	$(WORKERS_COMPOSE) restart worker-rabbit worker-doctrine
logs-workers:
	$(WORKERS_COMPOSE) logs -f --tail=100 worker-rabbit worker-doctrine
ps-workers:
	$(WORKERS_COMPOSE) ps worker-rabbit worker-doctrine

# usage: make scale-workers-rabbit 100 (or N=100); fewer than running stops the extra workers, 0 stops all
ifeq (scale-workers-rabbit,$(firstword $(MAKECMDGOALS)))
  N ?= $(word 2,$(MAKECMDGOALS))
  ifneq (,$(word 2,$(MAKECMDGOALS)))
    $(eval $(word 2,$(MAKECMDGOALS)):;@:)
  endif
endif
scale-workers-rabbit:
	@case "$(N)" in ''|*[!0-9]*) echo "usage: make scale-workers-rabbit <count>"; exit 1;; esac
	$(WORKERS_COMPOSE) up -d --no-recreate --no-build --no-deps --scale worker-rabbit=$(N) worker-rabbit

LOGS_COMPOSE = $(DOCKER_COMPOSE_COMMAND) -f docker-compose.yml -f docker-compose.logs.yml
LOGS_SERVICES = elasticsearch kibana logstash filebeat
up-logs:
	$(LOGS_COMPOSE) up -d $(LOGS_SERVICES)
down-logs:
	$(LOGS_COMPOSE) stop $(LOGS_SERVICES)
	$(LOGS_COMPOSE) rm -f $(LOGS_SERVICES)
ps-logs:
	$(LOGS_COMPOSE) ps $(LOGS_SERVICES)
