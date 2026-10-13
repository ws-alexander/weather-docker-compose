# Погода и климат

Учебное многокомпонентное приложение на основе сайта «Погода и климат». Проект разделен на четыре сервиса и запускается через Docker Compose.

## Структура

- **frontend** – HTML, CSS и JavaScript; Nginx отдает интерфейс и проксирует запросы к backend.
- **backend** – PHP API, логика приложения и работа с MySQL.
- **db** – MySQL 8.4; таблица создается при первом старте из `db/init.sql`, данные сохраняются в Docker volume.
- **web-service** – внутренний Python-сервис для получения данных о погоде через wttr.in.

## Запуск

1. Установите Docker Desktop (Windows/macOS) или Docker Engine с Docker Compose.
2. В корне проекта выполните:

   ```bash
   docker compose up --build -d
   ```

3. Откройте <http://localhost:8080>.
4. Логи: `docker compose logs -f`.
5. Остановить: `docker compose down`.
6. Остановить и удалить сохраненную историю: `docker compose down -v`.

## Примечания

- Для получения погоды контейнеру `web-service` нужен исходящий HTTPS-доступ к `wttr.in`.
- Для упрощения учебного запуска пароли MySQL заданы прямо в `docker-compose.yml`: `weather_app` использует пароль `demo_password`, а root – `demo_root_password`. Это демонстрационные пароли только для локального учебного запуска. Не используйте их на публичном сервере или с реальными данными.
- MySQL выполняет `db/init.sql` только при первом создании пустого тома данных.
- Сервисы связаны внутренней сетью Docker Compose и доступны друг другу по именам `backend`, `db` и `web-service`.
