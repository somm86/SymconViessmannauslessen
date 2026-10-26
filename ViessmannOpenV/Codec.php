<?php
final class OpenVCodec
{
    public static function validate(array $r): void
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $r['Name'] ?? '') ||
            !preg_match('/^[0-9a-f]{4}$/iD', $r['HexStamp'] ?? '') ||
            !in_array($r['Typ'] ?? '', ['bool','int','float','string'], true) ||
            !is_int($r['length'] ?? null) || $r['length'] < 1 || $r['length'] > 4 ||
            !in_array((string)($r['Convert'] ?? ''), ['', '2','10','1000','3600','X10','HexValue','Temperature10','Temperature100'], true) ||
            !is_bool($r['only_read'] ?? null) || !is_bool($r['signed'] ?? null)) {
            throw new InvalidArgumentException('Ungültige Registerkonfiguration');
        }
        if ($r['Typ'] === 'string' && $r['Convert'] !== 'HexValue') {
            throw new InvalidArgumentException('Strings benötigen HexValue');
        }
    }
    private static function factor(array $r): float
    {
        $c = (string)$r['Convert'];
        if ($c === 'X10') return 0.1;
        if ($c === 'Temperature10') return 10;
        if ($c === 'Temperature100') return 100;
        return in_array($c, ['2','10','1000','3600'], true) ? (float)$c : 1;
    }
    public static function encode(array $r, $value): string
    {
        self::validate($r);
        if ($r['Convert'] === 'HexValue') {
            if (!is_string($value) || !preg_match('/^[0-9a-f]+$/iD', $value) || strlen($value) !== $r['length']*2) {
                throw new InvalidArgumentException('HexValue benötigt exakt die konfigurierte Anzahl Bytes');
            }
            return hex2bin($value);
        }
        if ($r['Typ'] === 'bool') {
            if (!is_bool($value) && !in_array($value, [0,1], true)) throw new InvalidArgumentException('Bool erwartet true/false oder 0/1');
            $value = (int)$value;
        }
        if (!is_numeric($value) || !is_finite((float)$value)) throw new InvalidArgumentException('Numerischer Wert erforderlich');
        $raw = (float)$value * self::factor($r);
        if (abs($raw-round($raw)) > 0.000001) throw new InvalidArgumentException('Wert nicht exakt darstellbar');
        $bits = 8*$r['length'];
        $signed = $r['signed'] || strncmp((string)$r['Convert'], 'Temperature', 11) === 0;
        $min = $signed ? -(2 ** ($bits-1)) : 0;
        $max = 2 ** ($signed ? $bits-1 : $bits)-1;
        if ($raw < $min || $raw > $max) throw new InvalidArgumentException('Wert außerhalb des Registerbereichs');
        return substr(pack('V', (int)round($raw)), 0, $r['length']);
    }
    public static function decode(array $r, string $data)
    {
        self::validate($r);
        if (strlen($data) !== $r['length']) throw new InvalidArgumentException('Antwortlänge falsch');
        if ($r['Convert'] === 'HexValue') return strtoupper(bin2hex($data));
        $n = unpack('V', str_pad($data,4,"\0"))[1];
        $bits = 8*$r['length'];
        if (($r['signed'] || strncmp((string)$r['Convert'], 'Temperature', 11) === 0) && $n >= 2 ** ($bits-1)) $n -= 2 ** $bits;
        $n /= self::factor($r);
        switch ($r['Typ']) {
            case 'bool': return $n != 0;
            case 'int': if (floor($n) != $n) throw new InvalidArgumentException('Float-Typ für gebrochene Werte erforderlich'); return (int)$n;
            default: return $n;
        }
    }
    public static function frame(array $r, ?string $payload = null): string
    {
        self::validate($r);
        if ($payload !== null && strlen($payload) !== $r['length']) throw new InvalidArgumentException('Schreiblänge falsch');
        return hex2bin($payload === null ? 'F7' : 'F4').hex2bin($r['HexStamp']).chr($r['length']).($payload ?? '');
    }
}
