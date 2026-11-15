# Admission Information Chatbot

PHP 8 + SQLite web application: an NLP-based chatbot answering university admission enquiries, with a staff console.

## Run
Double-click `start.bat` (needs PHP 8.1+ with pdo_sqlite), or:

    php -S 127.0.0.1:8400 -t .

- Chatbot: http://127.0.0.1:8400/
- Staff console: http://127.0.0.1:8400/admin.php

Demo staff accounts: `admin@admissionbot.test` / `admin123` (administrator), `officer@admissionbot.test` / `officer123` (admission officer).

## Tools
- `php tools/seed_traffic.php` creates simulated demonstration traffic.
- `php tools/evaluate.php` evaluates accuracy on the development and held-out sets.

## Notes
The knowledge base contains general Nigerian admission guidance. Dates, fees and cut-off marks are deliberately not quoted; replace the entries with your institution's verified information in the staff console.
