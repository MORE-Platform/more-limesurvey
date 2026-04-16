<?php

class UserAuditLogPlugin extends PluginBase
{
    protected $storage = 'DbStorage';

    static protected $name        = 'UserAuditLogPlugin';
    static protected $description = 'Records user interactions in surveys (audit log with AJAX tracking).';

    public function init(): void
    {
        $this->subscribe('beforeSurveyPage');
        $this->subscribe('afterSurveyComplete');
        $this->subscribe('newDirectRequest');

        $this->ensureTable();
    }

    // ---------------------------------------------------------------------
    // SURVEY PAGE LOGIC + JS INJECTION
    // ---------------------------------------------------------------------

    public function beforeSurveyPage(): void
    {
        $surveyId = $this->event->get('surveyId');
        if (!$surveyId) {
            return;
        }

        // --- AUTH GUARD START ---
        // Redirect guest users to the login page
        if (Yii::app()->user->isGuest) {
            Yii::app()->controller->redirect(
                Yii::app()->baseUrl . '/index.php/admin/authentication/sa/login'
            );
            return;
        }
        // --- AUTH GUARD END ---

        $surveySession = Yii::app()->session['survey_' . $surveyId] ?? [];
        $token = $surveySession['token'] ?? null;

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

        // JS endpoint
        $endpointUrl = Yii::app()->createUrl('plugins/direct', ['plugin' => 'UserAuditLogPlugin', 'function' => 'logAnswerChange']);

        $surveyIdJs = (int)$surveyId;
        $stepJs = $step !== null ? (int)$step : 'null';
        $csrfTokenName = Yii::app()->request->csrfTokenName;

        Yii::app()->clientScript->registerScript(
            'ualp_script',
            <<<JS
(function () {
    var surveyId   = {$surveyIdJs};
    var pageNumber = {$stepJs};
    var endpoint   = "{$endpointUrl}";
    var csrfTokenName = "{$csrfTokenName}";

    console.log("[UALP] init", { surveyId, pageNumber, endpoint });

    function parseName(name) {
        var m = name.match(/^(\d+)X(\d+)X(\d+)(\w*)$/);
        if (!m) return null;
        return {
            qid: m[3],
            gid: m[2],
            sub: m[4] || null
        };
    }

    function getValue(el) {
        if (el.type === 'checkbox') return el.checked ? el.value : null;
        return el.value !== '' ? el.value : null;
    }

    var oldValues = {};

    $('input, select, textarea').each(function () {
        if (this.name && parseName(this.name)) {
            oldValues[this.name] = getValue(this);
        }
    });

    $(document).on('change', 'input, select, textarea', function () {
        var el = this;
        if (!el.name) return;

        var parsed = parseName(el.name);
        if (!parsed) return;

        // Try to get CSRF token from several locations
        var csrfToken = $('input[name="' + csrfTokenName + '"]').val() 
                     || (window.LS && window.LS.csrfToken) 
                     || "";

        var payload = {
            survey_id: surveyId,
            page_number: pageNumber,
            group_id: parsed.gid ? parseInt(parsed.gid) : null,
            question_code: parsed.qid,
            sub_question_code: parsed.sub,
            input_type: el.type || el.tagName.toLowerCase(),
            old_value: oldValues[el.name] || null,
            new_value: getValue(el)
        };

        // Build URLSearchParams for application/x-www-form-urlencoded
        var formData = new URLSearchParams();
        for (var key in payload) {
            if (payload[key] !== null) {
                formData.append(key, payload[key]);
            }
        }

        // Add CSRF token as a standard POST variable
        if (csrfToken) {
            formData.append(csrfTokenName, csrfToken);
        }

        oldValues[el.name] = payload.new_value;

        console.log("[UALP] change", payload);

        fetch(endpoint, {
            method: "POST",
            headers: { 
                "Content-Type": "application/x-www-form-urlencoded",
                "X-Requested-With": "XMLHttpRequest" 
            },
            credentials: "include",
            body: formData.toString()
        })
        .then(r => {
            if (!r.ok) console.warn("[UALP] request failed", r.status);
        })
        .catch(err => console.warn("[UALP] error", err));
    });
})();
JS
        );
    }

    public function afterSurveyComplete(): void
    {
        $surveyId = $this->event->get('surveyId');
        if (!$surveyId) return;

        $surveySession = Yii::app()->session['survey_' . $surveyId] ?? [];
        $token = $surveySession['token'] ?? null;

        $this->writeLog([
            'survey_id'         => $surveyId,
            'participant_token' => $token,
            'event_type'        => 'survey_submit',
        ]);
    }

    // ---------------------------------------------------------------------
    // AJAX ENDPOINT (SECURE)
    // ---------------------------------------------------------------------

    public function newDirectRequest(): void
    {
        $request = Yii::app()->request;
        
        // Check for specific function to avoid conflict with other plugins
        if ($this->event->get('function') !== 'logAnswerChange') {
            return;
        }

        if (!$request->isPostRequest) {
            http_response_code(405);
            die('POST only');
        }

        // Use getPost() to read application/x-www-form-urlencoded data
        $surveyId = $request->getPost('survey_id');

        if (empty($surveyId)) {
            http_response_code(400);
            die('Invalid Request');
        }

        // Retrieve the participant token from the session, as it's not in the AJAX payload
        $surveySession = Yii::app()->session['survey_' . $surveyId] ?? [];
        $token = $surveySession['token'] ?? null;

        try {
            $this->writeLog([
                'survey_id'         => (int)$surveyId,
                'participant_token' => $token,
                'event_type'        => 'answer_change',
                'page_number'       => $request->getPost('page_number'),
                'group_id'          => $request->getPost('group_id'),
                'question_code'     => $request->getPost('question_code'),
                'sub_question_code' => $request->getPost('sub_question_code'),
                'input_type'        => $request->getPost('input_type'),
                'old_value'         => $request->getPost('old_value'),
                'new_value'         => $request->getPost('new_value'),
            ]);

            http_response_code(200);
            echo json_encode(['status' => 'ok']);
            die();

        } catch (Exception $e) {
            error_log('[UALP] AJAX DB ERROR: ' . $e->getMessage());
            http_response_code(500);
            die();
        }
    }

    private function ensureTable(): void
    {
        $db = Yii::app()->db;
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

    private function writeLog(array $data): void
    {
        $yiiUser = Yii::app()->user;
        $session = Yii::app()->session;

        try {
            Yii::app()->db->createCommand()->insert(
                Yii::app()->db->tablePrefix . 'user_audit_log',
                [
                    'survey_id'         => $data['survey_id'],
                    'participant_token' => $data['participant_token'] ?? null,
                    'oauth_user_id'     => $yiiUser->isGuest ? null : $yiiUser->id,
                    'oauth_username'    => $yiiUser->isGuest ? null : $yiiUser->name,
                    'event_type'        => $data['event_type'],
                    'page_number'       => $data['page_number'] ?? null,
                    'group_id'          => $data['group_id'] ?? null,
                    'question_id'       => $data['question_id'] ?? null,
                    'question_code'     => $data['question_code'] ?? null,
                    'sub_question_code' => $data['sub_question_code'] ?? null,
                    'input_type'        => $data['input_type'] ?? null,
                    'old_value'         => $data['old_value'] ?? null,
                    'new_value'         => $data['new_value'] ?? null,
                    'session_id'        => $session->sessionID,
                    'ip_address'        => $_SERVER['REMOTE_ADDR'] ?? null,
                ]
            );
        } catch (Exception $e) {
            error_log('[UALP] writeLog DB ERROR: ' . $e->getMessage());
        }
    }
}