type LogLevel = 'info' | 'warn' | 'error' | 'debug';

interface LogEntry {
  level: LogLevel;
  message: string;
  timestamp: string;
  context?: any;
  error?: Error;
}

class Logger {
  private isDevelopment = process.env.NODE_ENV !== 'production';

  private formatLog(level: LogLevel, message: string, context?: any, error?: Error): LogEntry {
    return {
      level,
      message,
      timestamp: new Date().toISOString(),
      context,
      error,
    };
  }

  private printLog(entry: LogEntry) {
    if (!this.isDevelopment && entry.level === 'debug') return;

    const styles = {
      info: 'color: #3b82f6; font-weight: bold;',
      warn: 'color: #f59e0b; font-weight: bold;',
      error: 'color: #ef4444; font-weight: bold;',
      debug: 'color: #6b7280; font-weight: bold;',
    };

    const message = `%c[${entry.level.toUpperCase()}] %c${entry.timestamp}: %c${entry.message}`;
    const style1 = styles[entry.level];
    const style2 = 'color: inherit;';
    const style3 = 'font-weight: normal;';

    switch (entry.level) {
      case 'info':
        console.log(message, style1, style2, style3, entry.context || '');
        break;
      case 'warn':
        console.warn(message, style1, style2, style3, entry.context || '');
        break;
      case 'error':
        console.error(message, style1, style2, style3, entry.context || '', entry.error || '');
        break;
      case 'debug':
        console.debug(message, style1, style2, style3, entry.context || '');
        break;
      default:
        console.log(message, style1, style2, style3, entry.context || '');
    }
  }

  public info(message: string, context?: any) {
    this.printLog(this.formatLog('info', message, context));
  }

  public warn(message: string, context?: any) {
    this.printLog(this.formatLog('warn', message, context));
  }

  public error(message: string, error?: Error, context?: any) {
    this.printLog(this.formatLog('error', message, context, error));
    
    // In production, you would initialize Sentry or another monitoring tool here
    // Example: Sentry.captureException(error, { extra: context });
  }

  public debug(message: string, context?: any) {
    this.printLog(this.formatLog('debug', message, context));
  }
}

export const logger = new Logger();
