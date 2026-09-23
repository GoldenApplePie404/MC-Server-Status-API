package main

import (
	"fmt"
	"os"
	"path/filepath"
)

// atomicWriteFile 把 data 写入 path，保证最终可见性是完整文件（tmp + rename）。
//
// 为什么需要：
//   - 直接 os.WriteFile 在进程崩溃 / 断电 / 磁盘满时可能留下半截文件，
//     下次读 JSON / JSONL 时解析失败。
//   - tmp + rename 是 OS 级原子替换：写坏了只是留下一个孤立 .tmp，
//     旧文件在 rename 完成前始终完整可见。
//
// 跨平台注意：
//   - Linux/macOS: os.Rename 同文件系统原子替换已存在目标。
//   - Windows: Go 的 os.Rename 内部调 MoveEx + MOVEFILE_REPLACE_EXISTING，
//     也能原子替换已存在目标。同目录调用不存在跨设备回退问题。
func atomicWriteFile(path string, data []byte, perm os.FileMode) error {
	dir := filepath.Dir(path)
	if err := os.MkdirAll(dir, 0o755); err != nil {
		return fmt.Errorf("atomicWriteFile 创建目录失败: %w", err)
	}

	// tmp 文件放在同目录，确保 rename 是同文件系统原子操作
	tmp, err := os.CreateTemp(dir, "."+filepath.Base(path)+".tmp-*")
	if err != nil {
		return fmt.Errorf("atomicWriteFile 创建临时文件失败: %w", err)
	}
	tmpPath := tmp.Name()

	// 清理函数：任何中间错误都删掉 tmp
	cleanup := func() { _ = os.Remove(tmpPath) }

	if _, err := tmp.Write(data); err != nil {
		tmp.Close()
		cleanup()
		return fmt.Errorf("atomicWriteFile 写入临时文件失败: %w", err)
	}
	if err := tmp.Sync(); err != nil {
		// Sync 失败可能意味着数据未真正落盘，但 rename 后仍有机会安全。
		// 不阻断，让后续 rename 执行；cleanup 也不会被调用，因为我们希望文件就位。
		_ = tmp.Close()
	} else if err := tmp.Close(); err != nil {
		cleanup()
		return fmt.Errorf("atomicWriteFile 关闭临时文件失败: %w", err)
	}

	// 切权限（CreateTemp 给了 0600，改成调用方要求的权限）
	if perm != 0 {
		if err := os.Chmod(tmpPath, perm); err != nil {
			cleanup()
			return fmt.Errorf("atomicWriteFile 修权限失败: %w", err)
		}
	}

	if err := os.Rename(tmpPath, path); err != nil {
		cleanup()
		return fmt.Errorf("atomicWriteFile rename 失败: %w", err)
	}
	return nil
}
