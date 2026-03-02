"use client";

import { Component, type ReactNode } from "react";
import { AlertCircle } from "lucide-react";
import { errorLogger } from "@/lib/error-logger";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";

interface Props {
  children: ReactNode;
  fallback?: ReactNode;
}

interface State {
  hasError: boolean;
  error?: Error;
}

export class ErrorBoundary extends Component<Props, State> {
  constructor(props: Props) {
    super(props);
    this.state = { hasError: false };
  }

  static getDerivedStateFromError(error: Error): State {
    return { hasError: true, error };
  }

  componentDidCatch(error: Error, errorInfo: React.ErrorInfo): void {
    errorLogger.report(error, {
      componentStack: errorInfo.componentStack,
    });
  }

  render() {
    if (this.state.hasError && this.state.error) {
      if (this.props.fallback) {
        return this.props.fallback;
      }

      return (
        <Card className="max-w-md mx-auto mt-8">
          <CardContent className="flex flex-col items-center text-center py-10 space-y-4">
            <div className="flex h-12 w-12 items-center justify-center rounded-full bg-destructive/10">
              <AlertCircle className="h-6 w-6 text-destructive" />
            </div>
            <div className="space-y-1">
              <h3 className="text-lg font-medium">Something went wrong</h3>
              <p className="text-muted-foreground text-sm">
                An unexpected error occurred. The error has been reported for
                debugging.
              </p>
            </div>
            <div className="flex items-center gap-3">
              <Button
                onClick={() => this.setState({ hasError: false, error: undefined })}
              >
                Try again
              </Button>
              <Button
                variant="outline"
                onClick={() => window.location.assign("/")}
              >
                Go Home
              </Button>
            </div>
          </CardContent>
        </Card>
      );
    }

    return this.props.children;
  }
}
