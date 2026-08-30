import type { Express, Request, Response, NextFunction } from "express";
import { type Server } from "http";
import { basicAuth } from "./auth";
import fileRoutes from "./fileRoutes";
import thumbnailRoutes from "./thumbnails";
import shareRoutes, { handlePublicShare } from "./shares";
import serverRoutes from "./serverRoutes";
import { handleWebDAV } from "./webdav";

export async function registerRoutes(
  httpServer: Server,
  app: Express
): Promise<Server> {
  app.use("/api/files", basicAuth, fileRoutes);
  app.use("/api/thumbnail", basicAuth, thumbnailRoutes);
  app.use("/api/shares", basicAuth, shareRoutes);
  app.use("/api/servers", basicAuth, serverRoutes);

  app.get("/share/:token", (req: Request, res: Response) => {
    handlePublicShare(req, res);
  });

  const webdavMiddleware = (req: Request, res: Response, _next: NextFunction) => {
    handleWebDAV(req, res);
  };

  app.use("/webdav", basicAuth, webdavMiddleware);

  return httpServer;
}
