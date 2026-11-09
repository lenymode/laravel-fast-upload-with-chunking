.PHONY: demo down test logs
demo:
	docker compose up --build -d
	@echo "Laravel Resumable Uploads: http://localhost:$${APP_PORT:-8000}"
logs:
	docker compose logs -f web worker
test:
	docker compose run --rm web php artisan test
down:
	docker compose down
