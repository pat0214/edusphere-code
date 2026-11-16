# EduSphere backend — LavaLust (PHP) API

EduSphere's JSON REST API, built on **LavaLust v4** (https://github.com/ronmarasigan/LavaLust). No views — every controller returns the standard JSON envelope. Deployed on Render via the included Dockerfile; data lives in MySQL on Aiven.

```json
{ "success": true, "message": "…", "data": { }, "errors": null }
```

## What this repo is

This directory is a complete LavaLust 4.7.0 project, cloned from commit `74045f12bafcbd41b08b6f581706d683b729c768`. EduSphere's application code lives inside LavaLust's `app/` folders; the framework's `scheme/`, `public/`, `runtime/`, and `console/` remain in their standard locations. The Aiven CA verification setting is applied directly in the framework's PDO connection code and is enabled only when `DB_SSL_CA` is set.

## Structure

```
scheme/                        → LavaLust core framework and database layer
public/index.php               → framework front controller / web document root
runtime/                       → framework runtime data (writable)
console/                       → framework CLI support
app/config/                    → framework configuration + EduSphere routes/database/autoload
app/controllers/               → EduSphere API controllers
app/models/                    → LavaLust models + EduSphere data models
app/libraries/                 → framework libraries + EduSphere JWT, engines, and integrations
app/helpers/                   → framework helpers + EduSphere API helper
app/kernel|language|middlewares|modules|views|migrations/ → LavaLust extensions and remaining app folders
database/schema.sql · seed.sql · NAVICAT.md → EduSphere data setup
```

## Framework conformance (audited against the cloned kernel)

| Area | Convention checked | Status |
|---|---|---|
| Controllers | `*Controller.php` classes extend LavaLust's auto-loaded `MY_Controller`; shared `before_action()` uses LavaLust CORS | conformed |
| Models | `app/models/X_model.php`, `class X_model extends Model`, loaded via `$this->call->model('X_model')`; all application database queries are kept in models | conformed |
| Routes | `app/config/routes.php`, `$router->get/post/patch`, `{param}` placeholders | conformed |
| Loader | `app/config/autoload.php` loads `database` library + `api` helper; controllers never re-`helper()` the same file | conformed |
| Requests / responses | JSON, bearer tokens, IPs, webhook headers, and response emission use LavaLust `Request` / `Response` APIs; the existing `{success,message,data,errors}` envelope is preserved | conformed |
| CORS | `ALLOWED_ORIGINS` populates LavaLust's `security_headers.cors` allow-list; API controllers invoke the framework `handle_cors()` helper | conformed |
| Database | Models obtain the shared connection with `load_class('database','database')::instance()`; controllers, helpers, and libraries delegate persistence to models. Models use LavaLust query-builder methods for straightforward reads and CRUD, and prepared `raw($sql, $args)` where joins, aggregates, or database-specific expressions need it; Aiven TLS is added conditionally at the PDO options boundary | conforms with narrow driver patch |
| Libraries | `app/libraries/*` plain classes loaded via `$this->call->library()` (JWT, engines, clients) | conformed |
| Views | unused by design — JSON envelope via kernel Response header pattern | n/a (API-only) |

## Run locally

1. From this repository's root, copy `.env.example` to `.env` and fill in DB + JWT values. Set `GOOGLE_CLIENT_ID` to the same web client ID used by the frontend for Google sign-in. Configure Resend with `RESEND_API_KEY` and a verified `MAIL_FROM` sender, or configure `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASSWORD`, `SMTP_SECURE`, `MAIL_FROM_ADDRESS`, and `MAIL_FROM_NAME` for SMTP. Use `tls` for STARTTLS, `ssl` for implicit TLS, or `none` for unencrypted SMTP. If PHP cURL has no system trust store, set `CURL_CA_BUNDLE` to a trusted CA bundle path. Obtain the CA certificate for your Aiven service and save it as `certs/aiven-ca.pem`; relative `DB_SSL_CA` paths resolve from this repository's root. A missing or unreadable CA now returns a JSON 503 instead of a blank HTTP 500.
2. Build and start the LavaLust API from this repository's root:

   ```bash
   docker build -t edusphere-api .
   docker run --rm --env-file .env --mount "type=bind,source=C:\path\to\ca.pem,target=/run/secrets/aiven-ca.pem,readonly" -p 8080:80 edusphere-api
   ```

3. Check `http://localhost:8080/api/v1/health`.

The Docker build uses the LavaLust framework already present in this directory; it no longer downloads or overlays framework files during the build. Omit the CA mount for a local database and leave `DB_SSL_CA` unset. Do not copy `.env` into an image or commit it.

## Demo accounts (seeded): `aria@edusphere.dev` · `instructor@edusphere.dev` · `admin@edusphere.dev` — all `password123`. Verify demo credential: `GET /api/v1/verify/EDU-7Q4M-92XT`.

## Aiven SSL

When `DB_SSL_CA` points to Aiven's CA certificate, the LavaLust PDO connection verifies the server certificate. This setting is conditional and does not enable TLS options for local databases that do not provide a CA path. Do not remove the CA setting to bypass certificate validation for a remote database. Local `certs/*.pem` files are gitignored; for Render, upload the CA as a Secret File named `aiven-ca.pem` on the API service. Render exposes it at `/etc/secrets/aiven-ca.pem`, which is the path configured by `render.yaml`.

## Recovering an incomplete AI Tutor migration

Migration `database/migrations/007_ai_tutor.sql` is safe to rerun. If `/api/v1/health` reports `aiTutorSchema: "migration_required"`, run the full migration again against the database named by the Render API's `DB_NAME`. The dashboard remains available while those tables are missing; the AI Tutor returns a specific migration message until the schema is ready.

## Render deploy

`render.yaml` defines the Docker API web service and a one-minute notification-email Cron Job. The Dockerfile builds this complete LavaLust project, enables `mod_rewrite` for LavaLust's `public/index.php`, installs `pdo_mysql`, and honors Render's `PORT`. The image creates group `1000` if needed and adds Apache's `www-data` user to it so it can read Render Docker Secret Files. The blueprint also provisions a persistent disk for uploaded course materials and defaults `APP_URL` and `ALLOWED_ORIGINS` to `https://edusphere-front.onrender.com`. If the frontend uses a different URL, update both values in the Render service dashboard. Upload the Aiven CA as a Render Secret File named `aiven-ca.pem`; set the DB environment variables in the service dashboard. Health check: `/api/v1/health`.

### Classroom migration and hosting setup

1. Apply `database/migrations/008_classrooms.sql`, `database/migrations/009_jitsi_live_sessions.sql`, and `database/migrations/010_self_hosted_jitsi.sql` to the **same database configured in Render's `DB_NAME`** before deploying this version. For this deployment, use `defaultdb` if that is the value in Render; do not run the migration in a different Aiven database. These migrations are safe to rerun. Migration 010 switches existing JaaS settings to self-hosted Jitsi and ends any active JaaS sessions. After deploy, `/api/v1/health` should report `classroomSchema: "ready"`, `jitsiSchema: "ready"`, and `notificationEmailSchema: "ready"`.
2. The blueprint shares a generated `EMAIL_WORKER_TOKEN` and `API_BASE_URL` through the `edusphere-notification-worker` environment group. If you already manage the Render services separately rather than syncing this blueprint, add a Cron Job that POSTs to `$API_BASE_URL/api/v1/internal/notification-email-worker` every minute with `Authorization: Bearer $EMAIL_WORKER_TOKEN`; set the exact same random token on the API and worker. Configure Resend (`RESEND_API_KEY`, `MAIL_FROM`) or SMTP on the API. Queued notifications retry with backoff and stop after five attempts. User and admin email preferences can cancel pending messages; in-app notifications continue regardless.
3. Configure self-hosted Jitsi live sessions:
   - Host Jitsi Meet on a separate VPS/server with a public HTTPS domain; the Render API service does not host the Jitsi media bridge. Follow the [official Jitsi Docker self-hosting guide](https://jitsi.github.io/handbook/docs/devops-guide/devops-guide-docker/) and configure `PUBLIC_URL=https://meet.example.edu`.
   - Enable JWT authentication on that Jitsi server (`ENABLE_AUTH=1`, `AUTH_TYPE=jwt`). Set its `JWT_APP_ID` and `JWT_APP_SECRET`; if configuring allowlists, set `JWT_ACCEPTED_ISSUERS` to that app ID and `JWT_ACCEPTED_AUDIENCES=jitsi`.
   - Add matching `JITSI_JWT_APP_ID` and `JITSI_JWT_SECRET` to the backend Render environment only. Use a long random secret (at least 32 characters); never put it in frontend variables or source control.
   - In Admin Console > **Classrooms & email**, select **Self-hosted Jitsi with JWT**, enter only the Jitsi hostname (for example `meet.example.edu`, without `https://` or a path), and save. The migration defaults to self-hosted mode but leaves the domain blank until you configure it.
   - EduSphere creates a random room for each class session and issues a short-lived JWT only after checking active classroom membership. Teachers receive moderator claims; students do not. The IFrame API loads from your self-hosted domain; users can open a meeting in a new tab if embedding fails.
   - When upgrading from JaaS, remove the old `JAAS_APP_ID`, `JAAS_API_KEY_ID`, and `JAAS_PRIVATE_KEY` variables from the Render API service; this version no longer uses them.
   - The optional `meet.jit.si` public mode remains available for testing, but it is not the selected production provider.
4. Configure Google Cloud only if you want optional Calendar invitations:
   - Enable the Google Calendar API and create an OAuth **Web application** client.
   - Add the exact Render API callback URL `https://edusphere-api.onrender.com/api/v1/classrooms/google/callback` as an authorized redirect URI. Update `GOOGLE_OAUTH_REDIRECT_URI` if your API URL differs.
   - Set `GOOGLE_OAUTH_CLIENT_ID`, `GOOGLE_OAUTH_CLIENT_SECRET`, and `GOOGLE_TOKEN_ENCRYPTION_KEY` on the **backend only**. Generate the encryption key with `php -r "echo base64_encode(random_bytes(32));"`. Never put the OAuth secret, refresh token, or encryption key in frontend variables or source control.
   - The instructor connects a Google account in each classroom. EduSphere uses the Calendar `calendar.events` scope with OAuth state and PKCE, encrypts refresh tokens with AES-256-GCM, creates an ordinary calendar event (not Google Meet), links back to EduSphere, and invites only active classroom members. Google OAuth consent-screen publishing/testing requirements still apply to your Google project. Google is optional; a Google failure never prevents a Jitsi session from starting.
5. The Admin Console's **Classrooms & email** section controls classroom/live-session/email availability, provider selection, and backend-enforced capacity limits. Attendance is labeled as members who joined through EduSphere; actual in-meeting participation is not independently verified.

## React frontend integration and current scope

The standalone React frontend uses Vite's `/api` proxy to reach this API at `http://127.0.0.1:8080` during development. For a separately hosted API, set `VITE_API_BASE_URL` in the frontend environment and add the frontend origin to `ALLOWED_ORIGINS`.

Set `JWT_SECRET` to at least 32 random characters before using sign-in or registration. The API intentionally has no built-in development signing key.

Billing uses PayMongo hosted checkout with GCash, Maya, and card. Pro is a one-time ₱699 purchase; Team is ₱469 per seat with a three-seat minimum (₱1,407 total). Each successful purchase grants 30 days and does not auto-renew. Apply `database/migrations/005_paymongo_one_time_payments.sql` to an existing database before using checkout. Apply `database/migrations/006_module_quizzes.sql` to add instructor-created module quizzes and student quiz attempts. For local testing, set `PAYMONGO_SECRET_KEY` to a PayMongo test secret key and `PAYMONGO_WEBHOOK_SECRET` to the signing secret for a webhook subscribed to `checkout_session.payment.paid` at `/api/v1/payments/paymongo-webhook`; set `APP_URL` to the frontend origin and restart the API. Use a public HTTPS tunnel to receive PayMongo webhooks locally. Payments and plan access are recorded only after a valid signed paid webhook. Do not share or commit secret keys.

For the Playground AI Learning Assistant, apply `database/migrations/007_ai_tutor.sql` before deploying the API. Configure `GEMINI_API_KEY` (optionally `GEMINI_MODEL`) only in the backend environment; never expose the key in frontend variables. Admins can configure daily credits and feature costs. Failed provider calls release reserved credits, repeated request IDs are not charged twice, and ordinary code runs remain free.

The LavaLust API covers account registration and email verification, existing-account and Google authentication, course catalog/detail/enrollment and authoring, course materials, module quizzes (multiple choice and identification), final assessments, coding-assignment authoring/submissions, subject classrooms and membership approvals, Jitsi live sessions, optional Google Calendar invitations, placement and learning paths, exercises/playground, peer review, student/instructor/admin analytics, credentials, notifications, payments, uploads, and admin user management. In-app notifications are saved immediately; verified-recipient emails are delivered asynchronously through the notification queue after migration 008 is applied and the Render Cron Job is configured. Certificate and verification emails retain their existing delivery flow. Course materials are stored under `.data/course-materials`; keep that directory persistent and private in deployments.

Set `ALLOWED_ORIGINS` to the exact deployed frontend origin(s). The API uses bearer access tokens and the standard JSON response envelope. PHP uploads must allow 25 MB files (`upload_max_filesize=25M`, `post_max_size=26M`); the frontend's local `npm run dev` command applies these PHP CLI limits automatically.

## Testing (4 canonical flows)

1. **Placement → path**: `POST /api/v1/auth/register` → `GET /api/v1/assessments/placement` → `POST /api/v1/assessments/placement/submit` → `GET /api/v1/learning-path`. Submitting re-sequences items by skill gap; response includes `skillGaps` and earned badges.
2. **Path progression**: complete every lesson in the active course path, then return to `/path`. The completed course is skipped and the next published course is generated in level order (beginner, intermediate, advanced); when none remain, the path page offers the course catalog.
3. **Code → results**: configure a private `JUDGE0_URL`, then `POST /api/v1/playground/run` with Two Sum. Exercise tests run through Judge0. Free runs send source and stdin for JavaScript (63), Python (71), C (50), C++ (54), or C# (51), and award no XP. This API intentionally has no local host-process execution fallback.
4. **Assignment → anonymous review**: `POST /api/v1/submissions` (kind=assignment) assigns 3 least-loaded reviewers (never self, never dupes). Reviewer: `GET /api/v1/peer-review/queue` (aliases only) → `POST /api/v1/peer-review/submit`. Author: `GET /api/v1/peer-review/feedback` (reviewer aliases, never IDs).
