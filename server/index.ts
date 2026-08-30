import express, { type Request, Response, NextFunction } from "express";
import rateLimit from "express-rate-limit";
import { registerRoutes } from "./routes";
import { serveStatic } from "./static";
import { createServer } from "http";
import { config } from "./config";
import { logger } from "./logger";
import { AppError } from "./errors";
import { ZodError } from "zod";

const app = express();
app.disable("x-powered-by");
app.set("trust proxy", 1);
const isProduction = process.env.NODE_ENV === "production";

app.use(function securityHeaders(_req, res, next) {
  res.setHeader("X-Content-Type-Options", "nosniff");
  res.setHeader("X-Frame-Options", "DENY");
  res.setHeader("X-XSS-Protection", "1; mode=block");
  res.setHeader("Referrer-Policy", "strict-origin-when-cross-origin");
  if (config.httpsEnabled) {
    res.setHeader("Strict-Transport-Security", "max-age=31536000; includeSubDomains");
  }
  next();
});

const httpServer = createServer(app);

declare module "http" {
  interface IncomingMessage {
    rawBody: unknown;
  }
}

app.use(function jsonParser(req, res, next) {
  if (req.path.startsWith("/webdav")) return next();
  express.json({
    verify: (req, _res, buf) => { req.rawBody = buf; },
  })(req, res, next);
});

app.use(function urlencodedParser(req, res, next) {
  if (req.path.startsWith("/webdav")) return next();
  express.urlencoded({ extended: false })(req, res, next);
});

const apiRateLimiter = rateLimit({
  windowMs: 15 * 60 * 1000,
  max: 100,
  standardHeaders: true,
  legacyHeaders: false,
  message: {
    success: false,
    error: { code: "RATE_LIMITED", message: "Too many requests, please try again later" },
  },
});
app.use("/api", apiRateLimiter);
app.use("/webdav", apiRateLimiter);

export function log(message: string, source = "express") {
  logger.info(message, { source });
}

app.use(function requestLogger(req, res, next) {
  const start = Date.now();
  let capturedJsonBody: Record<string, any> | undefined;

  const originalJson = res.json;
  res.json = function (body, ...args) {
    capturedJsonBody = body;
    return originalJson.apply(res, [body, ...args]);
  };

  res.on("finish", () => {
    if (!req.path.startsWith("/api")) return;
    const durationMs = Date.now() - start;
    logger.request(
      req.method,
      req.path,
      res.statusCode,
      durationMs,
      req.body && typeof req.body === "object" ? req.body : undefined,
      capturedJsonBody,
    );
  });

  next();
});

(async () => {
  process.on("uncaughtException", (err) => {
    logger.error("Uncaught exception — shutting down", err);
    process.exit(1);
  });

  process.on("unhandledRejection", (reason) => {
    logger.error(
      "Unhandled promise rejection",
      reason instanceof Error ? reason : new Error(String(reason)),
    );
  });

  await registerRoutes(httpServer, app);

  app.use((err: any, req: Request, res: Response, next: NextFunction) => {
    if (res.headersSent) return next(err);

    let statusCode = 500;
    let code = "INTERNAL_ERROR";
    let message = "Internal Server Error";
    let details: unknown = undefined;

    if (err instanceof AppError) {
      statusCode = err.statusCode;
      code = err.code;
      message = err.message;
    } else if (err instanceof ZodError) {
      statusCode = 400;
      code = "VALIDATION_ERROR";
      message = "Invalid input data.";
      details = err.errors.map((e) => ({
        field: e.path.join("."),
        message: e.message,
      }));
    } else if (err.status || err.statusCode) {
      statusCode = err.status || err.statusCode;
      message = err.message || message;
    }

    logger.error(`${req.method} ${req.path} → ${statusCode} ${code}`, err, {
      route: req.path,
      requestBody: req.body && typeof req.body === "object" ? req.body : undefined,
    });

    const errorResponse: Record<string, any> = {
      success: false,
      error: { code, message },
    };
    if (details) errorResponse.error.details = details;
    if (!isProduction && err instanceof Error && err.stack) {
      errorResponse.error.stack = err.stack;
    }

    res.status(statusCode).json(errorResponse);
  });

  if (isProduction) {
    serveStatic(app);
  } else {
    const { setupVite } = await import("./vite");
    await setupVite(httpServer, app);
  }

  const port = parseInt(process.env.PORT || "5000", 10);
  httpServer.listen({ port, host: "0.0.0.0", reusePort: true }, () => {
    log(`serving on port ${port}`);
  });
})();
