# UserAuditLogPlugin — Concept & Design

> Status: **Ready for Implementation**
> Branch: `lime-survey-plugin`
> Scope: POC — all surveys, localhost only

---

## 1. Purpose

An eCRF audit log must capture a **full chronological record** of every interaction a user
makes with a survey — not just the final submitted values. This includes entering the survey,
navigating between pages, and every individual answer change (even if a value is overwritten
multiple times before submission).

This plugin replaces the HelloWorld console-only approach with **persistent server-side logging
into PostgreSQL**.

---

## 1a. Parallel Workflow — How This Sits Alongside LimeSurvey

**LimeSurvey's own persistence model:**
- Answers are only written to the LimeSurvey response table (`lime_survey_XXXXX`) when the
  user clicks **Next, Previous, or Submit**.
- Each click sends a full-page POST; LimeSurvey saves the current state of all answers on that
  page as a bulk snapshot.
- LimeSurvey has **no field-level change history** — it only ever knows the last saved value.

**Our audit log runs as a parallel, independent layer using AJAX:**

When a user changes a field, the browser JS immediately fires a silent background HTTP request
(AJAX) to our plugin endpoint — **no Next click required, no page reload, invisible to the user**.
The PHP endpoint receives it and writes a row to `lime_user_audit_log` right away.

```
User changes BPM: 72 → 75
  → JS fires AJAX in background immediately
  → audit row written: old_value=72, new_value=75

User changes BPM again: 75 → 80
  → JS fires AJAX in background immediately
  → audit row written: old_value=75, new_value=80

User clicks Next
  → LimeSurvey saves 80 to lime_survey_XXXXX  (only sees the final value)
  → our beforeSurveyPage hook fires
  → audit row written: event_type=page_load
```

LimeSurvey ends up knowing the answer was **80**.
Our audit log records the full history: **72 → 75 → 80**, with a timestamp on each change.

If a user changes an answer and closes the browser without clicking Next, LimeSurvey loses
those changes entirely — our audit table still has them.

**When exactly does an `answer_change` event fire?**

The JS listens for jQuery's `change` event (via `$(document).on("change", ...)`) rather than
the native DOM `addEventListener`. This is required because LimeSurvey's date picker and other
widgets fire jQuery-triggered change events that do not propagate to native DOM listeners.
The event fires at different moments depending on the input type:

| Input type | When the audit row is written |
|---|---|
| Radio button | As soon as a new option is selected |
| Checkbox | As soon as it is checked / unchecked |
| Dropdown / select | As soon as a new option is picked |
| Text / number field | When the user **leaves the field** (tabs out or clicks away) |
| Date picker | When a date is selected |

For text fields this means: if a user types `7`, corrects to `72`, then to `720`, then
backspaces back to `72` — only **one** audit row is written (when they leave the field),
with `new_value=72`. The intermediate keystrokes are not logged. This is intentional —
it gives one clean "committed value" entry per field interaction rather than one row per
keystroke, which keeps the audit log readable and meaningful for eCRF review.

> **Note (post-POC):** LimeSurvey supports many additional question types beyond the ones
> listed above — including arrays, ranking, slider, file upload, geolocation, equation,
> image select, and dual-scale arrays. Each has a different HTML structure and may not
> fire a standard `change` event in the same way. The POC covers the common types
> (radio, checkbox, select, text, date). A full production implementation must audit
> all LimeSurvey question types and ensure each is correctly captured by the JS listener.

---

## 2. What Gets Logged

Every row in the audit table represents one discrete event. The table is sorted by `created_at`.

| `event_type` | Triggered by | Notes |
|---|---|---|
| `survey_open` | PHP: `beforeSurveyPage` on page 1 | User enters the survey for the first time |
| `page_load` | PHP: `beforeSurveyPage` on pages > 1 | User navigated to a new page (next/prev) |
| `answer_change` | JS → AJAX → PHP endpoint | User changed an answer within a page |
| `survey_submit` | PHP: `afterSurveyComplete` | User submitted the completed survey |

### Why AJAX for answer changes?

LimeSurvey pages are server-rendered PHP. Answer changes within a single page happen
entirely in the browser — they never reach the server until the user clicks "next".
To log each individual change immediately (before navigation), JavaScript must capture
the event and POST it to a small PHP endpoint exposed by this plugin.

---

## 3. OAuth Enforcement

**POC scope: enforce for ALL surveys.**

> **Note on testing:** Keycloak is not available in the local POC environment. OAuth
> enforcement will be verified using LimeSurvey's local admin login as a stand-in.
> The redirect logic is the same regardless of auth provider.

### Goal
A user who only has a survey link must be blocked from accessing the survey without
authenticating first. After login they should ideally return directly to the survey.

---

### Option A — POC Implementation

Redirect unauthenticated users to the OAuth login page. After login, AuthOAuth2 lands
them on the admin dashboard. They must navigate back to the survey URL themselves.

**Flow:**
```
User opens survey URL
        │
        ▼
beforeSurveyPage fires
        │
        ├── isGuest? ──NO──► proceed, log access
        │
        YES
        │
        └── Redirect to:
            /index.php/admin/authentication/sa/login/authMethod/AuthOAuth2

            [user logs in → lands on admin dashboard]
            [user must open survey URL again]
```

Simple to implement. No hooks beyond `beforeSurveyPage`. No Keycloak dependency for
testing. UX is acceptable if users are instructed to log in before opening a survey link.

---

### Option B — Post-POC: Return to Survey After Login

After login, the user is redirected automatically back to the survey URL they came from.
No manual navigation needed.

**Approach:** Subscribe to LimeSurvey's `newUserSession` hook, which fires after any
successful authentication (form login, OAuth, or any auth plugin) — no changes to
AuthOAuth2 required.

> **Tested & rejected (POC):** `newUserSession` fires on every request with an existing
> session, not only at login. Using it for redirect breaks the login flow entirely.
> The `beforeSurveyPage` fallback (Option A flow) is used instead.

**Flow:**
```
User opens survey URL (e.g. /index.php/12345?token=abc123)
        │
        ▼
beforeSurveyPage — isGuest?
        │
        YES
        │
        ├── Store URL in session:
        │   session['userauditlog_return_url'] = current URL
        │
        └── Redirect to OAuth login

        [user authenticates]
        │
        ▼
newUserSession hook fires in our plugin
        │
        ├── 'userauditlog_return_url' in session? ──NO──► do nothing
        │
        YES
        │
        ├── Read + unset the stored URL
        └── Redirect → user lands back on the survey
```

**Fallback** (if `newUserSession` does not fire as expected): recover the stored URL
on the next `beforeSurveyPage` call instead — the user is now authenticated, so we
redirect then and clear the session key.

> Per-survey toggle (using `newSurveySettings`) is a further post-POC step.

---

## 4. Database Schema

### Why a separate table (not combined with `lime_auditlog_log`)

The built-in `lime_auditlog_log` table is owned and written by LimeSurvey's own AuditLog
plugin and tracks admin interface actions only. Our table serves a different purpose and
audience entirely — eCRF participant interactions by authenticated clinical users.

Merging the two would require either squeezing our structured data into their untyped
`oldvalues`/`newvalues` text blobs (losing all typed columns), or adding our columns to
a table we don't own (breaks if the built-in plugin is disabled or updated).

Both tables coexist independently. They answer different questions:
- `lime_auditlog_log` → "who changed survey configuration in the admin panel?"
- `lime_user_audit_log` → "what did clinician X enter for patient Y in question BPM and when?"

### Design decisions (vs. built-in `lime_auditlog_log`)

| Built-in AuditLog | UserAuditLogPlugin |
|-------------------|--------------------|
| One row = one admin action | One row = one discrete event or answer change |
| `entity` + `action` as free text | Typed `event_type` column |
| `fields`/`oldvalues`/`newvalues` as CSV/blob | Flat columns: `old_value`, `new_value`, `question_id`, `question_code` |
| Admin interface only | Survey participant + OAuth user events |
| No session or page context | `page_number`, `group_id`, `session_id` |

### Table: `lime_user_audit_log`

```sql
CREATE TABLE lime_user_audit_log (
    -- Identity
    id                  BIGSERIAL       PRIMARY KEY,

    -- When (table sorted by this)
    created_at          TIMESTAMPTZ     NOT NULL DEFAULT NOW(),

    -- Which survey / participant
    survey_id           INTEGER         NOT NULL,
    participant_token   VARCHAR(255),       -- survey access token

    -- Who (authenticated via OAuth / Keycloak)
    oauth_user_id       VARCHAR(255),       -- Yii::app()->user->id
    oauth_username      VARCHAR(255),       -- Yii::app()->user->name

    -- What kind of event
    event_type          VARCHAR(50)     NOT NULL,
    -- Values: survey_open | page_load | answer_change | survey_submit

    -- Navigation context (set for all event types)
    page_number         INTEGER,            -- current page/step index
    group_id            INTEGER,            -- LimeSurvey question group ID

    -- Question context (set for answer_change only)
    question_id         INTEGER,            -- LimeSurvey internal numeric question ID
    question_code       VARCHAR(255),       -- researcher-defined question code (e.g. "BPM")
    sub_question_code   VARCHAR(50),        -- for matrix/array questions (row code)
    input_type          VARCHAR(50),        -- radio | checkbox | text | select | date | ...

    -- Change payload (set for answer_change; old_value NULL on first answer)
    old_value           TEXT,
    new_value           TEXT,

    -- Network / session context
    session_id          VARCHAR(255),       -- PHP session ID for correlation
    ip_address          VARCHAR(45)         -- supports IPv6
);

-- Indexes
CREATE INDEX idx_ual_survey_id   ON lime_user_audit_log (survey_id);
CREATE INDEX idx_ual_created_at  ON lime_user_audit_log (created_at);
CREATE INDEX idx_ual_oauth_user  ON lime_user_audit_log (oauth_user_id);
CREATE INDEX idx_ual_token       ON lime_user_audit_log (participant_token);
CREATE INDEX idx_ual_event_type  ON lime_user_audit_log (event_type);
```

### Rationale for flat columns instead of JSONB

Each row is one atomic change to one question. Flat columns allow direct SQL queries
without unpacking JSON:

```sql
-- Full history for one participant, chronological
SELECT created_at, event_type, question_code, old_value, new_value
FROM lime_user_audit_log
WHERE participant_token = 'abc123'
ORDER BY created_at;

-- All changes to a specific question across all sessions
SELECT * FROM lime_user_audit_log
WHERE survey_id = 12345
  AND question_code = 'BPM'
ORDER BY created_at;
```

### `question_id` and `question_code` — POC scope

LimeSurvey has two question identifiers:
- `question_id` — internal numeric ID, parseable directly from the input name (`answer{SID}X{QID}X{SQID}`)
- `question_code` — set by the survey designer (e.g. `"BPM"`, `"VISIT_DATE"`), not in the DOM

**POC:** only `question_code` is logged. PHP injects a `{ questionId → questionCode }` map for
the current page; JS looks up the code via the numeric ID parsed from the input name and sends
it in the AJAX payload. The `question_id` column stays `NULL`.

> **Post-POC:** parse the numeric `question_id` directly from the input name and populate that
> column as well. Both columns then act as a fallback for each other.

### `old_value` and `new_value` policy

| Situation | `old_value` | `new_value` |
|-----------|------------|-------------|
| First time answering a question | `null` | the entered value |
| Changing an existing answer | previous value | the new value |
| Clearing an answer | previous value | `null` |

Every `answer_change` event has both fields present (null = no value). This gives a full
diff history even if a question is changed 10 times in one session.

---

## 5. Plugin Architecture

### Files

```
customPlugins/UserAuditLogPlugin/
├── CONCEPT.md
├── config.xml
└── UserAuditLogPlugin.php
```

### PHP hooks

| Hook | Method | What it does |
|------|--------|-------------|
| `beforeSurveyPage` | `onBeforeSurveyPage()` | 1. Redirect guest to OAuth login. 2. Log `survey_open` or `page_load`. |
| `afterSurveyComplete` | `onAfterSurveyComplete()` | Log `survey_submit`. |
| `newUnsecuredDirectRequest` | `onNewUnsecuredDirectRequest()` | Expose AJAX endpoint for JS to POST `answer_change` events. |

### AJAX endpoint (for answer_change)

URL pattern:
```
GET/POST /index.php/plugins/unsecure/plugin/UserAuditLogPlugin/function/logAnswerChange
```

Payload (POST JSON body):
```json
{
  "survey_id":        12345,
  "group_id":         3,
  "page_number":      2,
  "question_id":      42,
  "question_code":    "BPM",
  "sub_question_code": null,
  "input_type":       "text",
  "old_value":        "72",
  "new_value":        "75"
}
```

The endpoint:
1. Validates the request originates from the same session (checks PHP session)
2. Reads `oauth_user_id`, `oauth_username`, `participant_token`, `session_id`, `ip_address`
   from the server side (never trusted from the client payload)
3. Writes one row to `lime_user_audit_log`
4. Returns **HTTP 200** on success, non-200 on failure — no JSON body required.
   The JS checks `response.ok` and silently ignores errors (fire-and-forget).

### JavaScript (injected via `beforeSurveyPage`)

The JS is the same event-driven approach as HelloWorld, but instead of `console.log` it
fires `fetch()` to the AJAX endpoint above.

Events captured:
- `change` on any `input`, `select`, `textarea` → `answer_change`
- Page submit button click → nothing extra needed (server-side `beforeSurveyPage` handles it)

**Question code lookup:** PHP injects a `questionCodeMap` JSON object (`{ "42": "BPM", ... }`)
for all questions on the current page. JS parses the numeric question ID from the input name
and looks up the corresponding `question_code` from the map.

> **LimeSurvey 6 input name format:** The `answer` prefix was dropped in LimeSurvey 6.
> Input names follow the pattern `{SID}X{GID}X{QID}` (e.g. `376198X6X18`), where the
> third segment is the question ID and the second segment is the group ID.
> The regex `^(\d+)X(\d+)X(\d+)(\w*)$` is used instead of the old `answer\d+X...` pattern.
Only `question_code` is sent in the AJAX payload (POC scope — `question_id` column stays NULL).

Old value tracking: the JS records the value of each field at page load, then sends the
snapshot as `old_value` when a `change` event fires.

---

## 6. Table Creation

The plugin creates its own table on first activation. No manual migration needed.

```php
private function ensureTable(): void
{
    $db    = Yii::app()->db;
    $table = $db->tablePrefix . 'user_audit_log';

    if ($db->schema->getTable($table) !== null) {
        return;
    }

    $db->createCommand()->createTable($table, [
        'id'                 => 'BIGSERIAL PRIMARY KEY',
        'created_at'         => 'TIMESTAMPTZ NOT NULL DEFAULT NOW()',
        'survey_id'          => 'INTEGER NOT NULL',
        'participant_token'  => 'VARCHAR(255)',
        'oauth_user_id'      => 'VARCHAR(255)',
        'oauth_username'     => 'VARCHAR(255)',
        'event_type'         => 'VARCHAR(50) NOT NULL',
        'page_number'        => 'INTEGER',
        'group_id'           => 'INTEGER',
        'question_id'        => 'INTEGER',
        'question_code'      => 'VARCHAR(255)',
        'sub_question_code'  => 'VARCHAR(50)',
        'input_type'         => 'VARCHAR(50)',
        'old_value'          => 'TEXT',
        'new_value'          => 'TEXT',
        'session_id'         => 'VARCHAR(255)',
        'ip_address'         => 'VARCHAR(45)',
    ]);

    // Create indexes
    foreach (['survey_id','created_at','oauth_user_id','participant_token','event_type'] as $col) {
        $db->createCommand()->createIndex("idx_ual_{$col}", $table, $col);
    }
}
```

---

## 7. Dockerfile Change

```dockerfile
COPY --chown=33:33 customPlugins/UserAuditLogPlugin /var/www/html/plugins/UserAuditLogPlugin
```

---

## 8. Implementation Plan

### Working agreement

- **One step per session round.** A step is implemented, then reviewed by the developer before the next step begins.
- **Docker-testable after every step except Step 1.** The Dockerfile entry is added in Step 2 so the plugin is mounted into the container from that point on. Every subsequent step ends with a `docker compose up --build` and a manual test.
- **Status is updated in this table** after each completed step so progress is clear when resuming across sessions.
- **`newUnsecuredDirectRequest` routing note:** The `function` segment in the AJAX URL (`/function/logAnswerChange`) is just a string parameter we read with `$this->event->get('function')` inside the single `newUnsecuredDirectRequest` handler. It does **not** need to match a PHP method name — the name is freely chosen.

| Step | Title | Short Description | Status |
|------|-------|-------------------|--------|
| 1 | `config.xml` | Create `config.xml` for `UserAuditLogPlugin` (based on HelloWorld). No Docker test needed for this step. | **done** |
| 2 | Skeleton `UserAuditLogPlugin.php` + Dockerfile | Create the PHP class with `init()` subscribing all three hooks (`beforeSurveyPage`, `afterSurveyComplete`, `newUnsecuredDirectRequest`). Method bodies empty/stubbed. **Also add the Dockerfile COPY line in this step** so the plugin is in the container from here on. Rebuild, activate plugin in LimeSurvey admin, verify it loads without errors. | **done** |
| 3 | `ensureTable()` + `writeLog()` | Add the private `ensureTable()` method (creates `lime_user_audit_log` + indexes on first run) and the private `writeLog(array $data)` helper that inserts one row. Call `ensureTable()` from `init()`. Rebuild, verify the table appears in the DB after container start. | **done** |
| 4 | `onBeforeSurveyPage()` — OAuth redirect + return to survey | Guest check: store current URL in session, redirect to OAuth login. After login, `beforeSurveyPage` fallback detects stored URL and redirects back. Note: `newUserSession` hook was attempted but breaks every login (fires on all requests, not just login) — removed. Rebuild, test: open survey logged out → login → open any survey page → should land back on original survey. | **done** |
| 5 | `onBeforeSurveyPage()` — `survey_open` / `page_load` logging | After the guest check, log `survey_open` (page 1) or `page_load` (pages > 1) via `writeLog()`. Rebuild, navigate through a survey and verify rows appear in `lime_user_audit_log`. | **done** |
| 6 | `onAfterSurveyComplete()` — `survey_submit` logging | Log a `survey_submit` row when the survey is completed. Rebuild, submit a survey and confirm the row. | **done — untested** ⚠️ Survey submit returns HTTP 401 in the current environment (pre-existing issue, unrelated to this plugin — confirmed by deactivating the plugin, 401 persists). Needs to be verified once the survey end-URL / Spring Boot auth issue is resolved. Forwarded to the original implementor. |
| 7 | `onNewUnsecuredDirectRequest()` — AJAX endpoint | Expose the `logAnswerChange` function (read via `$this->event->get('function')`): validate session, read server-side fields, insert one `answer_change` row, return HTTP 200. Rebuild, test with `curl` or Postman. | **done** |
| 8 | JavaScript — `questionCodeMap` injection | In `onBeforeSurveyPage()`, query `Question::model()` for the current page's questions, build the `{ questionId → questionCode }` map, inject as JS variable via `clientScript->registerScript()`. Rebuild, verify the map is present in the browser console. | **done** |
| 9 | JavaScript — `answer_change` event listeners | Add the full JS block: old-value snapshot at page load, `change` listener that looks up `question_code` from the map and fires `fetch()` to the AJAX endpoint. Rebuild, change answers and watch rows appear in the DB. | **done** |

---

## 9. Verification Queries

DB container: `more-studymanager-backend-lime-db-1` — credentials: `-U limesurvey -d limesurvey`

```bash
# Check table structure
docker exec -it more-studymanager-backend-lime-db-1 psql -U limesurvey -d limesurvey -c "\d lime_user_audit_log"

# Watch the audit log live while filling in a survey
docker exec -it more-studymanager-backend-lime-db-1 psql -U limesurvey -d limesurvey -c "SELECT created_at, event_type, oauth_username, participant_token, question_code, old_value, new_value FROM lime_user_audit_log ORDER BY created_at DESC LIMIT 30;"

# Quick check — all rows
docker exec -it more-studymanager-backend-lime-db-1 psql -U limesurvey -d limesurvey -c "SELECT id, created_at, event_type, session_id, ip_address FROM lime_user_audit_log ORDER BY created_at DESC LIMIT 20;"

# Clear table (during testing)
docker exec -it more-studymanager-backend-lime-db-1 psql -U limesurvey -d limesurvey -c "TRUNCATE lime_user_audit_log;"
```

---

## 10. Open Questions (for after POC)

| # | Question |
|---|---------|
| 1 | `newUserSession` hook behaviour needs to be verified against a live container — fallback is to recover the stored return URL on the next `beforeSurveyPage` call. |
| 2 | For "update response" surveys (participant returns to amend answers), the JS old-value snapshot should be seeded from the DB — is this needed for the POC? |
| 3 | GDPR: should `participant_email` / `participant_name` be stored, or just the token? Currently only the token is stored. |
| 4 | Per-survey OAuth toggle (via `newSurveySettings`) as a follow-up to the POC. |

---

*Last updated: 2026-04-15 — Working agreement added; Dockerfile step merged into Step 2; routing note for `newUnsecuredDirectRequest` added*
