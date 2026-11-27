# XC local AI service

The panel includes an Arabic/English assistant in its authenticated admin and
reseller layouts. Inference runs on a separate Ollama host. The model receives
only the conversation and allowlisted tool results, never database credentials.

## Available panel actions

- Search subscriptions, read details and report upcoming expirations or totals.
- Propose enabling/disabling a subscription. Admins can extend expiry by days and
  change connection limits. Resellers can list official packages and purchase a
  renewal through the existing sale service at their effective package price.
- Search ordinary live channels, inspect assigned-server state, and propose
  start/stop/restart on one assigned server. Read server health snapshots.
- Search resellers, propose account activation/suspension and credit adjustments.
  Reseller adjustments transfer funds to/from managed sub-resellers; they do not
  create credits. Admin adjustments support four decimal places. No negative balance.
- Report recent credit movements and net credits within the user's account scope.

Every mutation requires a separate explicit confirmation, valid for five minutes.
The backend revalidates permissions, ownership, balances and reviewed snapshots.

| Area | Admin permissions | Reseller permissions |
| --- | --- | --- |
| Subscription reads and enable/disable | `users`; changes also `edit_user` | `create_line`, own/report-tree lines |
| Expiry/connection administrative edits | `users` and `edit_user` | Unavailable |
| Package renewal | Unavailable; use administrative extension | `create_line`, available official package; charges actor |
| Channel lookup | `streams` | Unavailable |
| Channel status/control | `streams` + `servers`; control also `edit_stream` | Unavailable |
| Server health | `servers` | Unavailable |
| Resellers and credits | `mng_regusers`; changes also `edit_reguser` | `create_sub_resellers`, managed descendants |
| Credit report | `credits_log` | `create_sub_resellers`, scoped movements |

MAG/Enigma lines are excluded. Pending activation codes cannot be enabled or renewed.
Paired lines and unlimited-expiry lines cannot use extension/package renewal.
Package changes follow native duration, bouquet and connection rules, including
restarting the term for incompatible packages. Prices and group access are checked
again at confirmation. Stream acceptance is reported as queued, not proof of playback.
Creation, deletion, bulk actions, shell commands and arbitrary SQL are not tools.

## Enable the panel integration

Create `panel/src/config/assistant.json` on the panel host, readable by the web
service account, with this server-owned configuration (do not commit runtime config):

```json
{"enabled": true, "url": "http://127.0.0.1:11435/api/chat"}
```

Only loopback ports 11434 and 11435 are accepted. Set `enabled` to false to hide
the widget and reject new chat/confirmation requests. The same-origin endpoint
is `api?action=assistant` in both authenticated scopes. GET obtains a session CSRF
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
