import { useState, type ReactNode } from "react";
import { Link, useLocation } from "wouter";
import { Button } from "@/components/ui/button";
import { Sheet, SheetClose, SheetContent, SheetHeader, SheetTitle, SheetTrigger } from "@/components/ui/sheet";
import { ThemeToggle } from "@/components/theme-toggle";
import { cn } from "@/lib/utils";
import { Eye, FolderOpen, HardDrive, LogOut, Menu, Server, Settings2 } from "lucide-react";

const navigation = [
  { href: "/", label: "Files", icon: FolderOpen },
  { href: "/browse", label: "Browse", icon: Eye },
  { href: "/servers", label: "Servers", icon: Server },
];

function Navigation({ onNavigate }: { onNavigate?: () => void }) {
  const [location] = useLocation();

  return (
    <nav aria-label="Primary navigation" className="space-y-1">
      {navigation.map(({ href, label, icon: Icon }) => {
        const active = location === href;
        return (
          <Link key={href} href={href} onClick={onNavigate}>
            <span
              className={cn(
                "flex min-h-10 items-center gap-3 rounded-lg px-3 text-sm font-medium transition-colors",
                "hover:bg-sidebar-accent hover:text-sidebar-accent-foreground",
                active
                  ? "bg-sidebar-accent text-sidebar-accent-foreground shadow-sm"
                  : "text-sidebar-foreground/75",
              )}
              data-testid={`nav-${label.toLowerCase()}`}
            >
              <Icon className="h-4 w-4 shrink-0" aria-hidden="true" />
              <span>{label}</span>
            </span>
          </Link>
        );
      })}
    </nav>
  );
}

export function AppShell({
  children,
  onLogout,
}: {
  children: ReactNode;
  onLogout: () => void;
}) {
  const [mobileNavOpen, setMobileNavOpen] = useState(false);

  return (
    <div className="flex h-screen min-h-0 bg-background text-foreground">
      <aside className="hidden w-60 shrink-0 border-r bg-sidebar lg:flex lg:flex-col">
        <div className="flex h-16 items-center gap-3 border-b px-5">
          <div className="flex h-9 w-9 items-center justify-center rounded-xl bg-primary text-primary-foreground shadow-sm">
            <HardDrive className="h-4 w-4" aria-hidden="true" />
          </div>
          <div className="min-w-0">
            <p className="truncate text-sm font-semibold tracking-tight">Cloud File Hub</p>
            <p className="truncate text-xs text-sidebar-foreground/60">Your files, organized</p>
          </div>
        </div>

        <div className="flex-1 space-y-6 overflow-y-auto p-3">
          <div>
            <p className="mb-2 px-3 text-[11px] font-semibold uppercase tracking-wider text-sidebar-foreground/50">
              Workspace
            </p>
            <Navigation />
          </div>
        </div>

        <div className="border-t p-3">
          <Button
            variant="ghost"
            className="w-full justify-start gap-3 text-sidebar-foreground/75 hover:bg-sidebar-accent hover:text-sidebar-accent-foreground"
            onClick={onLogout}
            data-testid="button-logout-sidebar"
          >
            <LogOut className="h-4 w-4" aria-hidden="true" />
            Sign out
          </Button>
        </div>
      </aside>

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="sticky top-0 z-[60] flex h-16 shrink-0 items-center justify-between gap-3 border-b bg-background/95 px-3 backdrop-blur supports-[backdrop-filter]:bg-background/80 sm:px-5">
          <div className="flex min-w-0 items-center gap-2">
            <Sheet open={mobileNavOpen} onOpenChange={setMobileNavOpen}>
              <SheetTrigger asChild>
                <Button variant="ghost" size="icon" className="lg:hidden" aria-label="Open navigation" data-testid="button-mobile-menu">
                  <Menu className="h-5 w-5" />
                </Button>
              </SheetTrigger>
              <SheetContent side="left" className="w-[min(18rem,85vw)] bg-sidebar px-3">
                <SheetHeader className="px-2 text-left">
                  <SheetTitle className="flex items-center gap-3">
                    <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-primary text-primary-foreground">
                      <HardDrive className="h-4 w-4" />
                    </span>
                    Cloud File Hub
                  </SheetTitle>
                </SheetHeader>
                <div className="mt-8">
                  <SheetClose asChild>
                    <div>
                      <Navigation />
                    </div>
                  </SheetClose>
                </div>
              </SheetContent>
            </Sheet>

            <div className="flex min-w-0 items-center gap-2 lg:hidden">
              <HardDrive className="h-5 w-5 shrink-0 text-primary" aria-hidden="true" />
              <span className="truncate text-sm font-semibold tracking-tight">Cloud File Hub</span>
            </div>
          </div>

          <div className="flex items-center gap-1">
            <Button variant="ghost" size="icon" className="hidden sm:inline-flex" aria-label="Settings" disabled>
              <Settings2 className="h-4 w-4" />
            </Button>
            <ThemeToggle />
            <Button
              variant="ghost"
              size="icon"
              className="lg:hidden"
              onClick={onLogout}
              aria-label="Sign out"
              data-testid="button-logout"
            >
              <LogOut className="h-4 w-4" />
            </Button>
          </div>
        </header>

        <main className="min-h-0 flex-1 overflow-hidden">
          {children}
        </main>
      </div>
    </div>
  );
}
