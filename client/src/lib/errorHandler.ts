export class AppError extends Error {
  statusCode: number;
  code: string;
  details?: unknown;

  constructor(statusCode: number, code: string, message: string, details?: unknown) {
    super(message);
    this.name = "AppError";
    this.statusCode = statusCode;
    this.code = code;
    this.details = details;
  }
}

export async function parseApiError(response: Response): Promise<AppError> {
  let body: any;
  try {
    body = await response.json();
  } catch {
    return new AppError(
      response.status,
      "UNKNOWN_ERROR",
      response.statusText || "An unexpected error occurred",
    );
  }

  if (body?.success === false && body?.error) {
    return new AppError(
      response.status,
      body.error.code || "UNKNOWN_ERROR",
      body.error.message || response.statusText,
      body.error.details,
    );
  }

  if (body?.message) {
    return new AppError(
      response.status,
      "UNKNOWN_ERROR",
      body.message,
    );
  }

  return new AppError(
    response.status,
    "UNKNOWN_ERROR",
    response.statusText || "An unexpected error occurred",
  );
}

export function isNetworkError(error: unknown): boolean {
  if (error instanceof TypeError) {
    const msg = error.message.toLowerCase();
    return msg.includes("fetch") || msg.includes("network");
  }
  return false;
}

export function getErrorMessage(error: unknown): string {
  if (isNetworkError(error)) {
    return "Network connection failed. Please try again.";
  }

  if (error instanceof AppError) {
    if (error.code === "VALIDATION_ERROR") {
      return error.message;
    }

    switch (error.statusCode) {
      case 401:
        return "Session expired. Please log in again.";
      case 403:
        return "You don't have permission to do that.";
      case 404:
        return "The requested resource was not found.";
      case 429:
        return "Too many requests. Please try again later.";
      default:
        if (error.statusCode >= 500) {
          return "Server error. Please try again later.";
        }
        return error.message || "An unexpected error occurred.";
    }
  }

  if (error instanceof Error) {
    const statusMatch = error.message.match(/^(\d{3}):/);
    if (statusMatch) {
      const status = parseInt(statusMatch[1], 10);
      switch (status) {
        case 401:
          return "Session expired. Please log in again.";
        case 403:
          return "You don't have permission to do that.";
        case 404:
          return "The requested resource was not found.";
        case 429:
          return "Too many requests. Please try again later.";
        default:
          if (status >= 500) {
            return "Server error. Please try again later.";
          }
      }
    }
    return error.message || "An unexpected error occurred.";
  }

  return "An unexpected error occurred.";
}

export function handleError(
  error: unknown,
  toast: (opts: { title?: string; description: string; variant?: "default" | "destructive" }) => void,
): void {
  const message = getErrorMessage(error);

  toast({
    title: "Error",
    description: message,
    variant: "destructive",
  });

  if (import.meta.env.DEV) {
    console.error("[App Error]", error);
  }
}
