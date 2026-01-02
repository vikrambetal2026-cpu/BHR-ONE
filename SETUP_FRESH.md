# Job Portal – Fresh Setup

## 1) Configure (no hardcoded secrets)
- Copy `.env.example` to `.env` and fill values, OR set these environment variables in your hosting panel:
  - `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`
  - `API_KEY`
  - `HF_TOKEN` (optional)
  - `RZP_KEY_ID`, `RZP_KEY_SECRET`, `RZP_WEBHOOK_SECRET` (optional)

## 2) Fresh database setup (overwrite)
Run:
- `sql/fresh_setup.sql`

Then load your candidate data into:
- `candidate_freetext(candidate_id, free_text)`

## 3) HTTP-only behavior
This build forces internal URLs to `http://` by default to avoid deployments where HTTPS breaks.
- To allow HTTPS URL generation, set `ALLOW_HTTPS_URLS=1` and `FORCE_HTTP=0`.

## 4) SSL verification disabled (as requested)
cURL SSL verification is disabled by default for both internal/external calls.
- For production, set:
  - `CURL_INSECURE_INTERNAL=0`
  - `CURL_INSECURE_EXTERNAL=0`


## Using an existing database (recommended on shared hosting)

- Import `sql/fresh_setup_existing_db.sql` (does not CREATE DATABASE).
- Create `api/config.local.php` (copy from `api/config.local.php.example`) and set DB_HOST/DB_NAME/DB_USER/DB_PASS.
- Test: open `/bhrjp/api/diag.php?debug=1` to confirm DB + tables.
