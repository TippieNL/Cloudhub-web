import * as React from "react";
import { AlertTriangle, RefreshCw } from "lucide-react";
import { Card, CardContent, CardFooter, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";

interface ErrorBoundaryProps {
  children: React.ReactNode;
}

interface ErrorBoundaryState {
  hasError: boolean;
  error: Error | null;
}

export class ErrorBoundary extends React.Component<ErrorBoundaryProps, ErrorBoundaryState> {
  constructor(props: ErrorBoundaryProps) {
    super(props);
    this.state = { hasError: false, error: null };
  }

  static getDerivedStateFromError(error: Error): ErrorBoundaryState {
    return { hasError: true, error };
  }

  componentDidCatch(error: Error, errorInfo: React.ErrorInfo): void {
    console.error("ErrorBoundary caught an error:", error, errorInfo);
  }

  render() {
    if (this.state.hasError) {
      const isDev = import.meta.env.DEV;

      return (
        <div
          data-testid="error-boundary-fallback"
          className="flex items-center justify-center min-h-[400px] p-6"
        >
          <Card className="max-w-md w-full">
            <CardHeader className="flex flex-col items-center gap-2 text-center">
              <AlertTriangle className="h-10 w-10 text-destructive" />
              <CardTitle className="text-xl">Something went wrong</CardTitle>
            </CardHeader>
            <CardContent className="text-center">
              {isDev && this.state.error ? (
                <p className="text-sm text-muted-foreground break-words">
                  {this.state.error.message}
                </p>
              ) : (
                <p className="text-sm text-muted-foreground">
                  An unexpected error occurred. Please try reloading the page.
                </p>
              )}
            </CardContent>
            <CardFooter className="flex justify-center">
              <Button
                data-testid="button-reload"
                onClick={() => window.location.reload()}
              >
                <RefreshCw className="mr-2 h-4 w-4" />
                Reload
              </Button>
            </CardFooter>
          </Card>
        </div>
      );
    }

    return this.props.children;
  }
}
