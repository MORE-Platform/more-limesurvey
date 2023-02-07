# more-limesurvey
MORE-Specific Repackaging of Limesurvey

## Authentication 

For authentication in MORE we use Keycloak. With the plugin https://github.com/BDSU/limesurvey-oauth2, we can use our account we use for the MORE studymanager 
to sign into Limesurvey. 

The plugin is installed in limesurvey under Configuration > Settings > Plugins > Upload & install (manually for now)
and needs to be configured: 

- Client ID: limesurvey
- Client Secret can be found in the limesurvey client on Keycloak
- Authorize URL: https://auth.more.redlink.io/realms/Auth-Client-Test/protocol/openid-connect/auth
- Scopes: openid
- Access Token URL: https://auth.more.redlink.io/realms/Auth-Client-Test/protocol/openid-connect/token
- User Details URL: https://auth.more.redlink.io/realms/Auth-Client-Test/protocol/openid-connect/userinfo
- Key for username in user details: preferred_username
- Key for e-mail in user details: email
- Key for display name in user details: name
- Check "Create new users", which creates a new user after successfull login

You can set global roles for new users. Roles can be created as administrator under Configuration > Users > User roles > Add user role. Once a new role is created,
it can be added as a global role for new users. 

## Limesurvey API (Remote Control)

Limesurvey uses JSON-RPC. This has to be enabled first before it can be used in Configuration > Settings > Global > Interfaces as such: 

<img width="422" alt="image" src="https://user-images.githubusercontent.com/73277803/217238637-d8830d9f-791e-41f3-bad8-2e1d17246c64.png">

To use the API, you first need to get a session key. Only then you can use it. The most important requests for us are: 

- get_session_key
- add_survey (creates a new survey)
- activate_tokens (creates a participant table, which is needed to import participants)
- add_participants (creates participants with defined parameters, such as token or uses left)
- activate_survey
- copy_survey
- list_surveys (lists all surveys belonging to a user)
- delete_survey

## Answering a questionnaire

To answer a questionnaire, a user needs an access token and the url of the survey. They can then answer the survey and the answers are stored in the limesurvey
database.

## Additional information 

Limesurvey allows custom scripts for a theme. With this feature, custom functionality can be added, when a user submits an answer, for example. This could be useful
in the future.
