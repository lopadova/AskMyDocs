#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
RUNTIME_DIR="$SCRIPT_DIR/.runtime"

# Read only launcher settings as data; never source or evaluate the app's .env.
# Explicit process environment values take precedence over the local file.
if [[ -f "$SCRIPT_DIR/../../.env" ]]; then
  while IFS= read -r fixture_env_line || [[ -n "$fixture_env_line" ]]; do
    fixture_env_line="${fixture_env_line%$'\r'}"
    case "$fixture_env_line" in
      LOCAL_INTEGRATIONS_HOST=*|LOCAL_INTEGRATIONS_API_PORT=*|LOCAL_INTEGRATIONS_MCP_PORT=*|LOCAL_INTEGRATIONS_NODE_BINARY=*)
        fixture_env_key="${fixture_env_line%%=*}"
        if declare -p "$fixture_env_key" >/dev/null 2>&1; then
          continue
        fi
        fixture_env_value="${fixture_env_line#*=}"
        if [[ "$fixture_env_value" == \"*\" || "$fixture_env_value" == \'*\' ]]; then
          fixture_env_value="${fixture_env_value:1:${#fixture_env_value}-2}"
        fi
        export "$fixture_env_key=$fixture_env_value"
        ;;
    esac
  done <"$SCRIPT_DIR/../../.env"
fi

HOST="${LOCAL_INTEGRATIONS_HOST:-127.0.0.1}"
API_PORT="${LOCAL_INTEGRATIONS_API_PORT:-4310}"
MCP_PORT="${LOCAL_INTEGRATIONS_MCP_PORT:-4311}"
API_PID_FILE="$RUNTIME_DIR/api.pid"
MCP_PID_FILE="$RUNTIME_DIR/mcp.pid"
NODE_BINARY="${LOCAL_INTEGRATIONS_NODE_BINARY:-}"

mkdir -p "$RUNTIME_DIR"

resolve_node_binary() {
  if [[ -n "$NODE_BINARY" ]]; then
    if [[ -x "$NODE_BINARY" && ! -d "$NODE_BINARY" ]]; then
      return 0
    fi
    echo "LOCAL_INTEGRATIONS_NODE_BINARY must point to an executable Node binary." >&2
    return 1
  fi

  if NODE_BINARY="$(command -v node)"; then
    return 0
  fi

  # Herd's PHP process does not inherit the interactive shell's PATH.
  local candidate
  for candidate in ~/.local/bin/node /opt/homebrew/bin/node /usr/local/bin/node /usr/bin/node; do
    if [[ -x "$candidate" && ! -d "$candidate" ]]; then
      NODE_BINARY="$candidate"
      return 0
    fi
  done

  echo "Node non disponibile. Configura LOCAL_INTEGRATIONS_NODE_BINARY con il percorso del binario Node." >&2
  return 1
}

expected_health() {
  local name="$1"
  local url="$2"
  local response
  response="$(curl --fail --silent --connect-timeout 1 --max-time 2 "$url")" || return 1
  local pattern='"service"[[:space:]]*:[[:space:]]*"askmydocs-local-'"$name"'-fixture"'
  [[ "$response" =~ $pattern ]]
}

require_loopback_host() {
  case "$HOST" in
    127.0.0.1|::1|localhost) ;;
    *)
      echo "LOCAL_INTEGRATIONS_HOST must be a loopback host." >&2
      exit 2
      ;;
  esac
}

health_host() {
  if [[ "$HOST" == "::1" ]]; then
    printf '[%s]' "$HOST"
    return
  fi

  printf '%s' "$HOST"
}

is_our_process() {
  local pid="$1"
  local server_file="$2"
  local command

  command="$(ps -p "$pid" -o command= 2>/dev/null || true)"
  [[ -n "$command" && "$command" == *"$SCRIPT_DIR/$server_file"* ]]
}

running_pid() {
  local pid_file="$1"
  local server_file="$2"

  [[ -f "$pid_file" ]] || return 1

  local pid
  pid="$(<"$pid_file")"
  if is_our_process "$pid" "$server_file"; then
    printf '%s\n' "$pid"
    return 0
  fi

  rm -f "$pid_file"
  return 1
}

wait_for_health() {
  local name="$1"
  local url="$2"

  for _ in {1..30}; do
    if expected_health "$name" "$url"; then
      return 0
    fi
    sleep 0.2
  done

  echo "$name did not become healthy at $url. See $RUNTIME_DIR/$name.log" >&2
  return 1
}

start_one() {
  local name="$1"
  local server_file="$2"
  local pid_file="$3"
  local health_url="$4"

  local pid
  if pid="$(running_pid "$pid_file" "$server_file")"; then
    if expected_health "$name" "$health_url"; then
      echo "$name is already running and healthy (pid $pid)."
      return 0
    fi

    echo "$name is running but not healthy; use restart after inspecting $RUNTIME_DIR/$name.log." >&2
    return 1
  fi

  if curl --silent --connect-timeout 1 --max-time 2 "$health_url" >/dev/null 2>&1; then
    echo "Impossibile avviare $name: la porta di $health_url è già occupata da un processo non gestito dal launcher." >&2
    return 1
  fi

  resolve_node_binary
  local pid
  pid="$("$NODE_BINARY" "$SCRIPT_DIR/spawn-server.mjs" "$SCRIPT_DIR/$server_file" "$RUNTIME_DIR/$name.log")"
  printf '%s\n' "$pid" >"$pid_file"

  if ! wait_for_health "$name" "$health_url"; then
    stop_one "$name" "$server_file" "$pid_file"
    return 1
  fi

  echo "$name started (pid $pid)."
}

stop_one() {
  local name="$1"
  local server_file="$2"
  local pid_file="$3"

  local pid
  if ! pid="$(running_pid "$pid_file" "$server_file")"; then
    echo "$name is not running."
    return 0
  fi

  kill -TERM "$pid"
  for _ in {1..25}; do
    if ! kill -0 "$pid" 2>/dev/null; then
      rm -f "$pid_file"
      echo "$name stopped."
      return 0
    fi
    sleep 0.2
  done

  if is_our_process "$pid" "$server_file"; then
    kill -KILL "$pid"
  fi
  rm -f "$pid_file"
  echo "$name stopped after timeout."
}

status_one() {
  local name="$1"
  local server_file="$2"
  local pid_file="$3"
  local health_url="$4"

  local pid
  if pid="$(running_pid "$pid_file" "$server_file")"; then
    if expected_health "$name" "$health_url"; then
      echo "$name is running and healthy (pid $pid)."
    else
      echo "$name is running but not healthy (pid $pid)." >&2
      return 1
    fi
  else
    echo "$name is stopped."
    return 1
  fi
}

require_loopback_host
HEALTH_HOST="$(health_host)"

case "${1:-status}" in
  start)
    start_one api api-server.mjs "$API_PID_FILE" "http://$HEALTH_HOST:$API_PORT/health"
    start_one mcp mcp-server.mjs "$MCP_PID_FILE" "http://$HEALTH_HOST:$MCP_PORT/health"
    ;;
  stop)
    stop_one mcp mcp-server.mjs "$MCP_PID_FILE"
    stop_one api api-server.mjs "$API_PID_FILE"
    ;;
  restart)
    "$0" stop
    "$0" start
    ;;
  status)
    api_status=0
    mcp_status=0
    status_one api api-server.mjs "$API_PID_FILE" "http://$HEALTH_HOST:$API_PORT/health" || api_status=$?
    status_one mcp mcp-server.mjs "$MCP_PID_FILE" "http://$HEALTH_HOST:$MCP_PORT/health" || mcp_status=$?
    (( api_status == 0 && mcp_status == 0 ))
    ;;
  *)
    echo "Usage: $0 {start|stop|restart|status}" >&2
    exit 2
    ;;
esac
