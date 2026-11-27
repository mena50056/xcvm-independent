# XC local AI service

The panel includes an Arabic/English assistant in its authenticated admin and
reseller layouts. Inference runs on a separate Ollama host. The model receives
only the conversation and allowlisted tool results, never database credentials.

## Available panel actions

- Search up to ten normal subscriptions by username or ID (no passwords).
- Summarize subscriptions visible to the current user.
- Propose enabling or disabling one subscription, then execute after the user
  presses the explicit confirmation button. Confirmation expires after five minutes.

Admins require `users` for reads and both `users` and `edit_user` for changes.
Resellers require `create_line` and are limited to their own lines and report tree,
matching the existing ownership policy. MAG and Enigma subscriptions are excluded.
Pending activation-code subscriptions cannot be enabled through the assistant.
Creation, deletion, renewal, balance changes, shell commands and arbitrary SQL are
not available tools.

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
Changes and credential-free `users_logs` audit entries commit together, followed by
the existing line-invalidation signal. A signal failure is reported separately.

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
python3 tests/assistant-http.py
python3 tests/assistant-client.py
node --check panel/src/Public/assets/admin/xcvm/assistant.js
```

The permission tests use an isolated SQLite database. HTTP tests use the real
controller and isolated model/tool doubles to check authentication, CSRF,
session-bound confirmations, cancellation, replay and rate limiting. Live deployment
checks separately verify assets, authentication and actual Ollama tool round trips.

References: [Linux service](https://docs.ollama.com/linux),
[chat API](https://docs.ollama.com/api/chat),
[OpenAI compatibility](https://docs.ollama.com/api/openai-compatibility).
