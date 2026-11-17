import { readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';

export async function patchQr(directory) {
    const filename = path.join(directory, 'lib/full/qrencode.php');
    let source = await readFile(filename, 'utf8');
    const replacements = [
        ['$col = $this->count / $this->blocks;', '$col = intdiv($this->count, $this->blocks);'],
        ['$col = ($this->count - $this->dataLength) / $this->blocks;', '$col = intdiv($this->count - $this->dataLength, $this->blocks);'],
    ];
    for (const [before, after] of replacements) {
        if (source.includes(before)) source = source.replace(before, after);
        else if (!source.includes(after)) throw new Error('QR compatibility patch no longer matches the pinned source');
    }
    await writeFile(filename, source);
    await writeFile(path.join(directory, 'PHOTOBOOTH-PATCH.txt'), 'Photobooth PHP 8 compatibility: qrencode.php uses intdiv for two ECC array indices instead of implicit float-to-int conversion. Integer indices and encoded QR data are unchanged. Original LGPL license and source are included.\n');
}
