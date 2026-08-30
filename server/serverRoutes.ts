import { Router } from "express";
import type { Request, Response, NextFunction } from "express";
import { storage } from "./storage";
import { createAdapter } from "./adapters";
import {
  localServerConfigSchema,
  ftpServerConfigSchema,
  sftpServerConfigSchema,
  smbServerConfigSchema,
  httpApiServerConfigSchema,
} from "@shared/schema";
import type { StorageServer } from "@shared/schema";
import { badRequest, notFound, validationError } from "./errors";

const router = Router();

const VALID_SERVER_TYPES = ["local", "ftp", "sftp", "smb", "http_api"] as const;

const SENSITIVE_FIELDS = ["password", "privateKey", "apiKey"];
const MASK = "\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022";

// ----- Helpers -----

function maskSensitiveConfig(server: StorageServer): StorageServer {
  const config = { ...((server.config as Record<string, any>) || {}) };
  for (const field of SENSITIVE_FIELDS) {
    if (config[field]) config[field] = MASK;
  }
  return { ...server, config };
}

function validateServerConfig(type: string, config: unknown) {
  const schemas: Record<string, typeof localServerConfigSchema> = {
    local: localServerConfigSchema,
    ftp: ftpServerConfigSchema,
    sftp: sftpServerConfigSchema,
    smb: smbServerConfigSchema,
    http_api: httpApiServerConfigSchema,
  };
  const schema = schemas[type];
  if (!schema) throw badRequest(`Unknown server type: ${type}`);
  return schema.parse(config);
}

async function resolveServer(req: Request): Promise<StorageServer> {
  const id = parseInt(req.params.id, 10);
  if (isNaN(id)) {
    throw badRequest("Invalid server ID");
  }

  const server = await storage.getServer(id);
  if (!server) {
    throw notFound("Server not found");
  }
  return server;
}

function sanitizeBrowsePath(rawPath: string): string {
  return rawPath
    .replace(/\\/g, "/")
    .split("/")
    .filter(Boolean)
    .filter(s => s !== "." && s !== ".." && !s.includes("\0"))
    .join("/");
}

// ----- Routes -----

router.get("/", async (_req: Request, res: Response, next: NextFunction) => {
  try {
    const servers = await storage.getServers();
    res.json(servers.map(maskSensitiveConfig));
  } catch (err) {
    next(err);
  }
});

router.get("/active", async (_req: Request, res: Response, next: NextFunction) => {
  try {
    const servers = await storage.getActiveServers();
    res.json(servers.map(maskSensitiveConfig));
  } catch (err) {
    next(err);
  }
});

router.get("/:id", async (req: Request, res: Response, next: NextFunction) => {
  try {
    const server = await resolveServer(req);
    res.json(maskSensitiveConfig(server));
  } catch (err) {
    next(err);
  }
});

router.post("/", async (req: Request, res: Response, next: NextFunction) => {
  try {
    const { name, type, config, isActive, isDefault } = req.body;

    if (!name || !type || !config) {
      throw badRequest("name, type, and config are required");
    }
    if (!VALID_SERVER_TYPES.includes(type)) {
      throw badRequest(`Invalid type. Must be one of: ${VALID_SERVER_TYPES.join(", ")}`);
    }

    try {
      validateServerConfig(type, config);
    } catch (validationErr: any) {
      if (validationErr.statusCode) throw validationErr;
      throw validationError(`Invalid config: ${validationErr.message}`);
    }

    const server = await storage.createServer({
      name,
      type,
      config,
      isActive: isActive !== false,
      isDefault: isDefault === true,
    });

    if (isDefault) await storage.setDefaultServer(server.id);

    res.status(201).json(maskSensitiveConfig(server));
  } catch (err) {
    next(err);
  }
});

router.put("/:id", async (req: Request, res: Response, next: NextFunction) => {
  try {
    const existing = await resolveServer(req);

    const { name, type, config, isActive, isDefault } = req.body;
    const updateData: Record<string, any> = {};

    if (name !== undefined) updateData.name = name;
    if (type !== undefined) updateData.type = type;
    if (isActive !== undefined) updateData.isActive = isActive;

    if (config !== undefined) {
      const existingConfig = (existing.config as Record<string, any>) || {};
      const mergedConfig = { ...existingConfig };
      for (const [key, value] of Object.entries(config as Record<string, any>)) {
        if (value !== MASK) mergedConfig[key] = value;
      }
      updateData.config = mergedConfig;

      try {
        validateServerConfig(type || existing.type, mergedConfig);
      } catch (validationErr: any) {
        if (validationErr.statusCode) throw validationErr;
        throw validationError(`Invalid config: ${validationErr.message}`);
      }
    }

    const updated = await storage.updateServer(existing.id, updateData);
    if (isDefault === true) await storage.setDefaultServer(existing.id);

    res.json(maskSensitiveConfig(updated!));
  } catch (err) {
    next(err);
  }
});

router.delete("/:id", async (req: Request, res: Response, next: NextFunction) => {
  try {
    const server = await resolveServer(req);
    await storage.deleteServer(server.id);
    res.json({ success: true, message: "Server deleted" });
  } catch (err) {
    next(err);
  }
});

router.post("/:id/test", async (req: Request, res: Response, next: NextFunction) => {
  try {
    const server = await resolveServer(req);
    const adapter = createAdapter(server);
    const result = await adapter.testConnection();
    res.json(result);
  } catch (err) {
    next(err);
  }
});

router.post("/:id/toggle", async (req: Request, res: Response, next: NextFunction) => {
  try {
    const server = await resolveServer(req);
    const updated = await storage.updateServer(server.id, { isActive: !server.isActive });
    res.json(maskSensitiveConfig(updated!));
  } catch (err) {
    next(err);
  }
});

router.post("/:id/set-default", async (req: Request, res: Response, next: NextFunction) => {
  try {
    const server = await resolveServer(req);
    await storage.setDefaultServer(server.id);
    res.json({ success: true, message: `${server.name} set as default` });
  } catch (err) {
    next(err);
  }
});

router.get("/:id/browse", async (req: Request, res: Response, next: NextFunction) => {
  try {
    const server = await resolveServer(req);
    const browsePath = sanitizeBrowsePath((req.query.path as string) || "");
    const adapter = createAdapter(server);
    const files = await adapter.listFiles(browsePath);

    res.json({
      files,
      path: browsePath,
      serverName: server.name,
      serverType: server.type,
    });
  } catch (err) {
    next(err);
  }
});

export default router;
