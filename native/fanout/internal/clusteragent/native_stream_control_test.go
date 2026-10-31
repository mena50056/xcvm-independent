package clusteragent

import (
	"context"
	"net"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"
)

type nativeControlFixture struct {
	a     *Agent
	mu    sync.Mutex
	calls []string
	fail  string
}

func nativeControlTest(t *testing.T) *nativeControlFixture {
	t.Helper()
	dir := t.TempDir()
	f := &nativeControlFixture{}
	sock := filepath.Join(dir, "c.sock")
	ln, err := net.Listen("unix", sock)
	if err != nil {
		t.Fatal(err)
	}
	server := &http.Server{Handler: http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		f.mu.Lock()
		f.calls = append(f.calls, r.Method+" "+r.URL.Path)
		fail := f.fail
		f.mu.Unlock()
		if r.URL.Path == fail {
			w.WriteHeader(503)
			return
		}
		if strings.HasPrefix(r.URL.Path, "/ingest/") {
			_, _ = w.Write([]byte(`{"socket":"/tmp/ingest.sock"}`))
			return
		}
		if r.URL.Path == "/streams" {
			_, _ = w.Write([]byte(`{"streams":[]}`))
			return
		}
		w.WriteHeader(204)
	})}
	go server.Serve(ln)
	t.Cleanup(func() { server.Close() })
	f.a = &Agent{NodeRootGo: true, SeedFanout: true, ReplicaDir: filepath.Join(dir, "replica"), SpoolDir: filepath.Join(dir, "spool"), FanoutCtl: sock, FfmpegBin: "/usr/bin/ffmpeg"}
	f.a.SetNodePaths(dir+"/", dir+"/", "3", "", "/usr/bin/ffprobe", "/bin/fanout")
	if err := os.MkdirAll(streamsDir(f.a.ReplicaDir), 0700); err != nil {
		t.Fatal(err)
	}
	record := `{"data":{"runtime":{"kind":"transcode","spec":{"sources":[{"cmd":"{ffmpeg_cpu} -i test -f mpegts unix:{ingest_sock}"}]}}}}`
	if err := os.WriteFile(filepath.Join(streamsDir(f.a.ReplicaDir), "7.json"), []byte(record), 0600); err != nil {
		t.Fatal(err)
	}
	return f
}
func (f *nativeControlFixture) run(typ string) (bool, []byte) {
	_, ok, b := f.a.nativeStreamExec(context.Background(), &Command{Type: typ, Seq: 1, Args: map[string]any{"stream_id": float64(7)}})
	return ok, b
}
func (f *nativeControlFixture) count(path string) int {
	f.mu.Lock()
	defer f.mu.Unlock()
	n := 0
	for _, p := range f.calls {
		if p == path {
			n++
		}
	}
	return n
}

func TestNativeStreamControlsExecuteAndPersistStop(t *testing.T) {
	f := nativeControlTest(t)
	if ok, b := f.run("stream.start"); !ok {
		t.Fatalf("start: %s", b)
	}
	if f.count("PUT /monitor/7") != 1 {
		t.Fatal("start did not reach supervisor")
	}
	if ok, b := f.run("stream.start"); !ok {
		t.Fatalf("restart: %s", b)
	}
	if f.count("PUT /monitor/7") != 2 {
		t.Fatal("restart was incorrectly deduplicated")
	}
	if ok, b := f.run("stream.stop"); !ok {
		t.Fatalf("stop: %s", b)
	}
	if f.count("DELETE /monitor/7") != 1 || f.count("DELETE /streams/7") != 1 {
		t.Fatal("stop did not release producer and stream")
	}
	record, _ := os.ReadFile(filepath.Join(streamsDir(f.a.ReplicaDir), "7.json"))
	f.a.seedFanoutRecord(7, record)
	f.a.supervisedSpec = nil
	f.a.seedHeldProxies(context.Background())
	f.a.reconcileFanout(context.Background())
	if f.count("PUT /monitor/7") != 2 {
		t.Fatal("replica or process reseed revived a stopped stream")
	}
	if ok, b := f.run("stream.start"); !ok {
		t.Fatalf("start after stop: %s", b)
	}
	if f.count("PUT /monitor/7") != 3 || f.a.nativeStreamStopped(7) {
		t.Fatal("verified start did not clear persisted stop")
	}
}
func TestNativeStreamControlNeverAcksControlFailure(t *testing.T) {
	f := nativeControlTest(t)
	f.fail = "/monitor/7"
	if ok, b := f.run("stream.start"); ok || !strings.Contains(string(b), "SUPERVISOR_START_FAILED") {
		t.Fatalf("false start success: %t %s", ok, b)
	}
	if ok, b := f.run("stream.stop"); ok || !strings.Contains(string(b), "SUPERVISOR_STOP_FAILED") {
		t.Fatalf("false stop success: %t %s", ok, b)
	}
}
func TestNativeStreamControlRejectsInvalidArguments(t *testing.T) {
	f := nativeControlTest(t)
	for _, args := range []map[string]any{{"stream_id": float64(-1)}, {"stream_id": float64(7.1)}, {"stream_id": "../7"}, {"stream_id": float64(7), "cmd": "arbitrary"}} {
		handled, ok, b := f.a.nativeStreamExec(context.Background(), &Command{Type: "stream.start", Args: args})
		if !handled || ok {
			t.Fatalf("accepted %v: %s", args, b)
		}
	}
	os.Remove(filepath.Join(streamsDir(f.a.ReplicaDir), "7.json"))
	os.Mkdir(filepath.Join(streamsDir(f.a.ReplicaDir), "7.json"), 0700)
	if ok, _ := f.run("stream.start"); ok {
		t.Fatal("unreadable assigned record accepted")
	}
	if len(f.calls) != 0 {
		t.Fatal("invalid control touched fanout")
	}
	if ok, b := DropExec(context.Background(), &Command{Type: "node.rpc"}, WireCommand{}); ok || !strings.Contains(string(b), "UNSUPPORTED_WITHOUT_PHP") {
		t.Fatal("unsupported command reported success")
	}
	f.a.NodeRootGo = false
	if handled, _, _ := f.a.nativeStreamExec(context.Background(), &Command{Type: "stream.start"}); handled {
		t.Fatal("intercepted a classic PHP node")
	}
}

func TestNativeStreamDoesNotAutostartUnrequestedEncoder(t *testing.T) {
	f := nativeControlTest(t)
	record := []byte(`{"data":{"resume":false,"server":{"on_demand":0},"runtime":{"kind":"transcode","spec":{"sources":[{"cmd":"{ffmpeg_cpu} test unix:{ingest_sock}"}]}}}}`)
	os.WriteFile(filepath.Join(streamsDir(f.a.ReplicaDir), "7.json"), record, 0600)
	f.a.seedFanoutRecord(7, record)
	f.a.seedHeldProxies(context.Background())
	if f.count("PUT /monitor/7") != 0 {
		t.Fatal("new stopped encoder autostarted")
	}
	if ok, b := f.run("stream.start"); !ok {
		t.Fatalf("explicit start: %s", b)
	}
	if f.count("PUT /monitor/7") != 1 {
		t.Fatal("explicit start not executed")
	}
}
