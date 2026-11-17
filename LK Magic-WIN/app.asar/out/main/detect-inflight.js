'use strict';

function createInFlightRequestCache() {
  const pending = new Map();

  function run(key, executor) {
    if (pending.has(key)) return pending.get(key);
    let request;
    try {
      request = Promise.resolve(executor());
    } catch (error) {
      request = Promise.reject(error);
    }
    pending.set(key, request);
    request.then(
      () => {
        if (pending.get(key) === request) pending.delete(key);
      },
      () => {
        if (pending.get(key) === request) pending.delete(key);
      },
    );
    return request;
  }

  return {
    run,
    has: (key) => pending.has(key),
    size: () => pending.size,
    clear: () => pending.clear(),
  };
}

module.exports = { createInFlightRequestCache };
