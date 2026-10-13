# EduAI — MilesWeb/cPanel deployment

This package is a production build of the existing EduAI React interface with a
pure PHP 8.2 and MySQL backend. Python, FastAPI, Docker and Node.js are not
required on the server.

## Server requirements

- PHP 8.2
- MySQL 5.7+ or MySQL 8 / MariaDB 10.4+
- Apache with `mod_rewrite`
- PHP extensions: PDO MySQL, mbstring, fileinfo, zlib, JSON and OpenSSL
- `upload_max_filesize` and `post_max_size` of at least 26M

## One-click installation

1. In cPanel **MySQL Databases**, create a database and database user. Grant the
   user **ALL PRIVILEGES** on that database. Keep the complete database name,
   username and password ready.
2. Upload every file and directory from this package's `public_html` folder
   into the domain's actual `public_html` directory.
3. In cPanel **Select PHP Version**, select PHP 8.2 and enable `pdo_mysql`,
   `mbstring`, `fileinfo`, `zlib`, `json` and `openssl`.
4. Open `https://YOUR-DOMAIN/install.php`.
5. Enter the MySQL details and choose your own administrator name, email and
   password. Click **Install EduAI Securely**.
6. The installer imports `database/eduai.sql`, creates `config.local.php`,
   generates a random application secret, securely hashes your password and
   creates `uploads/install.lock` so setup cannot run again.
7. Open `https://YOUR-DOMAIN/api/health`. A successful installation returns a
   JSON response with `"status":"ok"`.
8. Open the domain and log in with the administrator email/password selected
   in the installer. Choose the **Admin** role on the login screen.

The verified EVS-4 book data is installed automatically, including its 9
detected chapters and 9 lessons. You may upload more selectable-text PDFs from
the Admin/Teacher interface after installation.

No default password is included in the package.

## cPanel PHP limits

If a book upload is rejected before EduAI receives it, create or edit
`.user.ini` inside `public_html`:

```ini
upload_max_filesize=26M
post_max_size=28M
max_execution_time=180
max_input_time=180
memory_limit=256M
```

MilesWeb may take several minutes to apply `.user.ini` changes.

## PDF behavior

The PHP backend securely stores original PDFs and extracts selectable/editable
text directly in PHP, including common Flate-compressed PDF streams. Uploaded
files are MIME-checked, signature-checked, size-limited, randomly renamed and
blocked from script execution. The original editable book PDF remains available
to authenticated teachers/admins at:

`GET /api/content/books/{book_id}/pdf/download`

Image-only scans must first be made searchable/selectable with OCR before
uploading because shared hosting does not provide the system-level OCR binaries
used by the former Python/Docker deployment. This does not affect editable,
selectable-text PDFs.

## API and routing

The React build calls same-domain `/api/...` URLs automatically. Apache routes
all API requests through the PHP API without changing the frontend URLs.
Direct React routes fall back to `index.html`.

Implemented API groups:

- Authentication, signup, login, signed bearer tokens and password changes
- Users/admin listing
- Book upload/delete/list/detail
- Chapters and lessons
- Original PDF and answer-key storage/download/delete
- Lesson-scoped Easy Learn/AI chat
- Quiz and formal test-paper generation
- Quiz submission and progress history
- PDF question papers and answer keys
- Teacher lesson-plan generation
- Health check

## Security

- All database writes and lookups use PDO prepared statements.
- Passwords use `password_hash()` and `password_verify()`.
- Bearer tokens use HMAC-SHA256 signatures and expiration times.
- Admin/teacher operations enforce server-side role checks.
- Uploads are stored outside web execution rules and cannot run as PHP.
- Directory listing is disabled.
- SQL files and sensitive configuration-style files are denied over HTTP.
- Production errors are written to `uploads/eduai-error.log` without exposing
  stack traces to visitors.
- Chat, Easy Learn, lesson-plan teaching points, quizzes and test answers are
  generated only from the currently selected lesson. Questions with no lesson
  match return a clear “not available in the selected lesson” response instead
  of guessing from outside knowledge.

## Writable directories

Normally cPanel creates uploaded files with suitable ownership. If uploads fail,
set these directories to permission `755` or `775`:

- `uploads/`
- `uploads/books/`
- `uploads/answer_keys/`

Do not use permission `777`.
