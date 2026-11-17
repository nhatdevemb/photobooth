import { execFile } from 'node:child_process';
import path from 'node:path';

// DCC 2.x may emit duplicate Content-Length headers that Node's HTTP parser rejects.
// Use the same bundled cURL transport as the camera adapter to detect an existing DCC.
export function dccResponds(phpDirectory, port) {
    const script = "$c=curl_init($argv[1]);curl_setopt_array($c,[CURLOPT_PROXY=>'',CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT_MS=>500,CURLOPT_TIMEOUT_MS=>1500]);$r=curl_exec($c);$s=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);exit($r!==false&&$s===200?0:1);";
    return new Promise((resolve) => {
        execFile(path.join(phpDirectory, 'php.exe'), ['-n', '-d', 'extension_dir=ext', '-d', 'extension=curl', '-r', script, `http://127.0.0.1:${port}/?slc=list&param1=cameras`],
            { cwd: phpDirectory, windowsHide: true, timeout: 3000, maxBuffer: 65536 }, (error) => resolve(!error));
    });
}
