'use strict';
// Separate journals preserve existing V3 tasks and allow safe UI-package rollback.
const path = require('node:path');
const base = require('./lkmagic-output-v3-base.cjs');
const sourceFolder = require('./lk-export-subfolder-v1.cjs');
function createOutputManager(options) {
  const standard = base.createOutputManager(options);
  const automatic = sourceFolder.createOutputManager({...options,
    stateRoot: () => path.join(options.stateRoot(), 'lk-source-subfolder-v1'),
    stageRoot: () => path.join(options.stageRoot(), 'lk-source-subfolder-v1')});
  return {
    prepare(params, rows) {
      return params?.exportList?.lkMagicSourceSubfolder === true
        ? automatic.prepare(params, rows) : standard.prepare(params, rows);
    },
    bind(job, id) { return (job && job.version === 4 ? automatic : standard).bind(job, id); },
    async decorateEvent(data, reader) { return standard.decorateEvent(await automatic.decorateEvent(data, reader), reader); },
    async decorateHistory(items, reader) { return standard.decorateHistory(await automatic.decorateHistory(items, reader), reader); },
    finalPaths(id, files) { return automatic.finalPaths(id, standard.finalPaths(id, files)); }
  };
}
module.exports = {...base, createOutputManager};
