<?php

class UserAuditLogPlugin extends PluginBase
{
    protected $storage = 'DbStorage';

    static protected $name        = 'UserAuditLogPlugin';
    static protected $description = 'Records user interactions in surveys (audit log with AJAX tracking).';

    /** Filled by getSurveySetting() so beforeSurveyPage can include them in the console.log without extra DB calls. */
    private $dbgGlobal = 'n/a';
    private $dbgSurvey = 'n/a';

    public function init(): void
    {
        $this->subscribe('beforeSurveyPage');
        $this->subscribe('afterSurveyComplete');
        $this->subscribe('newDirectRequest');
        $this->subscribe('beforeSurveySettings');
        $this->subscribe('newSurveySettings');

        $this->ensureTable();
    }

    /**
     * Define the global plugin settings.
     */
    protected $settings = [
        'active' => [
            'type' => 'select',
            'label' => 'Audit log master switch:',
            'options' => [
                '0' => 'Deactivated',
                '1' => 'Activated',
            ],
            'default' => '0',
            'help' => 'Global on/off switch. When deactivated, no surveys are logged regardless of survey-level settings. When activated, each survey can individually enable logging (surveys default to deactivated).',
        ],
    ];

    /**
     * Define the survey-specific settings.
     */
    public function beforeSurveySettings(): void
    {
        $oEvent   = $this->event;
        // The article confirms the correct key is 'survey', not 'surveyId'
        $surveyId = (int) $oEvent->get('survey');

        $currentValue = $this->get('active', 'Survey', $surveyId) ?? '0';

        error_log("[UALP] beforeSurveySettings: surveyId={$surveyId} this->id=" . var_export($this->id, true) . " current={$currentValue}");

        // The correct API: set "surveysettings.{pluginId}", not the shared 'settings' array
        $oEvent->set("surveysettings.{$this->id}", [
            'name'     => get_class($this),
            'settings' => [
                'active' => [
                    'type'    => 'select',
                    'label'   => 'Audit log for this survey:',
                    'options' => [
                        '0' => 'Deactivated',
                        '1' => 'Activated',
                    ],
                    'default' => '0',
                    'current' => $currentValue,
                    'help'    => 'If activated, all user interactions will be logged to the audit table.',
                ],
            ],
        ]);

        // AJAX save on dropdown change — backup for when the normal form save is blocked by CSRF
        $saveUrl    = Yii::app()->createUrl('plugins/direct', [
            'plugin'   => 'UserAuditLogPlugin',
            'function' => 'saveSetting',
        ]);
        $surveyIdJs = $surveyId;

        Yii::app()->clientScript->registerScript(
            'ualp_settings_save',
            <<<JS
(function () {
    var surveyId = {$surveyIdJs};
    var saveUrl  = "{$saveUrl}";

    console.log('[UALP settings] init: surveyId=' + surveyId);

    $(document).on('change', 'select', function () {
        var name  = this.name;
        var value = this.value;
        console.log('[UALP settings] changed: name="' + name + '" value="' + value + '"');

        if (name.indexOf('UserAuditLogPlugin') === -1 || !surveyId) return;

        var sep = saveUrl.indexOf('?') !== -1 ? '&' : '?';
        fetch(saveUrl + sep + 'survey_id=' + encodeURIComponent(surveyId) + '&value=' + encodeURIComponent(value), {
            credentials: 'include',
            cache: 'no-store'
        })
        .then(function (r) { return r.json(); })
        .then(function (d) { console.log('[UALP settings] saved: ' + JSON.stringify(d)); })
        .catch(function (e) { console.warn('[UALP settings] save failed: ' + e); });
    });
})();
JS
        );
    }

    public function newSurveySettings(): void
    {
        $event    = $this->event;
        // Correct key is 'survey', not 'surveyId'
        $surveyId = (int) $event->get('survey');

        error_log("[UALP] newSurveySettings: surveyId={$surveyId}");

        foreach ((array) $event->get('settings') as $name => $value) {
            $this->set($name, $value, 'Survey', $surveyId);
            error_log("[UALP] newSurveySettings: saved '{$name}'='{$value}' for survey {$surveyId}");
        }
    }

    private function getSurveySetting(string $setting, int $surveyId)
    {
        $surveyId = (int) $surveyId;

        // Global master switch
        $globalVal    = $this->get($setting);
        $globalActive = in_array($globalVal, ['1', 1, true], true);
        $this->dbgGlobal = ($globalVal === null ? 'NULL' : $globalVal);

        if (!$globalActive) {
            $this->dbgSurvey = 'n/a (global OFF)';
            return '0';
        }

        // Survey-level setting via proper model/modelId storage
        $val = $this->get($setting, 'Survey', $surveyId);

        $this->dbgSurvey = ($val === null || $val === '') ? 'not saved' : $val;
        error_log("[UALP] getSurveySetting: survey={$surveyId} key={$setting} val=" . var_export($val, true));

        if ($val === null || $val === '') {
            return '0';
        }

        return in_array($val, ['1', 1, true], true) ? '1' : '0';
    }

    // ---------------------------------------------------------------------
    // SURVEY PAGE LOGIC + JS INJECTION
    // ---------------------------------------------------------------------

    public function beforeSurveyPage(): void
    {
        $surveyId = $this->event->get('surveyId');
        if (!$surveyId) {
            error_log("[UALP] beforeSurveyPage: No surveyId in event.");
            return;
        }

        $activeValue = $this->getSurveySetting('active', $surveyId);
        $surveyIdJs  = (int)$surveyId;
        $loggingStr  = $activeValue === '1' ? 'ON' : 'OFF';
        $dbgGlobal   = addslashes($this->dbgGlobal);
        $dbgSurvey   = addslashes($this->dbgSurvey);

        // Always visible in browser console regardless of active state.
        Yii::app()->clientScript->registerScript(
            'ualp_state',
            "console.log('[UALP] survey={$surveyIdJs} | global_db={$dbgGlobal} | survey_db={$dbgSurvey} | logging={$loggingStr}');"
        );

        if ($activeValue !== '1') {
            return;
        }

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
        $stepJs     = $step !== null ? (int)$step : 'null';

        Yii::app()->clientScript->registerScript(
            'ualp_script',
            <<<JS
(function () {
    var surveyId   = {$surveyIdJs};
    var pageNumber = {$stepJs};
    var endpoint   = "{$endpointUrl}";

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

        var newVal = getValue(el);

        var params = new URLSearchParams({
            survey_id:         surveyId,
            page_number:       pageNumber,
            group_id:          parsed.gid ? parseInt(parsed.gid) : '',
            question_code:     parsed.qid,
            sub_question_code: parsed.sub || '',
            input_type:        el.type || el.tagName.toLowerCase(),
            old_value:         oldValues[el.name] !== null ? oldValues[el.name] : '',
            new_value:         newVal !== null ? newVal : '',
            _t:                Date.now()
        });

        oldValues[el.name] = newVal;

        console.log("[UALP] change", { question: parsed.qid, old: params.get('old_value'), new: params.get('new_value') });

        var sep = endpoint.indexOf('?') !== -1 ? '&' : '?';
        fetch(endpoint + sep + params.toString(), {
            method: "GET",
            headers: { "X-Requested-With": "XMLHttpRequest" },
            credentials: "include",
            cache: "no-store"
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

        // Only proceed if the audit log is enabled for this survey
        if ($this->getSurveySetting('active', $surveyId) !== '1') {
            return;
        }

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
        $function = $this->event->get('function');

        // ── saveSetting ──────────────────────────────────────────────────────
        if ($function === 'saveSetting') {
            // Only authenticated admin users may write plugin settings.
            if (Yii::app()->user->isGuest) {
                http_response_code(403);
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
                die();
            }

            $request  = Yii::app()->request;
            $surveyId = (int) $request->getParam('survey_id');
            $value    = $request->getParam('value');

            if ($surveyId <= 0 || !in_array($value, ['0', '1'], true)) {
                http_response_code(400);
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'message' => 'Invalid parameters']);
                die();
            }

            // Use the same model/modelId storage that newSurveySettings and getSurveySetting use
            $this->set('active', $value, 'Survey', $surveyId);

            error_log("[UALP] saveSetting: set active='{$value}' for Survey/{$surveyId}");

            http_response_code(200);
            header('Content-Type: application/json');
            echo json_encode(['status' => 'ok', 'survey_id' => $surveyId, 'value' => $value]);
            die();
        }

        // ── logAnswerChange ───────────────────────────────────────────────────
        if ($function !== 'logAnswerChange') {
            return;
        }

        $request  = Yii::app()->request;
        $surveyId = $request->getParam('survey_id');

        if (empty($surveyId)) {
            error_log('[UALP] newDirectRequest: Missing survey_id');
            http_response_code(400);
            die('Invalid Request: Missing survey_id');
        }

        if ($this->getSurveySetting('active', $surveyId) !== '1') {
            http_response_code(403);
            die('Audit log is disabled for this survey.');
        }

        $surveySession = Yii::app()->session['survey_' . $surveyId] ?? [];
        if (empty($surveySession)) {
            error_log("[UALP] Warning: No survey session found for survey " . $surveyId);
        }
        $token = $surveySession['token'] ?? null;

        $this->writeLog([
            'survey_id'         => (int)$surveyId,
            'participant_token' => $token,
            'event_type'        => 'answer_change',
            'page_number'       => $request->getParam('page_number'),
            'group_id'          => $request->getParam('group_id'),
            'question_code'     => $request->getParam('question_code'),
            'sub_question_code' => $request->getParam('sub_question_code'),
            'input_type'        => $request->getParam('input_type'),
            'old_value'         => $request->getParam('old_value'),
            'new_value'         => $request->getParam('new_value'),
        ]);

        error_log('[UALP] logged answer_change for survey ' . $surveyId);
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'ok']);
        die();
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