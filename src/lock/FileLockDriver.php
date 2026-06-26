<?php

declare(strict_types=1);

namespace wise\crontab\lock;

/**
 * 文件锁驱动（TOCTOU 安全实现）
 *
 * 基于 PHP flock() + c+ 模式，消除 isLocked() 与 fopen() 之间的竞态窗口。
 * 适用于单机部署或共享文件系统的场景。
 */
class FileLockDriver implements LockDriverInterface
{
    private string $lockDir;

    /** @var array<string, resource> */
    private array $handles = [];

    public function __construct(string $lockDir = '')
    {
        $this->lockDir = rtrim(
            $lockDir ?: sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wise-crontab-locks',
            DIRECTORY_SEPARATOR
        );

        if (!is_dir($this->lockDir)) {
            mkdir($this->lockDir, 0755, true);
        }
    }

    private function getLockFile(string $taskName): string
    {
        return $this->lockDir . DIRECTORY_SEPARATOR . md5($taskName) . '.lock';
    }

    public function acquire(string $taskName, int $ttl): bool
    {
        $lockFile = $this->getLockFile($taskName);

        // 直接尝试获取锁（'c+' 不截断已有文件）
        $fp = @fopen($lockFile, 'c+');
        if (!$fp) {
            return false;
        }

        if (flock($fp, LOCK_EX | LOCK_NB)) {
            ftruncate($fp, 0);
            fwrite($fp, (string)time());
            $this->handles[$taskName] = $fp;
            return true;
        }

        // 检查是否过期
        $mtime = @filemtime($lockFile);
        fclose($fp);

        if ($mtime && (time() - $mtime) > $ttl) {
            @unlink($lockFile);
            $fp = @fopen($lockFile, 'c+');
            if ($fp && flock($fp, LOCK_EX | LOCK_NB)) {
                ftruncate($fp, 0);
                fwrite($fp, (string)time());
                $this->handles[$taskName] = $fp;
                return true;
            }
            if ($fp && is_resource($fp)) {
                fclose($fp);
            }
        }

        return false;
    }

    public function release(string $taskName): void
    {
        if (!isset($this->handles[$taskName])) {
            return;
        }

        $fp = $this->handles[$taskName];
        $lockFile = $this->getLockFile($taskName);

        flock($fp, LOCK_UN);
        fclose($fp);
        unset($this->handles[$taskName]);

        if (file_exists($lockFile)) {
            @unlink($lockFile);
        }
    }

    public function isLocked(string $taskName): bool
    {
        return file_exists($this->getLockFile($taskName));
    }

    public function __destruct()
    {
        foreach ($this->handles as $name => $fp) {
            try {
                $lockFile = $this->getLockFile($name);
                flock($fp, LOCK_UN);
                fclose($fp);
                if (file_exists($lockFile)) {
                    @unlink($lockFile);
                }
            } catch (\Throwable $e) {
                // 析构失败静默
            }
        }
    }
}
