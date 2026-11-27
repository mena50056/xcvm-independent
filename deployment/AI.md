# XC local AI service

The panel assistant is available **only in the authenticated admin panel**.
Reseller pages do not render its widget or assets. The legacy reseller endpoint
returns HTTP 403 for every operation, including metadata and old confirmations,
and clears its old session state. A reseller cannot use the admin endpoint either.
The admin's existing per-action permissions still apply; access to the assistant
does not grant full administrator privileges. Inference runs on a separate local
Ollama host and receives no database credentials or generated account passwords.

## Available admin actions

| Area | Required admin permissions |
| --- | --- |
| Subscription reads and enable/disable | `users`; changes also `edit_user` |
| Expiry/connection edits | `users` and `edit_user` |
| Channel lookup | `streams` |
| Channel status/control | `streams` + `servers`; control also `edit_stream` |
| Server health | `servers` |
| Resellers and credits | `mng_regusers`; changes also `edit_reguser` |
| Subscription creation, single or bulk | `add_user` |
| Reseller creation in a non-admin group | `add_reguser` |
| Credit report | `credits_log` |

Every mutation requires a separate explicit confirmation, valid for five minutes.
The backend revalidates permissions, ownership, balances and reviewed snapshots.
MAG/Enigma lines are excluded. Pending activation codes cannot be enabled or extended.
Paired lines and unlimited-expiry lines cannot use administrative expiry extensions.
Stream acceptance is reported as queued, not proof of playback.

Creation supports a single subscription or a batch of 2–50 normal subscriptions
from an explicitly selected official package, plus single reseller accounts with
an explicitly selected permitted group and initial balance. Admin creation applies
the administrative rules: no debit from the admin's balance; initial reseller
credits are issued administratively. The confirmation states this clearly.
The model cannot override owner, password, price or permissions.

Bulk creation holds one transaction across all native saves and audits: any
failure rolls back every subscription and charge. Confirmation is single-use for
the entire batch. Credentials are shown directly to the authenticated admin,
including an expandable list and browser-only CSV download for bulk results.
They are never sent to inference or saved in conversation history. Reset/reload
removes the displayed credentials. Native audit formats remain unchanged.
Deletion, other bulk mutations, shell commands and arbitrary SQL are not tools.

Legacy reseller domain helpers remain for regression coverage, but no reseller
session can reach them through the assistant. Package purchase and sub-reseller
transfer behavior in the regular panel is unchanged.

## Enable the panel integration

Create `panel/src/config/assistant.json` on the panel host, readable by the web
service account, with this server-owned configuration (do not commit runtime config):

```json
{"enabled": true, "url": "http://127.0.0.1:11435/api/chat"}
```

Only loopback ports 11434 and 11435 are accepted. Set `enabled` to false to hide
the widget and reject new chat/confirmation requests. The same-origin endpoint
is `api?action=assistant` in the authenticated admin scope only. GET obtains a session CSRF
token; POST requires it in `X-CSRF-Token`. The browser never contacts Ollama directly.

Conversation state stays in the PHP session. Permission changes reset it. Model
output renders as text, not HTML. Inference releases the session lock so navigation
continues; a request ID prevents an old response from reviving a reset conversation.
There is one active inference request per session, at most six per minute.
Confirmation tokens are session-bound and consumed before execution. The backend
rechecks permissions and locks/rechecks the line's owner, username and state.
Database changes and assistant audit entries commit together, followed by the existing
line-invalidation signal. Package renewals also retain the native sale audit behavior.
Assistant audit entries omit passwords; existing native audit formats are unchanged.
A signal failure is reported separately. Stream requests and acknowledgments are
audited separately because the stream backend is outside the database transaction.

## Connection contract

- Runtime: Ollama, local inference only (`OLLAMA_NO_CLOUD=1`).
- Base model: `qwen3:8b`; panel alias: `xc-assistant:latest`.
- Native endpoint on the AI host: `http://127.0.0.1:11434/api/chat`.
- OpenAI-compatible base URL: `http://127.0.0.1:11434/v1`.
- Use native chat with `think: false` for predictable CPU response latency.
- For OpenAI-compatible chat, set `reasoning_effort: "none"`; otherwise thinking
  can consume the output budget before any visible answer is returned.
- Context: 8192 tokens; default output limit: 1024 tokens; six CPU threads.
- One active inference request, at most eight queued requests.
- Service limits: six CPU cores worth of time, 12 GiB memory high watermark,
  16 GiB maximum. These limits reduce contention but do not reserve CPU for the panel.

The listener is loopback-only. Ollama does not validate API keys. Never expose
port 11434 publicly or send a client-provided tool definition directly to it.
For a panel on another machine, create an SSH tunnel **on the panel host**:

```sh
ssh -N -T -o ExitOnForwardFailure=yes -o ServerAliveInterval=30 \
  -L 127.0.0.1:11435:127.0.0.1:11434 AI_SSH_USER@AI_HOST
```

Then use `http://127.0.0.1:11435/api/chat` or
`http://127.0.0.1:11435/v1` from the panel backend. Configure a dedicated SSH
identity and a supervised tunnel. The deployed service is `xc-ai-tunnel` on the
panel host, with a dedicated forwarding-only SSH account on the AI host and a
pinned host key. Its only permitted destination is `127.0.0.1:11434`. An SDK requiring an API key can use a
placeholder for this loopback connection; SSH provides remote authentication.

## Request example

```json
{
  "model": "xc-assistant:latest",
  "stream": false,
  "think": false,
  "messages": [{"role": "user", "content": "مرحبا، كيف تساعدني؟"}]
}
```

## Adding tools

The backend must construct an allowlist of narrowly scoped tools per authenticated
user. Validate every tool name, argument, ownership boundary and permission again
at execution time; a model response is not authorization. Keep secrets out of
prompts and provide only the records needed for the request.

Return live data through tool results. Require explicit confirmation for destructive
or bulk operations, deduplicate mutations, and audit the actor, arguments and result.
After execution, append the assistant tool call and corresponding tool result to
the conversation, then request the final answer. Do not report success before the
backend confirms it. Never offer unrestricted shell or SQL execution as tools.

## Operations on the AI host

```sh
systemctl status ollama --no-pager
systemctl is-enabled ollama
journalctl -u ollama -n 80 --no-pager
ollama list
ollama ps
python3 /opt/xc-ai/smoke.py
```

Configuration is in `/etc/systemd/system/ollama.service.d/xc.conf`; the model
definition is `/opt/xc-ai/Modelfile`. After changing configuration, run
`systemctl daemon-reload` and `systemctl restart ollama`. After changing the
model definition, run `ollama create xc-assistant -f /opt/xc-ai/Modelfile`.
Stop inference with `systemctl stop ollama`; disable automatic startup with
`systemctl disable ollama`. These commands do not change the panel.

The inference smoke test checks Arabic output, a read-only tool-call proposal (without
executing a panel action), and the OpenAI-compatible chat endpoint. It is not
a test of panel integration or permission enforcement. Panel regression checks:

```sh
php tests/assistant-permissions.php
php tests/assistant-management.php
php tests/assistant-creation.php
python3 tests/assistant-http.py
python3 tests/assistant-client.py
node --check panel/src/Public/assets/admin/xcvm/assistant.js
```

The permission and management tests use an isolated SQLite database with the real
credit-transfer primitive. Package tests stub the native sale boundary; they verify
price/ownership revalidation and confirmation behavior, not the complete sale runtime. HTTP tests use the real
controller and isolated model/tool doubles to check authentication, CSRF,
session-bound confirmations, cancellation, replay and rate limiting. Live deployment
checks separately verify assets, authentication and actual Ollama tool round trips.

References: [Linux service](https://docs.ollama.com/linux),
[chat API](https://docs.ollama.com/api/chat),
[OpenAI compatibility](https://docs.ollama.com/api/openai-compatibility).
