# Music Backend Endpoints Documentations.

### Every post request need to include _csrf_token with value get from calling endpoint /internal/csrf-token/generator which return {token: token_value} take token_value and set to _csrf_token

### Session endpoint

This endpoint are for login, logout and collection of current user

1. login

This endpoint allow the POST request only here are the requirements

```
URL: /api/session/create
METHOD: POST
TYPE: form submit type not application/json
BODY KEY: email, password, __csrf_token
```
from this post request you will get
