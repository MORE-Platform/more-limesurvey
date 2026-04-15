<?php

class HelloWorld extends PluginBase
{
    protected $storage = 'DbStorage';

    static protected $name        = 'HelloWorld';
    static protected $description = 'Logs user interactions on survey pages to the browser console.';

    public function init(): void
    {
        $this->subscribe('beforeSurveyPage');
    }

    /**
     * Fires before each survey page is rendered.
     * Passes user/participant context to JS and registers interaction tracking.
     */
    public function beforeSurveyPage(): void
    {
        $surveyId = $this->event->get('surveyId');

        // 1. OAuth / logged-in user
        $yiiUser   = Yii::app()->user;
        $oauthUser = $yiiUser->isGuest ? null : [
            'id'   => $yiiUser->id,
            'name' => $yiiUser->name,
        ];

        // 2. Survey participant via token (only present for token-based surveys)
        $participant   = null;
        $surveySession = Yii::app()->session['survey_' . $surveyId] ?? null;
        $tokenValue    = $surveySession['token'] ?? null;

        if ($tokenValue && $surveyId) {
            $token = Token::model($surveyId)->findByAttributes(['token' => $tokenValue]);
            if ($token) {
                $participant = [
                    'token'     => $tokenValue,
                    'firstname' => $token->firstname,
                    'lastname'  => $token->lastname,
                    'email'     => $token->email,
                ];
            }
        }

        // Pass context to JS as a JSON literal so every log entry carries it
        $context = json_encode([
            'oauthUser'   => $oauthUser,
            'participant' => $participant,
        ]);

        $js = <<<JS
(function () {
    var ctx = {$context};

    /**
     * Central log function — emits one structured object per event.
     * Schema: timestamp, oauthUser, participant, action, [questionId, answerId, inputValue, ...]
     */
    function log(action, extra) {
        var entry = Object.assign(
            {
                timestamp:   new Date().toISOString(),
                oauthUser:   ctx.oauthUser,
                participant: ctx.participant,
                action:      action
            },
            extra || {}
        );
        console.log('[HelloWorld]', JSON.stringify(entry, null, 2));
    }

    /**
     * Parse a LimeSurvey input name into questionId + answerId.
     * Input names follow the pattern: answer{SID}X{QID}X{SQID}
     * e.g. "answer12345X42X0" → questionId=42, answerId=0
     */
    function parseName(name) {
        var m = name.match(/^answer\d+X(\d+)X?(\w+)?$/);
        return {
            questionId: m ? m[1] : null,
            answerId:   m ? (m[2] || null) : null,
        };
    }

    // ── Page load ────────────────────────────────────────────────────────────
    log('page_load');

    // ── Navigation button clicks (next / previous / submit) ──────────────────
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('button, input[type="submit"], input[type="button"]');
        if (!btn) return;

        var val = (btn.value || '').toLowerCase();
        var id  = (btn.id   || '').toLowerCase();

        var action = 'click_button';
        if (val === 'moveright' || id.indexOf('next')   !== -1) action = 'click_next';
        else if (val === 'moveleft'  || id.indexOf('prev')   !== -1) action = 'click_previous';
        else if (val === 'submit'    || id.indexOf('submit') !== -1) action = 'click_submit';

        log(action, {
            buttonId:    btn.id    || null,
            buttonName:  btn.name  || null,
            buttonValue: btn.value || null,
        });
    });

    // ── Answer selection (radio, checkbox, select, date picker, etc.) ─────────
    document.addEventListener('change', function (e) {
        var el = e.target;
        if (!el.name) return;

        var parsed = parseName(el.name);
        log('answer_change', Object.assign(parsed, {
            inputName:  el.name,
            inputType:  el.type || el.tagName.toLowerCase(),
            inputValue: el.type === 'checkbox' ? el.checked : el.value,
        }));
    });

    // ── Free-text input (logs on every keystroke) ─────────────────────────────
    document.addEventListener('input', function (e) {
        var el = e.target;
        // Skip types already handled by 'change'
        if (!el.name || el.type === 'radio' || el.type === 'checkbox' || el.tagName === 'SELECT') return;

        var parsed = parseName(el.name);
        log('answer_input', Object.assign(parsed, {
            inputName:  el.name,
            inputType:  el.type || el.tagName.toLowerCase(),
            inputValue: el.value,
        }));
    });

})();
JS;

        Yii::app()->clientScript->registerScript('helloWorld', $js);
    }
}
