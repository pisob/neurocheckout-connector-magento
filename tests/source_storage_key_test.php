<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../app/code/NeuroCheckout/Connector/Community/SourceStorageKey.php';
require_once __DIR__ . '/../app/code/NeuroCheckout/Connector/Community/ReconciledSourceExporter.php';
require_once __DIR__ . '/../app/code/NeuroCheckout/Connector/Community/SourcePullGateway.php';
require_once __DIR__ . '/../app/code/NeuroCheckout/Connector/Community/SourcePullProtocol.php';
use NeuroCheckout\Connector\Community\SourceStorageKey;
use NeuroCheckout\Connector\Community\ReconciledSourceExporter;
use NeuroCheckout\Connector\Community\SourcePullGateway;
use NeuroCheckout\Connector\Community\SourcePullProtocol;

$checks = 0;
function checkRotation(bool $condition, string $message): void {
    global $checks; $checks++;
    if (!$condition) { throw new RuntimeException($message); }
}
function rejectRotation(callable $call): void {
    try { $call(); } catch (RuntimeException $e) { checkRotation(true, 'refused'); return; }
    throw new RuntimeException('Unsafe rotation accepted');
}
function removeRotationFixture(string $path): void {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($path);
}

$platform = 'magento';
$path = '/neurocheckout/community/pull';
$root = sys_get_temp_dir() . '/nc-storage-rotation-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
$platformKey = random_bytes(32);
$wrap = static function (string $plain) use ($platformKey): string {
    $iv = random_bytes(12); $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $platformKey, OPENSSL_RAW_DATA, $iv, $tag);
    return base64_encode($iv . $tag . $cipher);
};
$unwrap = static function (string $value) use ($platformKey): string {
    $raw = base64_decode($value, true);
    if ($raw === false || strlen($raw) < 29) { throw new RuntimeException('invalid wrapper'); }
    $plain = openssl_decrypt(substr($raw,28), 'aes-256-gcm', $platformKey, OPENSSL_RAW_DATA, substr($raw,0,12), substr($raw,12,16));
    if ($plain === false) { throw new RuntimeException('invalid wrapper'); }
    return $plain;
};
$resolve = static function (string $directory, array $config) use ($wrap, $unwrap): string {
    return SourceStorageKey::resolve($directory, $config, $wrap, $unwrap);
};
try {
    foreach (['staging', 'production'] as $environment) {
        $directory = $root . '/' . $environment;
        mkdir($directory, 0700);
        $config = ['enabled'=>true, 'environment'=>$environment, 'platform'=>$platform, 'nativeScope'=>1,
            'shopId'=>'fixture-shop', 'secret'=>bin2hex(random_bytes(32))];
        $rotated = $config; $rotated['secret'] = bin2hex(random_bytes(32));
        $snapshot = [['kind'=>'cart', 'sourceId'=>'fixture-cart', 'payload'=>['status'=>'active', 'email'=>'fixture@example.invalid']]];
        $reader = static function () use (&$snapshot): array { return $snapshot; };
        $input = ['schema'=>1, 'shopId'=>$config['shopId'], 'streamId'=>null, 'cursor'=>'', 'limit'=>8];
        $first = (new ReconciledSourceExporter($directory, $config, $reader))->page($input);
        $next = $input; $next['cursor'] = $first['nextCursor']; $next['streamId'] = $first['streamId'];
        // Reproduce the released bug with the actual encrypted legacy snapshot.
        rejectRotation(static function () use ($directory, $rotated, $reader, $next): void {
            (new ReconciledSourceExporter($directory, $rotated, $reader))->page($next);
        });
        $snapshotFile = glob($directory . '/reconcile-*.bin')[0];
        $before = file_get_contents($snapshotFile);
        rejectRotation(static function () use ($resolve, $directory, $rotated): void { $resolve($directory, $rotated); });
        checkRotation(!glob($directory . '/storage-*.key'), 'wrong legacy key must not seed a replacement');
        checkRotation($resolve($directory, $config) === $config['secret'], 'legacy secret preserved');
        checkRotation(file_get_contents($snapshotFile) === $before, 'migration leaves snapshot bytes unchanged');
        checkRotation($resolve($directory, $rotated) === $config['secret'], 'new API secret cannot change storage key');
        $sidecar = glob($directory . '/storage-*.key')[0];
        $saved = file_get_contents($sidecar);
        checkRotation(strpos($saved, $config['secret']) === false && strpos($saved, $config['shopId']) === false, 'key file encrypted');
        checkRotation((fileperms($sidecar) & 0777) === 0600, 'key file is private');

        $export = static function (array $input, int $scope, array $configuration, string $directory) use ($reader, $resolve): array {
            $configuration['secret'] = $resolve($directory, $configuration);
            return (new ReconciledSourceExporter($directory, $configuration, $reader))->page($input);
        };
        $invoke = static function (array $input, string $signingSecret) use ($path, $platform, $rotated, $root, $directory, $export): array {
            $raw = json_encode($input, JSON_THROW_ON_ERROR);
            $time = (string)(int)floor(microtime(true)*1000); $nonce = bin2hex(random_bytes(16));
            $headers = ['content-type'=>'application/json', 'x-nc-source-time'=>$time, 'x-nc-source-nonce'=>$nonce,
                'x-nc-source-signature'=>hash_hmac('sha256', implode("\n", ['nc-source-pull-v1','POST',$path,$input['shopId'],$time,$nonce,hash('sha256',$raw)]), hex2bin($signingSecret))];
            return array_merge(SourcePullGateway::handle($platform, 1, $root, 'POST', $path, $headers, $raw, true, $export, $rotated, $directory), [$nonce]);
        };
        [$status] = $invoke($next, $config['secret']);
        checkRotation($status === 401, 'old authentication key rejected');
        [$status, $headers, $raw, $nonce] = $invoke($input, $rotated['secret']);
        checkRotation($status === 200, 'new authentication key accepted');
        checkRotation($headers['X-NC-Source-Response'] === SourcePullProtocol::responseSignature($rotated['secret'], $nonce, $raw), 'response signed by current key');
        $replay = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        checkRotation($replay['records'] === $first['records'] && $replay['nextCursor'] === $first['nextCursor'], 'retry preserves exact records and cursor');
        [$status, , $raw] = $invoke($next, $rotated['secret']);
        $page = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        checkRotation($status === 200 && $page['streamId'] === $first['streamId'] && $page['records'] === [], 'rotation never duplicates unchanged cart');
        $next['cursor'] = $page['nextCursor'];
        $snapshot[0]['payload']['status'] = 'converted';
        [$status, , $raw] = $invoke($next, $rotated['secret']);
        $page = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        checkRotation($status === 200 && $page['records'][0]['revision'] === 2 && $page['records'][0]['payload']['status'] === 'converted', 'conversion continues existing revision');

        foreach (['nativeScope'=>2, 'shopId'=>'other-shop', 'environment'=>$environment==='staging'?'production':'staging'] as $field=>$value) {
            $other = $rotated; $other[$field] = $value;
            checkRotation($resolve($directory,$other) === $rotated['secret'], 'scope has independent key: '.$field);
            $binding = json_encode(['source-storage-v1',$platform,$other['environment'],$other['nativeScope'],$other['shopId']]);
            $otherPath = $directory.'/storage-'.hash('sha256',$binding).'.key';
            file_put_contents($otherPath,$saved);
            rejectRotation(static function () use ($resolve,$directory,$other): void { $resolve($directory,$other); });
        }
        file_put_contents($sidecar,'corrupt');
        rejectRotation(static function () use ($resolve,$directory,$rotated): void { $resolve($directory,$rotated); });
        file_put_contents($sidecar,$saved);
        chmod($sidecar,0644);
        rejectRotation(static function () use ($resolve,$directory,$rotated): void { $resolve($directory,$rotated); });
        chmod($sidecar,0600);
        $lock = fopen($sidecar.'.lock','r+b'); flock($lock,LOCK_EX);
        rejectRotation(static function () use ($resolve,$directory,$rotated): void { $resolve($directory,$rotated); });
        flock($lock,LOCK_UN); fclose($lock);
        unlink($sidecar); symlink($snapshotFile,$sidecar);
        rejectRotation(static function () use ($resolve,$directory,$rotated): void { $resolve($directory,$rotated); });
        unlink($sidecar);
        rejectRotation(static function () use ($resolve,$directory,$rotated): void { $resolve($directory,$rotated); });
        checkRotation(!file_exists($sidecar), 'lost key never silently replaced');
        $fresh = $root.'/invalid-wrap-'.$environment; mkdir($fresh,0700);
        rejectRotation(static function () use ($fresh,$config): void {
            SourceStorageKey::resolve($fresh,$config,static function($p){return $p;},static function($p){return $p;});
        });
        checkRotation(!glob($fresh.'/storage-*.key'), 'failed encryption leaves no key');
    }
    echo $checks . " source storage rotation assertions passed.\n";
} finally { removeRotationFixture($root); }
