import { drizzle } from "drizzle-orm/node-postgres";
import pg from "pg";
import { eq, and } from "drizzle-orm";
import { storageServers, fileMetadata } from "@shared/schema";
import type {
  StorageServer,
  InsertStorageServer,
  FileMetadataRecord,
  InsertFileMetadata,
} from "@shared/schema";

const pool = new pg.Pool({
  connectionString: process.env.DATABASE_URL,
});

export const db = drizzle(pool);

export interface IStorage {
  // Storage Servers
  getServers(): Promise<StorageServer[]>;
  getServer(id: number): Promise<StorageServer | undefined>;
  getActiveServers(): Promise<StorageServer[]>;
  getDefaultServer(): Promise<StorageServer | undefined>;
  createServer(data: InsertStorageServer): Promise<StorageServer>;
  updateServer(
    id: number,
    data: Partial<InsertStorageServer>
  ): Promise<StorageServer | undefined>;
  deleteServer(id: number): Promise<boolean>;
  setDefaultServer(id: number): Promise<void>;

  // File Metadata
  getFileMetadata(
    serverId: number,
    filePath: string
  ): Promise<FileMetadataRecord | undefined>;
  getFilesByServer(serverId: number): Promise<FileMetadataRecord[]>;
  createFileMetadata(data: InsertFileMetadata): Promise<FileMetadataRecord>;
  deleteFileMetadata(serverId: number, filePath: string): Promise<boolean>;
}

export class DatabaseStorage implements IStorage {
  // Storage Servers

  async getServers(): Promise<StorageServer[]> {
    return db
      .select()
      .from(storageServers)
      .orderBy(storageServers.createdAt);
  }

  async getServer(id: number): Promise<StorageServer | undefined> {
    const result = await db
      .select()
      .from(storageServers)
      .where(eq(storageServers.id, id))
      .limit(1);
    return result[0];
  }

  async getActiveServers(): Promise<StorageServer[]> {
    return db
      .select()
      .from(storageServers)
      .where(eq(storageServers.isActive, true))
      .orderBy(storageServers.createdAt);
  }

  async getDefaultServer(): Promise<StorageServer | undefined> {
    const result = await db
      .select()
      .from(storageServers)
      .where(eq(storageServers.isDefault, true))
      .limit(1);
    return result[0];
  }

  async createServer(data: InsertStorageServer): Promise<StorageServer> {
    const result = await db
      .insert(storageServers)
      .values(data)
      .returning();
    return result[0];
  }

  async updateServer(
    id: number,
    data: Partial<InsertStorageServer>
  ): Promise<StorageServer | undefined> {
    const result = await db
      .update(storageServers)
      .set({
        ...data,
        updatedAt: new Date(),
      })
      .where(eq(storageServers.id, id))
      .returning();
    return result[0];
  }

  async deleteServer(id: number): Promise<boolean> {
    // First delete related file metadata
    await db
      .delete(fileMetadata)
      .where(eq(fileMetadata.serverId, id));

    // Then delete the server
    const result = await db
      .delete(storageServers)
      .where(eq(storageServers.id, id))
      .returning();

    return result.length > 0;
  }

  async setDefaultServer(id: number): Promise<void> {
    // First unset all isDefault to false
    await db
      .update(storageServers)
      .set({
        isDefault: false,
      })
      .where(eq(storageServers.isDefault, true));

    // Then set the target server's isDefault to true
    await db
      .update(storageServers)
      .set({
        isDefault: true,
      })
      .where(eq(storageServers.id, id));
  }

  // File Metadata

  async getFileMetadata(
    serverId: number,
    filePath: string
  ): Promise<FileMetadataRecord | undefined> {
    const result = await db
      .select()
      .from(fileMetadata)
      .where(
        and(
          eq(fileMetadata.serverId, serverId),
          eq(fileMetadata.filePath, filePath)
        )
      )
      .limit(1);
    return result[0];
  }

  async getFilesByServer(serverId: number): Promise<FileMetadataRecord[]> {
    return db
      .select()
      .from(fileMetadata)
      .where(eq(fileMetadata.serverId, serverId));
  }

  async createFileMetadata(
    data: InsertFileMetadata
  ): Promise<FileMetadataRecord> {
    const result = await db
      .insert(fileMetadata)
      .values(data)
      .returning();
    return result[0];
  }

  async deleteFileMetadata(
    serverId: number,
    filePath: string
  ): Promise<boolean> {
    const result = await db
      .delete(fileMetadata)
      .where(
        and(
          eq(fileMetadata.serverId, serverId),
          eq(fileMetadata.filePath, filePath)
        )
      )
      .returning();
    return result.length > 0;
  }
}

export const storage = new DatabaseStorage();
