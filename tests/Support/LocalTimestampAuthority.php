<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * A local RFC 3161 timestamp authority for the tests: its own CA and signing certificate, created
 * with openssl once per process. It really signs, with no network.
 */
final class LocalTimestampAuthority
{
    private static ?string $dir = null;

    public static function directory(): string
    {
        return self::$dir ??= self::create();
    }

    public static function caFile(): string
    {
        return self::directory().'/ca.pem';
    }

    public static function certFile(): string
    {
        return self::directory().'/tsa.crt';
    }

    /**
     * A signed reply (TimeStampResp, DER) to a request.
     */
    public static function reply(string $query): string
    {
        $dir = self::directory();
        $id = bin2hex(random_bytes(6));
        file_put_contents("{$dir}/{$id}.tsq", $query);

        self::run(['openssl', 'ts', '-reply', '-config', "{$dir}/ts.cnf", '-queryfile', "{$dir}/{$id}.tsq", '-out', "{$dir}/{$id}.tsr"]);
        $reply = (string) file_get_contents("{$dir}/{$id}.tsr");
        unlink("{$dir}/{$id}.tsq");
        unlink("{$dir}/{$id}.tsr");

        return $reply;
    }

    private static function create(): string
    {
        $dir = sys_get_temp_dir().'/iglesiatotal-test-tsa-'.getmypid();
        @mkdir($dir, 0700, true);

        file_put_contents("{$dir}/tsa.ext", "basicConstraints=CA:FALSE\nkeyUsage=critical,digitalSignature\nextendedKeyUsage=critical,timeStamping\n");
        file_put_contents("{$dir}/serial", "01\n");
        file_put_contents("{$dir}/ts.cnf", <<<CNF
            [ tsa ]
            default_tsa = tsa_config
            [ tsa_config ]
            serial = {$dir}/serial
            signer_cert = {$dir}/tsa.crt
            certs = {$dir}/tsa.crt
            signer_key = {$dir}/tsa.key
            signer_digest = sha256
            default_policy = 1.2.3.4.1
            digests = sha256
            accuracy = secs:1
            ordering = no
            tsa_name = no
            ess_cert_id_chain = no
            ess_cert_id_alg = sha256
            CNF);

        self::run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', "{$dir}/ca.key", '-out', "{$dir}/ca.pem", '-days', '3650', '-subj', '/CN=Iglesia Total Test TSA Root',
            '-addext', 'basicConstraints=critical,CA:TRUE', '-addext', 'keyUsage=critical,keyCertSign,cRLSign']);
        self::run(['openssl', 'req', '-newkey', 'rsa:2048', '-nodes', '-keyout', "{$dir}/tsa.key", '-out', "{$dir}/tsa.csr", '-subj', '/CN=Iglesia Total Test TSA']);
        self::run(['openssl', 'x509', '-req', '-in', "{$dir}/tsa.csr", '-CA', "{$dir}/ca.pem", '-CAkey', "{$dir}/ca.key", '-CAcreateserial',
            '-out', "{$dir}/tsa.crt", '-days', '3650', '-extfile', "{$dir}/tsa.ext"]);

        return $dir;
    }

    /**
     * @param  list<string>  $command
     */
    private static function run(array $command): void
    {
        $result = Process::run($command);

        if (! $result->successful()) {
            throw new RuntimeException('openssl failed: '.$result->errorOutput());
        }
    }
}
