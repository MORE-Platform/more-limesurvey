<?php

class UserAuditLogPlugin extends PluginBase
{
    protected $storage = 'DbStorage';

    static protected $name        = 'UserAuditLogPlugin';
    static protected $description = 'Records every user interaction with a survey into a dedicated PostgreSQL audit table. Enforces OAuth authentication before survey access.';

    public function init(): void
    {
        $this->subscribe('beforeSurveyPage');
        $this->subscribe('afterSurveyComplete');
        $this->subscribe('newUnsecuredDirectRequest');
        $this->ensureTable();
    }

    /**
     * Fires before each survey page is rendered.
     * Responsibilities: OAuth redirect for guests, log survey_open / page_load.
     */
    public function beforeSurveyPage(): void
    {
        // Step 4: redirect unauthenticated users to OAuth login
        // Skip redirect on the thank-you page (survey already completed, session may be cleared)
        $surveyId      = $this->event->get('surveyId');
        $surveySession = Yii::app()->session['survey_' . $surveyId] ?? [];
        $isCompleted   = isset($surveySession['finished']) && $surveySession['finished'];

        if (Yii::app()->user->isGuest && !$isCompleted) {
            Yii::app()->session['ualp_return_url'] = Yii::app()->request->hostInfo . Yii::app()->request->url;
            Yii::app()->controller->redirect(
                Yii::app()->baseUrl . '/index.php/admin/authentication/sa/login/authMethod/AuthOAuth2'
            );
            return;
        }

        // Step 4b fallback: user just logged in, return URL was stored before redirect
        $returnUrl = Yii::app()->session['ualp_return_url'] ?? null;
        if ($returnUrl) {
            unset(Yii::app()->session['ualp_return_url']);
            Yii::app()->controller->redirect($returnUrl);
            return;
        }

        // Step 5: log survey_open / page_load
        // $surveyId and $surveySession already set above
        $token = $surveySession['token'] ?? null;

        // step from event, fall back to survey session
        $step = $this->event->get('step');
        if ($step === null) {
            $step = $surveySession['step'] ?? null;
        }

        $eventType = ($step === null || (int)$step <= 0) ? 'survey_open' : 'page_load';

        $this->writeLog([
            'survey_id'         => $surveyId,
            'participant_token' => $token,
            'event_type'        => $eventType,
            'page_number'       => $step !== null ? (int)$step : null,
        ]);

        // Step 8: build questionId → questionCode map for all questions in this survey
        $questions = Question::model()->findAllByAttributes(
            ['sid' => $surveyId, 'parent_qid' => 0],
            ['select' => 'qid, title']
        );
        $questionCodeMap = [];
        foreach ($questions as $q) {
            $questionCodeMap[(string)$q->qid] = $q->title;
        }
        $questionCodeMapJson = json_encode($questionCodeMap, JSON_UNESCAPED_UNICODE);

        // Step 9: answer_change JS
        $stepJs       = $step !== null ? (int)$step : 'null';
        $endpointUrl  = Yii::app()->baseUrl . '/index.php/plugins/unsecure/plugin/UserAuditLogPlugin/function/logAnswerChange';
        $surveyIdJs   = (int)$surveyId;

        $yiiUser      = Yii::app()->user;
        $oauthUser    = $yiiUser->isGuest ? null : ['id' => $yiiUser->id, 'name' => $yiiUser->name];
        $oauthUserJson = json_encode($oauthUser);
        $tokenJson    = json_encode($token);

        Yii::app()->clientScript->registerScript(
            'ualp_beforeSurveyPage',
            <<<JS
(function () {
    var surveyId        = {$surveyIdJs};
    var pageNumber      = {$stepJs};
    var questionCodeMap = {$questionCodeMapJson};
    var endpointUrl     = '{$endpointUrl}';
    var oauthUser       = {$oauthUserJson};
    var participant     = {$tokenJson};

    console.log("[UserAuditLogPlugin] {$eventType}", {
        surveyId:        surveyId,
        step:            pageNumber,
        oauthUser:       oauthUser,
        participant:     participant,
        endpointUrl:     endpointUrl,
        questionCodeMap: questionCodeMap
    });

    // Parse LimeSurvey 6 input name: {SID}X{GID}X{QID} or {SID}X{GID}X{QID}{SQCODE}
    function parseName(name) {
        var m = name.match(/^(\d+)X(\d+)X(\d+)(\w*)$/);
        if (!m) return null;
        return {
            questionId:      m[3],
            groupId:         m[2],
            subQuestionCode: m[4] || null
        };
    }

    // Normalise value: checkbox → option value or null, others → string or null
    function getValue(el) {
        if (el.type === 'checkbox') return el.checked ? el.value : null;
        return el.value !== '' ? el.value : null;
    }

    // Snapshot values at page load as old_value baseline
    var oldValues = {};
    $('input[name], select[name], textarea[name]').each(function () {
        if (parseName(this.name)) oldValues[this.name] = getValue(this);
    });

    $(document).on('change', 'input, select, textarea', function () {
        var el     = this;
        console.log("[UserAuditLogPlugin] RAW change", { name: el.name, type: el.type, value: el.value });
        var parsed = parseName(el.name || '');
        if (!parsed) {
            console.log("[UserAuditLogPlugin] parseName miss, name=", el.name);
            return;
        }

        var oldValue      = oldValues.hasOwnProperty(el.name) ? oldValues[el.name] : null;
        var newValue      = getValue(el);
        var questionCode  = questionCodeMap[parsed.questionId] || null;

        // Update snapshot so next change on same field gets correct old_value
        oldValues[el.name] = newValue;

        var payload = {
            survey_id:         surveyId,
            page_number:       pageNumber,
            group_id:          parsed.groupId ? parseInt(parsed.groupId) : null,
            question_code:     questionCode,
            sub_question_code: parsed.subQuestionCode || null,
            input_type:        el.type || el.tagName.toLowerCase(),
            old_value:         oldValue,
            new_value:         newValue
        };

        console.log("[UserAuditLogPlugin] answer_change", payload);

        fetch(endpointUrl, {
            method:      'POST',
            headers:     { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body:        JSON.stringify(payload)
        }).then(function (r) {
            console.log('[UserAuditLogPlugin] logAnswerChange response:', r.status, r.ok ? 'OK' : 'FAIL');
            if (!r.ok) console.warn('[UserAuditLogPlugin] logAnswerChange failed', r.status);
        }).catch(function (e) {
            console.warn('[UserAuditLogPlugin] logAnswerChange error', e);
        });
    });
})();
JS
        );
    }

    /**
     * Fires after the survey is fully submitted.
     * Responsibilities: log survey_submit.
     */
    public function afterSurveyComplete(): void
    {
        $surveyId = $this->event->get('surveyId');
        if (!$surveyId) {
            return;
        }

        $surveySession = Yii::app()->session['survey_' . $surveyId] ?? [];
        $token         = $surveySession['token'] ?? null;

        $this->writeLog([
            'survey_id'         => $surveyId,
            'participant_token' => $token,
            'event_type'        => 'survey_submit',
        ]);
    }

    /**
     * Exposes unsecured AJAX endpoints for the browser JS.
     * Routes by $this->event->get('function').
     *
     * URL: POST /index.php/plugins/unsecure/plugin/UserAuditLogPlugin/function/logAnswerChange
     */
    public function newUnsecuredDirectRequest(): void
    {
        $yiiUser  = Yii::app()->user;
        $fnValue  = $this->event->get('function');
        error_log('[UALP] newUnsecuredDirectRequest called'
            . ' function='   . var_export($fnValue, true)
            . ' user='       . ($yiiUser->isGuest ? 'guest' : $yiiUser->name)
            . ' session='    . Yii::app()->session->sessionID
        );

        if ($fnValue !== 'logAnswerChange') {
            error_log('[UALP] function mismatch, got: ' . var_export($fnValue, true) . ' — skipping');
            return;
        }

        if (!Yii::app()->request->isPostRequest) {
            error_log('[UALP] not a POST request');
            http_response_code(405);
            die();
        }

        $raw  = file_get_contents('php://input');
        $body = json_decode($raw, true);
        error_log('[UALP] raw body: ' . $raw);

        if (!$body || empty($body['survey_id'])) {
            error_log('[UALP] invalid body');
            http_response_code(400);
            die();
        }

        if ($yiiUser->isGuest) {
            error_log('[UALP] rejected: user is guest');
            http_response_code(401);
            die();
        }

        $surveyId      = (int)$body['survey_id'];
        $surveySession = Yii::app()->session['survey_' . $surveyId] ?? [];
        $token         = $surveySession['token'] ?? null;
        error_log('[UALP] survey_id=' . $surveyId . ' token=' . $token . ' surveySession empty=' . (empty($surveySession) ? 'true' : 'false'));

        error_log('[UALP] calling writeLog');
        $this->writeLog([
            'survey_id'         => $surveyId,
            'participant_token' => $surveySession['token'] ?? null,
            'event_type'        => 'answer_change',
            'page_number'       => isset($body['page_number'])  ? (int)$body['page_number']  : null,
            'group_id'          => isset($body['group_id'])     ? (int)$body['group_id']     : null,
            'question_code'     => $body['question_code']      ?? null,
            'sub_question_code' => $body['sub_question_code']  ?? null,
            'input_type'        => $body['input_type']         ?? null,
            'old_value'         => isset($body['old_value'])   ? (string)$body['old_value']  : null,
            'new_value'         => isset($body['new_value'])   ? (string)$body['new_value']  : null,
        ]);
        error_log('[UALP] writeLog done');

        http_response_code(200);
        die();
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Creates lime_user_audit_log and its indexes on first plugin activation.
     * Safe to call on every init() — exits immediately if the table exists.
     */
    private function ensureTable(): void
    {
        $db    = Yii::app()->db;
        $table = $db->tablePrefix . 'user_audit_log';

        if ($db->schema->getTable($table) !== null) {
            return;
        }

        $db->createCommand()->createTable($table, [
            'id'                => 'BIGSERIAL PRIMARY KEY',
            'created_at'        => 'TIMESTAMPTZ NOT NULL DEFAULT NOW()',
            'survey_id'         => 'INTEGER NOT NULL',
            'participant_token' => 'VARCHAR(255)',
            'oauth_user_id'     => 'VARCHAR(255)',
            'oauth_username'    => 'VARCHAR(255)',
            'event_type'        => 'VARCHAR(50) NOT NULL',
            'page_number'       => 'INTEGER',
            'group_id'          => 'INTEGER',
            'question_id'       => 'INTEGER',
            'question_code'     => 'VARCHAR(255)',
            'sub_question_code' => 'VARCHAR(50)',
            'input_type'        => 'VARCHAR(50)',
            'old_value'         => 'TEXT',
            'new_value'         => 'TEXT',
            'session_id'        => 'VARCHAR(255)',
            'ip_address'        => 'VARCHAR(45)',
        ]);

        foreach (['survey_id', 'created_at', 'oauth_user_id', 'participant_token', 'event_type'] as $col) {
            $db->createCommand()->createIndex("idx_ual_{$col}", $table, $col);
        }
    }

    /**
     * Inserts one row into lime_user_audit_log.
     * Server-side fields (user, session, IP) are always read here — never trusted from outside.
     *
     * @param array $data {
     *   survey_id, event_type,
     *   page_number?, group_id?,
     *   question_id?, question_code?, sub_question_code?, input_type?,
     *   old_value?, new_value?,
     *   participant_token?
     * }
     */
    private function writeLog(array $data): void
    {
        $yiiUser = Yii::app()->user;
        $session = Yii::app()->session;
        $table   = Yii::app()->db->tablePrefix . 'user_audit_log';
        error_log('[UALP] writeLog table=' . $table . ' event=' . $data['event_type']);

        try {
        Yii::app()->db->createCommand()->insert(
            $table,
            [
                'survey_id'         => $data['survey_id'],
                'participant_token' => $data['participant_token'] ?? null,
                'oauth_user_id'     => $yiiUser->isGuest ? null : $yiiUser->id,
                'oauth_username'    => $yiiUser->isGuest ? null : $yiiUser->name,
                'event_type'        => $data['event_type'],
                'page_number'       => $data['page_number']       ?? null,
                'group_id'          => $data['group_id']          ?? null,
                'question_id'       => $data['question_id']       ?? null,
                'question_code'     => $data['question_code']     ?? null,
                'sub_question_code' => $data['sub_question_code'] ?? null,
                'input_type'        => $data['input_type']        ?? null,
                'old_value'         => $data['old_value']         ?? null,
                'new_value'         => $data['new_value']         ?? null,
                'session_id'        => $session->sessionID,
                'ip_address'        => $_SERVER['REMOTE_ADDR'] ?? null,
            ]
        );
        error_log('[UALP] writeLog INSERT OK event=' . $data['event_type']);
        } catch (\Exception $e) {
            error_log('[UALP] writeLog DB ERROR: ' . $e->getMessage());
            error_log($e->getTraceAsString());
        }
    }
}
