<?php

declare(strict_types=1);

namespace Circuit\Service;

class TimeoutExecutor
{
    public function executeWithTimeout(callable $callback, int $timeout): mixed
    {
        if ($timeout <= 0) {
            return $callback();
        }

        // Create shared memory segment
        $shmId = shmop_open(ftok(__FILE__, 'a'), "c", 0644, 1024);
        if ($shmId === false) {
            throw new \RuntimeException('Unable to create shared memory segment');
        }

        $pid = pcntl_fork();
        if ($pid === -1) {
            shmop_delete($shmId);
            throw new \RuntimeException('Unable to fork process');
        }

        if ($pid === 0) {
            // Child process
            try {
                $result = $callback();
                $serialized = serialize($result);
                shmop_write($shmId, $serialized, 0);
                exit(0);
            } catch (\Throwable $e) {
                $serialized = serialize(['error' => $e->getMessage()]);
                shmop_write($shmId, $serialized, 0);
                exit(1);
            }
        }

        // Parent process
        $start = time();
        while (time() - $start < $timeout) {
            $status = null;
            $res = pcntl_waitpid($pid, $status, WNOHANG);
            
            if ($res === -1) {
                shmop_delete($shmId);
                throw new \RuntimeException('Error waiting for child process');
            }
            
            if ($res > 0) {
                $data = shmop_read($shmId, 0, 1024);
                shmop_delete($shmId);
                
                if (pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0) {
                    return unserialize($data);
                }
                
                $error = unserialize($data);
                throw new \RuntimeException($error['error'] ?? "Operation failed");
            }
            
            usleep(100000); // 100ms
        }
        
        posix_kill($pid, SIGKILL);
        pcntl_waitpid($pid, $status); // Clean up zombie process
        shmop_delete($shmId);
        throw new \RuntimeException("Operation timed out after {$timeout} seconds");
    }
}
