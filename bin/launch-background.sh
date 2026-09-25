#!/bin/bash
# 后台任务启动器（AI 判读等长任务用）。
#
# 为什么需要它：PHP proc_open（Symfony Process）fork 子进程时，
# 子进程会继承 PHP 父进程的全部 fd（含 stdout/stderr 管道写端）。
# 后台孙进程若持有这些 fd，父进程读管道永远等不到 EOF，
# web 请求 / 测试进程随之挂起。这里先关掉 0/1/2 之外的继承 fd，
# 再以新会话（setsid）脱离终端拉起真实命令。
#
# 用法：launch-background.sh <日志文件> <命令> [参数...]

set -u

# 关掉从 PHP 父进程继承的多余 fd（/proc 在 Linux 可用；失败静默继续，
# 此时退化回普通 setsid 拉起，fd 泄漏风险由调用方环境自负）
if [ -d "/proc/$$/fd" ]; then
    for fd in $(ls "/proc/$$/fd" 2>/dev/null); do
        if [ "$fd" -gt 2 ] 2>/dev/null; then
            eval "exec ${fd}>&-" 2>/dev/null || true
            eval "exec ${fd}<&-" 2>/dev/null || true
        fi
    done
fi

LOG="$1"
shift

# 内核级互斥锁：fd 9 持有锁直到判读进程结束（setsid 子进程继承 fd，
# 锁随 artisan 整个生命周期有效），防止 pid 文件时序/复用问题导致
# 并发判读（两个进程写同一日志与 collect 目录会互相踩踏）
exec 9>>"${LOG}.lock"
if ! flock -n 9; then
    echo "[错误] 已有判读进程在运行（锁冲突，本次未启动）" >> "$LOG"
    exit 1
fi

setsid nohup "$@" >> "$LOG" 2>&1 < /dev/null &
echo $! > "${LOG}.pid"
wait
exit 0
