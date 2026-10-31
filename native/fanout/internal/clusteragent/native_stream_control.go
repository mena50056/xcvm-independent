// SPDX-License-Identifier: AGPL-3.0-or-later
package clusteragent

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"os"
	"path/filepath"
	"strconv"
)

// Only live stream controls are implemented here, not PHP's VOD commands.
const FeatureNativeStreamControls = "native_stream_controls"

func (a *Agent) nativeStreamControls() bool {
	return a.NodeRootGo && a.SeedFanout && a.ReplicaDir != "" && a.FanoutCtl != "" && a.SpoolDir != ""
}

func (a *Agent) controlFile(id int64) string {
	return filepath.Join(a.ReplicaDir, "stream-control", strconv.FormatInt(id, 10)+".json")
}

// A durable stop survives replica refreshes and agent/fanout restarts. Corrupt
// control state fails closed; only a verified start command can clear it.
func (a *Agent) nativeStreamStopped(id int64) bool {
	if !a.nativeStreamControls() {
		return false
	}
	b, err := os.ReadFile(a.controlFile(id))
	if errors.Is(err, os.ErrNotExist) {
		return false
	}
	if err != nil {
		return true
	}
	var state struct {
		Stopped *bool `json:"stopped"`
	}
	return json.Unmarshal(b, &state) != nil || state.Stopped == nil || *state.Stopped
}

func (a *Agent) saveNativeControl(id int64, stopped bool, seq uint64) error {
	dir := filepath.Dir(a.controlFile(id))
	if err := os.MkdirAll(dir, 0700); err != nil {
		return err
	}
	b, _ := json.Marshal(map[string]any{"stopped": stopped, "seq": seq})
	return writeFileMode(a.controlFile(id), b, 0600)
}

// A newly assigned, always-on encoder without resume intent stays stopped
// until a signed start arrives. Existing on-demand registrations stay usable.
func (a *Agent) nativeSeedStopped(id int64, record []byte) bool {
	if a.nativeStreamStopped(id) {
		return true
	}
	if !a.nativeStreamControls() {
		return false
	}
	if _, err := os.Stat(a.controlFile(id)); !errors.Is(err, os.ErrNotExist) {
		return false
	}
	var wrap struct {
		Data struct {
			Resume *bool `json:"resume"`
			Server struct {
				OnDemand int `json:"on_demand"`
			} `json:"server"`
			Runtime struct {
				Kind string `json:"kind"`
			} `json:"runtime"`
		} `json:"data"`
	}
	return json.Unmarshal(record, &wrap) == nil && wrap.Data.Resume != nil && !*wrap.Data.Resume && wrap.Data.Server.OnDemand == 0 && wrap.Data.Runtime.Kind == "transcode"
}

func (a *Agent) nativeStreamState(id int64, starting bool) error {
	status := 0
	if starting {
		status = 2
	}
	return spoolP0(a.SpoolDir, []map[string]any{{"type": "stream.state", "d": map[string]any{
		"stream_id": id, "fields": map[string]any{"pid": nil, "monitor_pid": nil, "stream_status": status, "to_analyze": 0},
	}}})
}

// Called only after handleCommand verified the panel signature, node, epoch,
// expiry and sequence. The directive comes from the already verified replica;
// command arguments cannot carry a URL, shell command or supervisor spec.
func (a *Agent) nativeStreamExec(ctx context.Context, cmd *Command) (bool, bool, []byte) {
	if !a.nativeStreamControls() || (cmd.Type != "stream.start" && cmd.Type != "stream.stop") {
		return false, false, nil
	}
	fail := func(code string) (bool, bool, []byte) {
		b, _ := json.Marshal(map[string]any{"result": false, "error": code})
		return true, false, b
	}
	raw, ok := cmd.Args["stream_id"].(float64)
	if !ok || raw < 1 || raw > MaxStreamID || raw != float64(int64(raw)) || len(cmd.Args) != 1 {
		return fail("INVALID_STREAM_ID")
	}
	id := int64(raw)
	// A missing record can be a command overtaking its new assignment's replica.
	if cmd.Type == "stream.start" {
		if _, err := os.Stat(filepath.Join(streamsDir(a.ReplicaDir), strconv.FormatInt(id, 10)+".json")); errors.Is(err, os.ErrNotExist) {
			if err := a.SyncStreams(ctx); err != nil {
				return fail("REPLICA_UNAVAILABLE")
			}
		}
	}
	a.streamsMu.Lock()
	defer a.streamsMu.Unlock()
	if cmd.Type == "stream.stop" {
		if err := a.saveNativeControl(id, true, cmd.Seq); err != nil {
			return fail("CONTROL_STATE_WRITE_FAILED")
		}
		// Always release even after an agent restart forgot its in-memory spec map.
		if err := a.unsuperviseStream(ctx, id); err != nil {
			return fail("SUPERVISOR_STOP_FAILED")
		}
		delete(a.supervisedSpec, id)
		if err := a.unregisterStream(ctx, id); err != nil {
			return fail("STREAM_REMOVE_FAILED")
		}
		if err := a.nativeStreamState(id, false); err != nil {
			return fail("STATE_REPORT_FAILED")
		}
		return true, true, []byte(`{"result":true,"state":"stopped"}`)
	}
	record, err := os.ReadFile(filepath.Join(streamsDir(a.ReplicaDir), strconv.FormatInt(id, 10)+".json"))
	if err != nil {
		return fail("STREAM_NOT_ASSIGNED")
	}
	d, ok := parseRuntimeDirective(record)
	if !ok {
		return fail("NO_RUNTIME_DIRECTIVE")
	}
	spec, transcode := d.transcodeSpec()
	body, proxy := d.registerBody(a.FfmpegBin)
	if d.Kind == "loopback" {
		body, proxy = a.loopbackBody(id)
	}
	if !transcode && !proxy {
		return fail("UNSUPPORTED_STREAM_RUNTIME")
	}
	if err := a.nativeStreamState(id, true); err != nil {
		return fail("STATE_REPORT_FAILED")
	}
	if err := a.saveNativeControl(id, false, cmd.Seq); err != nil {
		return fail("CONTROL_STATE_WRITE_FAILED")
	}
	// Start also implements the panel's historical restart-as-start behaviour:
	// a running encoder is handed its spec again and gets a new producer PID.
	if transcode {
		if err := a.superviseStream(ctx, id, spec); err != nil {
			return fail("SUPERVISOR_START_FAILED")
		}
		if a.supervisedSpec == nil {
			a.supervisedSpec = map[int64][]byte{}
		}
		a.supervisedSpec[id] = append([]byte(nil), spec...)
	} else {
		if err := a.unsuperviseStream(ctx, id); err != nil {
			return fail("SUPERVISOR_STOP_FAILED")
		}
		delete(a.supervisedSpec, id)
		if err := a.unregisterStream(ctx, id); err != nil {
			return fail("STREAM_REMOVE_FAILED")
		}
		if err := a.registerStream(ctx, id, body); err != nil {
			return fail("STREAM_REGISTER_FAILED")
		}
		req, err := http.NewRequestWithContext(ctx, http.MethodPost, fmt.Sprintf("http://fanout/streams/%d/ensure", id), nil)
		if err != nil {
			return fail("STREAM_START_FAILED")
		}
		response, err := fanoutClient(a.FanoutCtl).Do(req)
		if err != nil {
			return fail("STREAM_START_FAILED")
		}
		response.Body.Close()
		if response.StatusCode < 200 || response.StatusCode >= 300 {
			return fail("STREAM_START_FAILED")
		}
	}
	return true, true, []byte(`{"result":true,"state":"starting"}`)
}
