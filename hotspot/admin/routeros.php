<?php
// =============================================================
// Cliente minimo da API do RouterOS (v6.43+), sem dependencias.
// Conexao configurada em config.php (ROUTER_*). Com ROUTER_MOCK = true
// usa dados simulados, util para testar o painel sem o roteador.
// =============================================================

class RouterOsException extends RuntimeException {}

class RouterOsClient
{
    /** @var resource */
    private $sock;

    public function __construct(string $host, int $port, string $user, string $pass, bool $ssl = false, int $timeout = 5)
    {
        $ctx = stream_context_create(['ssl' => [
            // api-ssl do RouterOS costuma usar certificado proprio; prefira VPN a expor a API.
            'verify_peer'      => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
            'ciphers'          => 'ALL:@SECLEVEL=0',
        ]]);
        $sock = @stream_socket_client(
            ($ssl ? 'ssl' : 'tcp') . "://$host:$port", $errno, $errstr, $timeout,
            STREAM_CLIENT_CONNECT, $ctx
        );
        if (!$sock) {
            throw new RouterOsException("Sem conexao com o roteador ($errstr)");
        }
        stream_set_timeout($sock, $timeout);
        $this->sock = $sock;

        try {
            $this->command('/login', ['name' => $user, 'password' => $pass]);
        } catch (RouterOsException $e) {
            $this->close();
            throw new RouterOsException('Login recusado pelo roteador: ' . $e->getMessage());
        }
    }

    public function close(): void
    {
        if (is_resource($this->sock)) {
            fclose($this->sock);
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * @param array<string,string> $attrs   vira "=chave=valor"
     * @param string[]             $queries vira "?palavra" (ex.: "type=blocked")
     * @return array<int,array<string,string>> linhas "!re"
     */
    public function command(string $cmd, array $attrs = [], array $queries = []): array
    {
        $this->writeWord($cmd);
        foreach ($attrs as $k => $v) {
            $this->writeWord("=$k=$v");
        }
        foreach ($queries as $q) {
            $this->writeWord("?$q");
        }
        $this->writeWord('');

        $rows = [];
        $erro = null;
        while (true) {
            $sentence = $this->readSentence();
            if (!$sentence) {
                continue;
            }
            $tipo = array_shift($sentence);
            if ($tipo === '!re') {
                $rows[] = $this->parseAttrs($sentence);
            } elseif ($tipo === '!trap') {
                $erro = $this->parseAttrs($sentence)['message'] ?? 'erro desconhecido';
            } elseif ($tipo === '!fatal') {
                throw new RouterOsException('Roteador encerrou a conexao: ' . implode(' ', $sentence));
            } elseif ($tipo === '!done') {
                break;
            }
        }
        if ($erro !== null) {
            throw new RouterOsException($erro);
        }
        return $rows;
    }

    /** @param string[] $words */
    private function parseAttrs(array $words): array
    {
        $out = [];
        foreach ($words as $w) {
            if ($w !== '' && $w[0] === '=') {
                $pos = strpos($w, '=', 1);
                if ($pos !== false) {
                    $out[substr($w, 1, $pos - 1)] = substr($w, $pos + 1);
                }
            }
        }
        return $out;
    }

    private function writeWord(string $w): void
    {
        $this->writeAll($this->encodeLength(strlen($w)) . $w);
    }

    private function writeAll(string $data): void
    {
        $total = strlen($data);
        for ($done = 0; $done < $total;) {
            $n = @fwrite($this->sock, substr($data, $done));
            if ($n === false || $n === 0) {
                throw new RouterOsException('Falha ao enviar dados ao roteador');
            }
            $done += $n;
        }
    }

    private function encodeLength(int $l): string
    {
        if ($l < 0x80)       return chr($l);
        if ($l < 0x4000)     return pack('n', $l | 0x8000);
        if ($l < 0x200000) {
            $l |= 0xC00000;
            return chr(($l >> 16) & 0xFF) . chr(($l >> 8) & 0xFF) . chr($l & 0xFF);
        }
        if ($l < 0x10000000) return pack('N', $l | 0xE0000000);
        return chr(0xF0) . pack('N', $l);
    }

    /** @return string[] */
    private function readSentence(): array
    {
        $words = [];
        while (($w = $this->readWord()) !== '') {
            $words[] = $w;
        }
        return $words;
    }

    private function readWord(): string
    {
        $b = ord($this->readBytes(1));
        if ($b < 0x80) {
            $len = $b;
        } else {
            if ($b < 0xC0)      { $n = 1; $len = $b & 0x3F; }
            elseif ($b < 0xE0)  { $n = 2; $len = $b & 0x1F; }
            elseif ($b < 0xF0)  { $n = 3; $len = $b & 0x0F; }
            else                { $n = 4; $len = 0; }
            foreach (str_split($this->readBytes($n)) as $c) {
                $len = ($len << 8) | ord($c);
            }
        }
        return $len > 0 ? $this->readBytes($len) : '';
    }

    private function readBytes(int $n): string
    {
        $buf = '';
        while (strlen($buf) < $n) {
            $chunk = @fread($this->sock, $n - strlen($buf));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($this->sock);
                throw new RouterOsException(!empty($meta['timed_out'])
                    ? 'Tempo esgotado aguardando o roteador'
                    : 'Conexao com o roteador perdida');
            }
            $buf .= $chunk;
        }
        return $buf;
    }
}

// =============================================================
// Operacoes de hotspot usadas pelo painel
// =============================================================
interface HotspotRouter
{
    /** @return array<int,array{id:string,user:string,ip:string,mac:string,uptime:string,idle:string,login:string,up:int,down:int}> */
    public function activeList(): array;
    public function removeActive(string $id): void;
    /** Apaga os cookies de login do aparelho, para ele nao entrar de novo sem o portal. */
    public function clearCookies(string $mac): int;
    /** @return array<int,array{id:string,mac:string,comment:string}> */
    public function blockedList(): array;
    public function block(string $mac, string $comment): void;
    public function unblock(string $bindingId): void;
}

class HotspotRouterReal implements HotspotRouter
{
    public function __construct(private RouterOsClient $api) {}

    public function activeList(): array
    {
        $out = [];
        foreach ($this->api->command('/ip/hotspot/active/print') as $r) {
            $out[] = [
                'id'     => $r['.id'] ?? '',
                'user'   => $r['user'] ?? '',
                'ip'     => $r['address'] ?? '',
                'mac'    => strtoupper($r['mac-address'] ?? ''),
                'uptime' => $r['uptime'] ?? '',
                'idle'   => $r['idle-time'] ?? '',
                'login'  => $r['login-by'] ?? '',
                // bytes-in = recebido do cliente (upload); bytes-out = enviado ao cliente (download)
                'up'     => (int) ($r['bytes-in'] ?? 0),
                'down'   => (int) ($r['bytes-out'] ?? 0),
            ];
        }
        return $out;
    }

    public function removeActive(string $id): void
    {
        $this->api->command('/ip/hotspot/active/remove', ['.id' => $id]);
    }

    public function clearCookies(string $mac): int
    {
        $n = 0;
        foreach ($this->api->command('/ip/hotspot/cookie/print', [], ["mac-address=$mac"]) as $r) {
            if (!empty($r['.id'])) {
                $this->api->command('/ip/hotspot/cookie/remove', ['.id' => $r['.id']]);
                $n++;
            }
        }
        return $n;
    }

    public function blockedList(): array
    {
        $out = [];
        foreach ($this->api->command('/ip/hotspot/ip-binding/print', [], ['type=blocked']) as $r) {
            $out[] = [
                'id'      => $r['.id'] ?? '',
                'mac'     => strtoupper($r['mac-address'] ?? ''),
                'comment' => $r['comment'] ?? '',
            ];
        }
        return $out;
    }

    public function block(string $mac, string $comment): void
    {
        $this->api->command('/ip/hotspot/ip-binding/add', [
            'mac-address' => $mac,
            'type'        => 'blocked',
            'comment'     => $comment,
        ]);
    }

    public function unblock(string $bindingId): void
    {
        $this->api->command('/ip/hotspot/ip-binding/remove', ['.id' => $bindingId]);
    }
}

// Dados simulados, guardados em arquivo temporario (so para testar a tela)
class HotspotRouterMock implements HotspotRouter
{
    private string $file;

    public function __construct()
    {
        $this->file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sn_hotspot_mock.json';
    }

    private function load(): array
    {
        $d = is_file($this->file) ? json_decode((string) file_get_contents($this->file), true) : null;
        if (!is_array($d)) {
            $d = ['next' => 7, 'blocked' => [], 'active' => [
                ['id' => '*1', 'user' => 'visitante_sgp', 'ip' => '10.50.1.11', 'mac' => 'AA:BB:CC:00:00:01', 'start' => time() - 5400,  'idle' => 12,   'up' => 4200000,  'down' => 38000000],
                ['id' => '*2', 'user' => 'visitante_sgp', 'ip' => '10.50.1.12', 'mac' => 'AA:BB:CC:00:00:02', 'start' => time() - 600,   'idle' => 3,    'up' => 900000,   'down' => 12500000],
                ['id' => '*3', 'user' => 'visitante_sgp', 'ip' => '10.50.1.13', 'mac' => 'AA:BB:CC:00:00:03', 'start' => time() - 9800,  'idle' => 7300, 'up' => 120000,   'down' => 800000],
                ['id' => '*4', 'user' => 'visitante_sgp', 'ip' => '10.50.1.14', 'mac' => 'AA:BB:CC:00:00:04', 'start' => time() - 120,   'idle' => 0,    'up' => 15000000, 'down' => 210000000],
                ['id' => '*5', 'user' => 'visitante_sgp', 'ip' => '10.50.1.15', 'mac' => 'AA:BB:CC:00:00:05', 'start' => time() - 36000, 'idle' => 40,   'up' => 6000000,  'down' => 91000000],
                ['id' => '*6', 'user' => 'visitante_sgp', 'ip' => '10.50.1.16', 'mac' => 'AA:BB:CC:00:00:06', 'start' => time() - 2400,  'idle' => 1,    'up' => 300000,   'down' => 5100000],
            ]];
            $this->save($d);
        }
        return $d;
    }

    private function save(array $d): void
    {
        file_put_contents($this->file, json_encode($d), LOCK_EX);
    }

    private static function dur(int $s): string
    {
        $h = intdiv($s, 3600);
        $m = intdiv($s % 3600, 60);
        return ($h ? $h . 'h' : '') . ($m ? $m . 'm' : '') . ($s % 60) . 's';
    }

    public function activeList(): array
    {
        $out = [];
        foreach ($this->load()['active'] as $r) {
            $out[] = [
                'id' => $r['id'], 'user' => $r['user'], 'ip' => $r['ip'], 'mac' => $r['mac'],
                'uptime' => self::dur(time() - $r['start']), 'idle' => self::dur($r['idle']), 'login' => 'http-pap',
                'up' => $r['up'], 'down' => $r['down'],
            ];
        }
        return $out;
    }

    public function removeActive(string $id): void
    {
        $d = $this->load();
        $d['active'] = array_values(array_filter($d['active'], fn($r) => $r['id'] !== $id));
        $this->save($d);
    }

    public function clearCookies(string $mac): int
    {
        return 0;
    }

    public function blockedList(): array
    {
        return $this->load()['blocked'];
    }

    public function block(string $mac, string $comment): void
    {
        $d = $this->load();
        $d['blocked'][] = ['id' => '*B' . $d['next']++, 'mac' => strtoupper($mac), 'comment' => $comment];
        $this->save($d);
    }

    public function unblock(string $bindingId): void
    {
        $d = $this->load();
        $d['blocked'] = array_values(array_filter($d['blocked'], fn($r) => $r['id'] !== $bindingId));
        $this->save($d);
    }
}

function hotspot_router(): HotspotRouter
{
    if (defined('ROUTER_MOCK') && ROUTER_MOCK) {
        return new HotspotRouterMock();
    }
    foreach (['ROUTER_HOST', 'ROUTER_USER', 'ROUTER_PASS'] as $c) {
        if (!defined($c) || constant($c) === '') {
            throw new RouterOsException("Roteador nao configurado ($c ausente em config.php)");
        }
    }
    return new HotspotRouterReal(new RouterOsClient(
        ROUTER_HOST,
        defined('ROUTER_PORT') ? (int) ROUTER_PORT : 8728,
        ROUTER_USER,
        ROUTER_PASS,
        defined('ROUTER_SSL') && ROUTER_SSL
    ));
}
