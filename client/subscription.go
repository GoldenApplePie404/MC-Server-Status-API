package main

import (
	"encoding/json"
	"fmt"
	"net"
	"os"
	"strings"
)

// Server 表示一条订阅：监控哪个 Minecraft 服务器。
type Server struct {
	Host   string `json:"host"`              // 服务器地址（不含端口；IPv6 带方括号）
	Port   int    `json:"port"`              // 端口，0 表示使用默认 25565
	Alias  string `json:"alias,omitempty"`   // 别名，用于报告里的可读名称
	Group  string `json:"group,omitempty"`   // 分组，便于把服务器归类（如 "生存服"/"测试服"）
	Online int    `json:"-"`                 // 最近一次在线人数（运行期填充，不来自配置文件）
}

// Normalize 规范化一条订阅：补默认端口、默认别名。
func (s *Server) Normalize(defaultPort int) {
	if s.Port <= 0 {
		s.Port = defaultPort
	}
	if strings.TrimSpace(s.Alias) == "" {
		s.Alias = s.Host
	}
}

// Key 返回这台服务器的唯一标识（host+port），用于去重与快照文件名。
func (s *Server) Key() string {
	return net.JoinHostPort(s.Host, fmt.Sprintf("%d", s.Port))
}

// Subscriptions 是订阅清单文件的内容：一个服务器列表。未来可扩展 cron 等字段。
type Subscriptions struct {
	Servers []*Server `json:"servers"`
}

// LoadSubscriptions 读取并解析订阅清单文件。
// 参数 path 是清单文件路径（json）。
// 参数 defaultPort 是未指定端口时的默认端口（通常取配置里的值）。
func LoadSubscriptions(path string, defaultPort int) (*Subscriptions, error) {
	raw, err := os.ReadFile(path)
	if err != nil {
		return nil, fmt.Errorf("读取订阅清单 %s 失败: %w", path, err)
	}

	var subs Subscriptions
	if err := json.Unmarshal(raw, &subs); err != nil {
		return nil, fmt.Errorf("解析订阅清单 %s 失败: %w", path, err)
	}

	if len(subs.Servers) == 0 {
		return nil, fmt.Errorf("订阅清单 %s 中没有配置任何服务器 (servers 为空)", path)
	}

	// 规范化 + 去重
	seen := make(map[string]bool, len(subs.Servers))
	deduped := make([]*Server, 0, len(subs.Servers))
	for _, s := range subs.Servers {
		s.Normalize(defaultPort)
		key := s.Key()
		if seen[key] {
			fmt.Printf("[warn] 忽略重复订阅 %s\n", key)
			continue
		}
		seen[key] = true
		deduped = append(deduped, s)
	}
	subs.Servers = deduped

	return &subs, nil
}

// String 打印订阅总览，方便用户核对。
func (s *Subscriptions) String() string {
	var b strings.Builder
	fmt.Fprintf(&b, "共 %d 台服务器：\n", len(s.Servers))
	for i, srv := range s.Servers {
		grp := srv.Group
		if grp == "" {
			grp = "-"
		}
		fmt.Fprintf(&b, "  %2d. [%s] %s (%s)\n", i+1, grp, srv.Alias, srv.Key())
	}
	return b.String()
}