# XC local AI service

The inference service is separate from the panel. It does not yet execute panel
actions or have access to panel credentials, its database, or live status.

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
identity and a supervised tunnel when integrating; neither credentials nor a
permanent tunnel are included here. An SDK requiring an API key can use a
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

## Panel action integration still required

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

The smoke test checks Arabic output, a read-only tool-call proposal (without
executing a panel action), and the OpenAI-compatible chat endpoint. It is not
a test of panel integration or permission enforcement.

References: [Linux service](https://docs.ollama.com/linux),
[chat API](https://docs.ollama.com/api/chat),
[OpenAI compatibility](https://docs.ollama.com/api/openai-compatibility).
