type LogLevel = 'debug' | 'info' | 'warn' | 'error';

interface LogPayload {
    [key: string]: unknown;
}

interface LogRecord {
    ts: string;
    level: LogLevel;
    msg: string;
    component: string;
    payload?: LogPayload;
}

const LEVEL_PRIORITY: Record<LogLevel, number> = {
    debug: 10,
    info: 20,
    warn: 30,
    error: 40,
};

function currentLevel(): LogLevel {
    const raw = (process.env.MCP_SIDECAR_LOG_LEVEL ?? 'info').toLowerCase();
    if (raw === 'debug' || raw === 'info' || raw === 'warn' || raw === 'error') {
        return raw;
    }
    return 'info';
}

function shouldLog(level: LogLevel): boolean {
    return LEVEL_PRIORITY[level] >= LEVEL_PRIORITY[currentLevel()];
}

function emit(record: LogRecord): void {
    if (!shouldLog(record.level)) {
        return;
    }
    const line = JSON.stringify(record);
    if (record.level === 'error') {
        process.stderr.write(line + '\n');
        return;
    }
    process.stdout.write(line + '\n');
}

export function createLogger(component: string) {
    function log(level: LogLevel, msg: string, payload?: LogPayload): void {
        emit({
            ts: new Date().toISOString(),
            level,
            msg,
            component,
            payload,
        });
    }

    return {
        debug: (msg: string, payload?: LogPayload) => log('debug', msg, payload),
        info: (msg: string, payload?: LogPayload) => log('info', msg, payload),
        warn: (msg: string, payload?: LogPayload) => log('warn', msg, payload),
        error: (msg: string, payload?: LogPayload) => log('error', msg, payload),
    };
}

export type Logger = ReturnType<typeof createLogger>;
