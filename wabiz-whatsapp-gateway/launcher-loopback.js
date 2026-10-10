'use strict';

const http = require('http');
const { initServer } = require('@wppconnect/server');

const LOOPBACK = '127.0.0.1';
const PORT = 21465;

if (process.env.PORT && Number(process.env.PORT) !== PORT) {
  throw new Error('Refusing to start: PORT must be 21465 for this pilot.');
}

function installRequestGate(server) {
  const originalEmit = server.emit;

  server.emit = function (event, ...args) {
    if (event !== 'request') {
      return originalEmit.call(this, event, ...args);
    }

    const [request, response] = args;

    try {
      if (!request || typeof request.url !== 'string') {
        throw new Error('Invalid request URL');
      }

      const pathname = new URL(request.url, 'http://127.0.0.1').pathname;
      const segments = pathname.split('/').map((segment) => decodeURIComponent(segment));

      if (segments[segments.length - 1] === '') {
        segments.pop();
      }

      const normalized = segments.map((segment) => segment.toLowerCase());
      const method = String(request.method || '').toUpperCase();

      const isStartAll =
        normalized.length === 4 &&
        normalized[0] === '' &&
        normalized[1] === 'api' &&
        normalized[2].length > 0 &&
        normalized[3] === 'start-all';

      const isClearSessionData =
        normalized.length === 5 &&
        normalized[0] === '' &&
        normalized[1] === 'api' &&
        normalized[2].length > 0 &&
        normalized[3].length > 0 &&
        normalized[4] === 'clear-session-data';

      if (method === 'POST' && (isStartAll || isClearSessionData)) {
        response.statusCode = 404;
        response.setHeader('Content-Type', 'text/plain; charset=utf-8');
        response.setHeader('Content-Length', '9');
        response.end('Not Found');
        return true;
      }

      return originalEmit.call(this, event, ...args);
    } catch (_) {
      try {
        if (response && !response.headersSent && !response.writableEnded) {
          response.statusCode = 404;
          response.setHeader('Content-Type', 'text/plain; charset=utf-8');
          response.setHeader('Content-Length', '9');
          response.end('Not Found');
        } else if (request && request.socket && !request.socket.destroyed) {
          request.socket.destroy();
        }
      } catch (_) {
        if (request && request.socket && !request.socket.destroyed) {
          request.socket.destroy();
        }
      }

      return true;
    }
  };
}

const prototype = http.Server.prototype;
const originalListen = prototype.listen;
const originalOwnDescriptor = Object.getOwnPropertyDescriptor(prototype, 'listen');
let interceptListen = true;

prototype.listen = function (...args) {
  const [port, hostOrCallback] = args;
  const requestedPort = typeof port === 'string' ? Number(port) : port;
  const hostWasOmitted = hostOrCallback === undefined || typeof hostOrCallback === 'function';

  if (interceptListen && requestedPort === PORT && hostWasOmitted) {
    installRequestGate(this);
    // Preserve the callback position while inserting the loopback bind address.
    return originalListen.call(this, port, LOOPBACK, hostOrCallback);
  }

  return originalListen.apply(this, args);
};

try {
  initServer({
    port: PORT,
    startAllSession: false,
  });
} finally {
  interceptListen = false;
  if (originalOwnDescriptor) {
    Object.defineProperty(prototype, 'listen', originalOwnDescriptor);
  } else {
    delete prototype.listen;
  }
}