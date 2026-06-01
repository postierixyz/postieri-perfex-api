# Coolify deployment guide

Two ways to deploy this. **Option A is simpler** — pick that unless you need
the DB inside the same compose stack.

---

## Option A — "Docker Image" service (recommended)

Coolify pulls the prebuilt image, you add a separate MySQL.

### 1. Trigger a build

The image auto-pushes to `ghcr.io` on every push to `main`:

- Latest: `ghcr.io/postierixyz/postieri-perfex-api:latest`
- Pinned: `ghcr.io/postierixyz/postieri-perfex-api:3.4.1`

To force a rebuild: `git push` (or trigger `.github/workflows/docker.yml`
manually from the Actions tab).

### 2. Add a MySQL database in Coolify

1. **+ New Resource → Database → MySQL**
2. Name: `postieri-perfex-db`
3. Username: `perfex`
4. Password: _generate a strong one, save it_
5. Database name: `perfex`
6. **Save** — note the internal hostname Coolify shows (usually
   `postieri-perfex-db` or similar)

### 3. Add the Perfex service

1. **+ New Resource → Service → Docker Image**
2. **Image URL:** `ghcr.io/postierixyz/postieri-perfex-api:3.4.1`
3. **Port:** `80`
4. **Domain:** map something like `perfex.postieri.xyz` (Coolify auto-issues TLS)
5. **Add these environment variables:**

| Key | Value | Notes |
|---|---|---|
| `APP_URL` | `https://perfex.postieri.xyz` | Match the domain exactly |
| `DB_HOSTNAME` | `postieri-perfex-db` | The MySQL service hostname |
| `DB_PORT` | `3306` | |
| `DB_USERNAME` | `perfex` | |
| `DB_PASSWORD` | _same as in step 2_ | Mark as secret |
| `DB_NAME` | `perfex` | |
| `CRON_ENABLED` | `true` | |

6. **Add a persistent volume** for uploads (so customer-uploaded files survive
   container rebuilds):
   - Container path: `/var/www/html/uploads`
   - Type: Named volume
7. **Deploy**

### 4. Complete the Perfex installer

1. Open `https://perfex.postieri.xyz` — you'll see the Perfex installer
2. Click "Install" — DB connection is pre-wired
3. Set your admin email & password
4. Log in

### 5. Activate the Postieri API module

1. Sidebar → **Setup → Modules**
2. Find "Postieri API" — click **Activate**
3. Sidebar → **Setup → Postieri API** to manage tokens & webhooks

### 6. (Optional) Wire cron

Coolify services don't run cron automatically. Either:

- **Easiest:** Add a second "Docker Image" service in Coolify that runs a
  minimal cron loop (we can build that as a sidecar), OR
- **Coolify has a built-in cron feature** for each service — add a
  "Scheduled Task" that runs `php /var/www/html/index.php cron/index` every
  minute.

---

## Option B — "Docker Compose" service

1. **+ New Resource → Service → Docker Compose**
2. Point Coolify at this repo
3. **Set environment variables** before deploy:

| Key | Value |
|---|---|
| `DB_PASSWORD` | _generate, save it_ |
| `MYSQL_ROOT_PASSWORD` | _generate, save it_ |
| `DB_USERNAME` | `perfex` |
| `DB_NAME` | `perfex` |
| `DB_HOSTNAME` | `db` |
| `APP_URL` | `https://perfex.postieri.xyz` |

4. **Domain:** `perfex.postieri.xyz`
5. **Deploy**

Coolify will build the image from `docker/Dockerfile` and start both
containers.

---

## Verifying the API is live

From your laptop (or from this terminal):

```bash
# 1. Issue a token
curl -X POST https://perfex.postieri.xyz/api/v1/auth/token \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@postieri.xyz","password":"your-admin-pwd","name":"smoke-test"}'

# 2. List customers (paste the token from step 1)
curl https://perfex.postieri.xyz/api/v1/customers \
  -H "Authorization: Bearer ptx_xxxxxxxxxxxxx"
```

You should see a `{"status": true, "data": [...]}` envelope.

---

## Troubleshooting

| Symptom | Fix |
|---|---|
| 502 Bad Gateway | Container still building / starting. Check Coolify logs. |
| Installer loops back to step 1 | `db_debug` likely off — set `ENVIRONMENT=development` env var temporarily, check `/var/www/html/application/logs/`. |
| `Table 'tblpostieri_api_tokens' doesn't exist` | Module is installed but tables not created. Deactivate + reactivate the module in Setup → Modules. |
| Module not visible in Setup → Modules | The `modules/postieri_api/postieri_api.php` file isn't in the image. Rebuild: clear Docker cache in Coolify, redeploy. |
| Webhooks not firing | `CRON_ENABLED=true` is set, but Coolify might not be running cron. Add a scheduled task. |
