<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Model\Security;

class IpResolver
{
    /**
     * @param array<string, mixed> $server
     * @param list<string> $trustedProxyRules
     */
    public function resolve(array $server, array $trustedProxyRules = []): string
    {
        $remoteAddr = trim((string) ($server['REMOTE_ADDR'] ?? ''));
        $fallbackIp = $remoteAddr !== '' ? $remoteAddr : '127.0.0.1';

        if ($remoteAddr === '' || !$this->isTrustedProxy($remoteAddr, $trustedProxyRules)) {
            return $fallbackIp;
        }

        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP'] as $header) {
            $headerIp = trim((string) ($server[$header] ?? ''));
            if ($headerIp !== '') {
                return $headerIp;
            }
        }

        $forwardedFor = trim((string) ($server['HTTP_X_FORWARDED_FOR'] ?? ''));
        if ($forwardedFor !== '') {
            foreach (explode(',', $forwardedFor) as $part) {
                $candidateIp = trim($part);
                if ($candidateIp !== '') {
                    return $candidateIp;
                }
            }
        }

        return $fallbackIp;
    }

    /**
     * @return list<string>
     */
    public function parseRules(string $rawRules): array
    {
        $rules = preg_split('/[\s,;]+/', trim($rawRules));
        return array_values(array_filter(array_map('trim', is_array($rules) ? $rules : [])));
    }

    /**
     * @param list<string> $trustedProxyRules
     */
    public function isTrustedProxy(string $remoteAddr, array $trustedProxyRules): bool
    {
        if ($remoteAddr === '' || !$trustedProxyRules) {
            return false;
        }

        foreach ($trustedProxyRules as $rule) {
            if ($this->matchesRule($remoteAddr, $rule)) {
                return true;
            }
        }

        return false;
    }

    public function matchesRule(string $clientIp, string $rule): bool
    {
        if ($clientIp === '' || $rule === '') {
            return false;
        }

        if (strpos($rule, '/') === false) {
            return hash_equals($rule, $clientIp);
        }

        [$subnet, $prefix] = array_pad(explode('/', $rule, 2), 2, null);
        $prefix = is_numeric($prefix) ? (int) $prefix : -1;

        $ipBinary = @inet_pton($clientIp);
        $subnetBinary = @inet_pton((string) $subnet);
        if ($ipBinary === false || $subnetBinary === false || strlen($ipBinary) !== strlen($subnetBinary)) {
            return false;
        }

        $maxBits = strlen($ipBinary) * 8;
        if ($prefix < 0 || $prefix > $maxBits) {
            return false;
        }

        $fullBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;

        if ($fullBytes > 0 && substr($ipBinary, 0, $fullBytes) !== substr($subnetBinary, 0, $fullBytes)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return
            (ord($ipBinary[$fullBytes]) & $mask)
            === (ord($subnetBinary[$fullBytes]) & $mask);
    }
}
