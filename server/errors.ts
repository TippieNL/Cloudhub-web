export const ErrorCode = {
  VALIDATION_ERROR: "VALIDATION_ERROR",
  NOT_FOUND: "NOT_FOUND",
  UNAUTHORIZED: "UNAUTHORIZED",
  FORBIDDEN: "FORBIDDEN",
  CONFLICT: "CONFLICT",
  INTERNAL_ERROR: "INTERNAL_ERROR",
  RATE_LIMITED: "RATE_LIMITED",
  BAD_REQUEST: "BAD_REQUEST",
  SERVICE_UNAVAILABLE: "SERVICE_UNAVAILABLE",
} as const;

export type ErrorCodeType = (typeof ErrorCode)[keyof typeof ErrorCode];

export class AppError extends Error {
  public readonly statusCode: number;
  public readonly code: ErrorCodeType;
  public readonly isOperational: boolean;

  constructor(
    statusCode: number,
    code: ErrorCodeType,
    message: string,
    isOperational = true,
  ) {
    super(message);
    this.statusCode = statusCode;
    this.code = code;
    this.isOperational = isOperational;
    Object.setPrototypeOf(this, AppError.prototype);
  }
}

export function validationError(message: string): AppError {
  return new AppError(400, ErrorCode.VALIDATION_ERROR, message);
}

export function notFound(message: string): AppError {
  return new AppError(404, ErrorCode.NOT_FOUND, message);
}

export function forbidden(message: string): AppError {
  return new AppError(403, ErrorCode.FORBIDDEN, message);
}

export function conflict(message: string): AppError {
  return new AppError(409, ErrorCode.CONFLICT, message);
}

export function badRequest(message: string): AppError {
  return new AppError(400, ErrorCode.BAD_REQUEST, message);
}

export function internalError(message: string): AppError {
  return new AppError(500, ErrorCode.INTERNAL_ERROR, message, false);
}
