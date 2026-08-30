import type { Request, Response, NextFunction } from "express";
import crypto from "crypto";
import { config } from "./config";

export function basicAuth(req: Request, res: Response, next: NextFunction): void {
  const authHeader = req.headers.authorization;

  if (!authHeader || !authHeader.startsWith("Basic ")) {
    res.set("WWW-Authenticate", 'Basic realm="File Server"');
    res.status(401).json({
      success: false,
      error: { code: "UNAUTHORIZED", message: "Authentication required" },
    });
    return;
  }

  const credentials = Buffer.from(authHeader.slice(6), "base64").toString("utf-8");
  const [user, pass] = credentials.split(":");

  const userBuf = Buffer.from(user || "");
  const passBuf = Buffer.from(pass || "");
  const expectedUserBuf = Buffer.from(config.authUser);
  const expectedPassBuf = Buffer.from(config.authPass);

  const userMatch = userBuf.length === expectedUserBuf.length &&
    crypto.timingSafeEqual(userBuf, expectedUserBuf);
  const passMatch = passBuf.length === expectedPassBuf.length &&
    crypto.timingSafeEqual(passBuf, expectedPassBuf);

  if (!userMatch || !passMatch) {
    res.set("WWW-Authenticate", 'Basic realm="File Server"');
    res.status(401).json({
      success: false,
      error: { code: "UNAUTHORIZED", message: "Invalid credentials" },
    });
    return;
  }

  next();
}
