# MORE Limesurvey
MORE-Specific Repackaging of Limesurvey

---
## Development

This repository contains a `docker-compose.yaml` to launch Limesurvey locally for development and testing.

After starting the Compose using `docker compose up -d`, Limesurvey is available at http://localhost:8080.
To access the configuration backend, login via http://localhost:8080/index.php/admin/authentication/sa/login.

## Authentication 

For authentication in MORE we use SSO based on OAuth with Keycloak.
With the plugin https://github.com/BDSU/limesurvey-oauth2, we can use our account we use for the
MORE Study-Manager to sign in to Limesurvey.

The OAuth-plugin is shipped within this docker-image, but needs to be "loaded" before it can be
enabled and configured.
Go to `Configuration > Settings > Plugins`, press the `Scan files`-Button and select the
`AuthOAuth2`-Plugin.

Then continue with the configuration of the plugin:

- Client ID: `limesurvey`
- Client Secret can be found in the limesurvey client on Keycloak
- Authorize URL: https://auth.more.redlink.io/realms/Auth-Client-Test/protocol/openid-connect/auth
- Scopes: `openid`
- Access Token URL: https://auth.more.redlink.io/realms/Auth-Client-Test/protocol/openid-connect/token
- User Details URL: https://auth.more.redlink.io/realms/Auth-Client-Test/protocol/openid-connect/userinfo
- Key for username in user details: `preferred_username`
- Key for e-mail in user details: `email`
- Key for display name in user details: `name`
- Check "Create new users", which creates a new user after successfull login

You can set global roles for new users. Roles can be created as administrator under
`Configuration > Users > User roles > Add user role`. Once a new role is created,
it can be added as a global role for new users.

Finally, enable the plugin in the Overview.

**NOTE**: Even if you enable OAuth2 as the _default login_ mechanism, you can always switch to the default
(local database) login by directly going to `${BASE_URL}/index.php/admin/authentication/sa/login/authMethod/Authdb`. 

## Limesurvey API (Remote Control)

Limesurvey uses JSON-RPC. This has to be enabled first before it can be used in
`Configuration > Settings > Global > Interfaces` as such:

<img width="422" alt="JSON-RPC Configuration" src="doc/img/json-rpc.png">

To use the API, you first need to get a session key. Only then you can use it. The most important
requests for us are:

- get_session_key
- add_survey (creates a new survey)
- activate_tokens (creates a participant table, which is needed to import participants)
- add_participants (creates participants with defined parameters, such as token or uses left)
- activate_survey
- copy_survey
- list_surveys (lists all surveys belonging to a user)
- delete_survey

## Global Survey Settings

We must modify the default settings in LimeSurvey and ensure that these feature are activated within the Global Survey Settings:

- under the Presentation section turn on Automatically load end URL when survey complete:

  `Configuration > Settings > Global survey > Presentation`

  <img width="600" alt="JSON-RPC Configuration" src="doc/img/automatically-load-end-URL-lime-survey.png">

- under the Participant settings trun on Allow multiple responses or update responses with one access code:

  `Configuration > Settings > Global survey > Participant settings`

  <img width="600" alt="JSON-RPC Configuration" src="doc/img/allow-multiple-responses-lime-survey.png">

- under the "Notifications & Data" section you must enable "Date stamp" to store the date-timestamp

  `Configuration > Settings > Global survey > Notifications & Data`

  <img width="600" src="doc/img/date-stamp-lime-survey-setting.png">

- activate time stamps for questionnaires (if this is not activated it will send a incorrect dummy timestamp, that the more studymanager will filter out)

  `Configuration > Global Survey > Notification & Data > Date stamp`
 
 <img width="600" src="doc/img/lime-survey_datastamp.jpg">


## Activate Build In Auditlog

The Buildin Auditlog provided by Limesurvey is tracking actions performed on the admin interface only. Nevertheless, it is a good starting point. Data tracked by this Plugin can be accessed via terminal later on.

- Activate the Auditlog Plugin
  
  `Configuration > Plugins > Auditlog (activate via button)`

 <img width="600" src="doc/img/lime-survey_activate-build-in-auditlog.jpg">


### How to Access the Auditlog

Once activated, the buildin AuditLog will track any action on the admin interface. You can access the data via the terminal as follows:

#### Table Information - Get the basic table information

```bash
docker exec -it [Lime DB Docker Container] psql -U limesurvey -d limesurvey -c "\dt lime_audit*"
```

_Example Output:_

| Schema | Name               | Type  | Owner      |
|--------|--------------------|-------|------------|
| public | lime_auditlog_log | table | limesurvey |


#### Table Information - Get the table fields

```bash
docker exec -it [Lime DB Docker Container] psql -U limesurvey -d limesurvey -c "\d lime_auditlog_log"
```

_Example:_

| Column     | Type         | Nullable | Description                                      |
|------------|--------------|----------|--------------------------------------------------|
| id         | integer      | NOT NULL | Primary key, auto-increment                      |
| created    | timestamp    | -        | When the action occurred                         |
| uid        | varchar(255) | -        | LimeSurvey user ID who performed the action      |
| entity     | varchar(255) | -        | What was affected (e.g. token, survey)           |
| entityid   | varchar(255) | -        | ID of the affected entity                        |
| action     | varchar(255) | -        | What happened (e.g. create, update, delete)      |
| fields     | text         | -        | Which fields were affected                       |
| oldvalues  | text         | -        | Values before the action                         |
| newvalues  | text         | -        | Values after the action                          |

 
#### Table data

```bash
# Get the last 20 entries
docker exec -it [Lime DB Docker Container] psql -U limesurvey -d limesurvey -c "SELECT * FROM lime_auditlog_log ORDER BY created DESC LIMIT 20;"
```

_Example: Creating a participant in the participant table:_

| Field              | Value                                                                                                                                                                           |
|--------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| ID                 | 1                                                                                                                                                                               |
| Created            | 2026-04-07 13:21:08                                                                                                                                                             |
| User ID (uid)      | 1 (admin)                                                                                                                                                                       |
| Entity             | token_376198                                                                                                                                                                    |
| Action             | create                                                                                                                                                                          |
| Fields             | sent, remindersent, remindercount, completed, usesleft, emailstatus, firstname, lastname, email, token, language, validfrom, validuntil, tid, participant_id, blacklisted, mpid |
| Old Values         | (empty)                                                                                                                                                                         |
| New Values         | firstname: Test, lastname: Patient, email: test@test.at, token: (empty), completed: N, usesleft: 1, sent: N, emailstatus: OK, language: en                                      |

## Set permissions for a single survey

These permissions only apply for a single survey. If you want to set permissions for the whole system, you can use global permissions. These permissions can be offered either to a single user or to a user group.

To change the survey permissions, click the Settings tab. Then, click Survey permissions and choose to whom would you like to offer permissions. The permissions can be offered either separately to specific users or to a user group.

By default, an user (non-admin) cannot grant survey permissions to users that are not part of the same group as the survey administrator. This is a security option enabled by default in LimeSurvey. To change this, you need to deactivate option Group member can only see own group, located in the Global settings, under the Security tab. However, if you feel unsure about disabling this option, you can create groups containing those users that can be seen and be granted survey permissions by a survey creator.

Check the following link for further information:
https://manual.limesurvey.org/Manage_users#Set_permissions_for_a_single_survey

## Answering a questionnaire

To answer a questionnaire, a user needs an access token and the url of the survey. They can then
answer the survey and the answers are stored in the limesurvey database.

## Additional information 

Limesurvey allows custom scripts for a theme. With this feature, custom functionality can be
added, when a user submits an answer, for example. This could be useful in the future.
