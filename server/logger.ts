const SENSITIVE_FIELDS = new Set([
  "password",
  "privatekey",
  "apikey",
  "authorization",
  "secret",
  "token",
]);

function sanitize(obj: Record<string, any>): Record<string, any> {
  const result: Record<string, any> = {};
  for (const [key, value] of Object.entries(obj)) {
    if (SENSITIVE_FIELDS.has(key.toLowerCase())) {
      result[key] = "[REDACTED]";
    } else if (value && typeof value === "object" && !Array.isArray(value)) {
      result[key] = sanitize(value);
    } else {
      result[key] = value;
    }
  }
  return result;
}

const isProduction = process.env.NODE_ENV === "production";

type LogLevel = "info" | "warn" | "error";

interface LogEntry {
  timestamp: string;
  level: LogLevel;
  message: string;
  source?: string;
  route?: string;
  errorType?: string;
  stack?: string;
  requestBody?: Record<string, any>;
  [key: string]: any;
}

class Logger {
  private output(entry: LogEntry) {
    if (isProduction) {
      const method = entry.level === "error" ? "error" : entry.level === "warn" ? "warn" : "log";
      console[method](JSON.stringify(entry));
    } else {
      const time = new Date(entry.timestamp).toLocaleTimeString("en-US", {
        hour: "numeric",
        minute: "2-digit",
        second: "2-digit",
        hour12: true,
      });
      const source = entry.source || entry.level;
      let line = `${time} [${source}] ${entry.message}`;
      if (entry.route) line += ` | route: ${entry.route}`;
      if (entry.errorType) line += ` | type: ${entry.errorType}`;
      if (entry.requestBody) line += ` | body: ${JSON.stringify(entry.requestBody)}`;

      const method = entry.level === "error" ? "error" : entry.level === "warn" ? "warn" : "log";
      console[method](line);
      if (entry.stack && entry.level === "error") {
        console.error(entry.stack);
      }
    }
  }

  info(message: string, meta?: Partial<Omit<LogEntry, "timestamp" | "level" | "message">>) {
    this.output({
      timestamp: new Date().toISOString(),
      level: "info",
      message,
      ...meta,
    });
  }

  warn(message: string, meta?: Partial<Omit<LogEntry, "timestamp" | "level" | "message">>) {
    this.output({
      timestamp: new Date().toISOString(),
      level: "warn",
      message,
      ...meta,
    });
  }

  error(
    message: string,
    error?: Error | unknown,
    meta?: Partial<Omit<LogEntry, "timestamp" | "level" | "message">>,
  ) {
    const entry: LogEntry = {
      timestamp: new Date().toISOString(),
      level: "error",
      message,
      ...meta,
    };
    if (error instanceof Error) {
      entry.errorType = error.constructor.name;
      entry.stack = error.stack;
    }
    this.output(entry);
  }

  request(
    method: string,
    path: string,
    statusCode: number,
    durationMs: number,
    body?: Record<string, any>,
    responseBody?: Record<string, any>,
  ) {
    let message = `${method} ${path} ${statusCode} in ${durationMs}ms`;
    if (responseBody) message += ` :: ${JSON.stringify(responseBody)}`;
    this.output({
      timestamp: new Date().toISOString(),
      level: statusCode >= 500 ? "error" : statusCode >= 400 ? "warn" : "info",
      message,
      route: path,
      requestBody: body ? sanitize(body) : undefined,
      source: "express",
    });
  }
}

export const logger = new Logger();
export { sanitize };
