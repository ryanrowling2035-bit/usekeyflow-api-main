<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Support\Facades\File;

class RequestLogController extends Controller
{
    use ApiResponse;

    public function index()
    {
        try {
            $logPath = storage_path('logs');
            $files = glob("{$logPath}/request-log-*.txt");

            $logs = collect($files)->map(function ($file) {
                $filename = basename($file);
                preg_match('/^request-log-(\d{2}-\d{2}-\d{4})\.txt$/', $filename, $matches);

                return [
                    'filename' => $filename,
                    'date' => $matches[1] ?? null,
                    'size' => $this->formatFileSize(filesize($file)),
                    'last_modified' => date('Y-m-d H:i:s', filemtime($file)),
                ];
            })->sortByDesc('last_modified')->values();

            $html = $this->renderIndex($logs);

            return response($html);
        } catch (\Exception $e) {
            return $this->exceptionResponse('RequestLogController : index', $e);
        }
    }

    public function show(string $date)
    {
        try {
            $filename = "request-log-{$date}.txt";
            $path = storage_path("logs/{$filename}");

            if (! File::exists($path)) {
                return response('Log file not found', 422);
            }

            $content = File::get($path);
            $size = $this->formatFileSize(File::size($path));
            $html = $this->renderShow($date, $content, $size);

            return response($html);
        } catch (\Exception $e) {
            return $this->exceptionResponse('RequestLogController : show', $e);
        }
    }

    public function download(string $date)
    {
        try {
            $filename = "request-log-{$date}.txt";
            $path = storage_path("logs/{$filename}");

            if (! File::exists($path)) {
                return response('Log file not found', 422);
            }

            return response()->download($path, $filename, [
                'Content-Type' => 'text/plain',
            ]);
        } catch (\Exception $e) {
            return $this->exceptionResponse('RequestLogController : download', $e);
        }
    }

    public function delete(string $date)
    {
        try {
            $filename = "request-log-{$date}.txt";
            $path = storage_path("logs/{$filename}");

            if (File::exists($path)) {
                File::delete($path);
            }

            return redirect('/request-logs');
        } catch (\Exception $e) {
            return $this->exceptionResponse('RequestLogController : delete', $e);
        }
    }

    public function reset(string $date)
    {
        try {
            $filename = "request-log-{$date}.txt";
            $path = storage_path("logs/{$filename}");

            if (File::exists($path)) {
                File::put($path, '');
            }

            return redirect("/request-logs/{$date}");
        } catch (\Exception $e) {
            return $this->exceptionResponse('RequestLogController : reset', $e);
        }
    }

    private function formatFileSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2).' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 2).' KB';
        }

        return $bytes.' B';
    }

    private function renderIndex($logs): string
    {
        $csrfToken = csrf_token();
        $rows = '';
        foreach ($logs as $log) {
            $date = e($log['date']);
            $size = e($log['size']);
            $modified = e($log['last_modified']);
            $rows .= <<<ROW
                <tr>
                    <td><a href="/request-logs/{$date}">{$log['filename']}</a></td>
                    <td>{$size}</td>
                    <td>{$modified}</td>
                    <td class="actions">
                        <a href="/request-logs/{$date}/download" class="btn">Download</a>
                        <form class="inline-form" method="POST" action="/request-logs/{$date}/delete" onsubmit="return confirm('Delete {$log['filename']}?')">
                            <input type="hidden" name="_token" value="{$csrfToken}">
                            <button type="submit" class="btn btn-danger">Delete</button>
                        </form>
                    </td>
                </tr>
            ROW;
        }

        $count = count($logs);

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Request Logs</title>
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #0f172a; color: #e2e8f0; padding: 2rem; }
                h1 { margin-bottom: 0.5rem; color: #f8fafc; }
                .meta { color: #94a3b8; margin-bottom: 1.5rem; font-size: 0.9rem; }
                table { width: 100%; border-collapse: collapse; background: #1e293b; border-radius: 8px; overflow: hidden; }
                th, td { padding: 0.75rem 1rem; text-align: left; border-bottom: 1px solid #334155; }
                th { background: #334155; color: #cbd5e1; font-weight: 600; font-size: 0.85rem; text-transform: uppercase; }
                tr:last-child td { border-bottom: none; }
                tr:hover td { background: #273548; }
                a { color: #60a5fa; text-decoration: none; }
                a:hover { text-decoration: underline; }
                .empty { text-align: center; padding: 3rem; color: #64748b; }
                .actions { display: flex; gap: 0.5rem; align-items: center; }
                .inline-form { display: inline; }
                .btn { display: inline-block; padding: 0.35rem 0.75rem; font-size: 0.8rem; border-radius: 4px; cursor: pointer; text-align: center; min-width: 80px; border: 1px solid #475569; background: #334155; color: #e2e8f0; text-decoration: none; font-family: inherit; line-height: 1.4; }
                .btn:hover { background: #475569; text-decoration: none; }
                .btn-danger { background: #7f1d1d; border-color: #991b1b; color: #fca5a5; }
                .btn-danger:hover { background: #991b1b; }
            </style>
        </head>
        <body>
            <h1>Request Logs</h1>
            <p class="meta">{$count} log file(s) found</p>
            <table>
                <thead>
                    <tr><th>File</th><th>Size</th><th>Last Modified</th><th>Action</th></tr>
                </thead>
                <tbody>
                    {$rows}
                </tbody>
            </table>
        </body>
        </html>
        HTML;
    }

    private function renderShow(string $date, string $content, string $size): string
    {
        $escapedDate = e($date);
        $entries = $this->parseLogEntries($content);
        $entryCount = count($entries);

        $cards = '';
        foreach ($entries as $i => $entry) {
            // Index from bottom: latest (top) gets highest number, oldest (bottom) gets 1
            $cards .= $this->renderLogCard($entry, $entryCount - $i);
        }

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Log: {$escapedDate}</title>
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #0f172a; color: #e2e8f0; padding: 2rem; }
                h1 { margin-bottom: 0.5rem; color: #f8fafc; }
                .toolbar { display: flex; flex-wrap: wrap; gap: 1rem; align-items: center; margin-bottom: 1.5rem; color: #94a3b8; font-size: 0.9rem; }
                a { color: #60a5fa; text-decoration: none; }
                a:hover { text-decoration: underline; }
                .log-card { background: #1e293b; border-radius: 8px; margin-bottom: 1rem; overflow: hidden; border: 1px solid #334155; }
                .log-header { display: flex; flex-wrap: wrap; gap: 0.5rem 1.5rem; padding: 0.75rem 1rem; font-size: 0.85rem; border-bottom: 1px solid #334155; align-items: center; }
                .log-header .method { font-weight: 700; }
                .log-header .get { color: #34d399; }
                .log-header .post { color: #60a5fa; }
                .log-header .put, .log-header .patch { color: #fbbf24; }
                .log-header .delete { color: #f87171; }
                .log-header .url { color: #e2e8f0; word-break: break-all; }
                .log-header .status { font-weight: 600; }
                .log-header .status.s2xx { color: #34d399; }
                .log-header .status.s4xx { color: #fbbf24; }
                .log-header .status.s5xx { color: #f87171; }
                .log-header .dim { color: #64748b; }
                .log-meta { padding: 0.5rem 1rem; font-size: 0.8rem; color: #94a3b8; display: flex; flex-wrap: wrap; gap: 0.5rem 1.5rem; border-bottom: 1px solid #334155; }
                .body-row { display: flex; gap: 0; border-top: 1px solid #334155; }
                .body-col { flex: 1; min-width: 0; }
                .body-col + .body-col { border-left: 1px solid #334155; }
                details summary { padding: 0.5rem 1rem; cursor: pointer; font-size: 0.8rem; font-weight: 600; color: #94a3b8; user-select: none; list-style: none; display: flex; align-items: center; gap: 0.5rem; }
                details summary::-webkit-details-marker { display: none; }
                details summary:hover { color: #e2e8f0; background: #273548; }
                .json-body { padding: 0.75rem 1rem; font-family: 'SF Mono', 'Fira Code', 'Consolas', monospace; font-size: 0.8rem; line-height: 1.5; white-space: pre-wrap; word-wrap: break-word; background: #0f172a; color: #e2e8f0; max-height: 400px; overflow-y: auto; }
                .copy-btn { margin-left: auto; padding: 0.15rem 0.5rem; font-size: 0.72rem; border-radius: 3px; border: 1px solid #475569; background: #1e293b; color: #94a3b8; cursor: pointer; font-family: inherit; line-height: 1.4; flex-shrink: 0; }
                .copy-btn:hover { background: #334155; color: #e2e8f0; }
                .copy-btn.copied { border-color: #34d399; color: #34d399; }
                .filter-btn { padding: 0.25rem 0.65rem; font-size: 0.78rem; border-radius: 4px; border: 1px solid #475569; background: #1e293b; color: #94a3b8; cursor: pointer; font-family: inherit; }
                .filter-btn:hover { background: #334155; color: #e2e8f0; }
                .filter-btn.active { border-color: #60a5fa; color: #60a5fa; background: #1e3a5f; }
                .filter-btn[data-method="get"].active { border-color: #34d399; color: #34d399; background: #0d3025; }
                .filter-btn[data-method="post"].active { border-color: #60a5fa; color: #60a5fa; background: #1e3a5f; }
                .filter-btn[data-method="put"].active, .filter-btn[data-method="patch"].active { border-color: #fbbf24; color: #fbbf24; background: #3a2a00; }
                .filter-btn[data-method="delete"].active { border-color: #f87171; color: #f87171; background: #3a1010; }
                @media (max-width: 768px) { .body-row { flex-direction: column; } .body-col + .body-col { border-left: none; border-top: 1px solid #334155; } }
            </style>
        </head>
        <body>
            <h1>request-log-{$escapedDate}.txt</h1>
            <div class="toolbar">
                <a href="/request-logs">&larr; Back to list</a>
                <span>|</span>
                <span>{$entryCount} request(s)</span>
                <span>|</span>
                <span>Size: {$size}</span>
                <span>|</span>
                <a href="/request-logs/{$escapedDate}/download">Download</a>
                <span id="filter-bar" style="display:contents"></span>
            </div>
            {$cards}
            <script>
            (function() {
                const hash = window.location.hash.slice(1);
                if (hash) {
                    const card = document.getElementById(hash);
                    if (card) {
                        card.querySelectorAll('details').forEach(function(d) { d.open = true; });
                        setTimeout(function() { card.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 50);
                    }
                }
            })();
            (function() {
                var activeFilter = null;
                var cards = document.querySelectorAll('.log-card[data-method]');
                var methods = [];
                cards.forEach(function(c) {
                    var m = c.dataset.method;
                    if (m && methods.indexOf(m) === -1) methods.push(m);
                });
                if (methods.length < 2) return;
                var bar = document.getElementById('filter-bar');
                var sep = document.createElement('span');
                sep.textContent = '|';
                bar.appendChild(sep);
                var label = document.createElement('span');
                label.textContent = 'Filter by:';
                bar.appendChild(label);
                var allBtn = document.createElement('button');
                allBtn.className = 'filter-btn active';
                allBtn.textContent = 'All';
                allBtn.onclick = function() { setFilter(null, allBtn); };
                bar.appendChild(allBtn);
                var methodOrder = ['get','post','put','patch','delete'];
                methods.sort(function(a, b) {
                    return (methodOrder.indexOf(a) + 1 || 99) - (methodOrder.indexOf(b) + 1 || 99);
                });
                methods.forEach(function(m) {
                    var btn = document.createElement('button');
                    btn.className = 'filter-btn';
                    btn.dataset.method = m;
                    btn.textContent = m.toUpperCase();
                    btn.onclick = function() { setFilter(m, btn); };
                    bar.appendChild(btn);
                });
                function setFilter(method, clickedBtn) {
                    activeFilter = method;
                    bar.querySelectorAll('.filter-btn').forEach(function(b) { b.classList.remove('active'); });
                    clickedBtn.classList.add('active');
                    cards.forEach(function(c) {
                        c.style.display = (!method || c.dataset.method === method) ? '' : 'none';
                    });
                }
            })();
            document.addEventListener('toggle', function(e) {
                if (!e.target.matches('details[data-card]')) return;
                const card = e.target.dataset.card;
                // Sync sibling panel in same card
                document.querySelectorAll('details[data-card="' + card + '"]').forEach(function(d) {
                    d.open = e.target.open;
                });
                // Close all other cards when opening
                if (e.target.open) {
                    document.querySelectorAll('details[data-card]').forEach(function(d) {
                        if (d.dataset.card !== card) d.open = false;
                    });
                }
            }, true);
            function copyLink(event, btn, id) {
                event.preventDefault();
                const url = window.location.origin + window.location.pathname + '#' + id;
                navigator.clipboard.writeText(url).then(function() {
                    btn.textContent = 'Copied!';
                    btn.classList.add('copied');
                    setTimeout(function() {
                        btn.textContent = 'Copy Link';
                        btn.classList.remove('copied');
                    }, 1500);
                });
            }
            </script>
        </body>
        </html>
        HTML;
    }

    private function parseLogEntries(string $content): array
    {
        $blocks = preg_split('/^={80}$/m', $content);
        $entries = [];

        foreach ($blocks as $block) {
            $block = trim($block);
            if ($block === '') {
                continue;
            }

            $entry = [
                'timestamp' => '',
                'method' => '',
                'url' => '',
                'user' => '',
                'ip' => '',
                'duration' => '',
                'status' => '',
                'headers' => '',
                'query' => '',
                'request_body' => '',
                'response_body' => '',
            ];

            $lines = explode("\n", $block);
            $currentSection = null;
            $sectionBuffer = '';

            foreach ($lines as $line) {
                if (preg_match('/^\[(.+?)\] REQUEST$/', $line, $m)) {
                    $entry['timestamp'] = $m[1];

                    continue;
                }
                if (str_starts_with($line, str_repeat('-', 80))) {
                    continue;
                }

                if (preg_match('/^(Method|URL|User|IP|Duration|Status|Headers|Query):\s+(.*)$/', $line, $m)) {
                    $this->flushSection($entry, $currentSection, $sectionBuffer);
                    $key = strtolower($m[1]);
                    $entry[$key] = $m[2];
                    $currentSection = null;
                    $sectionBuffer = '';
                } elseif ($line === 'Request Body:') {
                    $this->flushSection($entry, $currentSection, $sectionBuffer);
                    $currentSection = 'request_body';
                    $sectionBuffer = '';
                } elseif ($line === 'Response Body:') {
                    $this->flushSection($entry, $currentSection, $sectionBuffer);
                    $currentSection = 'response_body';
                    $sectionBuffer = '';
                } elseif ($currentSection !== null) {
                    $sectionBuffer .= ($sectionBuffer ? "\n" : '').$line;
                }
            }
            $this->flushSection($entry, $currentSection, $sectionBuffer);

            if ($entry['timestamp'] || $entry['method']) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    private function flushSection(array &$entry, ?string $section, string $buffer): void
    {
        if ($section === null) {
            return;
        }
        $entry[$section] = trim($buffer);
    }

    private function renderLogCard(array $entry, int $index): string
    {
        $cardId = 'req-'.$index;
        $method = e($entry['method']);
        $methodClass = strtolower($method);
        $url = e($entry['url']);
        $status = e($entry['status']);
        $statusClass = 's'.substr($status, 0, 1).'xx';
        $duration = e($entry['duration']);
        $timestamp = e($entry['timestamp']);
        $user = e($entry['user']);
        $ip = e($entry['ip']);
        $headers = e($entry['headers']);
        $query = e($entry['query']);
        $requestBody = e($entry['request_body'] ?: '-');
        $responseBody = e($entry['response_body'] ?: '-');

        return <<<CARD
        <div class="log-card" id="{$cardId}" data-method="{$methodClass}">
            <div class="log-header">
                <span class="method {$methodClass}">{$method}</span>
                <span class="url">{$url}</span>
                <span class="status {$statusClass}">{$status}</span>
                <span class="dim">{$duration}</span>
                <span class="dim">{$timestamp}</span>
                <button class="copy-btn" style="margin-left:auto" onclick="copyLink(event, this, '{$cardId}')">Copy Link</button>
            </div>
            <div class="log-meta">
                <span>User: {$user}</span>
                <span>IP: {$ip}</span>
                <span>Headers: {$headers}</span>
                <span>Query: {$query}</span>
            </div>
            <div class="body-row">
                <div class="body-col">
                    <details data-card="{$cardId}">
                        <summary>Request Body</summary>
                        <div class="json-body">{$requestBody}</div>
                    </details>
                </div>
                <div class="body-col">
                    <details data-card="{$cardId}">
                        <summary>Response Body</summary>
                        <div class="json-body">{$responseBody}</div>
                    </details>
                </div>
            </div>
        </div>
        CARD;
    }
}
