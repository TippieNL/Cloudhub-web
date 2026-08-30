import { useState, useCallback } from "react";
import { useQuery, useMutation } from "@tanstack/react-query";
import { queryClient, apiRequest } from "@/lib/queryClient";
import { authFetch } from "@/lib/api";
import { SERVER_TYPE_LABELS, SERVER_TYPE_BADGE_CLASSES } from "@/lib/constants";
import { getErrorMessage } from "@/lib/errorHandler";
import { useToast } from "@/hooks/use-toast";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from "@/components/ui/dialog";
import { Badge } from "@/components/ui/badge";
import { Separator } from "@/components/ui/separator";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Switch } from "@/components/ui/switch";
import { Skeleton } from "@/components/ui/skeleton";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import {
  Plus,
  Pencil,
  Trash2,
  Star,
  Server,
  AlertCircle,
  RefreshCw,
  Plug,
  Loader2,
} from "lucide-react";
import type { StorageServer } from "@shared/schema";

const MASKED_VALUE = "\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022";
const SENSITIVE_KEYS = ["password", "privateKey", "apiKey"];

type ServerType = "local" | "ftp" | "sftp" | "smb" | "http_api";

interface ServerFormState {
  name: string;
  type: ServerType;
  config: Record<string, unknown>;
}

function getDefaultConfig(type: ServerType): Record<string, unknown> {
  switch (type) {
    case "local":
      return { basePath: "", baseUrl: "" };
    case "ftp":
      return { host: "", port: 21, username: "", password: "", basePath: "/", baseUrl: "", secure: false };
    case "sftp":
      return { host: "", port: 22, username: "", password: "", privateKey: "", basePath: "/", baseUrl: "" };
    case "smb":
      return { host: "", share: "", username: "", password: "", domain: "", basePath: "/", baseUrl: "", port: 445 };
    case "http_api":
      return { apiEndpoint: "", apiKey: "", uploadPath: "/upload", deletePath: "/delete", listPath: "/list", baseUrl: "" };
  }
}

function getConfigSummary(server: StorageServer): string {
  const cfg = server.config as Record<string, unknown>;
  switch (server.type) {
    case "local":
      return `Path: ${cfg.basePath || "—"}`;
    case "ftp":
    case "sftp":
      return `${cfg.host || "—"}:${cfg.port || "—"} ${cfg.basePath || "/"}`;
    case "smb":
      return `\\\\${cfg.host || "—"}\\${cfg.share || "—"} ${cfg.basePath || "/"}`;
    case "http_api":
      return `${cfg.apiEndpoint || "—"}`;
    default:
      return "";
  }
}

function ConfigFields({
  type,
  config,
  onChange,
}: {
  type: ServerType;
  config: Record<string, unknown>;
  onChange: (key: string, value: unknown) => void;
}) {
  const inputProps = (key: string, label: string, opts?: { required?: boolean; type?: string; placeholder?: string }) => (
    <div className="space-y-1" key={key}>
      <label className="text-sm font-medium" htmlFor={`config-${key}`}>
        {label}
        {opts?.required && <span className="text-destructive ml-1">*</span>}
      </label>
      <Input
        id={`config-${key}`}
        type={opts?.type || "text"}
        value={String(config[key] ?? "")}
        onChange={(e) => onChange(key, opts?.type === "number" ? Number(e.target.value) : e.target.value)}
        placeholder={opts?.placeholder}
        data-testid={`input-config-${key}`}
      />
    </div>
  );

  switch (type) {
    case "local":
      return (
        <div className="space-y-3">
          {inputProps("basePath", "Base Path", { required: true, placeholder: "/path/to/storage" })}
          {inputProps("baseUrl", "Base URL", { placeholder: "https://example.com/files" })}
        </div>
      );
    case "ftp":
      return (
        <div className="space-y-3">
          <div className="grid grid-cols-2 gap-3">
            {inputProps("host", "Host", { required: true, placeholder: "ftp.example.com" })}
            {inputProps("port", "Port", { type: "number", placeholder: "21" })}
          </div>
          <div className="grid grid-cols-2 gap-3">
            {inputProps("username", "Username", { required: true })}
            {inputProps("password", "Password", { type: "password" })}
          </div>
          {inputProps("basePath", "Base Path", { placeholder: "/" })}
          {inputProps("baseUrl", "Base URL", { placeholder: "https://example.com/files" })}
          <div className="flex items-center gap-3">
            <Switch
              id="config-secure"
              checked={Boolean(config.secure)}
              onCheckedChange={(v) => onChange("secure", v)}
              data-testid="switch-config-secure"
            />
            <label className="text-sm font-medium" htmlFor="config-secure">
              Use Secure Connection (FTPS)
            </label>
          </div>
        </div>
      );
    case "sftp":
      return (
        <div className="space-y-3">
          <div className="grid grid-cols-2 gap-3">
            {inputProps("host", "Host", { required: true, placeholder: "sftp.example.com" })}
            {inputProps("port", "Port", { type: "number", placeholder: "22" })}
          </div>
          {inputProps("username", "Username", { required: true })}
          {inputProps("password", "Password", { type: "password" })}
          <div className="space-y-1">
            <label className="text-sm font-medium" htmlFor="config-privateKey">
              Private Key
            </label>
            <textarea
              id="config-privateKey"
              className="flex min-h-[80px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
              value={String(config.privateKey ?? "")}
              onChange={(e) => onChange("privateKey", e.target.value)}
              placeholder="-----BEGIN RSA PRIVATE KEY-----"
              data-testid="input-config-privateKey"
            />
          </div>
          {inputProps("basePath", "Base Path", { placeholder: "/" })}
          {inputProps("baseUrl", "Base URL", { placeholder: "https://example.com/files" })}
        </div>
      );
    case "smb":
      return (
        <div className="space-y-3">
          <div className="grid grid-cols-2 gap-3">
            {inputProps("host", "Host", { required: true, placeholder: "192.168.1.100" })}
            {inputProps("port", "Port", { type: "number", placeholder: "445" })}
          </div>
          {inputProps("share", "Share Name", { required: true, placeholder: "SharedFolder" })}
          <div className="grid grid-cols-2 gap-3">
            {inputProps("username", "Username")}
            {inputProps("password", "Password", { type: "password" })}
          </div>
          {inputProps("domain", "Domain", { placeholder: "WORKGROUP" })}
          {inputProps("basePath", "Base Path", { placeholder: "/" })}
          {inputProps("baseUrl", "Base URL", { placeholder: "https://example.com/files" })}
        </div>
      );
    case "http_api":
      return (
        <div className="space-y-3">
          {inputProps("apiEndpoint", "API Endpoint", { required: true, placeholder: "https://api.example.com" })}
          {inputProps("apiKey", "API Key", { type: "password" })}
          <div className="grid grid-cols-3 gap-3">
            {inputProps("uploadPath", "Upload Path", { placeholder: "/upload" })}
            {inputProps("deletePath", "Delete Path", { placeholder: "/delete" })}
            {inputProps("listPath", "List Path", { placeholder: "/list" })}
          </div>
          {inputProps("baseUrl", "Base URL", { placeholder: "https://example.com/files" })}
        </div>
      );
  }
}

export default function ServerManagement() {
  const [dialogOpen, setDialogOpen] = useState(false);
  const [editingServer, setEditingServer] = useState<StorageServer | null>(null);
  const [formState, setFormState] = useState<ServerFormState>({
    name: "",
    type: "local",
    config: getDefaultConfig("local"),
  });
  const [deleteTarget, setDeleteTarget] = useState<StorageServer | null>(null);
  const [testingId, setTestingId] = useState<number | null>(null);
  const { toast } = useToast();

  const serversQuery = useQuery<StorageServer[]>({
    queryKey: ["/api/servers"],
  });

  const servers = serversQuery.data || [];

  const createMutation = useMutation({
    mutationFn: async (data: { name: string; type: string; config: Record<string, unknown>; isActive: boolean; isDefault: boolean }) => {
      await apiRequest("POST", "/api/servers", data);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["/api/servers"] });
      closeDialog();
      toast({ title: "Server created" });
    },
    onError: (err: Error) => {
      toast({ title: "Failed to create server", description: getErrorMessage(err), variant: "destructive" });
    },
  });

  const updateMutation = useMutation({
    mutationFn: async ({ id, data }: { id: number; data: Record<string, unknown> }) => {
      await apiRequest("PUT", `/api/servers/${id}`, data);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["/api/servers"] });
      closeDialog();
      toast({ title: "Server updated" });
    },
    onError: (err: Error) => {
      toast({ title: "Failed to update server", description: getErrorMessage(err), variant: "destructive" });
    },
  });

  const deleteMutation = useMutation({
    mutationFn: async (id: number) => {
      await apiRequest("DELETE", `/api/servers/${id}`);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["/api/servers"] });
      setDeleteTarget(null);
      toast({ title: "Server deleted" });
    },
    onError: (err: Error) => {
      toast({ title: "Failed to delete server", description: getErrorMessage(err), variant: "destructive" });
    },
  });

  const toggleMutation = useMutation({
    mutationFn: async (id: number) => {
      await apiRequest("POST", `/api/servers/${id}/toggle`);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["/api/servers"] });
    },
    onError: (err: Error) => {
      toast({ title: "Failed to toggle server", description: getErrorMessage(err), variant: "destructive" });
    },
  });

  const setDefaultMutation = useMutation({
    mutationFn: async (id: number) => {
      await apiRequest("POST", `/api/servers/${id}/set-default`);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["/api/servers"] });
      toast({ title: "Default server updated" });
    },
    onError: (err: Error) => {
      toast({ title: "Failed to set default", description: getErrorMessage(err), variant: "destructive" });
    },
  });

  const closeDialog = useCallback(() => {
    setDialogOpen(false);
    setEditingServer(null);
    setFormState({ name: "", type: "local", config: getDefaultConfig("local") });
  }, []);

  const openAddDialog = useCallback(() => {
    setEditingServer(null);
    setFormState({ name: "", type: "local", config: getDefaultConfig("local") });
    setDialogOpen(true);
  }, []);

  const openEditDialog = useCallback((server: StorageServer) => {
    setEditingServer(server);
    const cfg = server.config as Record<string, unknown>;
    const configCopy = { ...cfg };
    for (const key of SENSITIVE_KEYS) {
      if (key in configCopy && configCopy[key] && String(configCopy[key]).length > 0) {
        configCopy[key] = MASKED_VALUE;
      }
    }
    setFormState({
      name: server.name,
      type: server.type as ServerType,
      config: configCopy,
    });
    setDialogOpen(true);
  }, []);

  const handleConfigChange = useCallback((key: string, value: unknown) => {
    setFormState((prev) => ({
      ...prev,
      config: { ...prev.config, [key]: value },
    }));
  }, []);

  const handleTypeChange = useCallback((type: ServerType) => {
    setFormState((prev) => ({
      ...prev,
      type,
      config: getDefaultConfig(type),
    }));
  }, []);

  const handleSave = useCallback(() => {
    const configToSend = { ...formState.config };
    if (editingServer) {
      for (const key of SENSITIVE_KEYS) {
        if (configToSend[key] === MASKED_VALUE) delete configToSend[key];
      }
      updateMutation.mutate({
        id: editingServer.id,
        data: { name: formState.name, config: configToSend },
      });
    } else {
      for (const key of SENSITIVE_KEYS) {
        if (configToSend[key] === "") delete configToSend[key];
      }
      createMutation.mutate({
        name: formState.name,
        type: formState.type,
        config: configToSend,
        isActive: true,
        isDefault: servers.length === 0,
      });
    }
  }, [formState, editingServer, servers.length, createMutation, updateMutation]);

  const handleTestConnection = useCallback(async (serverId: number) => {
    setTestingId(serverId);
    try {
      const res = await authFetch(`/api/servers/${serverId}/test`, { method: "POST" });
      const data = await res.json();
      if (data.success) {
        toast({ title: "Connection successful", description: data.message });
      } else {
        toast({ title: "Connection failed", description: data.message, variant: "destructive" });
      }
    } catch (err) {
      toast({ title: "Connection test failed", description: err instanceof Error ? err.message : "Unknown error", variant: "destructive" });
    } finally {
      setTestingId(null);
    }
  }, [toast]);

  const isSaving = createMutation.isPending || updateMutation.isPending;

  if (serversQuery.isLoading) {
    return (
      <div className="p-6 space-y-4">
        <div className="flex items-center justify-between gap-4 flex-wrap">
          <div>
            <Skeleton className="h-7 w-48 mb-2" />
            <Skeleton className="h-4 w-72" />
          </div>
          <Skeleton className="h-9 w-28" />
        </div>
        <Separator />
        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
          {Array.from({ length: 3 }).map((_, i) => (
            <Card key={i}>
              <CardHeader>
                <Skeleton className="h-5 w-40" />
              </CardHeader>
              <CardContent className="space-y-3">
                <Skeleton className="h-4 w-full" />
                <Skeleton className="h-4 w-3/4" />
                <Skeleton className="h-8 w-full" />
              </CardContent>
            </Card>
          ))}
        </div>
      </div>
    );
  }

  if (serversQuery.isError) {
    return (
      <div className="flex flex-col items-center justify-center h-64 gap-4 px-4">
        <AlertCircle className="h-12 w-12 text-destructive" />
        <p className="text-muted-foreground text-center" data-testid="text-error">
          Failed to load servers. Please check your connection and try again.
        </p>
        <Button
          variant="outline"
          onClick={() => serversQuery.refetch()}
          data-testid="button-retry"
        >
          <RefreshCw className="h-4 w-4 mr-2" />
          Retry
        </Button>
      </div>
    );
  }

  return (
    <div className="p-6 space-y-4">
      <div className="flex items-center justify-between gap-4 flex-wrap">
        <div>
          <h1 className="text-xl font-semibold" data-testid="text-page-title">Storage Servers</h1>
          <p className="text-sm text-muted-foreground" data-testid="text-page-subtitle">
            Manage your file storage server connections
          </p>
        </div>
        <Button onClick={openAddDialog} data-testid="button-add-server">
          <Plus className="h-4 w-4 mr-2" />
          Add Server
        </Button>
      </div>

      <Separator />

      {servers.length === 0 ? (
        <div className="flex flex-col items-center justify-center h-64 gap-3 px-4">
          <Server className="h-16 w-16 text-muted-foreground/50" />
          <p className="text-muted-foreground" data-testid="text-empty-state">
            No storage servers configured yet
          </p>
          <p className="text-sm text-muted-foreground/70 text-center max-w-md">
            Add a storage server to start managing files across local storage, FTP, SFTP, SMB, or HTTP API endpoints.
          </p>
          <Button variant="outline" onClick={openAddDialog} data-testid="button-add-server-empty">
            <Plus className="h-4 w-4 mr-2" />
            Add Your First Server
          </Button>
        </div>
      ) : (
        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
          {servers.map((server) => (
            <Card
              key={server.id}
              className="hover-elevate"
              data-testid={`card-server-${server.id}`}
            >
              <CardHeader className="flex flex-row items-start justify-between gap-2 space-y-0 pb-3">
                <div className="flex-1 min-w-0 space-y-1">
                  <CardTitle className="text-base truncate" data-testid={`text-server-name-${server.id}`}>
                    {server.name}
                  </CardTitle>
                  <div className="flex items-center gap-2 flex-wrap">
                    <Badge
                      variant="secondary"
                      className={`no-default-hover-elevate no-default-active-elevate text-xs ${SERVER_TYPE_BADGE_CLASSES[server.type] || ""}`}
                      data-testid={`badge-type-${server.id}`}
                    >
                      {SERVER_TYPE_LABELS[server.type] || server.type}
                    </Badge>
                    {server.isDefault && (
                      <Badge
                        variant="secondary"
                        className="no-default-hover-elevate no-default-active-elevate text-xs bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200"
                        data-testid={`badge-default-${server.id}`}
                      >
                        <Star className="h-3 w-3 mr-1" />
                        Default
                      </Badge>
                    )}
                  </div>
                </div>
                <div className="flex items-center gap-2 shrink-0">
                  <Switch
                    checked={server.isActive}
                    onCheckedChange={() => toggleMutation.mutate(server.id)}
                    data-testid={`switch-active-${server.id}`}
                  />
                </div>
              </CardHeader>
              <CardContent className="space-y-3">
                <p className="text-sm text-muted-foreground truncate" data-testid={`text-config-summary-${server.id}`}>
                  {getConfigSummary(server)}
                </p>

                <div className="text-xs text-muted-foreground/70" data-testid={`text-status-${server.id}`}>
                  {server.isActive ? "Active" : "Inactive"}
                </div>

                <Separator />

                <div className="flex items-center gap-2 flex-wrap">
                  {!server.isDefault && (
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => setDefaultMutation.mutate(server.id)}
                      disabled={setDefaultMutation.isPending}
                      data-testid={`button-set-default-${server.id}`}
                    >
                      <Star className="h-3 w-3 mr-1" />
                      Set Default
                    </Button>
                  )}
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={() => openEditDialog(server)}
                    data-testid={`button-edit-${server.id}`}
                  >
                    <Pencil className="h-3 w-3 mr-1" />
                    Edit
                  </Button>
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={() => handleTestConnection(server.id)}
                    disabled={testingId === server.id}
                    data-testid={`button-test-${server.id}`}
                  >
                    {testingId === server.id ? (
                      <Loader2 className="h-3 w-3 mr-1 animate-spin" />
                    ) : (
                      <Plug className="h-3 w-3 mr-1" />
                    )}
                    Test
                  </Button>
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={() => setDeleteTarget(server)}
                    data-testid={`button-delete-${server.id}`}
                  >
                    <Trash2 className="h-3 w-3 mr-1" />
                    Delete
                  </Button>
                </div>
              </CardContent>
            </Card>
          ))}
        </div>
      )}

      <Dialog open={dialogOpen} onOpenChange={(open) => { if (!open) closeDialog(); }}>
        <DialogContent className="sm:max-w-lg max-h-[90vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle data-testid="text-dialog-title">
              {editingServer ? "Edit Server" : "Add Server"}
            </DialogTitle>
            <DialogDescription>
              {editingServer
                ? "Update the server configuration below."
                : "Configure a new storage server connection."}
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-4 py-2">
            <div className="space-y-1">
              <label className="text-sm font-medium" htmlFor="server-name">
                Server Name <span className="text-destructive">*</span>
              </label>
              <Input
                id="server-name"
                value={formState.name}
                onChange={(e) => setFormState((prev) => ({ ...prev, name: e.target.value }))}
                placeholder="My Storage Server"
                data-testid="input-server-name"
              />
            </div>

            <div className="space-y-1">
              <label className="text-sm font-medium">
                Server Type <span className="text-destructive">*</span>
              </label>
              <Select
                value={formState.type}
                onValueChange={(v) => handleTypeChange(v as ServerType)}
                disabled={!!editingServer}
              >
                <SelectTrigger data-testid="select-server-type">
                  <SelectValue placeholder="Select type" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="local">Local Storage</SelectItem>
                  <SelectItem value="ftp">FTP</SelectItem>
                  <SelectItem value="sftp">SFTP</SelectItem>
                  <SelectItem value="smb">SMB / CIFS</SelectItem>
                  <SelectItem value="http_api">HTTP API</SelectItem>
                </SelectContent>
              </Select>
            </div>

            <Separator />

            <ConfigFields
              type={formState.type}
              config={formState.config}
              onChange={handleConfigChange}
            />
          </div>

          <DialogFooter className="gap-2">
            <Button variant="outline" onClick={closeDialog} data-testid="button-cancel">
              Cancel
            </Button>
            <Button
              onClick={handleSave}
              disabled={isSaving || !formState.name.trim()}
              data-testid="button-save"
            >
              {isSaving && <Loader2 className="h-4 w-4 mr-2 animate-spin" />}
              {editingServer ? "Save Changes" : "Create Server"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={!!deleteTarget} onOpenChange={(open) => { if (!open) setDeleteTarget(null); }}>
        <DialogContent className="sm:max-w-sm">
          <DialogHeader>
            <DialogTitle data-testid="text-delete-title">Delete Server</DialogTitle>
            <DialogDescription>
              Are you sure you want to delete <strong>{deleteTarget?.name}</strong>? This action cannot be undone.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter className="gap-2">
            <Button
              variant="outline"
              onClick={() => setDeleteTarget(null)}
              data-testid="button-delete-cancel"
            >
              Cancel
            </Button>
            <Button
              variant="destructive"
              onClick={() => deleteTarget && deleteMutation.mutate(deleteTarget.id)}
              disabled={deleteMutation.isPending}
              data-testid="button-delete-confirm"
            >
              {deleteMutation.isPending && <Loader2 className="h-4 w-4 mr-2 animate-spin" />}
              Delete
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
