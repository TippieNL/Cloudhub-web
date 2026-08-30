import { useState, useEffect } from "react";
import { Switch, Route } from "wouter";
import { queryClient } from "./lib/queryClient";
import { QueryClientProvider } from "@tanstack/react-query";
import { Toaster } from "@/components/ui/toaster";
import { TooltipProvider } from "@/components/ui/tooltip";
import { ThemeProvider, useTheme } from "@/components/theme-provider";
import { ErrorBoundary } from "@/components/error-boundary";
import {
  isAuthenticated,
  setCredentials,
  clearCredentials,
  authFetch,
} from "@/lib/api";
import { handleError } from "@/lib/errorHandler";
import { toast } from "@/hooks/use-toast";
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
import { Sun, Moon, HardDrive, LogOut, Server, FolderOpen, Eye } from "lucide-react";
import { Separator } from "@/components/ui/separator";
import { Link, useLocation } from "wouter";
import NotFound from "@/pages/not-found";
import FileManager from "@/pages/file-manager";
import ServerManagement from "@/pages/server-management";
import ServerBrowser from "@/pages/server-browser";

function ThemeToggle() {
  const { theme, toggleTheme } = useTheme();
  return (
    <Button
      size="icon"
      variant="ghost"
      onClick={toggleTheme}
      data-testid="button-theme-toggle"
    >
      {theme === "dark" ? (
        <Sun className="h-4 w-4" />
      ) : (
        <Moon className="h-4 w-4" />
      )}
    </Button>
  );
}

function LoginDialog({
  open,
  onLogin,
  error,
}: {
  open: boolean;
  onLogin: (username: string, password: string) => void;
  error: string | null;
}) {
  const [username, setUsername] = useState("");
  const [password, setPassword] = useState("");
  const [loading, setLoading] = useState(false);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setLoading(true);
    await onLogin(username, password);
    setLoading(false);
  };

  return (
    <Dialog open={open}>
      <DialogContent className="sm:max-w-sm" onPointerDownOutside={(e) => e.preventDefault()}>
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <HardDrive className="h-5 w-5" />
            File Server
          </DialogTitle>
          <DialogDescription>
            Enter your credentials to access the file server.
          </DialogDescription>
        </DialogHeader>
        <form onSubmit={handleSubmit} className="space-y-4">
          <div className="space-y-2">
            <label className="text-sm font-medium" htmlFor="login-username">
              Username
            </label>
            <Input
              id="login-username"
              value={username}
              onChange={(e) => setUsername(e.target.value)}
              placeholder="Username"
              autoFocus
              data-testid="input-username"
            />
          </div>
          <div className="space-y-2">
            <label className="text-sm font-medium" htmlFor="login-password">
              Password
            </label>
            <Input
              id="login-password"
              type="password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              placeholder="Password"
              data-testid="input-password"
            />
          </div>
          {error && (
            <p className="text-sm text-destructive" data-testid="text-login-error">
              {error}
            </p>
          )}
          <DialogFooter>
            <Button
              type="submit"
              disabled={loading || !username || !password}
              className="w-full"
              data-testid="button-login"
            >
              {loading ? "Signing in..." : "Sign In"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

function Router() {
  return (
    <Switch>
      <Route path="/" component={FileManager} />
      <Route path="/servers" component={ServerManagement} />
      <Route path="/browse" component={ServerBrowser} />
      <Route component={NotFound} />
    </Switch>
  );
}

function AppContent() {
  const [authed, setAuthed] = useState(isAuthenticated());
  const [loginError, setLoginError] = useState<string | null>(null);
  const [location] = useLocation();

  useEffect(() => {
    if (isAuthenticated()) {
      authFetch("/api/files/config").then((res) => {
        if (res.status === 401) {
          clearCredentials();
          setAuthed(false);
        }
      }).catch(() => {});
    }

    const onUnhandledRejection = (event: PromiseRejectionEvent) => {
      event.preventDefault();
      handleError(event.reason, toast);
    };
    window.addEventListener("unhandledrejection", onUnhandledRejection);
    return () => window.removeEventListener("unhandledrejection", onUnhandledRejection);
  }, []);

  const handleLogin = async (username: string, password: string) => {
    setCredentials(username, password);
    try {
      const res = await authFetch("/api/files/config");
      if (res.ok) {
        setLoginError(null);
        setAuthed(true);
        queryClient.invalidateQueries();
      } else if (res.status === 401) {
        clearCredentials();
        setLoginError("Invalid username or password.");
      } else {
        clearCredentials();
        setLoginError(`Server error: ${res.status}`);
      }
    } catch {
      clearCredentials();
      setLoginError("Unable to connect to the server.");
    }
  };

  const handleLogout = () => {
    clearCredentials();
    setAuthed(false);
    queryClient.clear();
  };

  if (!authed) {
    return (
      <div className="min-h-screen bg-background flex items-center justify-center">
        <LoginDialog
          open={true}
          onLogin={handleLogin}
          error={loginError}
        />
      </div>
    );
  }

  return (
    <div className="flex flex-col h-screen">
      <header className="flex items-center justify-between gap-4 px-4 py-2 border-b bg-background sticky top-0 z-[60]">
        <div className="flex items-center gap-4">
          <div className="flex items-center gap-2 shrink-0">
            <HardDrive className="h-5 w-5 text-primary" />
            <h1 className="text-base font-semibold hidden sm:block">File Server</h1>
          </div>
          <nav className="flex items-center gap-0.5">
            <Link href="/">
              <Button
                variant={location === "/" ? "secondary" : "ghost"}
                size="sm"
                className="gap-1.5"
                data-testid="nav-files"
              >
                <FolderOpen className="h-4 w-4" />
                <span className="hidden sm:inline">Files</span>
              </Button>
            </Link>
            <Link href="/browse">
              <Button
                variant={location === "/browse" ? "secondary" : "ghost"}
                size="sm"
                className="gap-1.5"
                data-testid="nav-browse"
              >
                <Eye className="h-4 w-4" />
                <span className="hidden sm:inline">Browse</span>
              </Button>
            </Link>
            <Link href="/servers">
              <Button
                variant={location === "/servers" ? "secondary" : "ghost"}
                size="sm"
                className="gap-1.5"
                data-testid="nav-servers"
              >
                <Server className="h-4 w-4" />
                <span className="hidden sm:inline">Servers</span>
              </Button>
            </Link>
          </nav>
        </div>
        <div className="flex items-center gap-1">
          <ThemeToggle />
          <Button
            size="icon"
            variant="ghost"
            onClick={handleLogout}
            title="Sign out"
            data-testid="button-logout"
          >
            <LogOut className="h-4 w-4" />
          </Button>
        </div>
      </header>
      <main className="flex-1 overflow-hidden">
        <ErrorBoundary>
          <Router />
        </ErrorBoundary>
      </main>
    </div>
  );
}

function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <TooltipProvider>
        <ThemeProvider>
          <ErrorBoundary>
            <AppContent />
          </ErrorBoundary>
          <Toaster />
        </ThemeProvider>
      </TooltipProvider>
    </QueryClientProvider>
  );
}

export default App;
