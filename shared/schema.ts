import { z } from "zod";
import { pgTable, text, serial, integer, boolean, timestamp, jsonb } from "drizzle-orm/pg-core";
import { createInsertSchema } from "drizzle-zod";

export const fileItemSchema = z.object({
  name: z.string(),
  path: z.string(),
  isDirectory: z.boolean(),
  size: z.number(),
  modified: z.string(),
  extension: z.string().optional(),
});

export type FileItem = z.infer<typeof fileItemSchema>;

export const serverConfigSchema = z.object({
  readOnly: z.boolean(),
  allowDelete: z.boolean(),
  allowOverwrite: z.boolean(),
  rootDir: z.string().optional(),
});

export type ServerConfig = z.infer<typeof serverConfigSchema>;

export const uploadResponseSchema = z.object({
  success: z.boolean(),
  message: z.string(),
  files: z.array(z.string()).optional(),
});

export type UploadResponse = z.infer<typeof uploadResponseSchema>;

// Drizzle ORM Tables

export const storageServers = pgTable("storage_servers", {
  id: serial("id").primaryKey(),
  name: text("name").notNull(),
  type: text("type").notNull(), // 'local', 'ftp', 'sftp', 'smb', 'http_api'
  isActive: boolean("is_active").notNull().default(true),
  isDefault: boolean("is_default").notNull().default(false),
  config: jsonb("config").notNull(),
  createdAt: timestamp("created_at").notNull().defaultNow(),
  updatedAt: timestamp("updated_at").notNull().defaultNow(),
});

export const fileMetadata = pgTable("file_metadata", {
  id: serial("id").primaryKey(),
  serverId: integer("server_id").notNull().references(() => storageServers.id),
  filePath: text("file_path").notNull(),
  originalName: text("original_name").notNull(),
  size: integer("size").notNull(),
  mimeType: text("mime_type"),
  createdAt: timestamp("created_at").notNull().defaultNow(),
});

// Insert Schemas

export const insertStorageServerSchema = createInsertSchema(storageServers).omit({
  id: true,
  createdAt: true,
  updatedAt: true,
});

export const insertFileMetadataSchema = createInsertSchema(fileMetadata).omit({
  id: true,
  createdAt: true,
});

// Types

export type StorageServer = typeof storageServers.$inferSelect;
export type InsertStorageServer = z.infer<typeof insertStorageServerSchema>;
export type FileMetadataRecord = typeof fileMetadata.$inferSelect;
export type InsertFileMetadata = z.infer<typeof insertFileMetadataSchema>;

// Server Config Schemas

export const localServerConfigSchema = z.object({
  basePath: z.string(),
  baseUrl: z.string().optional(),
});

export const ftpServerConfigSchema = z.object({
  host: z.string(),
  port: z.number().default(21),
  username: z.string(),
  password: z.string(),
  basePath: z.string().default('/'),
  baseUrl: z.string().optional(),
  secure: z.boolean().default(false),
});

export const sftpServerConfigSchema = z.object({
  host: z.string(),
  port: z.number().default(22),
  username: z.string(),
  password: z.string().optional(),
  privateKey: z.string().optional(),
  basePath: z.string().default('/'),
  baseUrl: z.string().optional(),
});

export const smbServerConfigSchema = z.object({
  host: z.string(),
  share: z.string(),
  username: z.string().optional(),
  password: z.string().optional(),
  domain: z.string().optional(),
  basePath: z.string().default('/'),
  baseUrl: z.string().optional(),
  port: z.number().default(445),
});

export const httpApiServerConfigSchema = z.object({
  apiEndpoint: z.string(),
  apiKey: z.string().optional(),
  uploadPath: z.string().default('/upload'),
  deletePath: z.string().default('/delete'),
  listPath: z.string().default('/list'),
  baseUrl: z.string().optional(),
  headers: z.record(z.string()).optional(),
});
