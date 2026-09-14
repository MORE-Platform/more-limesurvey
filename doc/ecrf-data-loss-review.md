# eCRF data loss (survey 772698) — independent review + remediation plan

Reviewed against LimeSurvey tag `6.17.18+260831` (upstream git), the deployed
`reloadAnyResponse` plugin (v1.4.2) in `limesurvey-plugins/`, the image build in
`more-limesurvey/Dockerfile`, and `lime_user_audit_log.csv`.

**Verdict on the existing analysis: correct.** Every line reference checks out against
6.17.18 verbatim. Below: two corrections that change what you should *do*, one bug in
your own plugin that the analysis missed, and a priority-ordered fix list.

---

## 1. What was verified

All four destructive paths exist exactly as described, in
`application/controllers/survey/index.php` @ 6.17.18:

| Line | Path | Kills session? | Emits `newtest=Y`? | Visible to staff? |
|---|---|---|---|---|
| 45–46 | `newtest=Y` on entry | yes, **first, before anything** | — | no |
| 213–233 | `isClientTokenDifferentFromSessionToken` | **yes (223)** | yes (214) | yes — "Access code mismatch" |
| 247–268 | `isSurveyFinished` + `tokenanswerspersistence=Y` | **yes (248)** | yes (249) | **no — silent redirect (266)** |
| 308–335 | `didSessionTimeout` (POST with no `step`) | no, but offers the link | yes (335) | yes — "…problems with your connection" |

`isClientTokenDifferentFromSessionToken` @718 and `didSessionTimeout` @736 read exactly as
quoted. The recovery block is @582–606, and the selection really is
`findAllByAttributes(['token'=>$token], ['order' => 'id DESC'])` @584–586, with
`$oResponses[0]` taken @607 when no plugin sets a response.

The silent redirect at 266 is the most likely origin of `newtest=Y` in staff-held links, as
the analysis says — note the upstream code even carries a `//todo` at 253 admitting the URL
is never actually shown to the participant in the `renderExitMessage` case.

---

## 2. Correction 1 — this is response **orphaning**, not deletion. Check before you plan.

Nothing in these paths issues a `DELETE` or writes `NULL` over a stored answer. What happens
is: the session is killed → recovery misses → a **new blank response row** is inserted for the
same token → `id DESC` makes that new row win every subsequent open.

The old row, with Visit 1 in it, is still sitting in `lime_survey_772698`.

That is consistent with everything in the report: `old_value=''` on re-entry (a *new* row being
filled, not an old one being cleared), "identical values retyped", "Group 363 appears at pages
2, 3 and 4" (fieldmap rebuilt against an empty answer set), and the fact that the data could be
"restored manually" at all.

**This changes the triage.** Run this first — it tells you the true blast radius across the
whole study, not just the two incidents you happened to hear about:

```sql
-- every token with more than one response row = a suspected orphaning event
SELECT token,
       COUNT(*)                                    AS rows,
       MIN(id)                                     AS first_srid,
       MAX(id)                                     AS winning_srid,
       ARRAY_AGG(id ORDER BY id)                   AS srids,
       ARRAY_AGG(startdate ORDER BY id)            AS started,
       ARRAY_AGG(submitdate ORDER BY id)           AS submitted,
       ARRAY_AGG(lastpage ORDER BY id)             AS lastpage
FROM   lime_survey_772698
WHERE  token IS NOT NULL AND token <> ''
GROUP  BY token
HAVING COUNT(*) > 1
ORDER  BY COUNT(*) DESC, MIN(id);
```

and, to see which of the duplicates actually holds the data (Postgres):

```sql
SELECT id, token, startdate, submitdate,
       (SELECT count(*) FROM jsonb_each_text(to_jsonb(r) - 'id' - 'token' - 'submitdate'
                                              - 'startdate' - 'datestamp' - 'lastpage'
                                              - 'startlanguage' - 'seed' - 'refurl')
        WHERE value IS NOT NULL AND value <> '')   AS filled_fields
FROM   lime_survey_772698 r
WHERE  token IN (SELECT token FROM lime_survey_772698
                 WHERE token <> '' GROUP BY token HAVING count(*) > 1)
ORDER  BY token, id;
```

If `filled_fields` is high on a low `id` and near-zero on the highest `id`, that token is
orphaned, not lost — recoverable by merge, no re-keying, no protocol deviation to report as
data loss. Do this sweep before anyone else retypes anything.

Run the same two queries against **962812** — see §4.

---

## 3. Correction 2 — the upstream fix exists, but it is **not** in any LimeSurvey 6 release

LimeSurvey fixed the token-mismatch branch on 2026-07-07:

```
de9d2f235a13eb18ea0d6bdbb07f8b57cf0c515c
"Fixed issue: If a participant opens a 2nd browser windows with a different
 access code the first session gets terminated"   (issue #20598)
```

The fix is exactly the deletion of `killSurveySession($surveyid);` from that branch, plus a
clearer message ("Your current progress has been preserved.") and removal of the unreachable
`_createNewUserSessionAndRedirect()` call after it.

Where it landed:

```
git tag --contains de9d2f235a   →  7.0.6+260722 … 7.0.14+260904    (7.x only)
origin/master     contains it:  YES
origin/6.x        contains it:  no
origin/6.x-LTS    contains it:  no
origin/develop    contains it:  no
```

Latest 6.x at time of review is `6.17.18+260831` and **does not contain it**. So:

- "pin/upgrade to the newest `martialblog/limesurvey:6-apache`" does **not** fix this. Rebuilding
  the image on a schedule will never pick it up.
- Your options are (a) carry the patch in your Dockerfile, as you already do for
  `deletenonvalues`, or (b) plan a move to 7.x. Given this is a regulated eCRF, (a) now and (b)
  on a controlled timeline.

---

## 4. Corroboration from the other study (962812)

Your `lime_user_audit_log.csv` covers **962812**, not 772698 — but the same signature is there,
so this is a platform-level defect, not a Bad Vigaun site issue:

- **14 of 215 sessions touched more than one participant token.** One PHP session, two patients.
  Same shape as the 06:44–06:46 bounce on `1NrLUMI7xiIbUYA`.
- **6 "amnesia" events**: an `answer_change` arriving with `old_value=''` on a field that a
  previous session had already stored a non-empty value for. **3 of the 6 re-entered the
  identical value** — the retype signature.
- One of them is `2026-09-04 07:48` on token `TVQ6CjKryAyb2oF` — i.e. the incident date already
  named in your Dockerfile comment, in a different study.

Six events in six weeks in one survey is a low rate, which is exactly why it went unnoticed
until a patient was in the room. It is also why the `id DESC` fix matters more than the
session fix: the rate is low, the recovery cost is high, and the exposure is permanent.

---

## 5. What the analysis missed: `reloadAnyResponse`'s own recovery is dead code

`beforeSurveyPage` (reloadAnyResponse.php:557–564) detects the conflict, promises the user
*"We save your current session, you can try to reload the survey in some minutes."*, calls
`saveCurrentSrid()`, then `killSurveySession()`.

That promise cannot be kept. `saveCurrentSrid` (:902) writes a **scalar**; `getCurrentSrid`
(:920) reads it as an **array**:

```php
// saveCurrentSrid
$sessionCurrentSrid[$surveyId] = $currentSrid;                       // array(772698 => 41234)
Yii::app()->session['reloadAnyResponsecurrentSrid']
    = $sessionCurrentSrid[$surveyId];                                // ← stores 41234, not the array

// getCurrentSrid
$sessionCurrentSrid = Yii::app()->session['reloadAnyResponsecurrentSrid'];   // 41234 (int)
if (empty($sessionCurrentSrid) || empty($sessionCurrentSrid[$surveyId])) {
    return;                                                          // int[772698] → null → always returns here
}
```

`getCurrentSrid()` returns `null` **every time**. So `beforeSurveyPage` kills the session,
falls through to `if(!$srid) return;` at :584-586, and the next request lands in LimeSurvey's
`id DESC` lottery — the exact failure mode. Patch:

```php
- Yii::app()->session['reloadAnyResponsecurrentSrid'] = $sessionCurrentSrid[$surveyId];
+ Yii::app()->session['reloadAnyResponsecurrentSrid'] = $sessionCurrentSrid;
```

Two smaller ones in the same file:

- `beforeLoadResponse` :523/:527 dereferences `$oResponse->id` without a null guard. First-ever
  open of a token (no response row yet) → `Attempt to read property "id" on null`, and
  `saveSessionTime($surveyId, null)` is a no-op, so the multi-access guard silently does nothing
  for that patient's first session.
- `getIsUsed($surveyid, null, …)` at :557 resolves the srid **from `$_SESSION`**. After a session
  loss there is no srid, so it returns early — the multi-access guard is *off* in precisely the
  situation it exists to cover.

---

## 6. The load-bearing defect: `id DESC`

Fixing all four `killSurveySession` paths is necessary but not sufficient. Sessions will still
be lost — cookie cleared, browser crash, laptop sleeps through the GC window, container
redeploy, a second staff member on the same machine. Every one of those re-enters the same
lottery.

`order => id DESC` is the only reason a lost session becomes lost *data*. Make the selection
deterministic and every other path in this document degrades to a cosmetic annoyance.

Core hands you the extension point for free (index.php:595–606): if a plugin sets
`$event->set('response', $oResponse)`, core uses it and never falls back to `$oResponses[0]`.
No core patch needed. `reloadAnyResponse` only sets `response` for `srid=new`, so there is no
conflict.

### P0 plugin — `ecrfResponsePin`

```php
<?php
/**
 * Deterministic response selection for eCRF surveys.
 * One token == one canonical response row. Never let a newer blank row win.
 */
class ecrfResponsePin extends \LimeSurvey\PluginManager\PluginBase
{
    protected $storage = 'DbStorage';
    static protected $description = 'eCRF: pin each token to its richest response row.';
    static protected $name = 'ecrfResponsePin';

    public function init()
    {
        $this->subscribe('beforeLoadResponse');
    }

    public function beforeLoadResponse()
    {
        $event     = $this->getEvent();
        $responses = $event->get('responses');
        $surveyId  = $event->get('surveyId');

        if (empty($responses) || count($responses) < 2) {
            return; // nothing ambiguous to resolve
        }

        $best = null; $bestScore = -1;
        foreach ($responses as $r) {
            $score = $this->countFilled($r);
            // strictly greater, and $responses is id DESC, so ties resolve to the LOWEST id
            if ($score >= $bestScore) {
                $bestScore = $score;
                $best = $r;
            }
        }

        $ids = array_map(function ($r) { return $r->id; }, $responses);
        $this->log(sprintf(
            'survey %s token %s: %d candidate rows [%s]; core would pick %s, pinned %s (%d filled fields)',
            $surveyId, $responses[0]->token, count($responses),
            implode(',', $ids), $responses[0]->id, $best->id, $bestScore
        ), \CLogger::LEVEL_WARNING);

        $event->set('response', $best);
    }

    private function countFilled($response)
    {
        $skip = ['id','token','submitdate','startdate','datestamp','lastpage',
                 'startlanguage','seed','refurl','ipaddr'];
        $n = 0;
        foreach ($response->getAttributes() as $k => $v) {
            if (in_array($k, $skip, true)) { continue; }
            if ($v !== null && $v !== '') { $n++; }
        }
        return $n;
    }
}
```

Properties worth noting:

- **Self-healing.** It does not just prevent new orphans; on the next open of an
  already-orphaned token it re-pins the session to the row that actually holds the data.
- **Deterministic tie-break.** `>=` over an `id DESC` list means equal scores resolve to the
  lowest id — the original record.
- **It logs every ambiguity at WARNING**, which gives you the detection layer you currently
  don't have. Every line it writes is a token that would otherwise have been at risk.
- Ship it via the existing `PLUGINS` build-arg mechanism in your Dockerfile. Load order against
  `reloadAnyResponse` does not matter (that plugin only sets `response` for `srid=new`, and this
  one returns early when there is only one candidate).

Caveat to confirm before deploying: this enforces **one response per token**. If any survey on
this instance legitimately allows multiple responses per participant, gate the plugin on a
survey-id allowlist.

---

## 7. Priority-ordered plan

**P0 — today, no deploy needed**

1. Run the §2 SQL on 772698 **and** 962812. Produce the orphan list before anyone retypes
   anything else. Most "lost" visits are probably recoverable by merge.
2. Tell the Bad Vigaun site: do not use any link containing `newtest=Y`, and if the
   "Access code mismatch" or "session has expired" page appears, **do not click the button** —
   report it. That button is the destructive action.

**P0 — next image build**

3. Ship the `ecrfResponsePin` plugin (§6). This is the single change that converts "data loss"
   into "harmless session hiccup".

**P1 — same build**

4. Backport `de9d2f235a` into the Dockerfile, next to your existing `sed` patches:

```dockerfile
# --- Backport of upstream de9d2f235a (issue #20598), released in 7.0.6 but NEVER
#     backported to any 6.x release. The "Access code mismatch" page kills the
#     in-progress session before the participant has decided anything.
RUN set -eux; \
    IDX=/var/www/html/application/controllers/survey/index.php; \
    grep -n "killSurveySession" "$IDX"; \
    perl -0pi -e 's/(\$aErrors\s*=\s*array\(gT\(.Access code mismatch.\)\);.*?)\n\s*killSurveySession\(\$surveyid\);\n/$1\n/s' "$IDX"; \
    php -l "$IDX"; \
    # 223 must be gone; 46, 117 and 248 must remain
    test "$(grep -c 'killSurveySession' "$IDX")" -eq 3
```

5. Remove `newtest=Y` from the `isSurveyFinished` restart URL (line 249). The session is already
   killed at 248, so the parameter is redundant there — and this is the redirect that puts
   `newtest=Y` into the address bar, where staff copy it. Same `sed` style, same build.

6. Strip `newtest` at the reverse proxy for participant paths. Cheapest, highest-coverage
   control you have — it neutralises every already-circulating poisoned link, including the one
   in audit row 5283 and the ones the participant-list "Launch the survey" action generates:

```nginx
# nginx
if ($arg_newtest) {
    return 302 $scheme://$host$uri?token=$arg_token;
}
```

**P2 — this sprint**

7. Patch `saveCurrentSrid` (§5) and add the two null guards.
8. Move the site off bare `?token=…` links to `?srid=<id>&code=<accesscode>` links, which
   `reloadAnyResponse` already supports (`responseLink::setResponseLink()` →
   `getStartUrl()`). An explicit srid removes the guessing step entirely — it is the correct
   long-term shape for an eCRF, and it survives every session-loss scenario.
9. Verify at runtime that your two config patches actually took effect — `config-defaults.php`
   is overridden by `config.php` if that file sets the same keys:

```bash
docker compose exec limesurvey php -r '
  $c = require "/var/www/html/application/config/config.php";
  $d = require "/var/www/html/application/config/config-defaults.php";
  $m = array_merge($d, $c["config"] ?? []);
  var_dump($m["deletenonvalues"], $m["iSessionExpirationTime"]);'
```

**P3 — ongoing**

10. Alert on `SELECT token FROM lime_survey_<sid> GROUP BY token HAVING count(*) > 1` per study,
    plus on the `ecrfResponsePin` WARNING lines. Either one firing means a near-miss that used
    to be invisible.
11. Plan the 7.x migration. You will otherwise carry these patches indefinitely, and 6.x is not
    receiving this class of fix.

---

## 8. What not to do

- **Don't rely on "one patient per tab" training.** 14 of 215 sessions in 962812 already violate
  it. The failure is architectural: one cookie, one `$_SESSION['survey_<sid>']` slot, one srid.
  Staff cannot be the mitigation.
- **Don't set `alloweditaftercompletion = N`.** It blocks the multi-visit CRF workflow and does
  not stop the blank-row path (line 606 also accepts `!isset($oResponses[0]->submitdate)`).
- **Don't raise `multiAccessTime` to "protect" records.** It produces more 409 "someone updated
  this response" pages, and the observed staff response to a blocking page is to go find another
  link — which is how `newtest=Y` spread in the first place.
- **Don't rely on rebuilding the image to pick up the fix.** §3: it is not in 6.x, and no
  6.x release will contain it.

---

## 9. Verification before this is called closed

1. Two tokens, one browser, alternating opens, with the plugin deployed → single response row
   per token; `ecrfResponsePin` WARNING lines present; no blank rows created.
2. Mid-form: kill the session server-side (`DELETE FROM sessions WHERE …`), then POST the page →
   "session has expired" page, click the restart button → session rebuilds onto the **same**
   srid, all prior answers intact.
3. Submit a token to completion, reopen the bare token link → silent redirect still happens
   (line 266) but now carries no `newtest`, and lands on the same srid.
4. Re-run the §2 SQL → zero tokens with `count(*) > 1` in a clean test survey after all of the
   above.
5. Confirm `deletenonvalues=0` and `iSessionExpirationTime=21600` at runtime (§7.9), not just in
   `config-defaults.php`.
