import { execFileSync } from 'node:child_process'

// Best-effort cleanup: remove any connections the specs created, via the plugin itself.
// Requires wp-cli on PATH and the WordPress root (E2E_WP_PATH). Harmless if unavailable.
const WP_PATH = process.env.E2E_WP_PATH || '/mnt/src/work/wp/sites/wp-dev'
const PHP =
  '$s=new BitApps\\SMTP\\HTTP\\Services\\MailConfigService();' +
  'foreach($s->apiSettings()["connections"] as $c){' +
  '$n=$c["name"];' +
  'if(strpos($n,"E2E SMTP ")===0||strpos($n,"E2E Send ")===0||strpos($n,"E2E PW ")===0){$s->deleteConnection($c["id"]);}' +
  '}'

export default function globalTeardown(): void {
  try {
    // execFile (no shell) — the PHP is a fixed program argument, not interpolated into a command line.
    execFileSync('wp', ['eval', PHP], { cwd: WP_PATH, stdio: 'ignore', timeout: 30_000 })
  } catch {
    // Leftover E2E connections are harmless on a dev site; don't fail the run over cleanup.
  }
}
