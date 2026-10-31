Derived from Vateron-Media/XC_VM_Fanout commit 415a9ef60a614f2838191dda68661b3ca8b28136 (AGPL-3.0-or-later; original notices and additional terms retained).

Independent changes implement signed native live-stream start/stop/restart, durable stop intent across replica and process restarts, and failure acknowledgements for unsupported commands. MAIN routes live starts by the advertised native_stream_controls feature; VOD support is not advertised.

Reproduce the Linux agent from this directory:

    CGO_ENABLED=0 GOOS=linux GOARCH=amd64 go build -trimpath -ldflags "-s -w -X main.version=0.16.0-independent.1" -o xc_agent-linux-amd64 ./cmd/xc_agent

The separate xc_fanout daemon is unchanged on the existing test node.
