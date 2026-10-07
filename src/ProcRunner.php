<?php

declare(strict_types=1);

namespace BWC\Visa;

/**
 * Runs an external command with a hard timeout (pdftoppm, soffice, tesseract).
 * A hanging child would otherwise block the whole request until PHP's own
 * max_execution_time kills it - without running any cleanup.
 */
final class ProcRunner
{
    /**
     * @return array{code:int,out:string,timed_out:bool}
     */
    public static function run(string $cmd, int $timeoutSec): array
    {
        $proc = @proc_open(['/bin/sh', '-c', 'exec ' . $cmd], [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
        if (!is_resource($proc)) {
            return ['code' => 127, 'out' => 'could not start process', 'timed_out' => false];
        }
        stream_set_blocking($pipes[1], false);
        $out = '';
        $deadline = microtime(true) + $timeoutSec;
        $timedOut = false;
        $exit = -1;

        while (true) {
            $status = proc_get_status($proc);
            $chunk = stream_get_contents($pipes[1]);
            if ($chunk !== false && $chunk !== '') {
                $out .= $chunk;
            }
            if (!$status['running']) {
                $exit = (int) $status['exitcode']; // only valid on this first poll after exit
                break;
            }
            if (microtime(true) > $deadline) {
                $timedOut = true;
                self::killTree((int) $status['pid']);
                break;
            }
            usleep(20000);
        }
        $out .= (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $closed = proc_close($proc);
        $code = $exit !== -1 ? $exit : $closed;
        return ['code' => $timedOut ? 124 : $code, 'out' => $out, 'timed_out' => $timedOut];
    }

    private static function killTree(int $pid): void
    {
        // children first (sh -c spawns the real binary), then the shell itself
        @exec('pkill -KILL -P ' . $pid . ' 2>/dev/null');
        @posix_kill($pid, 9);
    }
}
